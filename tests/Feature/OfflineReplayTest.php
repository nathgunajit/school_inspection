<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Cluster;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Working without network (T2): requests kept on the device are sent again when the network returns.
 * Sending the same request twice must never create duplicates.
 */
class OfflineReplayTest extends TestCase
{
    public function test_replayed_saves_do_not_duplicate(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $i = $this->startInspection($officer, $this->schoolIn($cluster));
        $this->actingAs($officer);

        // answers are an upsert by question code
        $this->post(route('inspections.answers', $i), ['code' => 'TSI_01', 'value' => 40])->assertOk();
        $this->post(route('inspections.answers', $i), ['code' => 'TSI_01', 'value' => 40])->assertOk();
        $this->assertSame(1, $i->answers()->where('question_code', 'TSI_01')->count());

        // rows with the same row_uid are added once; rows without one behave as before
        $uid = (string) Str::uuid();
        $row = ['teacher_name' => 'R. Kumar', 'teacher_type' => 'regular', 'reason' => 'CL', 'row_uid' => $uid];
        $this->postJson(route('inspections.teachers.store', $i), $row)->assertOk()->assertJsonCount(1, 'teachers');
        $this->postJson(route('inspections.teachers.store', $i), $row)->assertOk()->assertJsonCount(1, 'teachers');
        $this->postJson(route('inspections.teachers.store', $i), ['row_uid' => strtoupper($uid)] + $row)->assertJsonCount(1, 'teachers');
        $this->postJson(route('inspections.teachers.store', $i), ['row_uid' => null] + $row)->assertJsonCount(2, 'teachers');

        $cls = ['teacher_name' => 'B. Devi', 'class' => 'V', 'subject' => 'Mathematics', 'tlm_used' => 'yes', 'participation' => 'satisfactory', 'row_uid' => (string) Str::uuid()];
        $this->postJson(route('inspections.classrooms.store', $i), $cls)->assertJsonCount(1, 'classrooms');
        $this->postJson(route('inspections.classrooms.store', $i), $cls)->assertJsonCount(1, 'classrooms');

        // a bad row_uid is refused
        $this->postJson(route('inspections.teachers.store', $i), ['row_uid' => 'not-a-uuid'] + $row)->assertStatus(422);

        // the same row_uid in another inspection is a different row
        $other = $this->startInspection($officer, \App\Models\School::where('cluster_id', $cluster->id)->orderBy('id')->skip(1)->firstOrFail());
        $this->postJson(route('inspections.teachers.store', $other), $row)->assertOk()->assertJsonCount(1, 'teachers');
    }

    public function test_step_page_carries_the_offline_key(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $i = $this->startInspection($officer, $this->schoolIn($cluster));
        $this->actingAs($officer)->get(route('inspections.step', [$i, 1]))->assertOk()
            ->assertSee('"inspectionKey":"'.$i->getRouteKey().'"', false);
        $this->actingAs($officer)->get(route('inspections.review', $i))->assertOk()
            ->assertSee('data-inspection-key="'.$i->getRouteKey().'"', false);
    }
}
