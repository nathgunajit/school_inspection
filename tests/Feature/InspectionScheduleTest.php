<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\ScheduleStatus;
use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\InspectionSchedule;
use App\Models\School;
use App\Support\RouteOptimizer;
use Tests\TestCase;

/** New Module 2: map-based inspection scheduling. */
class InspectionScheduleTest extends TestCase
{
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cluster = Cluster::orderBy('id')->first();
    }

    private function schools(int $n = 2, ?Cluster $c = null): array
    {
        return School::where('cluster_id', ($c ?? $this->cluster)->id)->orderBy('id')->take($n)->pluck('id')->all();
    }

    private function plan($actor, $officer, array $ids, string $action = 'save', ?string $date = null)
    {
        return $this->actingAs($actor)->postJson(route('schedules.store'), [
            'officer_id' => $officer->id, 'date' => $date ?? today()->addDay()->toDateString(), 'school_ids' => $ids, 'action' => $action,
        ]);
    }

    public function test_officer_saves_a_draft_with_distances_and_times(): void
    {
        $officer = $this->officer(UserLevel::Cluster, $this->cluster);
        $ids = $this->schools(2);
        $this->plan($officer, $officer, $ids)->assertOk()->assertJsonStructure(['redirect']);

        $s = InspectionSchedule::with('items')->firstOrFail();
        $this->assertSame(ScheduleStatus::Draft, $s->status);
        $this->assertSame($ids, $s->items->pluck('school_id')->all());
        $this->assertSame([1, 2], $s->items->pluck('sequence')->all());
        $this->assertNotNull($s->items[1]->distance_km);
        $this->assertGreaterThanOrEqual('09:30', $s->items[0]->planned_arrival); // the day starts at 09:30, plus travel from the start point
        $this->assertGreaterThan($s->items[0]->planned_arrival, $s->items[1]->planned_arrival);
        $this->assertGreaterThan(0, $s->total_minutes);
        $this->assertTrue(AuditLog::where('action', 'schedule_created')->where('auditable_id', $s->id)->exists());

        $this->actingAs($officer)->get(route('schedules.show', $s))->assertOk()->assertSee($s->items->load('school')[0]->school->name);
        $this->actingAs($officer)->get(route('schedules.index', ['view' => 'month', 'date' => today()->addDay()->toDateString()]))->assertOk()->assertSee($officer->name);
        $this->actingAs($officer)->get(route('schedules.plan', ['date' => today()->addDay()->toDateString()]))->assertOk()->assertSee('Day plan');
    }

    public function test_rules_past_date_outside_jurisdiction_limit_and_duplicates(): void
    {
        $officer = $this->officer(UserLevel::Cluster, $this->cluster);
        $ids = $this->schools(1);

        $this->plan($officer, $officer, $ids, 'save', today()->subDay()->toDateString())->assertStatus(422)->assertJsonValidationErrors('date');

        $outside = $this->schools(1, $this->otherCluster($this->cluster));
        $this->plan($officer, $officer, $outside)->assertStatus(422)->assertJsonValidationErrors('school_ids');

        config(['inspection.schedule.max_per_day' => 1]);
        $this->plan($officer, $officer, $this->schools(2))->assertStatus(422)->assertJsonValidationErrors('school_ids');
        config(['inspection.schedule.max_per_day' => 8]);

        // another officer of the same cluster cannot plan the same school on the same day
        $this->plan($officer, $officer, $ids)->assertOk();
        $other = $this->officer(UserLevel::Cluster, $this->cluster);
        $res = $this->plan($other, $other, $ids)->assertStatus(422);
        $this->assertStringContainsString('already planned for '.$officer->name, implode(' ', $res->json('errors.school_ids')));
        // but on another day it is fine
        $this->plan($other, $other, $ids, 'save', today()->addDays(2)->toDateString())->assertOk();
    }

    public function test_school_inspected_by_another_officer_this_month_is_blocked(): void
    {
        $a = $this->officer(UserLevel::Cluster, $this->cluster);
        $b = $this->officer(UserLevel::Cluster, $this->cluster);
        $school = School::find($this->schools(1)[0]);
        $i = $this->startInspection($a, $school);
        $i->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/T/1']);

        $date = today()->endOfMonth()->isToday() ? today()->toDateString() : today()->addDay()->toDateString();
        $res = $this->plan($b, $b, [$school->id], 'save', $date)->assertStatus(422);
        $this->assertStringContainsString('already inspected by '.$a->name, implode(' ', $res->json('errors.school_ids')));
        // next month it can be planned again
        $this->plan($b, $b, [$school->id], 'save', today()->addMonthNoOverflow()->startOfMonth()->toDateString())->assertOk();
    }

    public function test_submit_approve_publish_and_reject_flow(): void
    {
        $officer = $this->officer(UserLevel::Cluster, $this->cluster);
        $block = $this->officer(UserLevel::Block, $this->cluster);
        $this->plan($officer, $officer, $this->schools(2), 'submit')->assertOk();
        $s = InspectionSchedule::firstOrFail();
        $this->assertSame(ScheduleStatus::Submitted, $s->status);

        // the officer cannot change a plan waiting for approval
        $this->plan($officer, $officer, $this->schools(1))->assertStatus(422);

        // a block officer of another block cannot review it
        $stranger = $this->officer(UserLevel::Block, Cluster::where('block_id', '!=', $this->cluster->block_id)->first());
        $this->actingAs($stranger)->post(route('schedules.approve', $s))->assertSessionHasErrors('note');
        $this->actingAs($stranger)->get(route('schedules.show', $s))->assertForbidden();

        $this->actingAs($block)->get(route('schedules.approvals'))->assertOk()->assertSee($officer->name);
        $this->actingAs($block)->post(route('schedules.reject', $s), ['note' => 'Add the far school first'])->assertRedirect();
        $this->assertSame(ScheduleStatus::Rejected, $s->fresh()->status);

        // corrected and submitted again, then approved
        $this->plan($officer, $officer, $this->schools(3), 'submit')->assertOk();
        $this->actingAs($block)->post(route('schedules.approve', $s), ['note' => 'OK'])->assertRedirect();
        $s->refresh();
        $this->assertSame(ScheduleStatus::Published, $s->status);
        $this->assertSame($block->id, $s->reviewed_by);
        $this->assertNotNull($s->published_at);
        foreach (['schedule_submitted', 'schedule_rejected', 'schedule_approved', 'schedule_published'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('auditable_id', $s->id)->exists(), $action);
        }
        $this->actingAs($officer)->get(route('schedules.show', $s))->assertOk()->assertSee('Sent back')->assertSee('Approved');
    }

    public function test_without_approval_the_officer_publishes_directly(): void
    {
        config(['inspection.schedule.approval_required' => false]);
        $officer = $this->officer(UserLevel::Cluster, $this->cluster);
        $this->plan($officer, $officer, $this->schools(1), 'submit')->assertOk();
        $this->assertSame(ScheduleStatus::Published, InspectionSchedule::firstOrFail()->status);
    }

    public function test_higher_officer_assigns_and_reassigns_but_cluster_officer_cannot_assign(): void
    {
        $a = $this->officer(UserLevel::Cluster, $this->cluster);
        $b = $this->officer(UserLevel::Cluster, $this->cluster);
        $district = $this->officer(UserLevel::District, $this->cluster);

        $this->plan($a, $b, $this->schools(1))->assertStatus(422)->assertJsonValidationErrors('officer_id');

        $this->plan($district, $a, $this->schools(2), 'publish')->assertOk();
        $s = InspectionSchedule::firstOrFail();
        $this->assertSame(ScheduleStatus::Published, $s->status);
        $this->assertSame($district->id, $s->created_by);

        $this->actingAs($district)->post(route('schedules.reassign', $s), ['officer_id' => $b->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($b->id, $s->fresh()->officer_id);
        $this->assertTrue(AuditLog::where('action', 'schedule_reassigned')->where('auditable_id', $s->id)->exists());

        // reassigning to an officer whose area does not cover the schools fails
        $far = $this->officer(UserLevel::Cluster, Cluster::where('block_id', $this->cluster->block_id)->where('id', '!=', $this->cluster->id)->first());
        $this->actingAs($district)->post(route('schedules.reassign', $s), ['officer_id' => $far->id])->assertSessionHasErrors('school_ids');
        $this->assertSame($b->id, $s->fresh()->officer_id);
    }

    public function test_submitting_the_inspection_marks_the_visit_and_completes_the_plan(): void
    {
        $officer = $this->officer(UserLevel::Cluster, $this->cluster);
        $block = $this->officer(UserLevel::Block, $this->cluster);
        [$first, $second] = $this->schools(2);
        $this->plan($block, $officer, [$first, $second], 'publish', today()->toDateString())->assertOk();
        $s = InspectionSchedule::firstOrFail();

        $inspection = $this->startInspection($officer, School::find($first));
        $inspection->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/T/2']);
        app(\App\Services\ScheduleService::class)->markInspected($inspection->fresh());

        $item = $s->items()->where('school_id', $first)->first();
        $this->assertSame('inspected', $item->status);
        $this->assertSame($inspection->id, $item->inspection_id);
        $this->assertSame(ScheduleStatus::Published, $s->fresh()->status);

        // skipping the last visit completes the plan
        $last = $s->items()->where('school_id', $second)->first();
        $this->actingAs($officer)->post(route('schedules.skip', $last), ['reason' => 'School closed for flood'])->assertRedirect();
        $this->assertSame(ScheduleStatus::Completed, $s->fresh()->status);

        // a plan with an inspected visit cannot be cancelled
        $this->actingAs($block)->post(route('schedules.cancel', $s), ['reason' => 'Not needed'])->assertSessionHasErrors('reason');
    }

    public function test_cancel_frees_the_schools_for_that_day(): void
    {
        $a = $this->officer(UserLevel::Cluster, $this->cluster);
        $b = $this->officer(UserLevel::Cluster, $this->cluster);
        $ids = $this->schools(1);
        $this->plan($a, $a, $ids)->assertOk();
        $s = InspectionSchedule::firstOrFail();
        $this->actingAs($a)->post(route('schedules.cancel', $s), ['reason' => 'Meeting at the district office'])->assertRedirect();
        $this->assertSame(ScheduleStatus::Cancelled, $s->fresh()->status);
        $this->assertSame(0, $s->items()->count());
        $this->plan($b, $b, $ids)->assertOk();
        // the officer can plan the same day again
        $this->plan($a, $a, [$this->schools(2)[1]])->assertOk();
        $this->assertSame(ScheduleStatus::Draft, $s->fresh()->status);
    }

    public function test_planner_school_list_flags_and_scope(): void
    {
        $a = $this->officer(UserLevel::Cluster, $this->cluster);
        $b = $this->officer(UserLevel::Cluster, $this->cluster);
        $ids = $this->schools(1);
        $this->plan($a, $a, $ids)->assertOk();

        $rows = collect($this->actingAs($b)->getJson(route('schedules.schools', ['officer' => $b->id, 'date' => today()->addDay()->toDateString()]))->assertOk()->json('rows'));
        $this->assertSame(School::where('cluster_id', $this->cluster->id)->where('status', 'active')->count(), $rows->count());
        $this->assertStringContainsString($a->name, $rows->firstWhere('id', $ids[0])['blocked']);

        // nobody can list the schools of an officer they do not manage
        $this->actingAs($a)->getJson(route('schedules.schools', ['officer' => $b->id, 'date' => today()->toDateString()]))->assertForbidden();
    }

    public function test_route_optimizer_orders_by_nearest_and_removes_crossings(): void
    {
        // points on a line east of the start, given in a scrambled order, plus one without GPS
        $start = [26.0, 91.0];
        $points = ['c' => [26.0, 91.3], 'a' => [26.0, 91.1], 'x' => null, 'd' => [26.0, 91.4], 'b' => [26.0, 91.2]];
        $this->assertSame(['a', 'b', 'c', 'd', 'x'], RouteOptimizer::order($start, $points));

        // a square visited in the crossing order is untangled into the perimeter
        $sq = [1 => [26.0, 91.0], 2 => [26.1, 91.1], 3 => [26.0, 91.1], 4 => [26.1, 91.0]];
        $order = RouteOptimizer::order([25.99, 90.99], $sq);
        $len = function ($o) use ($sq) {
            $d = 0;
            for ($i = 1; $i < count($o); $i++) {
                $d += \App\Models\Inspection::haversine(...[...$sq[$o[$i - 1]], ...$sq[$o[$i]]]);
            }

            return $d;
        };
        $this->assertLessThanOrEqual($len([1, 3, 2, 4]), $len($order));
        $this->assertSame(1, $order[0]);
    }
}
