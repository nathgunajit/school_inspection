<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\School;
use App\Services\InspectionStartGuard;
use App\Services\DistrictBoundaryService;
use App\Services\JurisdictionService;
use Illuminate\Http\Request;

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
            ? $this->j->schools($user)
                ->with(['block', 'cluster', 'category', 'latestRatedInspection.rating']) // V6 Module 9: current rating
                // badges in the result list: my inspection today, last submitted inspection
                ->withExists(['inspections as mine_today' => fn ($w) => $w->where('officer_id', $user->id)
                    ->where('inspection_date', today()->toDateString())->where('status', '!=', InspectionStatus::Cancelled)])
                // Modification V3: another officer's open draft / completed by another officer this month
                ->withExists(['inspections as other_draft' => InspectionStartGuard::otherDraft($user)])
                ->withExists(['inspections as other_this_month' => InspectionStartGuard::otherThisMonth($user)])
                ->withMax(['inspections as last_inspected_on' => fn ($w) => $w->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])], 'inspection_date')
                ->when($q !== '', fn ($w) => ctype_digit($q)
                    ? $w->where('udise_code', 'like', $q.'%')
                    : $w->where('name', 'like', '%'.$q.'%'))
                ->when($request->district_id, fn ($w, $v) => $w->where('district_id', $v))
                ->when($request->block_id, fn ($w, $v) => $w->where('block_id', $v))
                ->when($request->cluster_id, fn ($w, $v) => $w->where('cluster_id', $v))
                ->orderByRaw("status = 'active' desc")->orderBy('name')
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

        $jur = $this->j->current($user);

        return view('schools.index', [
            'schools' => $schools,
            'q' => $q,
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
        ]);
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
