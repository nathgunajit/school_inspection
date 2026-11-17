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

    public function has(User $user, string $slug): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($user->isStateAdmin()) {
            return true;
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

    /** Which individually grantable permissions are valid for a level (PRD 3.2). */
    public static function grantableFor(UserLevel $level): array
    {
        return match ($level) {
            UserLevel::State, UserLevel::Block => [Permission::APPROVE_REGISTRATIONS],
            UserLevel::District => [Permission::APPROVE_REGISTRATIONS, Permission::MANAGE_USERS],
            default => [],
        };
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
