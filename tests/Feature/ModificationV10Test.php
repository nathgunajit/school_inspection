<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Modification V10 (Documents/Modification of the ApplicationV10.docx):
 *  1. ECCE TLM list next to FLN (see TlmItemsTest);
 *  2. Step 2 asks only Total trained (TSI_14) and Total untrained (TSI_15); TSI_10..13 kept for older inspections;
 *  3. absent teachers on one line, Type and Reason shown on every screen size.
 */
class ModificationV10Test extends TestCase
{
    private function draft(): Inspection
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->actingAs($officer);

        return $i;
    }

    private function answer(Inspection $i, string $code, $value): void
    {
        $this->post(route('inspections.answers', $i), ['code' => $code, 'value' => $value])->assertOk();
    }

    public function test_male_female_codes_are_hidden_and_the_totals_are_asked(): void
    {
        foreach (Q::LEGACY_BREAKDOWN_CODES as $c) {
            $this->assertTrue(Q::find($c)['hidden'] ?? false, $c); // code kept, not asked
            $this->assertArrayNotHasKey($c, Q::defaults());
        }
        $this->assertSame(0, Q::defaults()['TSI_14']);
        $this->assertSame(0, Q::defaults()['TSI_15']);

        $i = $this->draft();
        $html = $this->get(route('inspections.step', [$i, 2]))->assertOk()->getContent();
        $this->assertStringContainsString('Total Number of Trained Teachers', $html);
        $this->assertStringContainsString('Total Number of Untrained Teachers', $html);
        $this->assertStringNotContainsString('Male Untrained', $html);
        $this->assertStringNotContainsString('data-at="male"', $html);

        $missing = collect((new InspectionValidator($i->fresh()))->missing()[2] ?? [])->pluck('code');
        foreach (['TSI_10', 'TSI_11', 'TSI_12', 'TSI_13', 'TSI_14', 'TSI_15'] as $c) {
            $this->assertNotContains($c, $missing, $c);
        }
    }

    public function test_report_shows_the_totals_or_the_old_split(): void
    {
        $i = $this->draft();
        foreach (['TSI_04' => 6, 'TSI_14' => 4, 'TSI_15' => 2] as $c => $v) {
            $this->answer($i, $c, $v);
        }
        $this->get(route('inspections.review', $i))->assertOk()
            ->assertSee('Total Number of Trained Teachers')->assertSee('Trained 4 · Untrained 2')->assertDontSee('Male Trained Teachers');
        $this->get(route('inspections.pdf', $i))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_older_inspection_keeps_its_male_female_split_on_the_report(): void
    {
        $old = $this->draft();
        foreach (['TSI_04' => 6, 'TSI_10' => 1, 'TSI_11' => 1, 'TSI_12' => 2, 'TSI_13' => 1] as $c => $v) {
            $this->answer($old, $c, $v);
        }
        $this->get(route('inspections.review', $old))->assertOk()
            ->assertSee('Number of Male Trained Teachers')->assertSee('Male 2 · Female 3')->assertDontSee('Total Number of Trained Teachers');
        $this->get(route('inspections.pdf', $old))->assertOk();
    }

    public function test_absent_teacher_row_shows_type_and_reason_on_one_line(): void
    {
        $i = $this->draft();
        $this->answer($i, 'TSI_04', 3);
        $this->answer($i, 'TSI_09', 1);
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'R. Kalita', 'teacher_type' => 'contractual', 'reason' => 'CL',
            'row_uid' => (string) Str::uuid()])->assertOk();

        $this->get(route('inspections.review', $i))->assertOk()
            ->assertSee('rv-table rv-absent', false)->assertSee('class="rv-nowrap"', false)
            ->assertSee('Contractual Teacher')->assertSee('Casual Leave (CL)');
    }
}
