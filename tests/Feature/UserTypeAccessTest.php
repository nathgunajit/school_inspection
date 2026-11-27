<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Permission;
use Tests\TestCase;

/**
 * Check of every user type (Modification V5 Step 1, Product Details/PROJECT_DOCUMENT.md section 3):
 * which screens each level can open, and which inspections each level can see.
 */
class UserTypeAccessTest extends TestCase
{
    private const OFFICER_SCREENS = ['dashboard', 'profile', 'schools.index', 'inspections.mine', 'inspections.area'];

    private const ADMIN_SCREENS = ['admin.approvals.index', 'admin.users.index', 'admin.mobile-requests.index', 'admin.audit.index',
        'admin.locations.index', 'admin.schools.index', 'admin.imports.index'];

    public function test_state_admin_opens_every_screen(): void
    {
        $admin = $this->admin();
        foreach ([...self::OFFICER_SCREENS, ...self::ADMIN_SCREENS] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_officers_open_officer_screens_but_not_admin_screens(): void
    {
        foreach ([UserLevel::State, UserLevel::District, UserLevel::Block, UserLevel::Cluster] as $level) {
            $user = $this->officer($level);
            foreach (self::OFFICER_SCREENS as $route) {
                $this->actingAs($user)->get(route($route))->assertOk();
            }
            foreach (self::ADMIN_SCREENS as $route) {
                $this->actingAs($user)->get(route($route))->assertForbidden();
            }
        }
    }

    public function test_granted_rights_open_the_matching_admin_screen_only(): void
    {
        $district = $this->officer(UserLevel::District);
        $this->grant($district, Permission::APPROVE_REGISTRATIONS);
        $this->grant($district, Permission::MANAGE_USERS);

        $this->actingAs($district->fresh())->get(route('admin.approvals.index'))->assertOk();
        $this->actingAs($district->fresh())->get(route('admin.users.index'))->assertOk();
        $this->actingAs($district->fresh())->get(route('admin.audit.index'))->assertForbidden();
        // V16: the District Programmer sees the School Master of their district read-only, never the edit screens
        $this->actingAs($district->fresh())->get(route('admin.schools.index'))->assertOk()->assertDontSee(route('admin.schools.create'), false);
        $this->actingAs($district->fresh())->get(route('admin.schools.create'))->assertForbidden();
        $this->actingAs($district->fresh())->get(route('admin.imports.index'))->assertForbidden();
    }

    public function test_each_level_sees_submitted_inspections_of_its_own_area_only(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $sameBlock = Cluster::where('block_id', $cluster->block_id)->where('id', '!=', $cluster->id)->orderBy('id')->first();
        $sameDistrict = Cluster::whereHas('block', fn ($q) => $q->where('district_id', $cluster->block->district_id)
            ->where('id', '!=', $cluster->block_id))->orderBy('id')->first();
        $outside = $this->otherCluster($cluster);

        // a submitted inspection in $cluster, by a cluster officer there
        $inspector = $this->officer(UserLevel::Cluster, $cluster);
        $inspection = $this->startInspection($inspector, $this->schoolIn($cluster));
        $inspection->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/TEST/000001']);

        $expect = [
            'state admin' => [$this->admin(), true],
            'state' => [$this->officer(UserLevel::State), true],
            'district, same district' => [$this->officer(UserLevel::District, $cluster), true],
            'district, other district' => [$this->officer(UserLevel::District, $outside), false],
            'block, same block' => [$this->officer(UserLevel::Block, $cluster), true],
            'block, other block' => [$this->officer(UserLevel::Block, $sameDistrict), false],
            'cluster, same cluster' => [$this->officer(UserLevel::Cluster, $cluster), false], // 25-11-2026: same level: own inspections only
            'cluster, other cluster' => [$this->officer(UserLevel::Cluster, $sameBlock), false],
        ];

        foreach ($expect as [$user, $allowed]) {
            $report = $this->actingAs($user)->get(route('inspections.show', $inspection));
            $allowed ? $report->assertOk() : $report->assertForbidden();
            $list = $this->actingAs($user)->get(route('inspections.area', ['from' => today()->toDateString(), 'to' => today()->toDateString()]));
            $list->assertOk();
            $allowed ? $list->assertSee('SI/TEST/000001') : $list->assertDontSee('SI/TEST/000001');
        }
    }

    public function test_a_draft_is_visible_only_to_its_officer(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $inspector = $this->officer(UserLevel::Cluster, $cluster);
        $draft = $this->startInspection($inspector, $this->schoolIn($cluster));

        $this->actingAs($inspector)->get(route('inspections.show', $draft))->assertOk();
        $this->actingAs($this->officer(UserLevel::District, $cluster))->get(route('inspections.show', $draft))->assertForbidden();
        $this->actingAs($this->officer(UserLevel::Block, $cluster))->get(route('inspections.step', [$draft, 1]))->assertForbidden();
    }
}
