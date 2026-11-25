<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Notice;
use App\Models\NoticeRead;
use App\Models\User;
use Tests\TestCase;

/** 25-11-2026: "Latest notices" news ticker at the top of the dashboard. */
class DashboardTickerTest extends TestCase
{
    private function notice(User $sender, string $title, array $over = []): Notice
    {
        return Notice::create($over + [
            'type' => 'order', 'ref_no' => 'T/'.uniqid(), 'title' => $title, 'body' => 'Body text of the notice.',
            'notice_date' => today()->toDateString(), 'levels' => ['cluster', 'block', 'district', 'state'],
            'status' => 'published', 'created_by' => $sender->id,
        ]);
    }

    /** Only the ticker strip, so the notices card further down does not count. */
    private function ticker(User $user): string
    {
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        return preg_match('~<section class="news-ticker".*?</section>~s', $html, $m) ? $m[0] : '';
    }

    public function test_officer_sees_live_notices_scrolling_with_new_until_read(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $admin = $this->admin();
        $this->notice($admin, 'Older camera instruction', ['notice_date' => today()->subDays(3)->toDateString()]);
        $live = $this->notice($admin, 'Monthly inspection drive');
        $this->notice($admin, 'Old expired training', ['notice_date' => today()->subDays(5)->toDateString(), 'expires_on' => today()->subDay()->toDateString()]);
        $this->notice($admin, 'Withdrawn format', ['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->notice($admin, 'Other district order', ['district_id' => $this->otherCluster($cluster)->block->district_id]);

        $strip = $this->ticker($crcc);
        $this->assertStringContainsString('Monthly inspection drive', $strip);
        // 25-11-2026: the strip shows only the text and the date
        $this->assertStringContainsString(today()->format('d M Y'), $strip);
        $this->assertStringContainsString('data-notice-unread', $strip, 'unread: opening it lowers the counters');
        foreach (['news-ticker-type', 'NEW', $live->ref_no, $admin->name] as $gone) {
            $this->assertStringNotContainsString($gone, $strip);
        }
        $this->assertStringContainsString('aria-hidden="true"', $strip, 'the list is doubled for a seamless loop');
        $this->assertSame(5, substr_count($strip, 'tabindex="-1"'), 'one notice, 6 copies, only the first focusable');
        foreach (['Older camera instruction', 'Old expired training', 'Withdrawn format', 'Other district order'] as $t) {
            $this->assertStringNotContainsString($t, $strip);
        }

        NoticeRead::create(['notice_id' => $live->id, 'user_id' => $crcc->id, 'read_at' => now()]);
        $this->assertStringNotContainsString('data-notice-unread', $this->ticker($crcc));
    }

    public function test_only_notices_ticked_for_the_homepage_scroll(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $admin = $this->admin();
        $this->notice($admin, 'Ticked older order', ['notice_date' => today()->subDays(2)->toDateString()]);
        $this->notice($admin, 'Newest but not on homepage', ['show_on_home' => false]);

        $strip = $this->ticker($crcc);
        $this->assertStringContainsString('Ticked older order', $strip);
        $this->assertStringNotContainsString('Newest but not on homepage', $strip);

        Notice::query()->update(['show_on_home' => false]);
        $this->actingAs($crcc)->get(route('dashboard'))->assertOk()->assertDontSee('news-ticker', false)
            ->assertSee('Newest but not on homepage'); // still in the Notices card and the inbox
    }

    public function test_sender_sets_the_homepage_tick_when_writing_and_later(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $admin = $this->admin();
        $post = fn (array $over) => $this->actingAs($admin)->post(route('notices.store'), $over + [
            'type' => 'order', 'title' => 'Order '.count($over), 'body' => 'Body of the order.', 'notice_date' => today()->toDateString(), 'levels' => ['cluster'],
        ])->assertRedirect();
        $post(['show_on_home' => '0']);
        $this->assertFalse(Notice::latest('id')->first()->show_on_home);
        $post(['show_on_home' => '1', 'ref_no' => 'X/1']);
        $notice = Notice::latest('id')->first();
        $this->assertTrue($notice->show_on_home);

        $this->actingAs($admin)->get(route('notices.show', $notice))->assertOk()->assertSee('Remove from homepage');
        $this->actingAs($admin)->post(route('notices.home', $notice), ['show_on_home' => '0'])->assertRedirect();
        $this->assertFalse($notice->fresh()->show_on_home);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'notice_home_changed')->exists());

        $this->actingAs($crcc)->post(route('notices.home', $notice), ['show_on_home' => '1'])->assertForbidden();
        $this->assertFalse($notice->fresh()->show_on_home);
    }

