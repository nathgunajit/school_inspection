<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Cluster;
use App\Models\Permission;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Services\ApproverResolver;
use Tests\TestCase;

class ApprovalAndUserManagementTest extends TestCase
{
    private function applicant(UserLevel $level, ?Cluster $cluster = null): User
    {
        $u = $this->officer($level, $cluster);
        $u->update(['status' => UserStatus::Pending]);
        RegistrationRequest::create(['user_id' => $u->id, 'status' => 'pending']);

        return $u->fresh();
    }

    public function test_approval_chain_for_cluster_applicant(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $applicant = $this->applicant(UserLevel::Cluster, $cluster);
        $resolver = app(ApproverResolver::class);

        $admin = $this->admin();
        $district = $this->officer(UserLevel::District, $cluster);
        $block = $this->officer(UserLevel::Block, $cluster);
        $otherBlock = $this->officer(UserLevel::Block, $this->otherCluster($cluster));

        $this->assertTrue($resolver->canApprove($admin, $applicant));
        $this->assertFalse($resolver->canApprove($block, $applicant), 'block without permission');

        // V16: the District Programmer approves Cluster registrations; the Block MIS only views the user list
        $this->assertFalse($resolver->canApprove($district, $applicant), 'an ordinary District officer');
        $this->grant($district, Permission::APPROVE_REGISTRATIONS); // becomes the District Programmer
        $this->grant($block, Permission::MANAGE_USERS);             // becomes the Block MIS
        app()->forgetScopedInstances(); // drop the per-request permission cache
        $r = app(ApproverResolver::class);
        $this->assertTrue($r->canApprove($district->fresh(), $applicant), 'even though the block has a Block MIS');
        $this->assertFalse($r->canApprove($block->fresh(), $applicant), 'the Block MIS does not approve');
        $this->assertFalse($r->canApprove($otherBlock, $applicant), 'outside jurisdiction');
    }

    public function test_user_can_never_approve_self(): void
    {
        $admin = $this->admin();
        $this->assertFalse(app(ApproverResolver::class)->canApprove($admin, $admin));
    }

    public function test_district_and_state_applicants(): void
    {
        // V16 (27-11-2026): District and State applicants are approved by the State Admin only
        $r = app(ApproverResolver::class);
        $state = $this->officer(UserLevel::State);
        $this->assertFalse($r->canApprove($state, $this->applicant(UserLevel::District)), 'a State officer no longer approves District registrations');
        $this->assertFalse($r->canApprove($state, $this->applicant(UserLevel::State)), 'State applicants: State Admin only');
        $this->assertFalse($r->canApprove($this->districtProgrammer(), $this->applicant(UserLevel::District)), 'nor a District Programmer');
        $this->assertTrue($r->canApprove($this->admin(), $this->applicant(UserLevel::District)));
    }

    public function test_admin_approves_and_rejects_registrations(): void
    {
        $admin = $this->admin();
        $a = $this->applicant(UserLevel::Cluster);
        $b = $this->applicant(UserLevel::Block);
        $reqA = RegistrationRequest::where('user_id', $a->id)->first();
        $reqB = RegistrationRequest::where('user_id', $b->id)->first();

        $this->actingAs($admin)->get(route('admin.approvals.index'))->assertOk()->assertSee($a->name)->assertSee($b->name);
        $this->actingAs($admin)->post(route('admin.approvals.approve', $reqA))->assertRedirect();
        $this->assertSame(UserStatus::Active, $a->fresh()->status);

        $this->actingAs($admin)->post(route('admin.approvals.reject', $reqB), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.approvals.reject', $reqB), ['reason' => 'ID proof unreadable'])->assertRedirect();
        $this->assertSame(UserStatus::Rejected, $b->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration_rejected']);
    }

    public function test_officer_without_permission_cannot_open_approvals(): void
    {
        $this->actingAs($this->officer(UserLevel::Block))->get(route('admin.approvals.index'))->assertForbidden();
    }

    public function test_admin_deactivates_transfers_and_resets_password(): void
    {
        $admin = $this->admin();
        $cluster = Cluster::orderBy('id')->first();
        $user = $this->officer(UserLevel::Cluster, $cluster);
        $target = $this->otherCluster($cluster);

        $this->actingAs($admin)->patch(route('admin.users.status', $user))->assertRedirect();
        $this->assertSame(UserStatus::Inactive, $user->fresh()->status);

        $this->actingAs($admin)->post(route('admin.users.transfer', $user), [
            'state_id' => $target->block->district->state_id,
            'district_id' => $target->block->district_id,
            'block_id' => $target->block_id,
            'cluster_id' => $target->id,
            'effective_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame($target->id, $user->fresh()->currentJurisdiction->cluster_id);
        $this->assertSame(2, $user->jurisdictions()->count());
        $this->assertNotNull($user->jurisdictions()->where('cluster_id', $cluster->id)->first()->end_date);

        $this->actingAs($admin)->post(route('admin.users.reset-password', $user))->assertSessionHas('success');
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_district_with_manage_permission_sees_only_own_district_users(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $district = $this->officer(UserLevel::District, $cluster);
        $this->grant($district, Permission::MANAGE_USERS);
        $mine = $this->officer(UserLevel::Cluster, $cluster);
        $other = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));

        $this->actingAs($district->fresh())->get(route('admin.users.index'))->assertOk()->assertSee($mine->name)->assertDontSee($other->name);
        $this->actingAs($district->fresh())->get(route('admin.users.show', $other))->assertForbidden();
        // edit details is State Admin only
        $this->actingAs($district->fresh())->put(route('admin.users.update', $mine), ['name' => 'X'])->assertForbidden();
    }
}
