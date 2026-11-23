<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Exports\MySchoolsExport;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\School;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InspectionStartGuard;
use App\Services\DistrictBoundaryService;
use App\Services\JurisdictionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/** School search within the officer's jurisdiction (PRD 7.4). */
class SchoolController extends Controller
{
    public function __construct(private JurisdictionService $j, private InspectionStartGuard $startGuard) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $q = trim((string) $request->q);
        $searched = $q !== '' || $request->filled('district_id') || $request->filled('block_id') || $request->filled('cluster_id');

        $schools = $searched
            ? $this->listQuery($user, $request)
                ->with(['block', 'cluster', 'category', 'latestRatedInspection.rating']) // V6 Module 9: current rating
                // badges in the result list: my inspection today, last submitted inspection
                ->withExists(['inspections as mine_today' => fn ($w) => $w->where('officer_id', $user->id)
                    ->where('inspection_date', today()->toDateString())->where('status', '!=', InspectionStatus::Cancelled)])
                // Modification V3: another officer's open draft / completed by another officer this month
                ->withExists(['inspections as other_draft' => InspectionStartGuard::otherDraft($user)])
                ->withExists(['inspections as other_this_month' => InspectionStartGuard::otherThisMonth($user)])
                ->paginate(config('inspection.per_page'))->withQueryString()
            : null;

        // Live search (typing): return only the result list
        if ($request->ajax()) {
            return response()->json([
                'html' => view('schools._results', ['schools' => $schools, 'q' => $q])->render(),
                'total' => $schools?->total() ?? 0,
            ]);
        }

        // Exact UDISE match -> go straight to the school
        if ($schools && ctype_digit($q) && strlen($q) === 11 && $schools->total() === 1) {
            return redirect()->route('schools.show', $schools->first());
        }