    public function test_no_strip_without_notices_and_state_admin_sees_live_ones(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('news-ticker', false);

        $this->notice($admin, 'Holiday list published');
        $this->notice($admin, 'Withdrawn format', ['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $strip = $this->ticker($admin);
        $this->assertStringContainsString('Holiday list published', $strip);
        $this->assertStringNotContainsString('Withdrawn format', $strip);
        $this->assertStringNotContainsString('NEW', $strip);
    }

    public function test_pinned_latest_notification_beats_newer_notices_and_one_per_sender(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $admin = $this->admin();
        $old = $this->notice($admin, 'Pinned older order', ['notice_date' => today()->subDays(4)->toDateString(), 'show_on_home' => false]);
        $other = $this->notice($admin, 'Second pinned order', ['notice_date' => today()->subDays(3)->toDateString()]);
        $this->notice($admin, 'Brand new order');
        $this->assertStringContainsString('Brand new order', $this->ticker($crcc));

        $this->actingAs($admin)->post(route('notices.pin', $old), ['pin' => '1'])->assertRedirect();
        $old->refresh();
        $this->assertNotNull($old->pinned_at);
        $this->assertTrue($old->show_on_home, 'a pinned notice is always on the homepage');
        $strip = $this->ticker($crcc);
        $this->assertStringContainsString('Pinned older order', $strip);
        $this->assertStringNotContainsString('Brand new order', $strip);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'notice_pinned')->exists());
        $this->actingAs($admin)->get(route('notices.index'))->assertSee('bi-pin-angle-fill', false)->assertSee('Latest');
        $this->actingAs($admin)->get(route('notices.show', $old))->assertSee('Remove Latest Notification');

        // pinning another one of the same sender replaces it
        $this->actingAs($admin)->post(route('notices.pin', $other), ['pin' => '1']);
        $this->assertNull($old->fresh()->pinned_at);
        $this->assertStringContainsString('Second pinned order', $this->ticker($crcc));

        // only the sender (or a manager) may pin
        $this->actingAs($crcc)->post(route('notices.pin', $old), ['pin' => '1'])->assertForbidden();

        // removing it brings back the newest ticked notice; taking a pinned notice off the homepage unpins it
        $this->actingAs($admin)->post(route('notices.pin', $other), ['pin' => '0']);
        $this->assertNull($other->fresh()->pinned_at);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'notice_unpinned')->exists());
        $this->assertStringContainsString('Brand new order', $this->ticker($crcc));
        $this->actingAs($admin)->post(route('notices.pin', $other), ['pin' => '1']);
        $this->actingAs($admin)->post(route('notices.home', $other), ['show_on_home' => '0']);
        $this->assertNull($other->fresh()->pinned_at);
    }

    public function test_expired_or_withdrawn_cannot_be_pinned_and_compose_can_pin(): void
    {
        $admin = $this->admin();
        $expired = $this->notice($admin, 'Expired one', ['notice_date' => today()->subDays(5)->toDateString(), 'expires_on' => today()->subDay()->toDateString()]);
        $withdrawn = $this->notice($admin, 'Withdrawn one', ['status' => 'withdrawn', 'withdrawn_at' => now()]);
        foreach ([$expired, $withdrawn] as $n) {
            $this->actingAs($admin)->post(route('notices.pin', $n), ['pin' => '1'])->assertRedirect()->assertSessionHas('error');
            $this->assertNull($n->fresh()->pinned_at);
        }

        $first = $this->notice($admin, 'Pinned earlier', ['pinned_at' => now()->subHour()]);
        $this->actingAs($admin)->post(route('notices.store'), ['type' => 'order', 'title' => 'Composed and pinned', 'body' => 'Body of the order.',
            'notice_date' => today()->subDays(2)->toDateString(), 'levels' => ['cluster'], 'show_on_home' => '0', 'pin_latest' => '1'])->assertRedirect();
        $new = Notice::where('title', 'Composed and pinned')->first();
        $this->assertNotNull($new->pinned_at);
        $this->assertTrue($new->show_on_home);
        $this->assertNull($first->fresh()->pinned_at);
        $this->assertStringContainsString('Composed and pinned', $this->ticker($admin));
    }
}
