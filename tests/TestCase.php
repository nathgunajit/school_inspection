<?php

namespace Tests;

use App\Enums\UserLevel;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\Inspection;
use App\Models\Permission;
use App\Models\School;
use App\Models\State;
use App\Models\User;
use App\Models\UserJurisdiction;
use Database\Seeders\DemoLocationSchoolSeeder;
use Database\Seeders\MasterValueSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Safety guard: never let RefreshDatabase run against a real database
     * (e.g. when `php artisan config:cache` made the tests ignore phpunit.xml).
     */
    protected function beforeRefreshingDatabase()
    {
        $conn = config('database.default');
        $db = config("database.connections.$conn.database");
        if ($conn !== 'sqlite' || $db !== ':memory:') {
            fwrite(STDERR, "\nRefusing to run tests against [$conn:$db]. Run `php artisan config:clear` first.\n");
            exit(1);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, MasterValueSeeder::class, DemoLocationSchoolSeeder::class]);
    }

    /**
     * Create an active officer at a level, placed in the first location of each kind
     * (or the given cluster).
     */
    protected function officer(UserLevel $level, ?Cluster $cluster = null, array $attrs = []): User
    {
        $cluster ??= Cluster::orderBy('id')->first();
        $block = $cluster->block;
        $district = $block->district;

        $user = User::factory()->level($level)->create($attrs);
        UserJurisdiction::create([
            'user_id' => $user->id,
            'state_id' => $district->state_id,
            'district_id' => $level->needsDistrict() ? $district->id : null,
            'block_id' => $level->needsBlock() ? $block->id : null,
            'cluster_id' => $level->needsCluster() ? $cluster->id : null,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        return $user->fresh();
    }

    protected function admin(): User
    {
        return $this->officer(UserLevel::StateAdmin);
    }

    /** V16 (27-11-2026): the District Programmer of $cluster's district (approves Block registrations, manages its users). */
    protected function districtProgrammer(?Cluster $cluster = null, array $attrs = []): User
    {
        return $this->officer(UserLevel::District, $cluster, ['designation_id' => \App\Models\MasterValue::idFor('designation', User::DISTRICT_PROGRAMMER)] + $attrs);
    }

    /** V16: the Block MIS of $cluster's block (approves Cluster registrations, manages its users). */
    protected function blockMis(?Cluster $cluster = null, array $attrs = []): User
    {
        return $this->officer(UserLevel::Block, $cluster, ['designation_id' => \App\Models\MasterValue::idFor('designation', User::BLOCK_MIS)] + $attrs);
    }

    /**
     * Give a permission. V16: approve_registrations / manage_users follow the designation, so for those two the
     * officer becomes the District Programmer (District level) or Block MIS (Block level) of their area.
     */
    protected function grant(User $user, string $slug): void
    {
        if (in_array($slug, \App\Services\PermissionService::USER_MANAGER_SLUGS, true)) {
            $code = match ($user->level) {
                UserLevel::District => User::DISTRICT_PROGRAMMER,
                UserLevel::Block => $slug === Permission::MANAGE_USERS ? User::BLOCK_MIS
                    : throw new \LogicException('V16: the Block MIS only views users; approvals are for the District Programmer.'),
                default => throw new \LogicException('V16: only District Programmers and Block MIS approve or manage users.'),
            };
            $user->forceFill(['designation_id' => \App\Models\MasterValue::idFor('designation', $code)])->save();
            app(\App\Services\PermissionService::class)->flush();

            return;
        }
        $user->directPermissions()->attach(Permission::where('slug', $slug)->value('id'), ['granted_by' => null, 'granted_at' => now()]);
    }

    protected function schoolIn(Cluster $cluster): School
    {
        return School::where('cluster_id', $cluster->id)->orderBy('id')->firstOrFail();
    }

    /** A cluster in a different district (so it is outside every jurisdiction of $cluster). */
    protected function otherCluster(Cluster $cluster): Cluster
    {
        $districtId = $cluster->block->district_id;

        return Cluster::whereHas('block', fn ($q) => $q->where('district_id', '!=', $districtId))->orderBy('id')->firstOrFail();
    }

    protected function startInspection(User $officer, School $school): Inspection
    {
        $this->actingAs($officer)->post(route('inspections.start', $school), ['lat' => 26.1, 'lng' => 91.7, 'accuracy' => 10]);

        return Inspection::where('officer_id', $officer->id)->where('school_id', $school->id)->latest('id')->firstOrFail();
    }
}
