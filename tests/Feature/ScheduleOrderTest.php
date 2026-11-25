<?php

namespace Tests\Feature;

use App\Enums\ScheduleStatus;
use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\InspectionSchedule;
use App\Models\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** V14 items 2 and 4 (25-11-2026): visits assigned with an attached order; visits left planned show as Pending. */
class ScheduleOrderTest extends TestCase
{
    public function test_higher_officer_assigns_visits_with_an_order_the_crcc_can_open(): void
    {
        Storage::fake('private');
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $block = $this->officer(UserLevel::Block, $cluster);
        $ids = School::where('cluster_id', $cluster->id)->orderBy('id')->take(2)->pluck('id')->all();

        $this->actingAs($block)->post(route('schedules.store'), [
            'officer_id' => $crcc->id, 'date' => today()->addDay()->toDateString(), 'school_ids' => $ids, 'action' => 'publish',
            'order_ref' => 'BEEO/DOT/2026/7', 'attachment' => UploadedFile::fake()->create('order.pdf', 80, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $s = InspectionSchedule::firstOrFail();
        $this->assertSame(ScheduleStatus::Published, $s->status);
        $this->assertSame('BEEO/DOT/2026/7', $s->order_ref);
        $this->assertSame('order.pdf', $s->attachment_name);
        Storage::disk('private')->assertExists($s->attachment_path);
        $this->assertTrue(AuditLog::where('action', 'schedule_order_attached')->where('auditable_id', $s->id)->exists());

        $this->actingAs($crcc)->get(route('schedules.show', $s))->assertOk()->assertSee('Assigned by')->assertSee($block->name)->assertSee('BEEO/DOT/2026/7');
        $this->get(route('schedules.attachment', $s))->assertOk();
        $this->assertTrue(AuditLog::where('action', 'schedule_order_viewed')->exists());

        // someone outside cannot open the order
        $outsider = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));
        $this->actingAs($outsider)->get(route('schedules.attachment', $s))->assertNotFound();

        // a bad file type is refused
        $this->actingAs($block)->post(route('schedules.store'), [
            'officer_id' => $crcc->id, 'date' => today()->addDays(2)->toDateString(), 'school_ids' => $ids, 'action' => 'publish',
            'attachment' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('attachment');

        // the JSON body without a file still works (offline / older clients)
        $this->actingAs($crcc)->postJson(route('schedules.store'), [
            'officer_id' => $crcc->id, 'date' => today()->addDays(3)->toDateString(), 'school_ids' => $ids, 'action' => 'save',
        ])->assertOk();
        $this->assertNull(InspectionSchedule::where('schedule_date', today()->addDays(3)->toDateString())->value('attachment_path'));
    }

    public function test_visits_left_planned_after_their_day_show_as_pending(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $ids = School::where('cluster_id', $cluster->id)->orderBy('id')->take(1)->pluck('id')->all();
        $this->actingAs($crcc)->postJson(route('schedules.store'), [
            'officer_id' => $crcc->id, 'date' => today()->addDay()->toDateString(), 'school_ids' => $ids, 'action' => 'submit',
        ])->assertOk();
        $s = InspectionSchedule::firstOrFail();
        $s->forceFill(['status' => ScheduleStatus::Published, 'schedule_date' => today()->subDay()->toDateString()])->save();
        $s->items()->update(['schedule_date' => today()->subDay()->toDateString()]);

        $this->assertTrue($s->fresh()->items->first()->isPending());
        $this->get(route('schedules.show', $s))->assertOk()->assertSee('Pending');
        $this->get(route('schedules.index', ['view' => 'week', 'date' => today()->subDay()->toDateString(), 'status' => 'pending']))->assertOk()->assertSee($crcc->name);
        $this->get(route('schedules.index', ['view' => 'week', 'date' => today()->subDay()->toDateString(), 'status' => 'completed']))->assertOk()->assertDontSee('1 school ·');
    }

    /** V14 item 2: self-planning is open to every officer level that inspects. */
    public function test_every_officer_level_plans_its_own_visits(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $ids = School::where('cluster_id', $cluster->id)->orderBy('id')->take(1)->pluck('id')->all();
        foreach ([UserLevel::State, UserLevel::District, UserLevel::Block, UserLevel::Cluster] as $n => $level) {
            $u = $this->officer($level, $cluster);
            $this->actingAs($u)->postJson(route('schedules.store'), [
                'officer_id' => $u->id, 'date' => today()->addDays(10 + $n)->toDateString(), 'school_ids' => $ids, 'action' => 'save',
            ])->assertOk();
        }
        $this->assertSame(4, InspectionSchedule::count());
    }
}
