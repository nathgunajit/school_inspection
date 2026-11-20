<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\InspectionRating;
use App\Models\RatingScheme;
use App\Rating\DefaultScheme;
use App\Rating\RatingCalculator;
use App\Services\RatingService;
use Tests\TestCase;

/** New Module 3: automatic inspection rating. */
class InspectionRatingTest extends TestCase
{
    private function crit(string $section, string $code, array $points, float $weight = 1): array
    {
        return ['section' => $section, 'code' => $code, 'label' => $code, 'kind' => 'answer', 'weight' => $weight, 'points' => $points];
    }

    public function test_formula_weights_and_sections(): void
    {
        $yes = ['yes' => 1, 'no' => 0];
        $criteria = [
            $this->crit('condition', 'CND_02', $yes, 2),       // yes  → 2 of 2
            $this->crit('condition', 'CND_03', $yes, 1),       // no   → 0 of 1
            $this->crit('governance', 'GOVR_05', $yes, 1),     // yes  → 1 of 1
            $this->crit('condition', 'CND_01', ['pucca' => 1, 'semi_pucca' => 0.5, 'others' => 0], 2), // semi → 1 of 2
        ];
        $r = RatingCalculator::calculate($criteria, DefaultScheme::BANDS, ['CND_02' => 'yes', 'CND_03' => 'no', 'GOVR_05' => 'yes', 'CND_01' => 'semi_pucca']);

        $this->assertSame(4.0, $r['obtained']);
        $this->assertSame(6.0, $r['applicable']);
        $this->assertSame(66.67, $r['score_percent']);
        $this->assertSame('Good', $r['band']);
        $this->assertSame('yellow', $r['color']);
        $this->assertSame(60.0, $r['sections']['condition']['percent']);     // 3 of 5
        $this->assertSame(100.0, $r['sections']['governance']['percent']);
        $this->assertSame(['CND_03'], array_column($r['weaknesses'], 'label'));
        $this->assertContains('CND_02', array_column($r['strengths'], 'label'));
    }

    public function test_not_applicable_items_are_left_out_of_the_denominator(): void
    {
        $yes = ['yes' => 1, 'no' => 0];
        $criteria = [
            $this->crit('condition', 'CND_17', $yes),           // internet: no
            $this->crit('condition', 'CND_18', $yes),           // functional: disabled because CND_17 = no → N/A
            $this->crit('condition', 'CND_26', $yes),           // tinkering lab functional = "na" → N/A (and hidden)
            $this->crit('governance', 'GOVR_04', $yes),         // monthly sitting: hidden because GOVR_02 = no → N/A
            $this->crit('governance', 'GOVR_02', $yes),         // SMC formed: no
            $this->crit('condition', 'CND_02', $yes),           // yes
        ];
        $r = RatingCalculator::calculate($criteria, DefaultScheme::BANDS, [
            'CND_17' => 'no', 'CND_18' => 'yes', 'CND_24' => 'yes', 'CND_26' => 'na', 'GOVR_02' => 'no', 'GOVR_04' => 'yes', 'CND_02' => 'yes',
        ]);
        $this->assertSame(3.0, $r['applicable']);
        $this->assertSame(1.0, $r['obtained']);
        $this->assertSame(33.33, $r['score_percent']);
        $this->assertNull(collect($r['details'])->firstWhere('code', 'CND_18')['points']);

        // nothing applicable → not rated; an empty section → N/A
        $none = RatingCalculator::calculate([$this->crit('condition', 'CND_18', $yes)], DefaultScheme::BANDS, ['CND_17' => 'no', 'CND_18' => 'yes']);
        $this->assertNull($none['score_percent']);
        $this->assertNull($none['band']);
        $this->assertNull($none['sections']['condition']['percent']);
    }

