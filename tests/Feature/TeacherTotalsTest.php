<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Inspection;
use App\Models\User;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;
use Tests\TestCase;

/**
 * Step 2: Trained + Untrained (V10: TSI_14 + TSI_15; older inspections TSI_10..13) must be less than or equal to Total teachers (TSI_04..08),
 * and teachers present (TSI_09) cannot be more than Total teachers. Both are blocking.
 */
class TeacherTotalsTest extends TestCase
{
    private User $officer;

    private Inspection $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        $cluster = Cluster::orderBy('id')->first();
        $this->officer = $this->officer(UserLevel::Cluster, $cluster);
        $this->inspection = $this->startInspection($this->officer, $this->schoolIn($cluster));
    }

    private function fill(array $answers): void
    {
        foreach ($answers as $code => $v) {
            $this->actingAs($this->officer)->post(route('inspections.answers', $this->inspection), ['code' => $code, 'value' => $v])->assertOk();
        }
    }

    private function step2(): array
    {
        return (new InspectionValidator($this->inspection->fresh()))->missing()[2] ?? [];
    }

    private function codes(): array
    {
        return array_column($this->step2(), 'code');
    }

    /** Total teachers = 6 + 2 + 1 + 1 + 0 = 10 */
    private const TOTAL = ['TSI_04' => 6, 'TSI_05' => 2, 'TSI_06' => 1, 'TSI_07' => 1, 'TSI_08' => 0];

    public function test_breakdown_sums(): void
    {
        // Modification V10: total trained (TSI_14) + total untrained (TSI_15); blank counts are 0
        $this->assertSame(['trained' => 4, 'untrained' => 0, 'male' => null, 'female' => null, 'sum' => 4, 'legacy' => false], Q::teacherBreakdown(['TSI_14' => 4]));
        $this->assertSame(['trained' => 0, 'untrained' => 0, 'male' => null, 'female' => null, 'sum' => 0, 'legacy' => false], Q::teacherBreakdown([]));
        $this->assertSame(['trained' => 7, 'untrained' => 3, 'male' => null, 'female' => null, 'sum' => 10, 'legacy' => false], Q::teacherBreakdown(['TSI_14' => '7', 'TSI_15' => 3]));
        // an older inspection (male / female split) keeps its sums
        $this->assertSame(['trained' => 4, 'untrained' => 6, 'male' => 3, 'female' => 7, 'sum' => 10, 'legacy' => true],
            Q::teacherBreakdown(['TSI_10' => 1, 'TSI_11' => 2, 'TSI_12' => '3', 'TSI_13' => 4]));
        $this->assertSame(['trained' => 4, 'untrained' => 2, 'male' => 3, 'female' => 3, 'sum' => 6, 'legacy' => true], Q::teacherBreakdown(['TSI_10' => 1, 'TSI_11' => 2, 'TSI_12' => 3]));
        // once a new total is entered the new codes win
        $this->assertFalse(Q::teacherBreakdown(['TSI_10' => 1, 'TSI_14' => 2])['legacy']);
    }

    public function test_breakdown_more_than_total_blocks_step_and_submit(): void
    {
        $this->fill(self::TOTAL + ['TSI_09' => 8, 'TSI_14' => 9, 'TSI_15' => 3]); // 12 > 10

        $msg = collect($this->step2())->firstWhere('code', 'TSI_14')['message'] ?? '';
        $this->assertStringContainsString('Trained (9) + Untrained (3) = 12', $msg);
        $this->assertStringNotContainsString('Male', $msg);
        $this->assertStringContainsString('more than Total teachers (10)', $msg);
        $this->assertFalse((new InspectionValidator($this->inspection->fresh()))->progress()['steps'][2]['complete']);

        $this->actingAs($this->officer)->post(route('inspections.submit', $this->inspection), ['confirm' => '1'])
            ->assertRedirect(route('inspections.review', $this->inspection));
        $this->assertNotSame(InspectionStatus::Submitted, $this->inspection->fresh()->status);
        $this->actingAs($this->officer)->get(route('inspections.review', $this->inspection))->assertSee('more than Total teachers (10)');

        // equal to the total is allowed
        $this->fill(['TSI_15' => 1]); // 10
        $this->assertNotContains('TSI_14', $this->codes());
    }

    public function test_breakdown_less_than_or_equal_to_total_is_allowed(): void
    {
        // fewer than the total (e.g. training status not known for every teacher) is accepted
        $this->fill(self::TOTAL + ['TSI_09' => 8, 'TSI_14' => 7, 'TSI_15' => 2]); // 9 ≤ 10
        $this->assertNotContains('TSI_14', $this->codes());
        $this->fill(['TSI_14' => 0, 'TSI_15' => 0]); // 0 ≤ 10
        $this->assertNotContains('TSI_14', $this->codes());
        $this->fill(['TSI_14' => 8, 'TSI_15' => 2]); // 10 = 10
        $this->assertNotContains('TSI_14', $this->codes());
        $this->fill(['TSI_15' => 3]); // 11 > 10
        $this->assertContains('TSI_14', $this->codes());
    }

    public function test_older_draft_with_male_female_split_is_still_checked(): void
    {
        $this->fill(self::TOTAL + ['TSI_09' => 8, 'TSI_10' => 4, 'TSI_11' => 1, 'TSI_12' => 5, 'TSI_13' => 2]); // 12 > 10
        $msg = collect($this->step2())->firstWhere('code', 'TSI_10')['message'] ?? '';
        $this->assertStringContainsString('Male (5) + Female (7) = 12', $msg);
        $this->fill(['TSI_13' => 0]); // 10
        $this->assertNotContains('TSI_10', $this->codes());
    }
    public function test_present_more_than_total_blocks(): void
    {
        $this->fill(self::TOTAL + ['TSI_09' => 11]);
        $this->assertContains('TSI_09', $this->codes());
        $this->fill(['TSI_09' => 10]);
        $this->assertNotContains('TSI_09', $this->codes());
    }

    public function test_no_consistency_error_until_numbers_are_entered(): void
    {
        $this->fill(['TSI_14' => 10, 'TSI_15' => 10, 'TSI_09' => 50]); // total not entered yet
        $this->assertSame([], array_values(array_filter($this->step2(), fn ($m) => ! str_ends_with($m['message'], 'answer required'))));

        $this->fill(self::TOTAL);
        $this->fill(['TSI_09' => 9, 'TSI_14' => 5, 'TSI_15' => 5]); // 5 + 5 = 10
        $this->assertNotContains('TSI_14', $this->codes());
        $this->assertNotContains('TSI_09', $this->codes());
    }

    public function test_step_page_shows_live_breakdown_box(): void
    {
        // Modification V10: only Total trained / Total untrained are asked; the male / female split is gone
        $this->actingAs($this->officer)->get(route('inspections.step', [$this->inspection, 2]))->assertOk()
            ->assertSee('data-auto-total="breakdown"', false)->assertSee('a blank count is 0')->assertSee('Trained + Untrained teachers')
            ->assertSee('name="TSI_14" value="0"', false)->assertSee('name="TSI_15" value="0"', false)
            ->assertSee('Total Number of Trained Teachers')->assertDontSee('name="TSI_10"', false)->assertDontSee('Male Trained')->assertDontSee('Male trained + Female trained');
    }
}
