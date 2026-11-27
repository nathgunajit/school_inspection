<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\MasterValue;
use App\Models\RegistrationRequest;
use App\Models\State;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationAndLoginTest extends TestCase
{
    private function payload(array $over = []): array
    {
        $cluster = Cluster::orderBy('id')->first();

        return array_merge([
            'gender' => 'female',
            'name' => 'Asha Devi',
            'designation_id' => MasterValue::idFor('designation', 'cluster_resource_coordinator'),
            'employee_id' => 'EMP-1001',
            'id_proof' => UploadedFile::fake()->create('posting-order.pdf', 300, 'application/pdf'),
            'photo' => UploadedFile::fake()->image('photo.jpg', 300, 400),
            'level' => 'cluster',
            'state_id' => State::first()->id,
            'district_id' => $cluster->block->district_id,
            'block_id' => $cluster->block_id,
            'cluster_id' => $cluster->id,
            'mobile' => '9876543210',
            'email' => 'asha@example.org',
            'password' => 'Strong@123',
            'password_confirmation' => 'Strong@123',
            'privacy' => '1',
        ], $over);
    }

    public function test_officer_can_register_at_cluster_level_and_is_pending(): void
    {
        Storage::fake('private');
        $this->post(route('register'), $this->payload())->assertRedirect(route('login'));

        $user = User::where('mobile', '9876543210')->firstOrFail();
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertSame(UserLevel::Cluster, $user->level);
        $this->assertNotNull($user->currentJurisdiction->cluster_id);
        $this->assertDatabaseHas('registration_requests', ['user_id' => $user->id, 'status' => 'pending']);
        Storage::disk('private')->assertExists($user->id_proof_path);
        Storage::disk('private')->assertExists($user->photo_path);
        $this->assertTrue(AuditLog::where('action', 'registration_submitted')->exists());
    }

    /** 27-11-2026: the employee photo can be taken as a selfie; it is posted as a JPEG in the same photo field. */
    public function test_register_page_offers_a_selfie_and_a_selfie_photo_registers(): void
    {
        $this->get(route('register'))->assertOk()
            ->assertSee('data-selfie-open', false)->assertSee('id="selfie-modal"', false)
            ->assertSee('capture="user"', false)->assertSee('Take a selfie');

        Storage::fake('private');
        $this->post(route('register'), $this->payload(['photo' => UploadedFile::fake()->image('selfie-20261127-1030.jpg', 600, 600)]))
            ->assertRedirect(route('login'));
        Storage::disk('private')->assertExists(User::where('mobile', '9876543210')->value('photo_path'));
    }

    public function test_employee_photo_is_required_and_served_only_to_permitted_users(): void
    {
        Storage::fake('private');
        $this->post(route('register'), $this->payload(['photo' => null]))->assertSessionHasErrors('photo');
        $this->post(route('register'), $this->payload(['photo' => UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf')]))
            ->assertSessionHasErrors('photo');
        // 27-11-2026: JPG or PNG only, at most 1 MB
        $this->post(route('register'), $this->payload(['photo' => UploadedFile::fake()->image('me.gif', 300, 400)]))
            ->assertSessionHasErrors(['photo' => 'The photograph must be a JPG or PNG image.']);
        $this->post(route('register'), $this->payload(['photo' => UploadedFile::fake()->image('big.png', 300, 400)->size(1100)]))
            ->assertSessionHasErrors('photo');

        $this->post(route('register'), $this->payload())->assertRedirect(route('login'));
        $user = User::where('mobile', '9876543210')->firstOrFail();
        $user->update(['status' => UserStatus::Active]);

        // The officer can see their own photo
        $this->actingAs($user)->get(route('admin.users.photo', $user))->assertOk();

        // An unrelated officer in another district cannot
        $otherCluster = Cluster::whereHas('block', fn ($q) => $q->where('district_id', '!=', $user->currentJurisdiction->district_id))->first();
        $other = User::factory()->create(['level' => UserLevel::Cluster, 'status' => UserStatus::Active, 'mobile' => '9876500000', 'employee_id' => 'EMP-OTHER']);
        $other->jurisdictions()->create([
            'state_id' => $otherCluster->block->district->state_id, 'district_id' => $otherCluster->block->district_id,
            'block_id' => $otherCluster->block_id, 'cluster_id' => $otherCluster->id, 'start_date' => now()->toDateString(),
        ]);
        $this->actingAs($other)->get(route('admin.users.photo', $user))->assertNotFound();
    }

    public function test_location_hierarchy_is_checked_on_the_server(): void
    {
        Storage::fake('private');
        $cluster = Cluster::orderBy('id')->first();
        $foreignBlock = Block::where('district_id', '!=', $cluster->block->district_id)->first();

        $this->post(route('register'), $this->payload(['block_id' => $foreignBlock->id]))
            ->assertSessionHasErrors('block_id');
        $this->assertDatabaseMissing('users', ['mobile' => '9876543210']);
    }

    public function test_password_policy_and_unique_mobile(): void
    {
        Storage::fake('private');
        User::factory()->create(['mobile' => '9876543210']);
        $this->post(route('register'), $this->payload(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertSessionHasErrors(['password', 'mobile']);
    }

    public function test_rejected_officer_can_reapply(): void
    {
        Storage::fake('private');
        $this->post(route('register'), $this->payload());
        $user = User::where('mobile', '9876543210')->first();
        $user->update(['status' => UserStatus::Rejected]);
        RegistrationRequest::where('user_id', $user->id)->update(['status' => 'rejected', 'reason' => 'Blurred ID']);

        $this->post(route('register'), $this->payload(['name' => 'Asha Devi Corrected', 'id_proof' => UploadedFile::fake()->image('id.jpg')]))
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::where('mobile', '9876543210')->count());
        $this->assertSame(UserStatus::Pending, $user->fresh()->status);
        $this->assertSame('Asha Devi Corrected', $user->fresh()->name);
        $this->assertSame(2, RegistrationRequest::where('user_id', $user->id)->count());
    }

    public function test_login_page_lists_active_demo_accounts_outside_production_only(): void
    {
        User::factory()->create(['name' => 'Demo Active Officer', 'mobile' => '9000000031', 'status' => UserStatus::Active]);
        User::factory()->status(UserStatus::Pending)->create(['name' => 'Demo Pending Officer', 'mobile' => '9000000041']);

        $this->get(route('login'))->assertOk()
            ->assertSee('Demo accounts')->assertSee('Demo Active Officer')->assertDontSee('Demo Pending Officer');

        config(['inspection.demo_logins' => false]);
        $this->get(route('login'))->assertOk()->assertDontSee('Demo Active Officer');

        config(['inspection.demo_logins' => true]);
        $this->app['env'] = 'production';
        $this->get(route('login'))->assertOk()->assertDontSee('Demo Active Officer');
    }

    public function test_pending_user_cannot_log_in(): void
    {
        $user = User::factory()->status(UserStatus::Pending)->create();
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])
            ->assertSessionHasErrors('mobile');
        $this->assertGuest();
    }

    public function test_active_user_logs_in_and_login_is_audited(): void
    {
        $user = $this->officer(UserLevel::Cluster);
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
    }

    public function test_account_locks_after_three_failed_attempts_for_twenty_minutes(): void
    {
        config(['inspection.captcha_enabled' => false]);
        $user = $this->officer(UserLevel::Cluster);
        foreach (range(1, 2) as $_) {
            $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'wrong'])
                ->assertSessionHasErrors(['mobile' => 'The mobile number or password is incorrect. After 3 wrong attempts the account is locked for 20 minutes.']);
        }
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'wrong']);
        $this->assertSame(UserStatus::Locked, $user->fresh()->status);
        $this->assertSame(3, AuditLog::where('action', 'login_failed')->count());

        // correct password is refused while locked
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertSessionHasErrors('mobile');
        $this->assertGuest();
        $this->travel(19)->minutes();
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertSessionHasErrors('mobile');
        $this->assertGuest();

        // after the 20 minute lock it works again
        $this->travel(2)->minutes();
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
    }

    public function test_captcha_is_required_from_the_third_attempt(): void
    {
        config(['inspection.captcha_enabled' => true]);
        $user = $this->officer(UserLevel::Cluster);
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'wrong'])->assertSessionDoesntHaveErrors('captcha');
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'wrong'])->assertSessionDoesntHaveErrors('captcha');
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'wrong'])->assertSessionHasErrors('captcha');
    }

    public function test_session_lasts_twenty_minutes_with_idle_warning_and_ping(): void
    {
        $this->assertSame(20, (int) config('session.lifetime'));
        $this->assertStringContainsString('SESSION_LIFETIME=20', file_get_contents(base_path('.env.example')));

        $user = $this->officer(UserLevel::Cluster);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('<meta name="session-lifetime" content="20">', false)
            ->assertSee(route('session.ping'), false);
        $this->get(route('session.ping'))->assertNoContent();

        auth()->logout();
        $this->get(route('session.ping'))->assertRedirect(route('login'));
        $this->get(route('login', ['expired' => 1]))->assertOk()->assertSee('signed out after 20 minutes without activity');
    }

    public function test_temporary_password_forces_change(): void
    {
        $user = $this->officer(UserLevel::Block, null, ['must_change_password' => true]);
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.change'));
    }

    public function test_user_cannot_change_own_level_via_profile(): void
    {
        $user = $this->officer(UserLevel::Cluster);
        // there is no route for it; the mobile change creates a request instead of changing directly
        $this->actingAs($user)->post(route('profile.mobile'), ['new_mobile' => '9123456780', 'reason' => 'New SIM', 'current' => $user->mobile])
            ->assertSessionHas('success');
        $this->assertSame($user->mobile, $user->fresh()->mobile);
        $this->assertDatabaseHas('mobile_change_requests', ['user_id' => $user->id, 'new_mobile' => '9123456780', 'status' => 'pending']);
    }

    public function test_officer_can_upload_and_replace_own_photo_from_profile(): void
    {
        Storage::fake('private');
        $user = $this->officer(UserLevel::Cluster);

        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('profile-photo-input', false)
            // redesigned profile (Modification V3): details, mobile change, activity, postings; header account menu
            ->assertSee('Personal details')->assertSee('Change mobile number')->assertSee('My inspections')->assertSee('Jurisdiction history')
            ->assertSee('Submitted this month')->assertSee('account-link', false)->assertSee('Photo, details and postings');
        $this->actingAs($user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->create('x.pdf', 50, 'application/pdf')])
            ->assertSessionHasErrors('photo');

        // 27-11-2026: JPG or PNG only, at most 1 MB
        $this->actingAs($user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('me.gif', 300, 400)])
            ->assertSessionHasErrors(['photo' => 'The photograph must be a JPG or PNG image.']);
        $this->actingAs($user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('me.webp', 300, 400)])
            ->assertSessionHasErrors('photo');
        $this->actingAs($user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('big.jpg', 300, 400)->size(1100)])
            ->assertSessionHasErrors(['photo' => 'Photograph must be 1 MB or smaller.']);
        $this->assertNull($user->fresh()->photo_path);

        $this->actingAs($user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('me.jpg', 300, 400)])
            ->assertSessionHas('success');
        $first = $user->fresh()->photo_path;
        Storage::disk('private')->assertExists($first);

        $this->actingAs($user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('me2.png', 300, 400)]);
        $second = $user->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('private')->assertMissing($first);
        Storage::disk('private')->assertExists($second);
        $this->assertTrue(AuditLog::where('action', 'photo_changed')->exists());
        $this->actingAs($user)->get(route('admin.users.photo', $user))->assertOk();
    }
}
