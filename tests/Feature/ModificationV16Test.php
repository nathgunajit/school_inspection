<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\MasterValue;
use App\Models\RegistrationRequest;
use App\Models\School;
use App\Models\User;
use App\Services\ApproverResolver;
use App\Services\AreaOfficerGuard;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Modification V16 item 1 (27-11-2026): only the State Admin and District Programmers (District level) list users,
 * approve registrations and reset passwords; the Block MIS (Block level) only views the user list of their block; they see the School Master and Location Master
 * (District / Block) of their own area read-only; one District Programmer per district and one Block MIS per block.
 */
class ModificationV16Test extends TestCase
{
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cluster = Cluster::orderBy('id')->firstOrFail();
    }

    private function applicant(UserLevel $level, ?Cluster $cluster = null, array $attrs = []): User
    {
        $u = $this->officer($level, $cluster ?? $this->cluster, $attrs);
        $u->update(['status' => UserStatus::Pending]);
        RegistrationRequest::create(['user_id' => $u->id, 'status' => 'pending']);

        return $u->fresh();
    }

    /** A cluster of the same district but another block. */
    private function otherBlockCluster(): Cluster
    {
        return Cluster::whereHas('block', fn ($q) => $q->where('district_id', $this->cluster->block->district_id)->where('id', '!=', $this->cluster->block_id))
            ->orderBy('id')->firstOrFail();
    }

    public function test_only_the_state_admin_district_programmer_and_block_mis_handle_users(): void
    {
        foreach ([UserLevel::State, UserLevel::District, UserLevel::Block, UserLevel::Cluster] as $level) {
            $plain = $this->officer($level, $this->cluster);
            $this->actingAs($plain)->get(route('admin.users.index'))->assertForbidden();
            $this->get(route('admin.approvals.index'))->assertForbidden();
            $this->get(route('admin.schools.index'))->assertForbidden();
            $this->get(route('dashboard'))->assertOk()->assertDontSee(route('admin.users.index'), false);
        }
        foreach ([$this->districtProgrammer($this->cluster), $this->blockMis($this->cluster)] as $manager) {
            $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
            $this->get(route('dashboard'))->assertOk()->assertSee(route('admin.users.index'), false)->assertSee(route('admin.schools.index'), false);
        }
        $this->actingAs($this->districtProgrammer($this->cluster))->get(route('admin.approvals.index'))->assertOk();
        // the Block MIS only views the user list: no approvals
        $this->actingAs($this->blockMis($this->cluster))->get(route('admin.approvals.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('admin.approvals.index'), false);
        $this->assertTrue(Gate::forUser($this->districtProgrammer($this->otherCluster($this->cluster)))->allows('configure_coverage'));
        $this->assertFalse(Gate::forUser($this->officer(UserLevel::District, $this->cluster))->allows('configure_coverage'));
        $this->assertFalse(Gate::forUser($this->blockMis($this->otherCluster($this->cluster)))->allows('configure_coverage'));
    }

    public function test_approval_chain(): void
    {
        $r = app(ApproverResolver::class);
        $dp = $this->districtProgrammer($this->cluster);
        $mis = $this->blockMis($this->cluster);
        $farDp = $this->districtProgrammer($this->otherCluster($this->cluster));

        $block = $this->applicant(UserLevel::Block, $this->otherBlockCluster());
        $this->assertTrue($r->canApprove($dp, $block), 'Block: the District Programmer of that district');
        $this->assertFalse($r->canApprove($farDp, $block), 'not another district\'s');
        $this->assertFalse($r->canApprove($mis, $block), 'not a Block MIS');

        $cluster = $this->applicant(UserLevel::Cluster, $this->cluster);
        $this->assertTrue($r->canApprove($dp, $cluster), 'Cluster: the District Programmer, even where the block has a Block MIS');
        $this->assertFalse($r->canApprove($mis, $cluster), 'the Block MIS only views the user list');
        $this->assertFalse($r->canApprove($farDp, $cluster));

        $this->assertFalse($r->canApprove($dp, $this->applicant(UserLevel::District, $this->cluster)), 'District: State Admin only');
        $this->assertTrue($r->canApprove($this->admin(), $this->applicant(UserLevel::District, $this->cluster)));

        $this->actingAs($mis)->post(route('admin.approvals.approve', RegistrationRequest::where('user_id', $cluster->id)->first()))->assertForbidden();
        $this->actingAs($dp)->post(route('admin.approvals.approve', RegistrationRequest::where('user_id', $cluster->id)->first()))->assertRedirect();
        $this->assertSame(UserStatus::Active, $cluster->fresh()->status);
    }

    public function test_user_list_and_password_reset_stay_inside_the_area(): void
    {
        $dp = $this->districtProgrammer($this->cluster);
        $mis = $this->blockMis($this->cluster);
        $crcc = $this->officer(UserLevel::Cluster, $this->cluster, ['name' => 'Crcc Here']);
        $otherBlockCrcc = $this->officer(UserLevel::Cluster, $this->otherBlockCluster(), ['name' => 'Crcc Next Block']);
        $beo = $this->officer(UserLevel::Block, $this->otherBlockCluster(), ['name' => 'Beo Next Block']);
        $deo = $this->officer(UserLevel::District, $this->cluster, ['name' => 'Deo Same District']);
        $far = $this->officer(UserLevel::Cluster, $this->otherCluster($this->cluster), ['name' => 'Crcc Far Away']);

        $this->actingAs($dp)->get(route('admin.users.index'))->assertOk()
            ->assertSee('Crcc Here')->assertSee('Crcc Next Block')->assertSee('Beo Next Block')
            ->assertDontSee('Deo Same District')->assertDontSee('Crcc Far Away');
        $this->actingAs($mis)->get(route('admin.users.index'))->assertOk()
            ->assertSee('Crcc Here')->assertDontSee('Crcc Next Block')->assertDontSee('Beo Next Block')->assertDontSee('Crcc Far Away');

        $this->actingAs($dp)->post(route('admin.users.reset-password', $beo))->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($beo->fresh()->must_change_password);
        $this->post(route('admin.users.reset-password', $deo))->assertForbidden();
        $this->post(route('admin.users.reset-password', $far))->assertForbidden();
        // the Block MIS only views: the officer page opens, without Reset password
        $this->actingAs($mis)->get(route('admin.users.show', $crcc))->assertOk()->assertDontSee(route('admin.users.reset-password', $crcc), false);
        $this->post(route('admin.users.reset-password', $crcc))->assertForbidden();
        $this->post(route('admin.users.reset-password', $otherBlockCrcc))->assertForbidden();
        $this->actingAs($mis)->get(route('admin.users.show', $otherBlockCrcc))->assertForbidden();

        // list, approve and reset only: no edit, status or transfer
        $this->actingAs($dp)->patch(route('admin.users.status', $crcc))->assertForbidden();
        $this->put(route('admin.users.update', $crcc), ['name' => 'Changed', 'designation_id' => $crcc->designation_id])->assertForbidden();
        $this->get(route('admin.users.show', $crcc))->assertOk()->assertSee(route('admin.users.reset-password', $crcc), false)
            ->assertDontSee(route('admin.users.status', $crcc), false)->assertDontSee(route('admin.users.transfer', $crcc), false);
    }

    public function test_masters_are_read_only_and_limited_to_the_area(): void
    {
        $dp = $this->districtProgrammer($this->cluster);
        $mis = $this->blockMis($this->cluster);
        $mine = $this->schoolIn($this->cluster);
        $nextBlock = $this->schoolIn($this->otherBlockCluster());
        $far = $this->schoolIn($this->otherCluster($this->cluster));
        $farDistrict = $this->otherCluster($this->cluster)->block->district;

        $this->actingAs($dp)->get(route('admin.schools.index'))->assertOk()
            ->assertSee($mine->name)->assertSee($nextBlock->name)->assertDontSee($far->name)
            ->assertDontSee(route('admin.schools.create'), false)->assertDontSee(route('admin.schools.edit', $mine), false);
        $this->get(route('admin.schools.index', ['district_id' => $farDistrict->id]))->assertOk()->assertDontSee($far->name); // the filter cannot leave the district
        $this->actingAs($mis)->get(route('admin.schools.index'))->assertOk()->assertSee($mine->name)->assertDontSee($nextBlock->name)->assertDontSee($far->name);

        $ownBlock = $this->cluster->block;
        $nextBlockModel = $this->otherBlockCluster()->block;
        $this->actingAs($dp)->get(route('admin.locations.index', 'blocks'))->assertOk()
            ->assertSee($ownBlock->name)->assertSee($nextBlockModel->name)->assertDontSee('Add block')->assertDontSee($farDistrict->blocks()->first()->name);
        $this->get(route('admin.locations.index', 'districts'))->assertOk()->assertDontSee($farDistrict->name);
        $this->get(route('admin.locations.index', 'clusters'))->assertForbidden();
        $this->get(route('admin.locations.index', 'states'))->assertForbidden();
        $this->post(route('admin.locations.store', 'blocks'), ['district_id' => $ownBlock->district_id, 'code' => '999999', 'name' => 'New'])->assertForbidden();
        $this->get(route('admin.schools.edit', $mine))->assertForbidden();
        $this->actingAs($mis)->get(route('admin.locations.index', 'blocks'))->assertOk()->assertSee($ownBlock->name)->assertDontSee($nextBlockModel->name);

        $this->actingAs($dp)->get(route('admin.schools.export-pdf'))->assertOk();
    }

    public function test_one_district_programmer_per_district_and_one_block_mis_per_block(): void
    {
        $guard = app(AreaOfficerGuard::class);
        $dp = $this->districtProgrammer($this->cluster);
        $mis = $this->blockMis($this->cluster);
        $dpId = MasterValue::idFor('designation', User::DISTRICT_PROGRAMMER);
        $misId = MasterValue::idFor('designation', User::BLOCK_MIS);
        $loc = ['district_id' => $this->cluster->block->district_id, 'block_id' => $this->cluster->block_id];

        $this->assertSame($dp->id, $guard->holder(UserLevel::District, $loc, null, $dpId)?->id);
        $this->assertSame($mis->id, $guard->holder(UserLevel::Block, $loc, null, $misId)?->id);
        $this->assertNull($guard->holder(UserLevel::District, $loc, null, MasterValue::idFor('designation', 'district_education_officer')), 'other District officers share the district');
        $this->assertNull($guard->holder(UserLevel::Block, ['block_id' => $this->otherBlockCluster()->block_id], null, $misId), 'another block is free');
        $this->assertStringContainsString('Only one District Programmer can be registered for a district', $guard->message($dp, $loc));

        // approval of a second one is refused, naming the first
        $second = $this->applicant(UserLevel::District, $this->cluster, ['designation_id' => $dpId]);
        $this->actingAs($this->admin())->post(route('admin.approvals.approve', RegistrationRequest::where('user_id', $second->id)->first()))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(UserStatus::Pending, $second->fresh()->status);

        // the admin cannot make a second Block MIS in the block by editing the designation
        $beo = $this->officer(UserLevel::Block, $this->cluster);
        $this->put(route('admin.users.update', $beo), ['name' => $beo->name, 'designation_id' => $misId])->assertSessionHas('error');
        $this->assertNotSame($misId, (int) $beo->fresh()->designation_id);

        // once the first is inactive, the area is free
        $dp->update(['status' => UserStatus::Inactive]);
        $this->post(route('admin.approvals.approve', RegistrationRequest::where('user_id', $second->id)->first()))->assertRedirect();
        $this->assertSame(UserStatus::Active, $second->fresh()->status);
    }

    public function test_old_grants_no_longer_give_user_management(): void
    {
        $deo = $this->officer(UserLevel::District, $this->cluster);
        $deo->directPermissions()->attach(\App\Models\Permission::where('slug', 'manage_users')->value('id'), ['granted_at' => now()]);
        $this->actingAs($deo->fresh())->get(route('admin.users.index'))->assertForbidden();
        $this->assertSame([], \App\Services\PermissionService::grantableFor(UserLevel::District));
        $this->assertFalse(Gate::forUser($deo->fresh())->allows('manage_holidays'));
    }
}
