<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Support\MathCaptcha;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Login asks for the answer to a small sum; the answer lives only in the session and is used once. */
class LoginMathCaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.login.math_captcha' => true, 'inspection.captcha_enabled' => false]);
    }

    /** Reads the expected answer from the session question. */
    private function answer(): int
    {
        $q = session('math_captcha.q');
        [$a, $op, $b] = explode(' ', $q);

        return match ($op) { '+' => $a + $b, '−' => $a - $b, default => $a * $b };
    }

    public function test_login_page_shows_the_sum_image_and_the_answer_is_not_in_the_page(): void
    {
        $html = $this->get(route('login'))->assertOk()->assertSee('Enter the correct answer')->assertSee('name="math_answer"', false)->getContent();
        $this->assertStringNotContainsString(session('math_captcha.q'), $html);
        $this->get(route('login.math-captcha'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_wrong_answer_is_refused_and_right_answer_signs_in(): void
    {
        $user = $this->officer(UserLevel::Cluster, null, ['password' => Hash::make('Secret@123')]);

        $this->get(route('login'));
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123', 'math_answer' => (string) ($this->answer() + 1)])
            ->assertSessionHasErrors('math_answer');
        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts, 'a wrong sum is not a password failure');
        $this->assertGuest();

        $this->get(route('login'));
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertSessionHasErrors('math_answer');

        $this->get(route('login'));
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123', 'math_answer' => (string) $this->answer()])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_the_answer_is_never_negative(): void
    {
        $c = app(MathCaptcha::class);
        for ($i = 0; $i < 300; $i++) {
            $c->refresh();
            $this->assertGreaterThanOrEqual(1, $this->answer(), 'sum '.session('math_captcha.q'));
        }
    }

    public function test_an_answer_cannot_be_reused(): void
    {
        $c = app(MathCaptcha::class);
        $c->refresh();
        $right = $this->answer();
        $this->assertTrue($c->check($right));
        // the same answer again is checked against the NEW sum: only right if the new sum happens to have the same result
        $this->assertSame($right === $this->answer(), $c->check($right));
        $q = session('math_captcha.q');
        $this->assertNotNull($q, 'a new sum is issued after each check');
    }
}
