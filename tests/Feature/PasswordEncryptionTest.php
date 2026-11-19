<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Services\PasswordCrypto;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** V6 Module 5: passwords are RSA-encrypted in the browser and decrypted by DecryptPasswordFields. */
class PasswordEncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.security.require_encrypted_password' => true, 'inspection.captcha_enabled' => false]);
    }

    private function crypto(): PasswordCrypto
    {
        return app(PasswordCrypto::class);
    }

    public function test_pages_carry_the_public_key_and_session_nonce(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<meta name="pw-key" content="[A-Za-z0-9+\/=]{300,}" data-nonce="[A-Za-z0-9]{32}">/', $html);
        $this->assertStringContainsString('data-nonce="'.session('pw_nonce').'"', $html);
        $this->assertStringContainsString($this->crypto()->publicKeySpki(), $html);
    }

    public function test_login_with_encrypted_password_works_and_plain_text_is_refused(): void
    {
        $user = $this->officer(UserLevel::Cluster);
        $this->get(route('login'));

        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])
            ->assertSessionHasErrors(['password' => 'Your password could not be sent securely. Please reload the page and try again.']);
        $this->assertGuest();
        $this->assertSame(0, $user->fresh()->failed_login_attempts, 'a refused plain value is not counted as a wrong password');

        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => $this->crypto()->encryptForSession('Secret@123')])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_nonce_or_damaged_value_is_refused(): void
    {
        $user = $this->officer(UserLevel::Cluster);
        $this->get(route('login'));

        foreach ([$this->crypto()->encryptForSession('Secret@123', 'another-session-nonce-0000000000'), 'enc:bm90LXJzYQ==', 'enc:'] as $bad) {
            $this->post(route('login'), ['mobile' => $user->mobile, 'password' => $bad])->assertSessionHasErrors('password');
            $this->assertGuest();
        }
    }

    public function test_change_password_with_encrypted_fields_and_no_plain_text_stored(): void
    {
        $user = $this->officer(UserLevel::Cluster);
        $this->actingAs($user)->get(route('password.change'))->assertOk();
        $c = $this->crypto();

        $this->actingAs($user)->put(route('password.change.update'), [
            'current_password' => $c->encryptForSession('Secret@123'),
            'password' => $c->encryptForSession('Newpass@2026'),
            'password_confirmation' => $c->encryptForSession('Newpass@2026'),
        ])->assertRedirect(route('dashboard'));

        $this->assertTrue(Hash::check('Newpass@2026', $user->fresh()->password));
        $this->assertFalse(AuditLog::query()->where('meta', 'like', '%Newpass%')->exists());
    }

    public function test_plain_text_is_accepted_when_not_required(): void
    {
        config(['inspection.security.require_encrypted_password' => false]);
        $user = $this->officer(UserLevel::Cluster);

        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
    }

    public function test_key_command_keeps_an_existing_pair_unless_forced(): void
    {
        $before = $this->crypto()->publicKeySpki();
        $this->artisan('security:keys')->expectsOutputToContain('already exists')->assertSuccessful();
        $this->assertSame($before, app(PasswordCrypto::class)->publicKeySpki());
    }
}
