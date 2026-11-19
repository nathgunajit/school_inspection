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

/** Modification V7 (item 8): the redesigned rating card on the inspection report. */
class RatingCardTest extends TestCase
{
    private function rated(User $officer, School $school, string $date, array $answers): Inspection
    {
        $i = $this->startInspection($officer, $school);
        $i->update(['inspection_date' => $date, 'status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/RC/'.$i->id]);
        foreach ($answers as $code => $v) {
            InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => $code, 'value' => $v]);
        }
        app(RatingService::class)->rate($i->fresh());

        return $i->fresh('rating');
    }

    public function test_card_shows_band_scale_sections_and_change_since_the_previous_rating(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $older = $this->rated($this->officer(UserLevel::Cluster, $cluster), $school, now()->subMonths(2)->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'no', 'GOVR_05' => 'no']);
        $latest = $this->rated($this->officer(UserLevel::Block, $cluster), $school, now()->toDateString(), ['CND_02' => 'yes', 'CND_03' => 'yes', 'GOVR_05' => 'yes']);
        $admin = $this->admin();

        $this->assertSame($older->rating->id, $latest->previousRating()?->id);
        $this->assertNull($older->previousRating());

        $change = round($latest->rating->score_percent - $older->rating->score_percent, 1);
        $this->assertGreaterThan(0, $change);
        $this->actingAs($admin)->get(route('inspections.show', $latest))->assertOk()
            ->assertSee('rt-hero', false)->assertSee('rt-scale', false)->assertSee('Section scores')
            ->assertSee('since last inspection')->assertSee('+'.rtrim(rtrim(number_format($change, 1), '0'), '.').' pts')
            ->assertSee($older->inspection_date->format('d M Y'));

        $this->actingAs($admin)->get(route('inspections.show', $older))->assertOk()
            ->assertSee('First rated inspection of this school');

        // a draft or cancelled inspection of the school is never the "previous" one
        $older->update(['status' => InspectionStatus::Cancelled]);
        $this->assertNull($latest->fresh()->previousRating());
    }
}
