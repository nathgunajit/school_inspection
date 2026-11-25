<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Notice;
use App\Models\Permission;
use App\Services\NoticeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** V14 item 1 (25-11-2026): Instruction / Notification / Order letters with read and acknowledge tracking. */
class NoticeTest extends TestCase
{
    private function send($sender, array $over = [])
    {
        return $this->actingAs($sender)->post(route('notices.store'), $over + [
            'type' => 'instruction', 'ref_no' => 'SSA/2026/1', 'title' => 'Visit 2-3 schools daily', 'body' => 'Every CRCC shall visit 2 to 3 schools every working day.',
            'notice_date' => today()->toDateString(), 'levels' => ['cluster'],
        ]);
    }

    public function test_state_admin_sends_to_cluster_officers_who_read_and_acknowledge(): void
    {
        Storage::fake('private');
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $block = $this->officer(UserLevel::Block, $cluster);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('notices.create'))->assertOk()->assertSee('Cluster Level (CRCC)');
        $this->send($admin, ['attachment' => UploadedFile::fake()->create('order.pdf', 100, 'application/pdf')])->assertRedirect();
        $notice = Notice::firstOrFail();
        $this->assertSame(['cluster'], $notice->levels);
        Storage::disk('private')->assertExists($notice->attachment_path);
        $this->assertTrue(AuditLog::where('action', 'notice_published')->exists());

        // the block officer is not in the audience
        $this->actingAs($block)->get(route('notices.index'))->assertOk()->assertDontSee('Visit 2-3 schools daily');
        $this->get(route('notices.show', $notice))->assertNotFound();
        $this->get(route('notices.attachment', $notice))->assertNotFound();

        $this->assertSame(1, app(NoticeService::class)->unreadCount($crcc));
        $this->actingAs($crcc)->get(route('dashboard'))->assertOk()->assertSee('Visit 2-3 schools daily');
        $this->get(route('notices.index'))->assertOk()->assertSee('Visit 2-3 schools daily');
        $this->get(route('notices.show', $notice))->assertOk()->assertSee('Acknowledge');
        $this->assertSame(0, app(NoticeService::class)->unreadCount($crcc));
        $this->get(route('notices.attachment', $notice))->assertOk();
        $this->post(route('notices.acknowledge', $notice))->assertRedirect();
        $this->assertNotNull($notice->reads()->where('user_id', $crcc->id)->value('acknowledged_at'));
        $this->post(route('notices.store'), [])->assertForbidden(); // officers cannot send

        $report = app(NoticeService::class)->report($notice);
        $this->assertSame([1, 1, 1], [$report['total'], $report['read'], $report['ack']]);
        $this->actingAs($admin)->get(route('notices.show', $notice))->assertOk()->assertSee('Who has read it')->assertSee($crcc->name);

        $this->post(route('notices.withdraw', $notice))->assertRedirect();
        $this->assertSame('withdrawn', $notice->fresh()->status);
        $this->actingAs($crcc)->get(route('notices.index'))->assertDontSee('Visit 2-3 schools daily');
    }

    public function test_district_sender_stays_in_own_district(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $other = $this->otherCluster($cluster);
        $sender = $this->officer(UserLevel::District, $cluster);
        $this->grant($sender, Permission::MANAGE_USERS);
        $inside = $this->officer(UserLevel::Cluster, $cluster);
        $outside = $this->officer(UserLevel::Cluster, $other);

        $this->send($sender->fresh(), ['district_id' => $other->block->district_id])->assertSessionHasErrors('district_id');
        $this->send($sender->fresh(), ['levels' => ['state']])->assertSessionHasErrors('levels'); // not below the sender
        $this->actingAs($sender->fresh())->get(route('notices.create'))->assertOk()->assertDontSee('State Level');
        $this->send($sender->fresh())->assertRedirect();
        $notice = Notice::firstOrFail();
        $this->assertSame($cluster->block->district_id, $notice->district_id);

        $this->actingAs($inside)->get(route('notices.index'))->assertSee('Visit 2-3 schools daily');
        $this->actingAs($outside)->get(route('notices.index'))->assertDontSee('Visit 2-3 schools daily');
        $this->get(route('notices.show', $notice))->assertNotFound();

        // a cluster-targeted notice reaches only that cluster
        $this->send($sender->fresh(), ['title' => 'Only one cluster', 'cluster_id' => $cluster->id])->assertRedirect();
        $this->assertSame($cluster->block_id, Notice::where('title', 'Only one cluster')->value('block_id'));
        $this->actingAs($inside)->get(route('notices.index'))->assertSee('Only one cluster');

        $this->actingAs($this->officer(UserLevel::District, $cluster))->get(route('notices.create'))->assertForbidden();
    }

    public function test_expired_notices_leave_the_inbox(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $this->send($this->admin(), ['notice_date' => today()->subDays(5)->toDateString(), 'expires_on' => today()->subDay()->toDateString()])->assertRedirect();
        $this->actingAs($crcc)->get(route('notices.index'))->assertDontSee('Visit 2-3 schools daily');
        $this->assertSame(0, app(NoticeService::class)->unreadCount($crcc));
    }
}
