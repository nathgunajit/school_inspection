<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionFundPosition;
use App\Questionnaire\InspectionValidator;
use Tests\TestCase;

/** Absent Teachers (step 3) and Fund Details (step 5) are Done only after the officer acts. */
class StepCompletionTest extends TestCase
{
    private function draft(): Inspection
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $i = $this->startInspection($officer, $school);
        $this->actingAs($officer);

        return $i;
    }

    private function steps(Inspection $i): array
    {
        return (new InspectionValidator($i->fresh()))->progress()['steps'];
    }

    public function test_new_draft_shows_absentee_and_fund_as_not_started(): void
    {
        $i = $this->draft();
        $p = (new InspectionValidator($i->fresh()))->progress();

        foreach ([3, 5] as $step) {
            $this->assertFalse($p['steps'][$step]['complete'], "step $step not done");
            $this->assertSame(0, $p['steps'][$step]['pct'], "step $step not started");
        }
        $this->assertFalse($p['can_submit']);
        $this->assertSame('ABSENTEE', $p['missing'][3][0]['code']);
        $this->assertSame('FUND', $p['missing'][5][0]['code']);
    }

    public function test_all_teachers_present_marks_step_3_done(): void
    {
        $i = $this->draft();
        $this->postJson(route('inspections.answers', $i), ['code' => 'ABS_00', 'value' => ['yes']])->assertOk();
        $this->assertTrue($this->steps($i)[3]['complete']);

        // unticking makes it not started again
        $this->postJson(route('inspections.answers', $i), ['code' => 'ABS_00', 'value' => []])->assertOk();
        $this->assertFalse($this->steps($i)[3]['complete']);
    }

    public function test_an_absent_teacher_marks_step_3_done_but_not_together_with_all_present(): void
    {
        $i = $this->draft();
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'R. Das', 'teacher_type' => array_key_first(\App\Models\InspectionAbsentTeacher::TYPES),
            'reason' => array_key_first(\App\Models\InspectionAbsentTeacher::REASONS)])->assertOk();
        $this->assertTrue($this->steps($i)[3]['complete']);

        $this->postJson(route('inspections.answers', $i), ['code' => 'ABS_00', 'value' => ['yes']])->assertOk();
        $p = (new InspectionValidator($i->fresh()))->progress();
        $this->assertFalse($p['steps'][3]['complete'], 'rows and "all present" contradict each other');
        $this->assertSame('ABS_00', $p['missing'][3][0]['code']);
    }

    public function test_one_fund_year_marks_step_5_done(): void
    {
        $i = $this->draft();
        $fy = InspectionFundPosition::financialYears()[0];
        $res = $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'asg_received' => 1.5])->assertOk();
        $this->assertTrue($res->json('progress.steps.5.complete') ?? $this->steps($i)[5]['complete']);
        $this->assertTrue($this->steps($i)[5]['complete']);

        $fund = $i->fundPositions()->first();
        $this->deleteJson(route('inspections.funds.destroy', [$i, $fund]))->assertOk();
        $this->assertFalse($this->steps($i)[5]['complete']);
    }

    public function test_stepper_shows_not_started_on_a_new_draft(): void
    {
        $i = $this->draft();
        $html = $this->get(route('inspections.step', [$i, 1]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Absent Teachers.*?Not started/s', $html);
        $this->assertMatchesRegularExpression('/Fund Details.*?Not started/s', $html);
        $step3 = $this->get(route('inspections.step', [$i, 3]))->assertOk()->assertSee('All teachers present')->getContent();
        $this->assertSame(1, substr_count($step3, 'id="q-ABS_00"'), 'the "all teachers present" question is shown once');
    }
}
