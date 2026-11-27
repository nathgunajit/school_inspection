<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Models\Permission;
use App\Models\User;

/**
 * Permission matrix (PRD 3.2): role permissions plus individual "If permitted" grants.
 */
class PermissionService
{
    /** Per-request cache: user id => slugs */
    private array $cache = [];

    /** V16 item 1 (27-11-2026): these two come from the officer's designation only, never from a role row or a grant. */
    public const USER_MANAGER_SLUGS = [Permission::APPROVE_REGISTRATIONS, Permission::MANAGE_USERS];

    public function has(User $user, string $slug): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($user->isStateAdmin()) {
            return true;
        }
        if (in_array($slug, self::USER_MANAGER_SLUGS, true)) {
            // V16: the District Programmer approves and manages; the Block MIS only views the user list
            return $slug === Permission::MANAGE_USERS
                ? $user->isDistrictProgrammer() || $user->isBlockMis()
                : $user->isDistrictProgrammer();
        }

        return in_array($slug, $this->slugsFor($user), true);
    }

    public function slugsFor(User $user): array
    {
        return $this->cache[$user->id] ??= array_values(array_unique(array_merge(
            Permission::query()
                ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where('roles.slug', $user->level->value)
                ->pluck('permissions.slug')->all(),
            $user->directPermissions()->pluck('slug')->all(),
        )));
    }

    /** Which individually grantable permissions are valid for a level. V16 (27-11-2026): none; approving and managing users follow the designation (District Programmer, Block MIS). */
    public static function grantableFor(UserLevel $level): array
    {
        return [];
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
