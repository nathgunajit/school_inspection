<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Registration approval chain (PRD 3.3).
 *
 *  State    -> State Admin
 *  District -> State Admin, or State user with approval permission
 *  Block    -> District user (same district) with permission, otherwise State Admin
 *  Cluster  -> Block user (same block) with permission, otherwise District user with permission,
 *              otherwise State Admin
 */
class ApproverResolver
{
    public const PENDING_VERSION_KEY = 'approvals:pending:version';

    public const PENDING_COUNT_TTL = 60; // seconds

    public function __construct(
        private PermissionService $permissions,
        private JurisdictionService $jurisdictions,
    ) {}

    public function canApprove(User $approver, User $applicant): bool
    {
        if ($approver->id === $applicant->id || ! $approver->isActive()) {
            return false;
        }
        if ($approver->isStateAdmin()) {
            return true;
        }
        if (! $this->permissions->has($approver, Permission::APPROVE_REGISTRATIONS)) {
            return false;
        }

        $target = $applicant->currentJurisdiction()->first();
        if (! $this->jurisdictions->contains($approver, $target)) {
            return false;
        }

        return match ($applicant->level) {
            UserLevel::District => $approver->level === UserLevel::State,
            UserLevel::Block => $approver->level === UserLevel::District,
            UserLevel::Cluster => match ($approver->level) {
                UserLevel::Block => true,
                // District acts only if no Block approver exists for that block
                UserLevel::District => ! $this->hasPermittedApprover(UserLevel::Block, 'block_id', $target->block_id),
                default => false,
            },
            default => false, // State level: State Admin only
        };
    }

    /** Pending registrations this approver may act on. */
    public function pendingFor(User $approver): Collection
    {
        return RegistrationRequest::query()
            ->where('status', 'pending')
            ->with(['user.currentJurisdiction.state', 'user.currentJurisdiction.district', 'user.currentJurisdiction.block', 'user.currentJurisdiction.cluster', 'user.designation'])
            ->oldest()
            ->get()
            ->filter(fn ($r) => $r->user && $this->canApprove($approver, $r->user))
            ->values();
    }

    /**
     * Badge count for the layout and dashboard (audit P1), cached per approver.
     * Any change to a registration request bumps the version, so the badge
     * refreshes at once; the short TTL covers changes to the approvers themselves.
     * The approvals page itself always uses the live pendingFor() list.
     */
    public function pendingCountFor(User $approver): int
    {
        $version = (int) Cache::get(self::PENDING_VERSION_KEY, 0);

        return (int) Cache::remember(
            "approvals:pending:{$version}:{$approver->id}",
            self::PENDING_COUNT_TTL,
            fn () => $this->pendingFor($approver)->count(),
        );
    }

    public static function flushPendingCounts(): void
    {
        Cache::increment(self::PENDING_VERSION_KEY);
    }

    private function hasPermittedApprover(UserLevel $level, string $column, ?int $id): bool
    {
        if (! $id) {
            return false;
        }

        return User::query()
            ->where('level', $level->value)
            ->where('status', UserStatus::Active->value)
            ->whereHas('currentJurisdiction', fn ($q) => $q->where($column, $id))
            ->whereHas('directPermissions', fn ($q) => $q->where('slug', Permission::APPROVE_REGISTRATIONS))
            ->exists();
    }
}