    public function test_band_boundaries(): void
    {
        $this->assertSame('Very Good', RatingCalculator::band(89.99, DefaultScheme::BANDS)['band']);
        $this->assertSame('Excellent', RatingCalculator::band(90, DefaultScheme::BANDS)['band']);
        $this->assertSame('Good', RatingCalculator::band(60, DefaultScheme::BANDS)['band']);
        $this->assertSame('Needs Improvement', RatingCalculator::band(59.99, DefaultScheme::BANDS)['band']);
        $this->assertSame('Unsatisfactory', RatingCalculator::band(39.99, DefaultScheme::BANDS)['band']);
        $this->assertSame('red', RatingCalculator::band(0, DefaultScheme::BANDS)['color']);
        $this->assertSame('Excellent', RatingCalculator::band(100, array_reverse(DefaultScheme::BANDS))['band']); // any order
    }

    public function test_metrics_and_their_zero_denominators(): void
    {
        $m = fn ($code) => ['section' => DefaultScheme::METRICS[$code][0], 'code' => $code, 'label' => $code, 'kind' => 'metric', 'weight' => 1, 'points' => null];
        $all = array_map($m, array_keys(DefaultScheme::METRICS));
        $answers = ['TSI_01' => 80, 'TSI_02' => 20, 'TSI_04' => 2, 'TSI_05' => 1, 'TSI_06' => 1, 'TSI_07' => 0, 'TSI_08' => 0, 'TSI_09' => 3,
            'TSI_10' => 1, 'TSI_11' => 1, 'TSI_12' => 2, 'TSI_13' => 0];
        $rows = [
            'absent' => [['reason' => 'CL'], ['reason' => 'UL'], ['reason' => 'EL'], ['reason' => 'MEDL']],
            'classrooms' => [['tlm_used' => true, 'participation' => 'satisfactory'], ['tlm_used' => false, 'participation' => 'not_satisfactory']],
            'funds' => [['asg_received' => 1, 'asg_expenditure' => 0.5, 'mdm_received' => 1, 'mdm_expenditure' => 1.5]],
        ];
        $points = collect(RatingCalculator::calculate($all, DefaultScheme::BANDS, $answers, $rows)['details'])->pluck('points', 'code');
        $this->assertEquals(0.8, $points['student_attendance']);
        $this->assertEquals(0.75, $points['teacher_attendance']);
        $this->assertEquals(0.75, $points['trained_teachers']);
        $this->assertEquals(0.75, $points['absentee_authorised']);
        $this->assertEquals(0.5, $points['classroom_tlm']);
        $this->assertEquals(0.5, $points['classroom_participation']);
        $this->assertEquals(1.0, $points['fund_utilisation']); // 2 of 2 lakh

        // no absentees, no classrooms, nothing received, no students → N/A, not zero
        $empty = collect(RatingCalculator::calculate($all, DefaultScheme::BANDS, ['TSI_01' => 0, 'TSI_02' => 0], ['funds' => [['asg_received' => 0]]])['details'])->pluck('points', 'code');
        foreach (['student_attendance', 'teacher_attendance', 'absentee_authorised', 'classroom_tlm', 'fund_utilisation'] as $k) {
            $this->assertNull($empty[$k], $k);
        }
    }

