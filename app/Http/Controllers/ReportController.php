<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Exports\ArrayExport;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\Inspection;
use App\Models\MasterValue;
use App\Models\User;
use App\Questionnaire\QuestionnaireDefinition as Q;
use App\Services\AuditLogger;
use App\Services\EvidenceStorage;
use App\Services\JurisdictionService;
use App\Support\MasterPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/** Inspection lists, single report view, PDF and Excel export (PRD 11). */
class ReportController extends Controller
{
    public function __construct(private JurisdictionService $j) {}

    public function mine(Request $request)
    {
        $user = $request->user();
        $query = $this->filtered(Inspection::where('officer_id', $user->id), $request, own: true);

        return view('inspections.index', [
            'mode' => 'mine',
            'inspections' => $query->clone()->with(['school', 'rating'])->latest('inspection_date')->latest('id')->paginate(config('inspection.per_page'))->withQueryString(),
            'counts' => [
                'draft' => Inspection::where('officer_id', $user->id)->whereIn('status', ['draft', 'unlocked'])->count(),
                'submitted' => Inspection::where('officer_id', $user->id)->where('status', 'submitted')->count(),
                'month' => Inspection::where('officer_id', $user->id)->where('status', 'submitted')->where('inspection_date', '>=', now()->startOfMonth()->toDateString())->count(),
            ],
        ] + $this->filterOptions($request));
    }

