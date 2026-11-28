<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ConnectivitySurvey;
use App\Services\MapDataService;
use App\Support\MasterPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/** Interactive Map View (New Module 1): drill-down from the State to the schools of a cluster. */
class MapController extends Controller
{
    public function __construct(private MapDataService $map) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $geo = fn (string $k) => ($p = config("inspection.map.geojson.$k")) && is_file(public_path($p)) ? asset($p) : null;

        $showRating = $this->showRatings($user);

        return view('map.index', [
            'showRating' => $showRating,
            // V13: Rating Report "Map view"; 28-11-2026: ?colour=net = internet connectivity
            'colour' => match (true) {
                $showRating && $request->query('colour') === 'rating' => 'rating',
                $request->query('colour') === 'net' => 'net',
                default => 'status',
            },
            'start' => $this->map->startView($user),
            'officers' => $this->map->officers($user, 'district', null),
            'bands' => app(\App\Services\RatingService::class)->activeScheme()->bands, // V6 Module 9: legend
            'config' => [
                'tiles' => config('inspection.map.tiles'),
                'attribution' => config('inspection.map.attribution'),
                'center' => config('inspection.map.center'),
                'zoom' => config('inspection.map.zoom'),
                'geojson' => ['state' => $geo('state'), 'districts' => $geo('districts')],
                'urls' => [
                    'areas' => route('map.areas', ['level' => '__L__']),
                    'schools' => route('map.schools'),
                    'netSchools' => route('map.net-schools'), // 28-11-2026: Internet colour list
                    'netExport' => route('map.net-schools.export'),
                    'netPdf' => route('map.net-schools.export-pdf'),
                    'school' => route('schools.show', ['school' => '__ID__']),
                    'report' => route('inspections.show', ['inspection' => '__ID__']),
                ],
                'canOpenSchool' => $user->can('conduct_inspections'),
                'canExport' => $user->can('export_excel'), // 28-11-2026: Internet list downloads
                'routing' => config('inspection.map.routing'), // V6 Module 8
                'showRating' => $showRating, // Modification V9; V13: always for the State Admin
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
            'rows' => $this->withoutRatings($rows, $user),
            'totals' => $this->map->totals($rows, count($this->map->officers($user, $level, $parent))),
            'offices' => $this->map->offices($user, $level, $parent), // 23-11-2026: District / Block offices
        ]);
    }

    /**
     * 28-11-2026: with the Internet colour, the list below the map shows the surveyed schools of the area in view
     * (level + parent as for areas(), 'school' = a cluster), No internet first.
     */
    public function netSchools(Request $request)
    {
        $s = $this->netScope($request);

        return response()->json($this->netList($s));
    }

    /** 28-11-2026: the Internet school list as Excel (the list on screen: area, period, officer filter). */
    public function netSchoolsExport(Request $request)
    {
        $s = $this->netScope($request);
        $list = $this->netList($s, PHP_INT_MAX);
        $this->netAudit($s, $list, 'excel');

        return Excel::download(new ArrayExport(self::NET_HEADS, $this->netExportRows($list['rows'])), $this->netFileName($s, 'xlsx'));
    }

    /** 28-11-2026: the Internet school list as PDF, at most MasterPdf::maxRows() schools. */
    public function netSchoolsPdf(Request $request)
    {
        $s = $this->netScope($request);
        $max = MasterPdf::maxRows();
        $list = $this->netList($s, $max);
        if ($list['total'] > $max) {
            return back()->with('warning', number_format($list['total']).' schools match. A PDF holds at most '.number_format($max).': choose a district or a shorter period, or download Excel.');
        }
        MasterPdf::prepare();
        $this->netAudit($s, $list, 'pdf');
        $f = $s['filters'];
        $chips = array_values(array_filter([
            'Area: '.$s['area'],
            'From: '.Carbon::parse($f['from'])->format('d-m-Y'),
            'To: '.Carbon::parse($f['to'])->format('d-m-Y'),
            ($f['officer_id'] ?? null) ? 'Officer: '.User::whereKey($f['officer_id'])->value('name') : null,
            'Schools surveyed: '.number_format($list['total']).' · No internet: '.number_format($list['no']).' · Internet OK: '.number_format($list['ok']),
            'Downloaded by: '.$request->user()->name,
        ]));

        return MasterPdf::download('Internet Connectivity at Schools', self::NET_HEADS, $this->netExportRows($list['rows']), $chips, $this->netFileName($s, 'pdf'));
    }

