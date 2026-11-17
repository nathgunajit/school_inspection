<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Permission matrix, PRD 3.2. "If permitted" rights are granted per user, not per role. */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            Permission::APPROVE_REGISTRATIONS => 'Approve registrations of the level below',
            Permission::MANAGE_USERS => 'Activate, deactivate and edit users',
            Permission::MANAGE_MASTERS => 'Manage Location and School Master',
            Permission::CONDUCT_INSPECTIONS => 'Conduct and submit inspections',
            Permission::VIEW_REPORTS => 'View inspection reports',
            Permission::EXPORT_EXCEL => 'Export lists to Excel',
            Permission::UNLOCK_INSPECTIONS => 'Unlock a submitted inspection',
        ];
        foreach ($perms as $slug => $name) {
            Permission::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        $base = [Permission::CONDUCT_INSPECTIONS, Permission::VIEW_REPORTS, Permission::EXPORT_EXCEL];
        $matrix = [
            UserLevel::StateAdmin->value => array_keys($perms),
            UserLevel::State->value => $base,
            UserLevel::District->value => $base,
            UserLevel::Block->value => $base,
            UserLevel::Cluster->value => $base,
        ];

        foreach (UserLevel::cases() as $level) {
            $role = Role::updateOrCreate(['slug' => $level->value], ['name' => $level->label()]);
            $role->permissions()->sync(Permission::whereIn('slug', $matrix[$level->value])->pluck('id'));
        }
    }
}
