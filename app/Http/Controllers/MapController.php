<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MapDataService;
use Illuminate\Http\Request;

/** Interactive Map View (New Module 1): drill-down from the State to the schools of a cluster. */
class MapController extends Controller
{
    public function __construct(private MapDataService $map) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $geo = fn (string $k) => ($p = config("inspection.map.geojson.$k")) && is_file(public_path($p)) ? asset($p) : null;

        return view('map.index', [
            'start' => $this->map->startView($user),
            'officers' => $this->map->officers($user, 'district', null),
            'config' => [
                'tiles' => config('inspection.map.tiles'),
                'attribution' => config('inspection.map.attribution'),
                'center' => config('inspection.map.center'),
                'zoom' => config('inspection.map.zoom'),
                'geojson' => ['state' => $geo('state'), 'districts' => $geo('districts')],
                'urls' => [
                    'areas' => route('map.areas', ['level' => '__L__']),
                    'schools' => route('map.schools'),
                    'school' => route('schools.show', ['school' => '__ID__']),
                    'report' => route('inspections.show', ['inspection' => '__ID__']),
                ],
                'canOpenSchool' => $user->can('conduct_inspections'),
                'routing' => config('inspection.map.routing'), // V6 Module 8
            ],
        ]);
    }

    /** Districts, blocks or clusters under a parent, with counts. */
    public function areas(Request $request, string $level)
    {
        abort_unless(in_array($level, MapDataService::LEVELS, true), 404);
        $user = $request->user();
        $parent = $request->filled('parent') ? (int) $request->input('parent') : null;
        $parentType = $this->map->parentType($level);

        if ($parentType && $parent === null && ! $user->level->isStateWide()) {
            abort(403, 'Choose an area inside your jurisdiction.');
        }
        if ($parentType && $parent !== null) {
            abort_unless($this->map->canSeeArea($user, $parentType, $parent), 403, 'This area is outside your jurisdiction.');
        }

        $filters = $this->filters($request);
        $rows = $this->map->areas($user, $level, $parent, $filters);

        return response()->json([
            'level' => $level,
            'parent' => $parent,
            'breadcrumb' => $this->map->breadcrumb($level, $parent),
            'outline' => $this->map->outlineUrl($level, $parent),
            'rows' => $rows,
            'totals' => $this->map->totals($rows, count($this->map->officers($user, $level, $parent))),
        ]);
    }

    /** Schools of a cluster (or a search across the user's jurisdiction). */
    public function schools(Request $request)
    {
        $user = $request->user();
        $search = trim((string) $request->input('q')) ?: null;
        $cluster = $request->filled('cluster') ? (int) $request->input('cluster') : null;

        if ($cluster === null && $search === null) {
            abort(422, 'Choose a cluster or type a school name / UDISE code.');
        }
        if ($cluster !== null) {
            abort_unless($this->map->canSeeArea($user, 'cluster', $cluster), 403, 'This cluster is outside your jurisdiction.');
        }
        if ($search !== null && mb_strlen($search) < 3) {
            abort(422, 'Type at least 3 characters.');
        }

        $rows = $this->map->schools($user, $cluster, $this->filters($request), $search);

        return response()->json([
            'level' => 'school',
            'parent' => $cluster,
            'breadcrumb' => $cluster !== null ? $this->map->breadcrumb('school', $cluster) : [],
            'outline' => $cluster !== null ? $this->map->outlineUrl('school', $cluster) : null,
            'rows' => $rows,
            'totals' => $this->map->schoolTotals($rows, $cluster !== null ? count($this->map->officers($user, 'school', $cluster)) : 0),
        ]);
    }

    /** Period and officer filters, validated. The officer must be inside the user's jurisdiction. */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'officer' => ['nullable', 'integer'],
        ]);
        $officer = isset($data['officer']) ? (int) $data['officer'] : null;
        if ($officer !== null) {
            abort_unless(app(\App\Services\JurisdictionService::class)->users($request->user())->whereKey($officer)->exists()
                || $officer === $request->user()->id, 403, 'This officer is outside your jurisdiction.');
        }

        return [
            'from' => $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'officer_id' => $officer,
        ];
    }
}