    public function test_service_rates_a_submitted_v2_inspection_and_skips_drafts(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        foreach (['CND_02' => 'yes', 'CND_03' => 'no', 'GOVR_05' => 'yes'] as $code => $v) {
            InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => $code, 'value' => $v]);
        }
        $service = app(RatingService::class);
        $this->assertNull($service->rate($i)); // still a draft

        $i->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/R/1']);
        $rating = $service->rate($i->fresh());
        $this->assertSame(66.67, $rating->score_percent);
        $this->assertSame(1, RatingScheme::count()); // version 1 created on first use
        $this->assertSame(1, $rating->scheme->version);

        // legacy v1 inspections are not rated
        $legacy = Inspection::create($i->only(['officer_id', 'school_id', 'district_id', 'block_id', 'cluster_id', 'state_code', 'district_code', 'block_code', 'cluster_code'])
            + ['inspection_date' => today()->subYear(), 'started_at' => now()->subYear(), 'status' => InspectionStatus::Submitted, 'questionnaire_version' => 1]);
        $this->assertNull($service->rate($legacy));

        // the report shows the rating
        $this->actingAs($officer)->get(route('inspections.show', $i))->assertOk()->assertSee('School performance rating')->assertSee('66.67')->assertSee('Good');
        $this->actingAs($officer)->get(route('inspections.mine'))->assertOk()->assertSee('66.7% · Good', false);
        $this->actingAs($officer)->get(route('inspections.pdf', $i))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_admin_saves_a_new_version_and_old_ratings_keep_theirs(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => 'CND_02', 'value' => 'yes']);
        InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => 'CND_03', 'value' => 'no']);
        $i->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/R/2']);
        $service = app(RatingService::class);
        $v1 = $service->rate($i->fresh());
        $this->assertSame(50.0, $v1->score_percent);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.rating.index'))->assertOk()->assertSee('Rating bands')->assertSee('CND_02')->assertSee('data-rating-settings', false)->assertSee('Weight share')->assertSee('How the score is calculated')->assertSee('data-band-legend', false);
        $scheme = $service->activeScheme();
        $cnd02 = $scheme->criteria->firstWhere('code', 'CND_02');
        $bands = DefaultScheme::BANDS;

        // invalid bands are refused
        $this->actingAs($admin)->post(route('admin.rating.update'), ['bands' => [['min' => 10, 'label' => 'A', 'color' => 'green'], ['min' => 50, 'label' => 'B', 'color' => 'red']]])
            ->assertSessionHasErrors('bands');

        $this->actingAs($admin)->post(route('admin.rating.update'), [
            'criteria' => [$cnd02->id => ['weight' => 3, 'points' => ['yes' => 1, 'no' => 0]]],
            'bands' => $bands,
        ])->assertRedirect(route('admin.rating.index'))->assertSessionHasNoErrors();

        $v2 = $service->activeScheme();
        $this->assertSame(2, $v2->version);
        $this->assertSame(3.0, $v2->criteria->firstWhere('code', 'CND_02')->weight);
        $this->assertFalse(RatingScheme::where('version', 1)->value('is_active'));
        $this->assertSame($scheme->id, $i->fresh()->rating->scheme_id); // the old rating keeps version 1
        $this->assertDatabaseHas('audit_logs', ['action' => 'rating_scheme_saved']);

        // recalculating uses version 2: 3 of 4
        $this->actingAs($admin)->post(route('admin.rating.recalculate'))->assertRedirect();
        $this->assertSame(75.0, $i->fresh()->rating->score_percent);
        $this->assertSame($v2->id, $i->fresh()->rating->scheme_id);
    }

    public function test_only_masters_admins_open_rating_settings(): void
    {
        foreach ([UserLevel::State, UserLevel::District, UserLevel::Block, UserLevel::Cluster] as $level) {
            $u = $this->officer($level);
            $this->actingAs($u)->get(route('admin.rating.index'))->assertForbidden();
            $this->actingAs($u)->post(route('admin.rating.recalculate'))->assertForbidden();
        }
    }

    public function test_recalculate_command_rates_unrated_inspections(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => 'CND_02', 'value' => 'yes']);
        $i->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/R/3']);

        $this->artisan('ratings:recalculate')->expectsOutputToContain('Rated 1 inspection(s)')->assertSuccessful();
        $this->assertSame(100.0, InspectionRating::where('inspection_id', $i->id)->value('score_percent'));
        $this->artisan('ratings:recalculate')->expectsOutputToContain('Rated 0 inspection(s)')->assertSuccessful();
    }

    public function test_default_scheme_covers_only_real_question_codes(): void
    {
        foreach (DefaultScheme::criteria() as $c) {
            if ($c['kind'] === 'answer') {
                $q = \App\Questionnaire\QuestionnaireDefinition::find($c['code']);
                $this->assertNotNull($q, $c['code']);
                foreach (array_keys($c['points']) as $value) {
                    $this->assertArrayHasKey($value, $q['options'], $c['code'].' '.$value);
                }
            } else {
                $this->assertArrayHasKey($c['code'], DefaultScheme::METRICS);
            }
        }
    }
}
