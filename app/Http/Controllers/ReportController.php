<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
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

/** Inspection lists, single report view and PDF downloads (PRD 11; the Excel export was removed on 25-11-2026). */
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

        // Embed photographs as data URIs so DomPDF needs no remote access; since 25-11-2026 each is fitted
        // into the same 4:3 box (PdfPhoto), so every photo prints at the same size
        $data['images'] = $data['inspection']->evidence
            ->filter(fn ($e) => $e->kind === 'photo' && $e->status === \App\Enums\EvidenceStatus::Uploaded)
            ->mapWithKeys(function ($p) {
            $disk = Storage::disk(EvidenceStorage::DISK);

            return [$p->id => $disk->exists($p->storage_path) ? \App\Support\PdfPhoto::dataUri($disk->get($p->storage_path), $p->mime ?: 'image/jpeg') : null];
        });

        // Official header logos (Modification V4), embedded so DomPDF needs no file or remote access
        $data['logos'] = \App\Support\PdfLogos::all();
        $data['generatedAt'] = now(); // shown as DD-MM-YYYY HH:MM in the footer
        $data += self::qrData($inspection); // 28-11-2026: QR to the public, view-only page

        AuditLogger::log('inspection_pdf', $inspection);
        $name = str_replace('/', '-', $inspection->inspection_no ?: 'draft-'.$inspection->id).'.pdf';

        // Render first, then stamp "Page X of Y" on every page (the total is only known after rendering)
        $dompdf = Pdf::loadView('inspections.pdf', $data)->setPaper('a4')->getDomPDF();
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 40 - 62, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.28, 0.33, 0.41]);

        // 26-11-2026: sent as a download (attachment), never opened in the browser
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * 28-11-2026: the no-internet declaration form (NET_01 = Yes / NET_02), pre-filled with the school, the inspection and
     * the officer. The officer prints it, gets it signed by the Block / District officer and attaches the scanned PDF.
     */
    public function networkDeclaration(Inspection $inspection)
    {
        $this->authorize('view', $inspection);
        // 28-11-2026: the inspection report itself (every detail entered, no photos) ending with the Declaration section
        $data = $this->reportData($inspection) + ['logos' => \App\Support\PdfLogos::all(), 'generatedAt' => now(), 'declaration' => true]
            + self::qrData($inspection);

        AuditLogger::log('network_declaration_downloaded', $inspection);
        $name = 'No-internet-declaration-'.($inspection->school?->udise_code ?? $inspection->id).'-'.substr((string) $inspection->inspection_date, 0, 10).'.pdf';

        $dompdf = Pdf::loadView('inspections.pdf', $data)->setPaper('a4')->getDomPDF();
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 40 - 62, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.28, 0.33, 0.41]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * 23-11-2026: Inspections / My Inspections as a PDF list, one row per inspection (the full answers are in each report).
     * Same scope and filters as the list, at most config('inspection.master_pdf_max_rows') rows.
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

    /**
     * 28-11-2026: the QR printed on the inspection PDF and the declaration form: it opens the public, view-only page of
     * this inspection (InspectionVerifyController). A cancelled inspection gets none.
     *
     * @return array{verifyUrl: ?string, qr: ?string}
     */
    public static function qrData(Inspection $inspection): array
    {
        if ($inspection->status === \App\Enums\InspectionStatus::Cancelled) {
            return ['verifyUrl' => null, 'qr' => null];
        }
        $url = $inspection->verifyUrl();

        return ['verifyUrl' => $url, 'qr' => \App\Support\QrCode::dataUri($url)];
    }

    /** The report data (web report, PDF, declaration form). */
    public function reportData(Inspection $inspection): array
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
            ->when($request->school, fn ($w, $v) => $w->whereHas('school', fn ($s) => \App\Support\SchoolSearch::apply($s, (string) $v)));
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
