<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Cluster;
use App\Models\MasterValue;
use App\Models\RegistrationRequest;
use App\Models\State;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Modification V9: only one active officer per cluster. */
class OneOfficerPerClusterTest extends TestCase
{
    private function payload(Cluster $cluster, array $over = []): array
    {
        return array_merge([
            'gender' => 'female',
            'date_of_birth' => '1985-06-15',
            'name' => 'Asha Devi',
            'designation_id' => MasterValue::idFor('designation', 'cluster_resource_coordinator'),
            'photo' => UploadedFile::fake()->image('photo.jpg', 300, 400),
            'level' => 'cluster',
            'state_id' => State::first()->id,
            'district_id' => $cluster->block->district_id,
            'block_id' => $cluster->block_id,
            'cluster_id' => $cluster->id,
            'mobile' => '9876543210',
            'password' => 'Strong@123',
            'password_confirmation' => 'Strong@123',
            'privacy' => '1',
        ], $over);
    }

    public function test_registration_is_refused_when_the_cluster_has_an_active_officer(): void
    {
        Storage::fake('private');
        $cluster = Cluster::orderBy('id')->first();
        $holder = $this->officer(UserLevel::Cluster, $cluster, ['name' => 'Arjun Saikia', 'mobile' => '9000001234']);

        $this->get(route('register'));
        $this->post(route('register'), $this->payload($cluster))
            ->assertSessionHasErrors(['cluster_id', 'area_taken']);

        $msg = session('errors')->first('area_taken');
        $this->assertStringContainsString('Arjun Saikia', $msg);
        $this->assertStringContainsString('******1234', $msg);
        $this->assertStringNotContainsString('9000001234', $msg, 'mobile is masked');
        $this->assertStringContainsString('contact your higher officials', $msg);
        $this->assertNull(User::where('mobile', '9876543210')->first(), 'no user created');
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration_blocked_area_taken', 'auditable_id' => $holder->id]);

        $this->get(route('register'))->assertSee('Officer already registered')->assertSee('Arjun Saikia');
    }

    public function test_registration_is_allowed_when_the_existing_officer_is_not_active(): void
    {
        Storage::fake('private');
        $cluster = Cluster::orderBy('id')->first();
        foreach ([UserStatus::Inactive, UserStatus::Rejected, UserStatus::Pending] as $i => $status) {
            $this->officer(UserLevel::Cluster, $cluster, ['status' => $status, 'mobile' => '900000100'.$i]);
        }

        $this->post(route('register'), $this->payload($cluster))->assertRedirect(route('login'));
        $this->assertSame(UserStatus::Pending, User::where('mobile', '9876543210')->first()->status);
    }

    public function test_another_cluster_and_other_levels_are_not_affected(): void
    {
        Storage::fake('private');
        $cluster = Cluster::orderBy('id')->first();
        $this->officer(UserLevel::Cluster, $cluster);
        $this->officer(UserLevel::Block, $cluster);

        $this->post(route('register'), $this->payload($this->otherCluster($cluster)))->assertRedirect(route('login'));

        // Cluster level only: State, District and Block may have several active officers
        $this->officer(UserLevel::District, $cluster);
        $this->officer(UserLevel::State, $cluster);
        $designation = MasterValue::ofType('designation')->first()->id;
        $other = ['designation_id' => $designation, 'cluster_id' => null];
        $this->post(route('register'), $this->payload($cluster, $other + ['level' => 'block', 'mobile' => '9876543211']))->assertRedirect(route('login'));
        $this->post(route('register'), $this->payload($cluster, $other + ['level' => 'district', 'block_id' => null, 'mobile' => '9876543212']))->assertRedirect(route('login'));
        $this->post(route('register'), $this->payload($cluster, $other + ['level' => 'state', 'block_id' => null, 'district_id' => null, 'mobile' => '9876543213']))->assertRedirect(route('login'));

        // and they can be approved even though an officer of that level is already active there
        $admin = $this->admin();
        foreach (['9876543211', '9876543212', '9876543213'] as $mobile) {
            $u = User::where('mobile', $mobile)->first();
            $this->assertSame(UserStatus::Pending, $u->status);
            $this->actingAs($admin)->post(route('admin.approvals.approve', RegistrationRequest::where('user_id', $u->id)->first()))
                ->assertSessionMissing('error');
            $this->assertSame(UserStatus::Active, $u->fresh()->status, "$mobile approved");
        }
    }

    public function test_approval_is_blocked_when_another_officer_became_active(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $admin = $this->admin();
        $applicant = $this->officer(UserLevel::Cluster, $cluster, ['status' => UserStatus::Pending]);
        $req = RegistrationRequest::create(['user_id' => $applicant->id, 'status' => 'pending']);
        $this->officer(UserLevel::Cluster, $cluster, ['name' => 'Arjun Saikia']);

        $this->actingAs($admin)->get(route('admin.approvals.show', $req))
            ->assertOk()->assertSee('Cannot approve: officer already active')->assertSee('Arjun Saikia');
        $this->actingAs($admin)->post(route('admin.approvals.approve', $req))->assertSessionHas('error');
        $this->assertSame(UserStatus::Pending, $applicant->fresh()->status);

        // rejecting still works
        $this->actingAs($admin)->post(route('admin.approvals.reject', $req), ['reason' => 'Cluster already has an officer'])->assertRedirect();
        $this->assertSame(UserStatus::Rejected, $applicant->fresh()->status);
    }

    public function test_reactivation_and_transfer_cannot_create_a_second_active_officer(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $other = $this->otherCluster($cluster);
        $admin = $this->admin();
        $this->officer(UserLevel::Cluster, $cluster, ['name' => 'Arjun Saikia']);
        $inactive = $this->officer(UserLevel::Cluster, $cluster, ['status' => UserStatus::Inactive]);
        $mover = $this->officer(UserLevel::Cluster, $other);

        $this->actingAs($admin)->patch(route('admin.users.status', $inactive))->assertSessionHas('error');
        $this->assertSame(UserStatus::Inactive, $inactive->fresh()->status);

        $this->actingAs($admin)->post(route('admin.users.transfer', $mover), [
            'state_id' => State::first()->id,
            'district_id' => $cluster->block->district_id,
            'block_id' => $cluster->block_id,
            'cluster_id' => $cluster->id,
            'effective_date' => now()->toDateString(),
        ])->assertSessionHas('error');
        $this->assertSame($other->id, $mover->currentJurisdiction()->first()->cluster_id);
    }
}
