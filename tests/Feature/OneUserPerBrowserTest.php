<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Http\Middleware\CompactHtml;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Notice;
use App\Support\PageUser;
use Tests\TestCase;

/** 25-11-2026: only one user can be signed in at a time in one browser; smaller HTML pages. */
class OneUserPerBrowserTest extends TestCase
{
    public function test_sign_in_page_says_who_is_signed_in_and_can_switch(): void
    {
        $officer = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());

        $this->actingAs($officer)->get(route('login'))->assertOk()
            ->assertSee('Already signed in')->assertSee($officer->name)->assertSee('is already signed in on this browser')
            ->assertSee('Sign out and sign in as another user')->assertSee(route('login.switch'), false)
            ->assertDontSee('name="password"', false);

        $this->actingAs($officer)->post(route('login.switch'))->assertRedirect(route('login'))
            ->assertSessionHas('status', $officer->name.' has been signed out. You can now sign in.');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'browser_user_switched')->where('user_id', $officer->id)->exists());
        $this->get(route('login'))->assertOk()->assertSee('name="password"', false)->assertSee('name="si-guest"', false);
    }

    public function test_second_user_cannot_sign_in_over_the_first(): void
    {
        $officer = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $admin = $this->admin();

        $this->actingAs($officer)->post(route('login'), ['mobile' => $admin->mobile, 'password' => 'whatever'])
            ->assertRedirect(route('login'))->assertSessionHas('warning');
        $this->assertAuthenticatedAs($officer);
    }

    public function test_saves_from_a_page_of_another_user_are_refused(): void
    {
        $admin = $this->admin();
        $officer = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $notice = Notice::create(['type' => 'order', 'ref_no' => 'P/1', 'title' => 'Order', 'body' => 'Body', 'notice_date' => today()->toDateString(),
            'levels' => ['cluster'], 'status' => 'published', 'created_by' => $admin->id]);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="si-user" content="'.PageUser::token($admin).'">', $html);
        $this->assertStringContainsString('data-si-signout', $html);

        // a page of the officer, while the admin is now signed in on this browser
        $this->actingAs($admin)->post(route('notices.home', $notice), ['show_on_home' => '0', PageUser::FIELD => PageUser::token($officer)])
            ->assertRedirect(route('dashboard'))->assertSessionHas('warning');
        $this->assertTrue($notice->fresh()->show_on_home, 'nothing saved');

        $this->actingAs($admin)->postJson(route('notices.home', $notice), ['show_on_home' => '0'], [PageUser::HEADER => PageUser::token($officer)])
            ->assertStatus(409)->assertJson(['user_changed' => true]);
        $this->assertTrue($notice->fresh()->show_on_home);

        // the page's own user (or no token at all) is let through
        $this->actingAs($admin)->post(route('notices.home', $notice), ['show_on_home' => '0', PageUser::FIELD => PageUser::token($admin)])->assertRedirect();
        $this->assertFalse($notice->fresh()->show_on_home);
        $this->actingAs($admin)->post(route('notices.home', $notice), ['show_on_home' => '1'])->assertRedirect();
        $this->assertTrue($notice->fresh()->show_on_home);

        // signing out always works, even from an old page
        $this->actingAs($admin)->post(route('logout'), [PageUser::FIELD => PageUser::token($officer)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_html_is_sent_without_indentation_but_keeps_pre_textarea_and_script(): void
    {
        $html = "<div>\n    <p>One\n        two</p>   \n    <textarea>\n  keep\n</textarea>\n  <script>\n    if (a) {\n        b();\n    }\n</script>\n</div>";
        $this->assertSame("<div>\n<p>One\ntwo</p>\n<textarea>\n  keep\n</textarea>\n<script>\n    if (a) {\n        b();\n    }\n</script>\n</div>", CompactHtml::compact($html));

        $page = $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('~\n[ \t]+<(div|a|span|section)\b~', $page);
    }
}