        return view('schools.index', ['schools' => $schools, 'q' => $q] + $this->filterOptions($user, $request));
    }

    /**
     * 23-11-2026 (V14): auto-suggestions for the school search boxes (resources/js/school-suggest.js), by name
     * or UDISE prefix, always inside the user's jurisdiction. Active schools only, except `all=1` for master managers.
     */
    public function suggest(Request $request)
    {
        $user = $request->user();
        $q = trim(mb_substr((string) $request->q, 0, 100));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }
        $all = $request->boolean('all') && $user->can('manage_masters');

        $items = $this->j->schools($user)
            ->when(! $all, fn ($w) => $w->where('status', 'active'))
            ->when(ctype_digit($q), fn ($w) => $w->where('udise_code', 'like', $q.'%'), fn ($w) => $w->where('name', 'like', '%'.$q.'%'))
            ->with(['cluster:id,name,code', 'block:id,name,code'])
            ->orderBy('name')->limit(10)
            ->get(['id', 'udise_code', 'name', 'status', 'cluster_id', 'block_id'])
            ->map(fn (School $s) => [
                'udise' => $s->udise_code,
                'name' => $s->name,
                'area' => trim(($s->cluster?->label ?? '').' · '.($s->block?->label ?? ''), ' ·'),
                'status' => $s->status,
            ]);

        return response()->json(['items' => $items]);
    }

    /**
     * 23-11-2026: My Schools, every school in the officer's own jurisdiction (cluster, block, district or the whole
     * state, through JurisdictionService), listed without a search, with inspection progress and an Excel download.
     */
    public function mine(Request $request)
    {
        $user = $request->user();
        $done = [InspectionStatus::Submitted, InspectionStatus::Unlocked];

        $schools = $this->inspectedFilter($this->listQuery($user, $request), $request)
            ->with(['block:id,name,code', 'cluster:id,name,code', 'category:id,label', 'management:id,label'])
            ->paginate(config('inspection.per_page'))->withQueryString();

        // the tiles count the whole jurisdiction (the filters only narrow the list)
        $all = $this->j->schools($user);
        $total = (clone $all)->count();
        $inspected = (clone $all)->whereHas('inspections', fn ($w) => $w->whereIn('status', $done))->count();
        $stats = [
            'total' => $total,
            'inspected' => $inspected,
            'not_inspected' => $total - $inspected,
            'this_month' => (clone $all)->whereHas('inspections', fn ($w) => $w->whereIn('status', $done)
                ->where('inspection_date', '>=', now()->startOfMonth()->toDateString()))->count(),
        ];

        return view('schools.mine', ['schools' => $schools, 'q' => trim((string) $request->q), 'stats' => $stats,
            'scope' => $this->scopeLabel($user)] + $this->filterOptions($user, $request));
    }

    /** My Schools as Excel: the same scope, filters and order as the page; official header, protected sheet. */
    public function mineExport(Request $request)
    {
        $query = $this->inspectedFilter($this->listQuery($request->user(), $request), $request);
        $meta = $this->exportMeta($request, (clone $query)->count());
        $this->logMineExport($request, $meta, 'excel');

        return Excel::download(new MySchoolsExport($query, $meta), 'my-schools-'.now()->format('Y-m-d').'.xlsx');
    }

    /** My Schools as PDF (official header, background, page numbers); capped like the master PDFs. */
    public function minePdf(Request $request)
    {
        $query = $this->inspectedFilter($this->listQuery($request->user(), $request), $request);
        $count = (clone $query)->count();
        $max = \App\Support\MasterPdf::maxRows();
        if ($count > $max) {
            return back()->with('warning', number_format($count).' schools match. A PDF holds at most '.number_format($max)
                .': filter by district, block or cluster, or use Download Excel for the full list.');
        }
        \App\Support\MasterPdf::prepare();
        $meta = $this->exportMeta($request, $count);
        $this->logMineExport($request, $meta, 'pdf');

        $rows = [];
        $query->with(MySchoolsExport::RELATIONS)->chunk(500, function ($schools) use (&$rows) {
            foreach ($schools as $s) {
                $rows[] = array_merge([count($rows) + 1], MySchoolsExport::row($s));
            }
        });

        return \App\Support\MasterPdf::download('My Schools', array_merge(['Sl'], MySchoolsExport::HEADINGS), $rows,
            array_merge(['Area: '.$meta['scope'], 'Officer: '.$meta['officer']], $meta['filters']), 'my-schools-'.now()->format('Y-m-d').'.pdf');
    }

    /** Area, officer and readable filter labels for the downloads. */
    private function exportMeta(Request $request, int $rows): array
    {
        $user = $request->user();
        $q = trim((string) $request->q);
        $filters = array_values(array_filter([
            $q !== '' ? 'Search: '.$q : null,
            $request->district_id ? 'District: '.(District::find((int) $request->district_id)?->label ?? '—') : null,
            $request->block_id ? 'Block: '.(Block::find((int) $request->block_id)?->label ?? '—') : null,
            $request->cluster_id ? 'Cluster: '.(Cluster::find((int) $request->cluster_id)?->label ?? '—') : null,
            match ($request->inspected) { 'yes' => 'Inspected: Yes', 'no' => 'Inspected: No (not inspected yet)', default => null },
        ]));

        return ['scope' => $this->scopeLabel($user), 'officer' => $user->name, 'filters' => $filters, 'rows' => $rows];
    }

    private function logMineExport(Request $request, array $meta, string $format): void
    {
        AuditLogger::log('my_schools_exported', null, [
            'format' => $format,
            'rows' => $meta['rows'],
            'filters' => array_filter($request->only(['q', 'district_id', 'block_id', 'cluster_id', 'inspected'])),
        ]);
    }

    /**
     * The officer's schools with visit counts, the search / location filters and the visit order
     * (23-11-2026: never inspected first, then the least visited, then the one visited longest ago; closed schools last).
     * Location filters only narrow the jurisdiction scope, so an ID outside it returns nothing (domain rule 1).
     */
    private function listQuery(User $user, Request $request): Builder
    {
        $q = trim((string) $request->q);
        $done = [InspectionStatus::Submitted, InspectionStatus::Unlocked];

        return $this->j->schools($user)
            ->withMax(['inspections as last_inspected_on' => fn ($w) => $w->whereIn('status', $done)], 'inspection_date')
            ->withCount(['inspections as visits' => fn ($w) => $w->whereIn('status', $done)])
            ->when($q !== '', fn ($w) => ctype_digit($q)
                ? $w->where('udise_code', 'like', $q.'%')
                : $w->where('name', 'like', '%'.$q.'%'))
            ->when($request->district_id, fn ($w, $v) => $w->where('district_id', $v))
            ->when($request->block_id, fn ($w, $v) => $w->where('block_id', $v))
            ->when($request->cluster_id, fn ($w, $v) => $w->where('cluster_id', $v))
            ->orderByRaw("status = 'active' desc")->orderBy('visits')->orderBy('last_inspected_on')->orderBy('name');
    }

    /** My Schools filter: inspected = yes (at least one submitted / unlocked inspection) or no (none yet). */
    private function inspectedFilter(Builder $query, Request $request): Builder
    {
        $done = fn ($w) => $w->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked]);

        return match ($request->inspected) {
            'yes' => $query->whereHas('inspections', $done),
            'no' => $query->whereDoesntHave('inspections', $done),
            default => $query,
        };
    }

    /** District / block / cluster select options for the officer's level (Find School and My Schools). */
    private function filterOptions(User $user, Request $request): array
    {
        $jur = $this->j->current($user);

        return [
            'districts' => $user->level->isStateWide() ? District::where('is_active', true)->orderBy('name')->get() : collect(),
            'blocks' => match (true) {
                $user->level->isStateWide() && $request->district_id => Block::where('district_id', $request->district_id)->orderBy('name')->get(),
                $user->level->value === 'district' => Block::where('district_id', $jur?->district_id)->orderBy('name')->get(),
                default => collect(),
            },
            'clusters' => match (true) {
                (bool) $request->block_id => Cluster::where('block_id', $request->block_id)->orderBy('name')->get(),
                $user->level->value === 'block' => Cluster::where('block_id', $jur?->block_id)->orderBy('name')->get(),
                default => collect(),
            },
        ];
    }

    /** "Cluster · NAME (code)", "Block · …", "District · …" or "Whole state". */
    private function scopeLabel(User $user): string
    {
        if ($user->level->isStateWide()) {
            return 'Whole state';
        }
        $j = $this->j->current($user);

        return match ($user->level->value) {
            'district' => 'District · '.($j?->district?->label ?? '—'),
            'block' => 'Block · '.($j?->block?->label ?? '—'),
            'cluster' => 'Cluster · '.($j?->cluster?->label ?? '—'),
            default => $user->level->label(),
        };
    }

    public function show(Request $request, School $school)
    {
        $user = $request->user();
        abort_unless($this->j->coversSchool($user, $school), 404);
        $school->load(['state', 'district', 'block', 'cluster', 'management', 'category', 'ac', 'pc', 'latestRatedInspection.rating']);

        $today = $school->inspections()->where('officer_id', $user->id)->where('inspection_date', today()->toDateString())->first();
        // own draft (any date), own submission today, or blocked by another officer (Modification V3)
        $start = $this->startGuard->check($user, $school);
        $history = $school->inspections()->with(['officer', 'rating'])
            ->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->latest('inspection_date')->take(10)->get();

        // V6 Module 8: small map with the school, my location, distance and road route
        $mapConfig = $school->hasCoordinates() ? [
            'tiles' => config('inspection.map.tiles'), 'attribution' => config('inspection.map.attribution'),
            'center' => [$school->latitude, $school->longitude], 'zoom' => 14,
            'routing' => config('inspection.map.routing'),
            'outline' => app(DistrictBoundaryService::class)->urlForId($school->district_id),
            'school' => ['lat' => $school->latitude, 'lng' => $school->longitude, 'name' => $school->name],
        ] : null;

        return view('schools.show', compact('school', 'today', 'history', 'start', 'mapConfig'));
    }

    /**
     * Modification V11 item 2: printable blank inspection form, for a school without internet.
     * Same jurisdiction check as the school page; nothing about the school is changed.
     */
    public function blankForm(Request $request, School $school)
    {
        abort_unless($this->j->coversSchool($request->user(), $school), 404);
        $school->load(['district', 'block', 'cluster']);

        $questions = array_map(fn ($qs) => array_values(array_filter($qs, fn ($q) => empty($q['hidden']))), \App\Questionnaire\QuestionnaireDefinition::questions());
        $labels = \App\Models\MasterValue::where('type', 'photo_category')->pluck('label', 'code');
        $photoLabels = collect(array_keys(config('inspection.mandatory_photos')))->map(fn ($c) => $labels[$c] ?? $c)->all();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('inspections.blank-form', [
            'school' => $school,
            'steps' => \App\Questionnaire\QuestionnaireDefinition::steps(),
            'questions' => $questions,
            'logos' => \App\Support\PdfLogos::all(),
            'photoLabels' => $photoLabels,
        ])->setPaper('a4');

        \App\Services\AuditLogger::log('blank_form_downloaded', $school);

        return $pdf->download('Inspection-form-'.$school->udise_code.'.pdf');
    }
}
