<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\School;
use App\Services\JurisdictionService;
use Illuminate\Http\Request;

/** School search within the officer's jurisdiction (PRD 7.4). */
class SchoolController extends Controller
{
    public function __construct(private JurisdictionService $j) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $q = trim((string) $request->q);
        $searched = $q !== '' || $request->filled('district_id') || $request->filled('block_id') || $request->filled('cluster_id');

        $schools = $searched
            ? $this->j->schools($user)
                ->with(['block', 'cluster', 'category'])
                ->when($q !== '', fn ($w) => ctype_digit($q)
                    ? $w->where('udise_code', 'like', $q.'%')
                    : $w->where('name', 'like', '%'.$q.'%'))
                ->when($request->district_id, fn ($w, $v) => $w->where('district_id', $v))
                ->when($request->block_id, fn ($w, $v) => $w->where('block_id', $v))
                ->when($request->cluster_id, fn ($w, $v) => $w->where('cluster_id', $v))
                ->orderByRaw("status = 'active' desc")->orderBy('name')
                ->paginate(config('inspection.per_page'))->withQueryString()
            : null;

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
        $school->load(['state', 'district', 'block', 'cluster', 'management', 'category']);

        $today = $school->inspections()->where('officer_id', $user->id)->whereDate('inspection_date', today())->first();
        $history = $school->inspections()->with('officer')
            ->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->latest('inspection_date')->take(10)->get();

        return view('schools.show', compact('school', 'today', 'history'));
    }
}
