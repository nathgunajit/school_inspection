<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Inspection;
use App\Models\User;
use App\Support\InspectionVisibility;
use Tests\TestCase;

/**
 * 25-11-2026: Cluster, Block and District officers may inspect the same school; only an officer of the SAME level
 * blocks it. Each officer sees their own inspections and those of lower levels; State users see all.
 */
class InspectionVisibilityTest extends TestCase
{
    private function submit(Inspection $i, int $daysAgo, array $answers = []): Inspection
    {
        foreach ($answers as $code => $v) {
            $i->answers()->updateOrCreate(['question_code' => $code], ['step' => 1, 'value' => $v]);
        }
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_date' => today()->subDays($daysAgo)->toDateString(),
            'inspection_no' => 'SI/T/'.$i->id])->save();

        return $i->fresh();
    }

    public function test_levels_below_and_the_snapshot(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $this->assertSame(['block', 'cluster'], InspectionVisibility::levelsBelow($this->officer(UserLevel::District, $cluster)));
        $this->assertSame(['cluster'], InspectionVisibility::levelsBelow($this->officer(UserLevel::Block, $cluster)));
        $this->assertSame([], InspectionVisibility::levelsBelow($this->officer(UserLevel::Cluster, $cluster)));
        $this->assertNull(InspectionVisibility::levelsBelow($this->officer(UserLevel::State)));
        $this->assertNull(InspectionVisibility::levelsBelow($this->admin()));

        $i = $this->startInspection($this->officer(UserLevel::Block, $cluster), $this->schoolIn($cluster));
        $this->assertSame('block', $i->officer_level);
    }

    public function test_different_levels_may_inspect_the_same_school_the_same_level_is_blocked(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $this->submit($this->startInspection($crcc, $school), 0);

        // a Block and a District officer still start it the same day (not blocked by the CRCC)
        $block = $this->officer(UserLevel::Block, $cluster);
        $b = $this->startInspection($block, $school);
        $this->assertSame(InspectionStatus::Draft, $b->status);
        $this->assertSame($block->id, $b->officer_id);
        $district = $this->officer(UserLevel::District, $cluster);
        $this->assertSame($district->id, $this->startInspection($district, $school)->officer_id);

        // a second Block officer of the same block is blocked by the first one's draft
        $block2 = $this->officer(UserLevel::Block, $cluster);
        $this->actingAs($block2)->post(route('inspections.start', $school), ['lat' => 26.1, 'lng' => 91.7, 'accuracy' => 10])
            ->assertRedirect(route('schools.show', $school))->assertSessionHas('warning');
        $this->assertFalse(Inspection::where('officer_id', $block2->id)->exists());

        // once the Block draft is submitted this month, the second Block officer is still blocked (same level, same month)
        $this->submit($b, 0);
        $this->actingAs($block2)->post(route('inspections.start', $school), ['lat' => 26.1, 'lng' => 91.7, 'accuracy' => 10])
            ->assertRedirect(route('schools.show', $school));
        $this->assertFalse(Inspection::where('officer_id', $block2->id)->exists());
    }

    public function test_officers_see_own_and_lower_levels_only(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $block = $this->officer(UserLevel::Block, $cluster);
        $district = $this->officer(UserLevel::District, $cluster);
        $c = $this->submit($this->startInspection($crcc, $school), 3);
        $b = $this->submit($this->startInspection($block, $school), 2);
        $d = $this->submit($this->startInspection($district, $school), 1);
        $range = ['from' => today()->subDays(10)->toDateString(), 'to' => today()->toDateString()];

        $sees = function (User $u, array $visible) use ($c, $b, $d, $school, $range) {
            $area = $u->can('view_reports') ? $this->actingAs($u)->get(route('inspections.area', $range))->assertOk()->getContent() : null;
            $page = $this->actingAs($u)->get(route('schools.show', $school))->assertOk()->getContent();
            foreach (['c' => $c, 'b' => $b, 'd' => $d] as $k => $i) {
                $ok = in_array($k, $visible, true);
                $this->actingAs($u)->get(route('inspections.show', $i))->assertStatus($ok ? 200 : 403);
                $this->assertSame($ok, str_contains($page, $i->inspection_no), "school history of {$u->level->value}: $k");
                if ($area !== null) {
                    $this->assertSame($ok, str_contains($area, $i->inspection_no), "area list of {$u->level->value}: $k");
                }
            }
        };
        $sees($crcc, ['c']);
        $sees($block, ['c', 'b']);
        $sees($district, ['c', 'b', 'd']);
        $sees($this->officer(UserLevel::State), ['c', 'b', 'd']);

        // the CRCC's school list counts only its own visit
        $this->actingAs($crcc)->get(route('schools.mine', ['q' => $school->udise_code]))->assertOk()->assertSee('Inspected 1 time ', false);
        $this->actingAs($district)->get(route('schools.mine', ['q' => $school->udise_code]))->assertOk()->assertSee('Inspected 3 times', false);
    }

    public function test_next_visit_prefill_copies_only_from_a_visible_inspection(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $block = $this->officer(UserLevel::Block, $cluster);
        $this->submit($this->startInspection($crcc, $school), 20, ['CND_02' => 'no']);
        $this->submit($this->startInspection($block, $school), 5, ['CND_02' => 'yes']);

        $mine = $this->startInspection($crcc, $school);
        $this->assertSame('no', $mine->answers()->where('question_code', 'CND_02')->value('value'), 'the CRCC never copies the Block visit');
        $district = $this->officer(UserLevel::District, $cluster);
        $theirs = $this->startInspection($district, $school);
        $this->assertSame('yes', $theirs->answers()->where('question_code', 'CND_02')->value('value'), 'the District copies the newest visible (Block) visit');
    }
}
