<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\School;
use App\Models\User;
use App\Services\DistrictRankingService;
use App\Services\RatingService;
use Tests\TestCase;

/** 27-11-2026: District Ranking report, Coverage % and Rating % ranked separately, for State-wide viewers. */
class DistrictRankingTest extends TestCase
{
    private Cluster $north;

    private Cluster $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->north = Cluster::orderBy('id')->first();
        $this->south = Cluster::whereHas('block', fn ($q) => $q->where('district_id', '!=', $this->north->block->district_id))->orderBy('id')->first();
    }

    private function rated(User $officer, School $school, array $answers): Inspection
    {
        $i = $this->startInspection($officer, $school);
        $i->answers()->delete();
        $i->fundPositions()->delete();
        $i->update(['inspection_date' => today()->toDateString(), 'status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/DR/'.$i->id]);
        foreach ($answers as $code => $v) {
            InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => $code, 'value' => $v]);
        }
        app(RatingService::class)->rate($i->fresh());

        return $i->fresh('rating');
    }

    /** North: two schools inspected, low ratings. South: one school, a high rating. */
    private function seedRankings(): void
    {
        $low = ['CND_02' => 'yes', 'CND_03' => 'no', 'GOVR_05' => 'no'];
        $northSchools = School::where('cluster_id', $this->north->id)->orderBy('id')->take(2)->get();
        $this->rated($this->officer(UserLevel::Cluster, $this->north), $northSchools[0], $low);
        $this->rated($this->officer(UserLevel::Block, $this->north), $northSchools[1] ?? $northSchools[0], $low);
        $this->rated($this->officer(UserLevel::Cluster, $this->south), $this->schoolIn($this->south), ['CND_02' => 'yes', 'CND_03' => 'yes', 'GOVR_05' => 'yes']);
    }

    public function test_state_admin_sees_both_rankings_ranked_separately(): void
    {
        $this->seedRankings();
        $res = $this->actingAs($this->admin())->get(route('reports.ranking'))->assertOk()
            ->assertSee('Coverage ranking')->assertSee('Rating ranking')->assertSee('District Ranking');

        $coverage = $res->viewData('coverage');
        $scored = $coverage->whereNotNull('pct')->values();
        $this->assertNotEmpty($scored);
        $this->assertSame($scored->sortByDesc('pct')->pluck('pct')->values()->all(), $scored->pluck('pct')->all(), 'highest Inspection coverage % first');
        $this->assertSame(1, $scored->first()['rank']);

        $rating = $res->viewData('rating');
        $southId = $this->south->block->district_id;
        $northId = $this->north->block->district_id;
        $this->assertSame($southId, $rating->first()['id'], 'the best average rating leads the Rating ranking');
        $this->assertSame(1, $rating->first()['rank']);
        $this->assertSame(2, $rating->firstWhere('id', $northId)['rank']);
        $this->assertGreaterThan($rating->firstWhere('id', $northId)['pct'], $rating->first()['pct']);
        $this->assertSame(2, $rating->firstWhere('id', $northId)['rated']);
        // every active district is listed; the unrated ones last, without a rank
        $this->assertSame(\App\Models\District::where('is_active', true)->count(), $rating->count());
        $this->assertTrue($rating->whereNull('pct')->every(fn ($r) => $r['rank'] === null));
    }

    public function test_ties_share_a_rank_and_empty_rows_come_last(): void
    {
        $rows = collect([
            ['name' => 'B', 'pct' => 50.0, 'n' => 2],
            ['name' => 'A', 'pct' => 80.0, 'n' => 1],
            ['name' => 'C', 'pct' => 50.0, 'n' => 3],
            ['name' => 'D', 'pct' => null, 'n' => 0],
            ['name' => 'E', 'pct' => 10.0, 'n' => 1],
        ]);
        $ranked = DistrictRankingService::rank($rows, 'pct', 'n');
        $this->assertSame(['A', 'C', 'B', 'E', 'D'], $ranked->pluck('name')->all(), 'ties listed by the second figure');
        $this->assertSame([1, 2, 2, 4, null], $ranked->pluck('rank')->all());
    }

    public function test_state_officers_see_coverage_only_and_others_are_refused(): void
    {
        $state = $this->officer(UserLevel::State);
        $this->actingAs($state)->get(route('reports.ranking'))->assertOk()->assertSee('Coverage ranking')
            ->assertDontSee('Rating ranking (')->assertSee('shown to the State Administrator only');
        $this->get(route('reports.coverage'))->assertOk()->assertSee(route('reports.ranking'), false); // the Reports tab

        foreach ([UserLevel::District, UserLevel::Block, UserLevel::Cluster] as $level) {
            $u = $this->officer($level, $this->north);
            $this->actingAs($u)->get(route('reports.ranking'))->assertForbidden();
            $this->get(route('reports.ranking.export'))->assertForbidden();
        }
        $this->actingAs($this->officer(UserLevel::District, $this->north))->get(route('reports.coverage'))->assertOk()->assertDontSee(route('reports.ranking'), false);
    }

    public function test_excel_and_pdf_downloads(): void
    {
        $this->seedRankings();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('reports.ranking.export', ['type' => 'monthly']))->assertOk()
            ->assertHeader('content-disposition');
        $this->get(route('reports.ranking.export-pdf', ['type' => 'monthly']))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(2, AuditLog::where('action', 'district_ranking_exported')->count());
        $this->assertSame(2, count(app(DistrictRankingService::class)->sections($admin, app(DistrictRankingService::class)->filters(request(), $admin))), 'Coverage and Rating');

        $state = $this->officer(UserLevel::State);
        $this->assertCount(1, app(DistrictRankingService::class)->sections($state, app(DistrictRankingService::class)->filters(request(), $state)), 'no Rating sheet for a State officer');
    }
}