    public function area(Request $request)
    {
        $user = $request->user();
        $base = $this->j->inspections($user)->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked]);
        $query = $this->filtered($base, $request);

        [$from, $to] = $this->period($request);
        $schoolsQ = $this->j->schools($user)->where('status', 'active');
        foreach (['district_id', 'block_id', 'cluster_id'] as $c) {
            if ($request->filled($c)) {
                $schoolsQ->where($c, $request->input($c));
            }
        }

        return view('inspections.index', [
            'mode' => 'area',
            'inspections' => $query->clone()->with(['school', 'officer', 'rating'])->latest('submitted_at')->paginate(config('inspection.per_page'))->withQueryString(),
            'counts' => [
                'schools' => $schoolsQ->count(),
                'inspected' => $query->clone()->distinct('school_id')->count('school_id'),
                'submitted' => $query->clone()->count(),
            ],
            'period' => [$from, $to],
        ] + $this->filterOptions($request));
    }

    public function show(Inspection $inspection)
    {
        $this->authorize('view', $inspection);

        return view('inspections.show', $this->reportData($inspection));
    }

    public function pdf(Inspection $inspection)
    {
        $this->authorize('view', $inspection);
        $data = $this->reportData($inspection);

        // Embed photographs as data URIs so DomPDF needs no remote access
        $data['images'] = $data['inspection']->evidence
            ->filter(fn ($e) => $e->kind === 'photo' && $e->status === \App\Enums\EvidenceStatus::Uploaded)
            ->mapWithKeys(function ($p) {
            $disk = Storage::disk(EvidenceStorage::DISK);

            return [$p->id => $disk->exists($p->storage_path) ? 'data:'.$p->mime.';base64,'.base64_encode($disk->get($p->storage_path)) : null];
        });

        // Official header logos (Modification V4), embedded so DomPDF needs no file or remote access
        $data['logos'] = \App\Support\PdfLogos::all();
        $data['generatedAt'] = now(); // shown as DD-MM-YYYY HH:MM in the footer

        AuditLogger::log('inspection_pdf', $inspection);
        $name = str_replace('/', '-', $inspection->inspection_no ?: 'draft-'.$inspection->id).'.pdf';

        // Render first, then stamp "Page X of Y" on every page (the total is only known after rendering)
        $dompdf = Pdf::loadView('inspections.pdf', $data)->setPaper('a4')->getDomPDF();
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 40 - 62, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.28, 0.33, 0.41]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    public function export(Request $request, string $mode)
    {
        $user = $request->user();
        abort_unless($user->can('export_excel'), 403);
        abort_unless($mode === 'area', 404); // 23-11-2026 (V14): My Inspections has no Excel download, only the PDF

        $rows =$this->exportQuery($request, $mode)
            ->with(['school.category', 'officer.designation', 'district', 'block', 'cluster', 'answers', 'absentTeachers', 'classroomObservations', 'fundPositions', 'photos', 'rating'])
            ->orderBy('inspection_date')->limit(20000)->get();

        // All questionnaire answers (steps 1, 2, 6) in definition order, except the person-met fields shown separately
        $codes = array_values(array_filter(array_keys(Q::all()), fn ($c) => ! str_starts_with($c, 'BAS_')));
        $headings = ['Inspection ID', 'Status', 'Questionnaire', 'Date', 'Submitted at', 'District', 'Block', 'Cluster', 'UDISE Code', 'School', 'Category',
            'Officer', 'Designation', 'Person met', 'Absentee entries', 'Unauthorised leave', 'Classrooms observed',
            'Fund years', 'Fund received (lakh)', 'Fund expenditure (lakh)', 'Photos', 'Photos from gallery', 'Rating score (%)', 'Rating'];
        foreach ($codes as $c) {
            $headings[] = Q::find($c)['label'];
        }

        $data = $rows->map(function (Inspection $i) use ($codes) {
            $a = $i->answerMap();
            $row = [
                $i->inspection_no ?? '—', $i->status->label(), 'v'.$i->questionnaire_version, $i->inspection_date->format('d-m-Y'), $i->submitted_at?->format('d-m-Y H:i'),
                $i->district?->label, $i->block?->label, $i->cluster?->label, $i->school->udise_code, $i->school->name, $i->school->category?->label,
                $i->officer->name, $i->officer->designation_label, trim($i->person_met_name.' ('.$i->person_met_designation.')', ' ()'),
                $i->absentTeachers->count(), $i->absentTeachers->where('reason', 'UL')->count(), $i->classroomObservations->count(),
                $i->fundPositions->pluck('financial_year')->implode(', '),
                round($i->fundPositions->sum(fn ($f) => $f->totalReceived()), 2), round($i->fundPositions->sum(fn ($f) => $f->totalExpenditure()), 2),
                $i->photos->count(), $i->photos->where('source', 'gallery')->count(),
                $i->rating?->score_percent, $i->rating?->band ?? '—',
            ];
            foreach ($codes as $c) {
                $row[] = Q::optionLabel($c, $a[$c] ?? null);
            }

            return $row;
        })->all();

        if (! config('inspection.rating.display')) { // Modification V9: rating columns left out unless RATING_DISPLAY=true
            $drop = array_keys(array_intersect($headings, ['Rating score (%)', 'Rating']));
            $headings = array_values(array_diff_key($headings, array_flip($drop)));
            $data = array_map(fn ($r) => array_values(array_diff_key($r, array_flip($drop))), $data);
        }

        AuditLogger::log('inspections_exported', null, ['mode' => $mode, 'format' => 'excel', 'rows' => count($data), 'filters' => $request->query()]);

        return Excel::download(new ArrayExport($headings, $data), 'inspections-'.$mode.'-'.now()->format('Ymd-His').'.xlsx');
    }

    /**
     * 23-11-2026: Inspections / My Inspections as a PDF list, one row per inspection (the full answers stay in Excel).
     * Same scope and filters as the Excel export, at most config('inspection.master_pdf_max_rows') rows.
     */
    public function exportPdf(Request $request, string $mode)
    {
        abort_unless(in_array($mode, ['mine', 'area'], true), 404);
        $query = $this->exportQuery($request, $mode);
        $count = (clone $query)->count();
        $max = MasterPdf::maxRows();
        if ($count > $max) {
            return back()->with('warning', number_format($count).' inspections match. A PDF holds at most '.number_format($max)
                .': narrow the period or filter by district, block or cluster, or use Export for the full list.');
        }
        MasterPdf::prepare();

        $area = $mode === 'area';
        $headings = ['Sl', 'Inspection ID', 'Date', 'UDISE Code', 'School', 'Cluster', 'Block'];
        if ($area) {
            $headings[] = 'Officer';
        }
        array_push($headings, 'Status', 'Absentees', 'Classrooms', 'Photos', 'Submitted at');

        $rows = [];
        $query->with(['school:id,udise_code,name', 'officer:id,name', 'block:id,name,code', 'cluster:id,name,code'])
            ->withCount(['absentTeachers', 'classroomObservations', 'photos'])
            ->orderBy('inspection_date')->orderBy('id')
            ->chunk(500, function ($list) use (&$rows, $area) {
                foreach ($list as $i) {
                    $row = [count($rows) + 1, $i->inspection_no ?? '—', $i->inspection_date->format('d-m-Y'), $i->school?->udise_code, $i->school?->name,
                        $i->cluster?->label, $i->block?->label];
                    if ($area) {
                        $row[] = $i->officer?->name;
                    }
                    array_push($row, $i->status->label(), $i->absent_teachers_count, $i->classroom_observations_count, $i->photos_count,
                        $i->submitted_at?->format('d-m-Y H:i') ?? '—');
                    $rows[] = $row;
                }
            });

        AuditLogger::log('inspections_exported', null, ['mode' => $mode, 'format' => 'pdf', 'rows' => count($rows), 'filters' => $request->query()]);

        return MasterPdf::download($mode === 'mine' ? 'My Inspections' : 'Inspections', $headings, $rows, $this->filterLabels($request, $mode),
            'inspections-'.$mode.'-'.now()->format('Ymd-His').'.pdf');
    }

    /* ---------------- helpers ---------------- */

    /** The export base: own inspections, or the jurisdiction's submitted ones. Cluster users export only their own (PRD 3.2). */
    private function exportQuery(Request $request, string $mode): Builder
    {
        $user = $request->user();
        $base = $mode === 'mine' || $user->level->value === 'cluster'
            ? Inspection::where('officer_id', $user->id)
            : $this->j->inspections($user)->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked]);

        return $this->filtered($base, $request, own: $mode === 'mine');
    }

    /** Readable filter chips for the PDF. */
    private function filterLabels(Request $request, string $mode): array
    {
        $own = $mode === 'mine';
        $period = null;
        if (! $own || $request->filled('from') || $request->filled('to')) {
            [$from, $to] = $this->period($request);
            $period = 'Period: '.\Illuminate\Support\Carbon::parse($from)->format('d-m-Y').' to '.\Illuminate\Support\Carbon::parse($to)->format('d-m-Y');
        }
        $status = $request->status ? (InspectionStatus::tryFrom((string) $request->status)?->label() ?? ucfirst((string) $request->status)) : null;

        return array_values(array_filter([
            'Downloaded by: '.$request->user()->name,
            $period,
            $request->district_id ? 'District: '.(District::find((int) $request->district_id)?->label ?? '—') : null,
            $request->block_id ? 'Block: '.(Block::find((int) $request->block_id)?->label ?? '—') : null,
            $request->cluster_id ? 'Cluster: '.(Cluster::find((int) $request->cluster_id)?->label ?? '—') : null,
            $request->officer_id ? 'Inspecting officer: '.(User::find((int) $request->officer_id)?->name ?? '—') : null,
            $status ? 'Status: '.($request->status === 'draft' ? 'Draft / unlocked' : $status) : null,
            $request->school ? 'School: '.$request->school : null,
        ]));
    }

    private function reportData(Inspection $inspection): array
    {
        $inspection->load(['school.district', 'school.block', 'school.cluster', 'school.management', 'school.category', 'officer.designation',
            'answers', 'absentTeachers', 'classroomObservations.tlmItems', 'fundPositions', 'evidence.category', 'remarks', 'unlocker']);

        return [
            'inspection' => $inspection,
            'steps' => Q::steps(),
            'questions' => Q::questions(),
            'answers' => $inspection->answerMap(),
            'remarks' => $inspection->remarksMap(),
            'startDistance' => $inspection->distanceFromSchool($inspection->start_lat, $inspection->start_lng),
            'submitDistance' => $inspection->distanceFromSchool($inspection->submit_lat, $inspection->submit_lng),
            'photoCategories' => \App\Models\MasterValue::ofType('photo_category')->get(),
            'rating' => $rating = $inspection->rating()->with('scheme:id,version,bands')->first(), // New Module 3
            'previousRating' => $rating?->isRated() ? $inspection->previousRating() : null, // Modification V7
        ];
    }

    private function period(Request $request): array
    {
        return [
            $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            $request->date('to')?->toDateString() ?? now()->toDateString(),
        ];
    }

    private function filtered(Builder $q, Request $request, bool $own = false): Builder
    {
        if (! $own || $request->filled('from') || $request->filled('to')) {
            [$from, $to] = $this->period($request);
            $q->where('inspection_date', '>=', $from)->where('inspection_date', '<=', $to);
        }

        return $q
            ->when($request->district_id, fn ($w, $v) => $w->where('inspections.district_id', $v))
            ->when($request->block_id, fn ($w, $v) => $w->where('inspections.block_id', $v))
            ->when($request->cluster_id, fn ($w, $v) => $w->where('inspections.cluster_id', $v))
            ->when($request->officer_id, fn ($w, $v) => $w->where('officer_id', $v))
            ->when($request->status, fn ($w, $v) => $v === 'draft' ? $w->whereIn('status', ['draft', 'unlocked']) : $w->where('status', $v))
            ->when($request->school, fn ($w, $v) => $w->whereHas('school', fn ($s) => $s->where('udise_code', 'like', "$v%")->orWhere('name', 'like', "%$v%")));
    }

    private function filterOptions(Request $request): array
    {
        $user = $request->user();
        $jur = $this->j->current($user);
        $districtId = $request->district_id ?: $jur?->district_id;
        $blockId = $request->block_id ?: $jur?->block_id;

        return [
            'districts' => $user->level->isStateWide() ? District::orderBy('name')->get() : collect(),
            'blocks' => $districtId && in_array($user->level->value, ['state_admin', 'state', 'district'], true) ? Block::where('district_id', $districtId)->orderBy('name')->get() : collect(),
            'clusters' => $blockId && $user->level->value !== 'cluster' ? Cluster::where('block_id', $blockId)->orderBy('name')->get() : collect(),
            'officers' => $user->level->value !== 'cluster'
                ? $this->j->users($user)->whereIn('id', $this->j->inspections($user)->select('officer_id'))->orderBy('name')->get(['id', 'name'])
                : collect(),
        ];
    }
}
