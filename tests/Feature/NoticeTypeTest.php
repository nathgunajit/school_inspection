<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Notice;
use App\Models\NoticeType;
use Tests\TestCase;

/** 25-11-2026: Notices & Orders letter types master (add / edit / make inactive). */
class NoticeTypeTest extends TestCase
{
    private function compose($sender, string $type, string $title = 'A letter')
    {
        return $this->actingAs($sender)->post(route('notices.store'), [
            'type' => $type, 'title' => $title, 'body' => 'Body of the letter.', 'notice_date' => today()->toDateString(), 'levels' => ['cluster'],
        ]);
    }

    public function test_six_default_types_including_the_new_ones(): void
    {
        $this->assertSame(['instruction', 'notification', 'order', 'publication', 'meeting_minutes', 'information'],
            NoticeType::ordered()->pluck('code')->all());
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('notices.create'))->assertOk()
            ->assertSee('Publication')->assertSee('Meeting Minutes')->assertSee('Information');
        $this->compose($admin, 'meeting_minutes', 'Minutes of the review')->assertRedirect();
        $n = Notice::firstOrFail();
        $this->assertSame('Meeting Minutes', $n->typeLabel());
        $this->get(route('notices.show', $n))->assertOk()->assertSee('--nt: #00838f', false)->assertSee('bi-people', false);
    }

    public function test_admin_adds_edits_and_deactivates_a_type(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.notice-types.index'))->assertOk()->assertSee('Meeting Minutes');
        $this->post(route('admin.notice-types.store'), ['name' => 'Circular', 'icon' => 'bi-envelope-paper', 'color' => '#AA3300', 'sort_order' => 70])->assertRedirect();
        $t = NoticeType::where('code', 'circular')->firstOrFail();
        $this->assertSame('#aa3300', $t->color);
        $this->post(route('admin.notice-types.store'), ['name' => 'circular', 'icon' => 'bi-bell', 'color' => '#000000'])->assertSessionHasErrors('name');

        $this->compose($admin, 'circular', 'First circular')->assertRedirect();
        $this->assertSame('Circular', Notice::firstOrFail()->typeLabel());

        // edit: name and colour change, the code stays
        $this->put(route('admin.notice-types.update', $t), ['name' => 'Office Circular', 'icon' => 'bi-bell', 'color' => '#123456', 'sort_order' => 5])->assertRedirect();
        $this->assertSame('circular', $t->fresh()->code);
        $this->assertSame('Office Circular', Notice::firstOrFail()->typeLabel());
        $this->put(route('admin.notice-types.update', $t), ['name' => '', 'icon' => 'bi-bell', 'color' => '#123456'])->assertSessionHasErrorsIn('edit', 'name');

        // inactive: not offered, refused on send, old notices keep the label
        $this->patch(route('admin.notice-types.status', $t))->assertRedirect();
        $this->assertFalse($t->fresh()->is_active);
        $this->get(route('notices.create'))->assertOk()->assertDontSee('value="circular"', false)->assertSee('value="order"', false); // (the flash still names it)
        $this->compose($admin, 'circular', 'Second circular')->assertSessionHasErrors('type');
        $this->assertSame('Office Circular', Notice::firstOrFail()->typeLabel());
        $this->assertSame(3, AuditLog::where('action', 'like', 'notice_type_%')->count());
    }

    public function test_only_master_managers_reach_the_list(): void
    {
        $dpo = $this->officer(UserLevel::District, Cluster::orderBy('id')->first());
        $this->actingAs($dpo)->get(route('admin.notice-types.index'))->assertForbidden();
        $this->post(route('admin.notice-types.store'), ['name' => 'X', 'icon' => 'bi-bell', 'color' => '#000000'])->assertForbidden();
    }
}
