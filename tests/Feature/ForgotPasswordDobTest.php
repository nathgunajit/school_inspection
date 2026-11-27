<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 27-11-2026: Forgot password with the registered mobile number and date of birth. */
class ForgotPasswordDobTest extends TestCase
{
    private const NEW = 'NewPass@2026';

    private const FAIL = 'The mobile number and date of birth do not match an active account.';

    private function officerWithDob(array $attrs = []): User
    {
        return $this->officer(UserLevel::Block, null, ['date_of_birth' => '1985-06-15'] + $attrs);
    }

    private function attempt(string $mobile, string $dob, array $over = [])
    {
        return $this->post(route('password.dob'), array_merge([
            'mobile' => $mobile, 'date_of_birth' => $dob, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ], $over));
    }

    public function test_page_has_the_four_fields_and_password_eyes(): void
    {
        $page = $this->get(route('password.request'))->assertOk();
        foreach (['name="mobile"', 'name="date_of_birth"', 'name="password"', 'name="password_confirmation"'] as $field) {
            $page->assertSee($field, false);
        }
        $this->assertSame(2, substr_count($page->getContent(), 'data-pw-toggle'));
        $page->assertDontSee('Send a reset link instead'); // the email option is hidden (27-11-2026)
    }

    public function test_mobile_and_date_of_birth_reset_the_password(): void
    {
        $user = $this->officerWithDob();
        $this->attempt($user->mobile, '1985-06-15')->assertSessionHasNoErrors()->assertRedirect(route('login'))->assertSessionHas('success');
        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
        $this->assertTrue(AuditLog::where('action', 'password_reset_dob')->where('auditable_id', $user->id)->exists());
    }

    public function test_every_failure_gives_the_same_message_and_changes_nothing(): void
    {
        $user = $this->officerWithDob();
        $noDob = $this->officer(UserLevel::Block, null, ['date_of_birth' => null]);
        $inactive = $this->officerWithDob(['status' => UserStatus::Inactive]);

        $this->attempt($user->mobile, '1985-06-16')->assertSessionHasErrors(['date_of_birth' => self::FAIL]);
        $this->attempt('9123400000', '1985-06-15')->assertSessionHasErrors(['date_of_birth' => self::FAIL]);
        $this->attempt($noDob->mobile, '1985-06-15')->assertSessionHasErrors(['date_of_birth' => self::FAIL]);
        $this->attempt($inactive->mobile, '1985-06-15')->assertSessionHasErrors(['date_of_birth' => self::FAIL]);
        $this->attempt($user->mobile, '1985-06-15', ['password_confirmation' => 'Other@2026'])->assertSessionHasErrors('password');

        foreach ([$user, $noDob, $inactive] as $u) {
            $this->assertFalse(Hash::check(self::NEW, $u->fresh()->password));
        }
        $this->assertSame(4, AuditLog::where('action', 'password_reset_dob_failed')->count());
    }

    public function test_attempts_are_limited_per_mobile(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = $this->officerWithDob();
        foreach (range(1, 5) as $i) {
            $this->attempt($user->mobile, '1990-01-0'.$i)->assertSessionHasErrors(['date_of_birth' => self::FAIL]);
        }
        $this->attempt($user->mobile, '1985-06-15')->assertSessionHasErrors('mobile');
        $this->assertFalse(Hash::check(self::NEW, $user->fresh()->password));
    }

    public function test_a_locked_officer_is_unlocked_by_the_reset(): void
    {
        $user = $this->officerWithDob(['status' => UserStatus::Locked, 'failed_login_attempts' => 5, 'locked_until' => now()->addHour()]);
        $this->attempt($user->mobile, '1985-06-15')->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    public function test_the_math_captcha_is_checked_first(): void
    {
        config(['inspection.login.math_captcha' => true]);
        $user = $this->officerWithDob();
        $this->get(route('password.request'))->assertOk()->assertSee('name="math_answer"', false);
        $this->attempt($user->mobile, '1985-06-15', ['math_answer' => '9999'])->assertSessionHasErrors('math_answer');
        $this->assertFalse(Hash::check(self::NEW, $user->fresh()->password));
    }
}