    private const NET_HEADS = ['Sl', 'UDISE', 'School', 'Cluster · Block', 'Internet', 'Times reported without internet', 'Last reported', 'Remark', 'Declaration', 'Last checked'];

    /** Level / parent / filters of the Internet list, with the same jurisdiction checks as areas() and schools(). */
    private function netScope(Request $request): array
    {
        $level = (string) $request->input('level');
        abort_unless(in_array($level, [...MapDataService::LEVELS, 'school'], true), 404);
        $user = $request->user();
        $parent = $request->filled('parent') ? (int) $request->input('parent') : null;
        $parentType = $this->map->parentType($level);

        if ($parentType && $parent === null && ! $user->level->isStateWide()) {
            abort(403, 'Choose an area inside your jurisdiction.');
        }
        if ($parentType && $parent !== null) {
            abort_unless($this->map->canSeeArea($user, $parentType, $parent), 403, 'This area is outside your jurisdiction.');
        }
        $crumbs = $this->map->breadcrumb($level, $parent);

        return [
            'user' => $user, 'level' => $level, 'parent' => $parent,
            'col' => $parentType && $parent !== null ? $parentType.'_id' : null,
            'filters' => $this->filters($request),
            'area' => (string) (end($crumbs)['name'] ?? 'Assam'),
        ];
    }

    private function netList(array $s, int $limit = 500): array
    {
        $f = $s['filters'];

        return app(ConnectivitySurvey::class)->surveyedSchools($s['user'], $s['col'], $s['parent'], $f['from'], $f['to'], $f['officer_id'] ?? null, $limit);
    }

    private function netExportRows(array $rows): array
    {
        return array_map(fn ($r, $n) => [
            $n + 1, $r['udise'], $r['name'], $r['area'], ConnectivitySurvey::label($r['state']), $r['reports'],
            $r['last_date'] ?? '—', $r['last_remark'] ?: '—',
            // same rule as the list on screen (renderNetTable)
            $r['state'] !== 'no' && ! $r['last_date'] ? '—' : ($r['signed_pdf'] ? 'Signed PDF' : ($r['declared'] ? 'Ticked, no PDF' : 'Missing')),
            $r['checked'],
        ], $rows, array_keys($rows));
    }

    private function netFileName(array $s, string $ext): string
    {
        return 'internet-schools-'.(Str::slug($s['area']) ?: 'area').'-'.$s['filters']['from'].'-to-'.$s['filters']['to'].'.'.$ext;
    }

    private function netAudit(array $s, array $list, string $format): void
    {
        AuditLogger::log('net_schools_exported', null, ['format' => $format, 'level' => $s['level'], 'parent' => $s['parent'],
            'area' => $s['area'], 'filters' => array_filter($s['filters']), 'rows' => count($list['rows'])]);
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
            'rows' => $this->withoutRatings($rows, $user),
            'totals' => $this->map->schoolTotals($rows, $cluster !== null ? count($this->map->officers($user, 'school', $cluster)) : 0),
            'offices' => $this->map->offices($user, 'school', $cluster, $rows),
        ]);
    }

    /**
     * Modification V9: ratings are left out of the map unless config('inspection.rating.display').
     * Modification V13 item 6: the State Admin always sees them (rating map view), as on the Rating Report.
     */
    private function showRatings($user): bool
    {
        return (bool) config('inspection.rating.display') || $user->isStateAdmin();
    }

    private function withoutRatings(array $rows, $user): array
    {
        return $this->showRatings($user) ? $rows : array_map(fn ($r) => array_diff_key($r, ['rating' => true]), $rows);
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
