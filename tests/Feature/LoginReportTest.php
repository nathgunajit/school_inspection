<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Cluster;
use App\Models\UserLoginLog;
use App\Support\UserAgent;
use Tests\TestCase;

/** V6 Module 6: login user monitoring report for the State Administrator. */
class LoginReportTest extends TestCase
{
    private const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 13; SM-A515F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.captcha_enabled' => false]);
    }

    public function test_sign_in_activity_and_sign_out_are_recorded(): void
    {
        $user = $this->officer(UserLevel::Cluster);

        $this->withHeader('User-Agent', self::CHROME_ANDROID)
            ->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
        $log = UserLoginLog::where('user_id', $user->id)->sole();
        $this->assertSame('success', $log->status);
        $this->assertSame(['Chrome 120', 'Android 13', 'mobile'], [$log->browser, $log->platform, $log->device]);
        $this->assertNotNull($log->session_id);
        $this->assertSame('online', $log->sessionState());

        // activity is written at most once a minute
        $this->travel(2)->minutes();
        $this->get(route('dashboard'))->assertOk();
        $this->assertTrue($log->fresh()->last_seen_at->gt($log->login_at));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $log->refresh();
        $this->assertNotNull($log->logout_at);
        $this->assertSame('manual', $log->logout_reason);
        $this->assertSame('signed_out', $log->sessionState());
        $this->assertSame(2, $log->durationMinutes());
    }

    public function test_refused_attempts_are_recorded_with_their_reason(): void
    {
        $user = $this->officer(UserLevel::Cluster);
        $pending = $this->officer(UserLevel::Cluster, null, ['status' => UserStatus::Pending]);

        $this->post(route('login'), ['mobile' => '9876500000', 'password' => 'x']);
        $this->post(route('login'), ['mobile' => $pending->mobile, 'password' => 'Secret@123']);
        foreach (range(1, 3) as $_) {
            $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'wrong']);
        }
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123']); // while locked

        $rows = UserLoginLog::orderBy('id')->get(['user_id', 'mobile', 'status', 'reason'])->map->only('user_id', 'status', 'reason')->all();
        $this->assertSame([
            ['user_id' => null, 'status' => 'failed', 'reason' => 'Unknown mobile number'],
            ['user_id' => $pending->id, 'status' => 'denied', 'reason' => 'Account pending'],
            ['user_id' => $user->id, 'status' => 'failed', 'reason' => 'Wrong password'],
            ['user_id' => $user->id, 'status' => 'failed', 'reason' => 'Wrong password'],
            ['user_id' => $user->id, 'status' => 'locked', 'reason' => 'Locked after 3 failures'],
            ['user_id' => $user->id, 'status' => 'locked', 'reason' => 'Account locked'],
        ], $rows);
        $this->assertSame('9876500000', UserLoginLog::orderBy('id')->first()->mobile);
    }

    public function test_report_shows_summary_and_filters_for_the_state_admin_only(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $other = Cluster::whereHas('block', fn ($q) => $q->where('district_id', '!=', $cluster->block->district_id))->orderBy('id')->first();
        $a = $this->officer(UserLevel::Cluster, $cluster, ['name' => 'Alpha Officer']);
        $b = $this->officer(UserLevel::Block, $other, ['name' => 'Bravo Officer']);
        $this->officer(UserLevel::Cluster, $cluster, ['name' => 'Charlie Never']);

        foreach ([$a, $b] as $u) {
            $this->post(route('login'), ['mobile' => $u->mobile, 'password' => 'Secret@123']);
            $this->post(route('logout'));
        }
        $this->post(route('login'), ['mobile' => $a->mobile, 'password' => 'bad']);
        UserLoginLog::create(['user_id' => $a->id, 'mobile' => $a->mobile, 'status' => 'success', 'login_at' => now()->subDays(3)]);

        $admin = $this->admin();
        $res = $this->actingAs($admin)->get(route('admin.login-report.index'))->assertOk();
        $summary = $res->viewData('summary');
        $this->assertSame(2, $summary['logins'], 'today only by default');
        $this->assertSame(2, $summary['users']);
        $this->assertSame(1, $summary['refused']);
        $this->assertSame(1, $summary['never']);
        $this->assertSame($a->mobile, $summary['top_refused']->first()->mobile);
        $res->assertSee('Alpha Officer')->assertSee('Bravo Officer')->assertSee('Wrong password');

        $f = fn (array $q) => $this->actingAs($admin)->get(route('admin.login-report.index', $q))->assertOk()->viewData('logs')->pluck('user_id')->unique()->values()->all();
        $this->assertSame([$b->id], $f(['level' => 'block']));
        $this->assertSame([$a->id], $f(['district_id' => $cluster->block->district_id]));
        $this->assertSame([$a->id], $f(['status' => 'failed']));
        $this->assertSame([$b->id], $f(['q' => 'Bravo']));
        $this->assertCount(3, $this->actingAs($admin)->get(route('admin.login-report.index', ['from' => now()->subWeek()->toDateString(), 'level' => 'cluster']))->viewData('logs'));
        $this->actingAs($admin)->get(route('admin.login-report.index', ['status' => 'nonsense']))->assertSessionHasErrors('status');

        $this->actingAs($admin)->get(route('admin.login-report.user', $a))->assertOk()->assertSee('Alpha Officer')->assertSee('Sign-in history')->assertSee('Wrong password');
        $export = $this->actingAs($admin)->get(route('admin.login-report.export'))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $export->headers->get('content-type'));

        foreach ([UserLevel::State, UserLevel::District] as $level) {
            $officer = $this->officer($level);
            $this->actingAs($officer)->get(route('admin.login-report.index'))->assertForbidden();
            $this->actingAs($officer)->get(route('admin.login-report.export'))->assertForbidden();
            $this->actingAs($officer)->get(route('admin.login-report.user', $a))->assertForbidden();
        }
    }

    public function test_online_now_counts_open_recent_sessions(): void
    {
        $a = $this->officer(UserLevel::Cluster);
        $b = $this->officer(UserLevel::Cluster);
        UserLoginLog::create(['user_id' => $a->id, 'status' => 'success', 'login_at' => now()->subMinutes(5), 'last_seen_at' => now()->subMinute()]);
        UserLoginLog::create(['user_id' => $b->id, 'status' => 'success', 'login_at' => now()->subHours(2), 'last_seen_at' => now()->subHours(2)]);
        UserLoginLog::create(['user_id' => $b->id, 'status' => 'success', 'login_at' => now()->subMinutes(3), 'logout_at' => now()->subMinute()]);

        $res = $this->actingAs($this->admin())->get(route('admin.login-report.index', ['status' => 'online']))->assertOk();
        $this->assertSame(1, $res->viewData('summary')['online']);
        $this->assertSame([$a->id], $res->viewData('logs')->pluck('user_id')->all());
        $this->assertSame('timed_out', UserLoginLog::where('user_id', $b->id)->orderBy('id')->first()->sessionState());
    }

    public function test_user_agent_reader(): void
    {
        $this->assertSame(['browser' => 'Chrome 120', 'platform' => 'Android 13', 'device' => 'mobile'], UserAgent::parse(self::CHROME_ANDROID));
        $this->assertSame(['browser' => 'Edge 131', 'platform' => 'Windows', 'device' => 'desktop'],
            UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0'));
        $this->assertSame(['browser' => 'Safari 17', 'platform' => 'iOS 17', 'device' => 'tablet'],
            UserAgent::parse('Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'));
        $this->assertSame(['browser' => null, 'platform' => null, 'device' => null], UserAgent::parse(null));
    }
}
