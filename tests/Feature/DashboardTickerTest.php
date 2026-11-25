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
        $this->assertStringContainsString('NEW', $strip);
        $this->assertStringContainsString('aria-hidden="true"', $strip, 'the list is doubled for a seamless loop');
        $this->assertSame(5, substr_count($strip, 'tabindex="-1"'), 'one notice, 6 copies, only the first focusable');
        foreach (['Older camera instruction', 'Old expired training', 'Withdrawn format', 'Other district order'] as $t) {
            $this->assertStringNotContainsString($t, $strip);
        }

        NoticeRead::create(['notice_id' => $live->id, 'user_id' => $crcc->id, 'read_at' => now()]);
        $this->assertStringNotContainsString('NEW', $this->ticker($crcc));
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
}
