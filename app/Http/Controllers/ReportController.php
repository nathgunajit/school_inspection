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
            'inspections' => $query->clone()->with(['school'])->latest('inspection_date')->latest('id')->paginate(config('inspection.per_page'))->withQueryString(),
            'counts' => [
                'draft' => Inspection::where('officer_id', $user->id)->whereIn('status', ['draft', 'unlocked'])->count(),
                'submitted' => Inspection::where('officer_id', $user->id)->where('status', 'submitted')->count(),
                'month' => Inspection::where('officer_id', $user->id)->where('status', 'submitted')->whereDate('inspection_date', '>=', now()->startOfMonth()->toDateString())->count(),
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
            'inspections' => $query->clone()->with(['school', 'officer'])->latest('submitted_at')->paginate(config('inspection.per_page'))->withQueryString(),
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

        AuditLogger::log('inspection_pdf', $inspection);
        $name = str_replace('/', '-', $inspection->inspection_no ?: 'draft-'.$inspection->id).'.pdf';

        return Pdf::loadView('inspections.pdf', $data)->setPaper('a4')->stream($name);
    }

    public function export(Request $request, string $mode)
    {
        $user = $request->user();
        abort_unless($user->can('export_excel'), 403);
        abort_unless(in_array($mode, ['mine', 'area'], true), 404);

        // Cluster users export only their own (PRD 3.2: "Own only")
        $base = $mode === 'mine' || $user->level->value === 'cluster'
            ? Inspection::where('officer_id', $user->id)
            : $this->j->inspections($user)->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked]);

        $rows = $this->filtered($base, $request, own: $mode === 'mine')
            ->with(['school.category', 'officer.designation', 'district', 'block', 'cluster', 'answers', 'absentTeachers', 'photos'])
            ->orderBy('inspection_date')->limit(20000)->get();

        $keyCodes = ['GOV_02', 'GOV_05', 'GOV_06', 'GOV_08', 'GOV_10', 'GOV_12', 'GOV_14', 'GOV_18', 'CON_01', 'CON_02', 'CON_04', 'CON_05', 'CON_06', 'CON_07'];
        $headings = ['Inspection ID', 'Status', 'Date', 'Submitted at', 'District', 'Block', 'Cluster', 'UDISE Code', 'School', 'Category', 'Officer', 'Designation', 'Person met',
            'Teachers sanctioned', 'Teachers working', 'Teachers present', 'Teachers absent', 'Absent w/o info', 'Students enrolled', 'Students present', 'Photos'];
        foreach ($keyCodes as $c) {
            $headings[] = Q::find($c)['label'];
        }

        $data = $rows->map(function (Inspection $i) use ($keyCodes) {
            $a = $i->answerMap();
            $row = [
                $i->inspection_no ?? '—', $i->status->label(), $i->inspection_date->format('d-m-Y'), $i->submitted_at?->format('d-m-Y H:i'),
                $i->district?->name, $i->block?->name, $i->cluster?->name, $i->school->udise_code, $i->school->name, $i->school->category?->label,
                $i->officer->name, $i->officer->designation?->label, trim($i->person_met_name.' ('.$i->person_met_designation.')', ' ()'),
                $a['ATT_SANCTIONED'] ?? null, $a['ATT_WORKING'] ?? null, $a['ATT_PRESENT'] ?? null, $a['ATT_ABSENT'] ?? null,
                $i->absentTeachers->where('status', 'absent_without_info')->count(), $a['ATT_ENROLLED'] ?? null, $a['ATT_STUDENTS_PRESENT'] ?? null,
                $i->photos->count(),
            ];
            foreach ($keyCodes as $c) {
                $row[] = Q::optionLabel($c, $a[$c] ?? null);
            }

            return $row;
        })->all();

        AuditLogger::log('inspections_exported', null, ['mode' => $mode, 'rows' => count($data), 'filters' => $request->query()]);

        return Excel::download(new ArrayExport($headings, $data), 'inspections-'.$mode.'-'.now()->format('Ymd-His').'.xlsx');
    }

    /* ---------------- helpers ---------------- */

    private function reportData(Inspection $inspection): array
    {
        $inspection->load(['school.district', 'school.block', 'school.cluster', 'school.management', 'school.category', 'officer.designation',
            'answers', 'absentTeachers', 'fundPositions.category', 'evidence.category', 'remarks', 'unlocker']);

        return [
            'inspection' => $inspection,
            'steps' => Q::steps(),
            'questions' => Q::questions(),
            'answers' => $inspection->answerMap(),
            'remarks' => $inspection->remarksMap(),
            'startDistance' => $inspection->distanceFromSchool($inspection->start_lat, $inspection->start_lng),
            'submitDistance' => $inspection->distanceFromSchool($inspection->submit_lat, $inspection->submit_lng),
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
            $q->whereDate('inspection_date', '>=', $from)->whereDate('inspection_date', '<=', $to);
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
