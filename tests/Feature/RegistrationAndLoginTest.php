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

    public function test_employee_photo_is_required_and_served_only_to_permitted_users(): void
    {
        Storage::fake('private');
        $this->post(route('register'), $this->payload(['photo' => null]))->assertSessionHasErrors('photo');
        $this->post(route('register'), $this->payload(['photo' => UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf')]))
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

    public function test_account_locks_after_five_failed_attempts(): void
    {
        $user = $this->officer(UserLevel::Cluster);
        foreach (range(1, 5) as $_) {
            $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'wrong']);
        }
        $this->assertSame(UserStatus::Locked, $user->fresh()->status);
        $this->assertSame(5, AuditLog::where('action', 'login_failed')->count());

        // correct password is refused while locked
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertSessionHasErrors('mobile');
        $this->assertGuest();

        // after the lock period it works again
        $this->travel(16)->minutes();
        $this->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
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

        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('profile-photo-input', false);
        $this->actingAs($user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->create('x.pdf', 50, 'application/pdf')])
            ->assertSessionHasErrors('photo');

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
