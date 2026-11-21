<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\School;
use App\Models\User;
use App\Services\RatingService;
use Tests\TestCase;

/** V6 Module 10: rating report, scoped by jurisdiction, with filters, area breakdown, school change and exports. */
class RatingReportTest extends TestCase
{
    private Cluster $north;

    private Cluster $south;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.rating.display' => true]); // Modification V9: ratings are hidden by default
        $this->north = Cluster::orderBy('id')->first();
        $this->south = Cluster::whereHas('block', fn ($q) => $q->where('district_id', '!=', $this->north->block->district_id))->orderBy('id')->first();
    }

    /** A submitted, rated inspection; GOVR_05 / CND_03 answers move the score (2 of 3 'yes' = 66.67 %). */
    private function rated(User $officer, School $school, string $date, array $answers): Inspection
    {
        $i = $this->startInspection($officer, $school);
        $i->answers()->delete(); // V11: a later visit is pre-filled from the last one; these tests rate exactly the given answers
        $i->fundPositions()->delete();
        $i->update(['inspection_date' => $date, 'status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/RR/'.$i->id]);
        foreach ($answers as $code => $v) {
            InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => $code, 'value' => $v]);
        }
        app(RatingService::class)->rate($i->fresh());

        return $i->fresh('rating');
    }

    private function seedRatings(): array
    {
        $n = $this->officer(UserLevel::Cluster, $this->north);
        $s = $this->officer(UserLevel::Cluster, $this->south);
        $school = $this->schoolIn($this->north);
        $older = $this->rated($n, $school, now()->subMonths(6)->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'no', 'GOVR_05' => 'no']);
        $latest = $this->rated($this->officer(UserLevel::Block, $this->north), $school, now()->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'yes', 'GOVR_05' => 'yes']);
        $other = $this->rated($s, $this->schoolIn($this->south), now()->subDays(3)->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'yes', 'GOVR_05' => 'no']);

        return compact('school', 'older', 'latest', 'other');
    }

    public function test_state_view_summarises_bands_sections_districts_and_school_change(): void
    {
        ['school' => $school, 'older' => $older, 'latest' => $latest, 'other' => $other] = $this->seedRatings();
        $res = $this->actingAs($this->admin())->get(route('reports.ratings'))->assertOk();

        $sum = $res->viewData('summary');
        $this->assertSame(2, $sum['rated'], 'the 6-month-old rating is outside the default period');
        $this->assertSame(2, $sum['schools']);
        $this->assertEqualsWithDelta(($latest->rating->score_percent + $other->rating->score_percent) / 2, $sum['avg'], 0.1);
        $this->assertSame(2, array_sum(array_column($sum['bands'], 'count')));
        $this->assertNotEmpty(array_filter($sum['sections'], fn ($x) => $x['avg'] !== null));

        $this->assertSame('district', $res->viewData('areaLevel'));
        $this->assertCount(2, $res->viewData('areas'));

        $row = $res->viewData('rows')->getCollection()->first(fn ($r) => $r['school']->id === $school->id);
        $this->assertSame($latest->id, $row['inspection']->id);
        $this->assertSame((float) $older->rating->score_percent, $row['previous']['score'], 'the previous rating may be older than the period');
        $this->assertEqualsWithDelta($latest->rating->score_percent - $older->rating->score_percent, $row['change'], 0.05);
        $res->assertSee($school->name)->assertSee('Rating bands')->assertSee('District-wise rating');
        // Modification V8 item 5: donut of the bands, and every section average carries its band
        $res->assertSee('report-donut', false)->assertSee('conic-gradient', false)->assertSee('Average by section')->assertSee('sec-score', false);
        foreach (array_filter($sum['sections'], fn ($x) => $x['avg'] !== null) as $sec) {
            $this->assertNotNull($sec['band']);
            $this->assertSame(\App\Rating\RatingCalculator::band($sec['avg'], app(\App\Services\RatingReportService::class)->bands()), ['band' => $sec['band'], 'color' => $sec['color']]);
        }

        // a wider period counts the older rating too
        $wide = $this->actingAs($this->admin())->get(route('reports.ratings', ['from' => now()->subYear()->toDateString()]))->viewData('summary');
        $this->assertSame(3, $wide['rated']);
    }

    public function test_only_the_state_admin_can_open_the_report(): void
    {
        $this->seedRatings();
        foreach ([UserLevel::State, UserLevel::District, UserLevel::Block, UserLevel::Cluster] as $level) {
            $officer = $this->officer($level, $this->north);
            $this->actingAs($officer)->get(route('reports.ratings'))->assertForbidden();
            $this->actingAs($officer)->get(route('reports.ratings.export'))->assertForbidden();
            $this->actingAs($officer)->get(route('dashboard'))->assertOk()->assertDontSee('Rating Report');
        }
        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->assertSee('Rating Report');
    }

    public function test_report_service_still_scopes_to_the_jurisdiction(): void
    {
        ['school' => $school, 'other' => $other] = $this->seedRatings();
        $svc = app(\App\Services\RatingReportService::class);
        $f = $svc->filters(\Illuminate\Http\Request::create('/reports/ratings', 'GET'));

        $district = $this->officer(UserLevel::District, $this->north);
        $this->assertSame(1, $svc->summary($district, $f)['rated']);
        $names = $svc->schools($district, $f)->pluck('school.name')->all();
        $this->assertNotContains($other->school->name, $names);
    }

    public function test_filters_band_cluster_and_validation(): void
    {
        ['latest' => $latest] = $this->seedRatings();
        $admin = $this->admin();

        $byBand = $this->actingAs($admin)->get(route('reports.ratings', ['band' => $latest->rating->band]))->viewData('summary');
        $this->assertSame(1, $byBand['rated']);

        $byCluster = $this->actingAs($admin)->get(route('reports.ratings', ['cluster_id' => $this->south->id]));
        $this->assertSame(1, $byCluster->viewData('summary')['rated']);
        $this->assertNull($byCluster->viewData('areaLevel'));

        $sorted = $this->actingAs($admin)->get(route('reports.ratings', ['sort' => 'score_asc']))->viewData('rows')->getCollection();
        $this->assertLessThanOrEqual($sorted->last()['score'], $sorted->first()['score']);

        $this->actingAs($admin)->get(route('reports.ratings', ['sort' => 'nonsense']))->assertSessionHasErrors('sort');
        $this->actingAs($admin)->get(route('reports.ratings', ['from' => '2026-05-01', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
    }

    public function test_state_admin_sees_the_report_even_with_ratings_hidden_elsewhere(): void
    {
        config(['inspection.rating.display' => false]);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('reports.ratings'))->assertOk();
        $this->actingAs($admin)->get(route('reports.ratings.export'))->assertOk();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Rating Report');
    }

    public function test_excel_export_and_no_pdf(): void
    {
        $this->seedRatings();
        $officer = $this->admin();

        $xlsx = $this->actingAs($officer)->get(route('reports.ratings.export', ['from' => now()->subYear()->toDateString()]))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));
        // Modification V9: the rating report PDF is removed
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('reports.ratings.pdf'));
        $this->actingAs($officer)->get('/reports/ratings/pdf')->assertNotFound();
        $this->actingAs($officer)->get(route('reports.ratings'))->assertOk()->assertDontSee('bi-file-earmark-pdf', false);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'rating_report_exported')->count());
    }
}
