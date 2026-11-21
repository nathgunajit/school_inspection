<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionFundPosition;
use App\Questionnaire\InspectionValidator;
use Tests\TestCase;

/** Absent Teachers (step 3) follow the step 2 numbers; Fund Details (step 5) are optional. */
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

    public function test_new_draft_shows_absentee_as_not_started_and_fund_as_optional(): void
    {
        $i = $this->draft();
        $p = (new InspectionValidator($i->fresh()))->progress();

        $this->assertFalse($p['steps'][3]['complete'], 'step 3 not done');
        $this->assertSame(0, $p['steps'][3]['pct'], 'step 3 not started');
        $this->assertFalse($p['can_submit']);
        $this->assertSame('ABSENTEE', $p['missing'][3][0]['code']);
        // Modification V9: fund details are optional
        $this->assertTrue($p['steps'][5]['complete']);
        $this->assertArrayNotHasKey(5, $p['missing']);
    }

    private function teacherNumbers(Inspection $i, int $total, int $present): void
    {
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_04', 'value' => $total])->assertOk();
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_09', 'value' => $present])->assertOk();
    }

    private function addAbsent(Inspection $i)
    {
        return $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'R. Das', 'teacher_type' => array_key_first(\App\Models\InspectionAbsentTeacher::TYPES),
            'reason' => array_key_first(\App\Models\InspectionAbsentTeacher::REASONS)]);
    }

    /** 21-11-2026: step 3 follows step 2. All present: nothing to fill, and no absent row can be added. */
    public function test_all_teachers_present_on_step_2_marks_step_3_done(): void
    {
        $i = $this->draft();
        $this->teacherNumbers($i, 5, 5);
        $p = (new InspectionValidator($i->fresh()))->progress();
        $this->assertTrue($p['steps'][3]['complete']);
        $this->assertArrayNotHasKey(3, $p['missing']);
        $this->addAbsent($i)->assertStatus(422);

        $this->get(route('inspections.step', [$i, 3]))->assertOk()->assertSee('All teachers present today')
            ->assertSee('data-absent-state', false)->assertDontSee('id="q-ABS_00"', false);
        $this->get(route('inspections.review', $i))->assertOk()->assertSee('All teachers present (Total 5, Present 5)');
    }

    /** Absent teachers on step 2: exactly that many rows are needed. */
    public function test_step_3_needs_every_absent_teacher_from_step_2(): void
    {
        $i = $this->draft();
        $this->teacherNumbers($i, 5, 3);
        $p = (new InspectionValidator($i->fresh()))->progress();
        $this->assertFalse($p['steps'][3]['complete']);
        $this->assertSame('Add the details of all 2 absent teacher(s) (0 added)', $p['missing'][3][0]['message']);
        $this->get(route('inspections.step', [$i, 3]))->assertOk()->assertSee('2 teachers absent today');

        $this->addAbsent($i)->assertOk();
        $this->assertSame(50, $this->steps($i)[3]['pct']);
        $this->addAbsent($i)->assertOk();
        $this->assertTrue($this->steps($i)[3]['complete']);
        $this->addAbsent($i)->assertStatus(422); // a third is refused

        // Present typed above Total: nothing is removed (step 2 shows the error)
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_09', 'value' => 9])->assertOk()->assertJsonMissingPath('absent_removed');
        $this->assertSame(2, $i->absentTeachers()->count());

        // V12 item 2: step 2 corrected to all present: the absentee list is removed automatically
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_09', 'value' => 5])->assertOk()
            ->assertJsonPath('absent_removed', 2)->assertJsonCount(0, 'teachers');
        $this->assertSame(0, $i->absentTeachers()->count());
        $this->assertTrue($this->steps($i)[3]['complete']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'absent_teachers_cleared', 'auditable_id' => $i->id]);
    }

    /** Present (TSI_09) is required; until it is entered step 3 waits for step 2. The old ABS_00 tick no longer counts. */
    public function test_present_is_required_and_step_3_waits_for_step_2(): void
    {
        $i = $this->draft();
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_04', 'value' => 4])->assertOk();
        $p = (new InspectionValidator($i->fresh()))->progress();
        $this->assertContains('TSI_09', collect($p['missing'][2])->pluck('code'));
        $this->assertStringContainsString('step 2 first', $p['missing'][3][0]['message']);
        $this->assertNull(InspectionValidator::absentLimit($i->fresh()->answerMap()));
        $this->assertTrue(\App\Questionnaire\QuestionnaireDefinition::find('ABS_00')['hidden']); // code kept, no longer asked
        $this->get(route('inspections.step', [$i, 3]))->assertOk()->assertSee('Enter the teacher numbers on step 2 first');
        $this->get(route('inspections.step', [$i, 2]))->assertOk()->assertSee('data-auto-total="absent"', false)
            ->assertSee('data-step4-href', false);
    }

    public function test_fund_years_can_be_added_and_removed_step_5_stays_done(): void
    {
        $i = $this->draft();
        $fy = InspectionFundPosition::financialYears()[0];
        $res = $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'asg_received' => 1.5])->assertOk();
        $this->assertTrue($res->json('progress.steps.5.complete') ?? $this->steps($i)[5]['complete']);
        $this->assertTrue($this->steps($i)[5]['complete']);

        $fund = $i->fundPositions()->first();
        $this->deleteJson(route('inspections.funds.destroy', [$i, $fund]))->assertOk();
        $this->assertTrue($this->steps($i)[5]['complete']); // V9: optional
    }

    public function test_stepper_shows_not_started_on_a_new_draft(): void
    {
        $i = $this->draft();
        $html = $this->get(route('inspections.step', [$i, 1]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Absent Teachers.*?Not started/s', $html);
        $this->assertMatchesRegularExpression('/Fund Details.*?Not started/s', $html);
        $this->get(route('inspections.step', [$i, 3]))->assertOk()->assertSee('Enter the teacher numbers on step 2 first');
    }
}
