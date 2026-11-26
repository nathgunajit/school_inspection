<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Inspection;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 26-11-2026 performance: the main pages run a fixed number of queries, however many rows they show.
 *
 * Each budget is the count measured when the test was written plus a small margin. A page that starts loading a
 * relation per row (N+1) goes over it; LAZY_LOADING_CHECK=throw (phpunit.xml) catches the same thing per relation.
 * If a change really needs more queries, raise the number here and say why in ACTION.md.
 */
class QueryBudgetTest extends TestCase
{
    private const BUDGETS = [
        // measured 26-11-2026 (after the map chain and Today's Schools setting memos) + 2
        'dashboard cluster' => 21,
        'dashboard district' => 17,
        'dashboard admin' => 17,
        'find school' => 9,
        'my schools' => 12,
        'my inspections' => 10,
        'area inspections' => 13,
        'inspection report' => 19,
        'map schools json' => 23,
        'coverage report' => 12, // V15 (26-11-2026): +1, the alerts bell unread count (cached 60 s; the test cache is per request)
        'alerts' => 6,           // V15: the Alerts page (measured 4 + 2)
    ];

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cluster = Cluster::orderBy('id')->first();
    }

    /** A few submitted inspections in the cluster, so lists have several rows (an N+1 would show). */
    private function submitted(User $officer, int $n = 3): Inspection
    {
        $last = null;
        foreach (School::where('cluster_id', $this->cluster->id)->orderBy('id')->take($n)->get() as $k => $school) {
            $last = $this->startInspection($officer, $school);
            $last->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'QB/'.$last->id,
                'inspection_date' => today()->subDays($k)->toDateString()])->save();
        }

        return $last;
    }

    private function queries(User $user, string $url, bool $json = false): int
    {
        Cache::flush(); // a cold cache: the first visit of the minute
        $this->actingAs($user)->get($url); // warm the session / encrypted keys outside the count
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $json ? $this->actingAs($user)->getJson($url) : $this->actingAs($user)->get($url);
        DB::disableQueryLog();
        $res->assertOk();

        if (getenv('QUERY_BUDGET_DUMP') && str_contains($url, getenv('QUERY_BUDGET_DUMP'))) { // list the queries of one page
            foreach (DB::getQueryLog() as $q) {
                fwrite(STDERR, "\n    ".substr($q['query'], 0, 150));
            }
        }

        return count(DB::getQueryLog());
    }

    private function assertBudget(string $page, int $count): void
    {
        $budget = self::BUDGETS[$page];
        if ($budget === 0 || getenv('QUERY_BUDGET_REPORT')) {
            fwrite(STDERR, sprintf("\n  query budget  %-20s %3d", $page, $count));
        }
        if ($budget > 0) {
            $this->assertLessThanOrEqual($budget, $count, "{$page}: {$count} queries, budget {$budget}");
        }
    }

    public function test_pages_stay_within_their_query_budget(): void
    {
        $crcc = $this->officer(UserLevel::Cluster, $this->cluster);
        $district = $this->officer(UserLevel::District, $this->cluster);
        $admin = $this->admin();
        $last = $this->submitted($crcc);

        $this->assertBudget('dashboard cluster', $this->queries($crcc, route('dashboard')));
        $this->assertBudget('dashboard district', $this->queries($district, route('dashboard')));
        $this->assertBudget('dashboard admin', $this->queries($admin, route('dashboard')));
        $this->assertBudget('find school', $this->queries($crcc, route('schools.index', ['q' => 'a'])));
        $this->assertBudget('my schools', $this->queries($crcc, route('schools.mine')));
        $this->assertBudget('my inspections', $this->queries($crcc, route('inspections.mine')));
        $this->assertBudget('area inspections', $this->queries($district, route('inspections.area')));
        $this->assertBudget('inspection report', $this->queries($crcc, route('inspections.show', $last)));
        $this->assertBudget('map schools json', $this->queries($district, route('map.schools', ['cluster' => $this->cluster->id]), true));
        $this->assertBudget('coverage report', $this->queries($district, route('reports.coverage')));
        $this->assertBudget('alerts', $this->queries($crcc, route('alerts.index')));
    }
}
