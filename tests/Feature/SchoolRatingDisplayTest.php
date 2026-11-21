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

/** V6 Module 9: the school's rating on the school list, the school page and the map. */
class SchoolRatingDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.rating.display' => true]); // Modification V9: ratings are hidden by default
    }

    /** A submitted, rated inspection of $school on $date with the given answers. */
    private function rated(User $officer, School $school, string $date, array $answers): Inspection
    {
        $i = $this->startInspection($officer, $school);
        $i->answers()->delete(); // V11: a later visit is pre-filled from the last one; these tests rate exactly the given answers
        $i->fundPositions()->delete();
        $i->update(['inspection_date' => $date, 'status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/T/'.$i->id]);
        foreach ($answers as $code => $v) {
            InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => $code, 'value' => $v]);
        }
        app(RatingService::class)->rate($i->fresh());

        return $i->fresh('rating');
    }

    public function test_latest_rating_is_the_newest_submitted_rated_inspection(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $a = $this->officer(UserLevel::Cluster, $cluster);
        $b = $this->officer(UserLevel::Block, $cluster);
        $school = $this->schoolIn($cluster);

        $old = $this->rated($a, $school, now()->subMonth()->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'no', 'GOVR_05' => 'no']);
        $new = $this->rated($b, $school, now()->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'yes', 'GOVR_05' => 'yes']);
        $this->assertSame($new->id, $school->fresh()->latestRatedInspection->id);
        $this->assertSame(100.0, $school->fresh()->latestRating()->score_percent);

        // an unlocked inspection (being corrected) does not count until it is resubmitted
        $new->update(['status' => InspectionStatus::Unlocked]);
        $this->assertSame($old->id, $school->fresh()->latestRatedInspection->id);
        $this->assertNull($this->schoolIn(Cluster::orderByDesc('id')->first())->latestRating());
    }

    public function test_school_page_list_and_map_show_the_rating(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);
        $this->rated($officer, $school, now()->subMonth()->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'no', 'GOVR_05' => 'no']);
        $latest = $this->rated($this->officer(UserLevel::Block, $cluster), $school, now()->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'yes', 'GOVR_05' => 'no']);
        $score = $latest->rating->score_percent;

        $this->actingAs($officer)->get(route('schools.show', $school))->assertOk()
            ->assertSee('school-rating-box', false)->assertSee(rtrim(rtrim(number_format($score, 1), '0'), '.').'%')->assertSee($latest->rating->band)
            ->assertSee('pts');

        $this->actingAs($officer)->get(route('schools.index', ['cluster_id' => $cluster->id]))->assertSee($latest->rating->band);

        $row = collect($this->actingAs($officer)->getJson(route('map.schools', ['cluster' => $cluster->id]))->json('rows'))->firstWhere('id', $school->id);
        $this->assertSame(['score' => (float) $score, 'band' => $latest->rating->band, 'color' => $latest->rating->color], $row['rating']);

        // area rows: average of the ratings submitted in the period (this month: only the latest one)
        $area = collect($this->actingAs($this->admin())->getJson(route('map.areas', ['level' => 'cluster', 'parent' => $cluster->block_id]))->json('rows'))->firstWhere('id', $cluster->id);
        $this->assertSame(['avg' => round($score, 1), 'count' => 1, 'band' => $latest->rating->band, 'color' => $latest->rating->color], $area['rating']);
        $wide = collect($this->actingAs($this->admin())->getJson(route('map.areas', ['level' => 'cluster', 'parent' => $cluster->block_id,
            'from' => now()->subMonths(2)->toDateString()]))->json('rows'))->firstWhere('id', $cluster->id);
        $this->assertSame(2, $wide['rating']['count']);

        $this->actingAs($officer)->get(route('map.index'))->assertOk()->assertSee('Colour schools by', false)->assertSee('Not rated yet');
    }

    public function test_school_without_rating_shows_none(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);

        $this->actingAs($officer)->get(route('schools.show', $school))->assertOk()->assertDontSee('school-rating-box', false);
        $row = collect($this->actingAs($officer)->getJson(route('map.schools', ['cluster' => $cluster->id]))->json('rows'))->firstWhere('id', $school->id);
        $this->assertNull($row['rating']);
    }
}
