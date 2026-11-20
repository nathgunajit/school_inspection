<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Cluster;
use App\Models\User;

/**
 * Modification V9: one active officer per cluster. This applies to the Cluster level only;
 * State, District and Block officers are not limited and may share an area.
 *
 * An Active or Locked officer holds the cluster. Pending, rejected and inactive officers do not, so a
 * new officer can register once the old account is deactivated or transferred. The rule is checked at
 * registration, approval, re-activation and transfer.
 */
class AreaOfficerGuard
{
    public function appliesTo(UserLevel $level): bool
    {
        return $level === UserLevel::Cluster;
    }

    /** The active Cluster officer already holding this cluster, if any. $loc carries cluster_id. */
    public function holder(UserLevel $level, array $loc, ?int $exceptUserId = null): ?User
    {
        $clusterId = $loc['cluster_id'] ?? null;
        if (! $this->appliesTo($level) || ! $clusterId) {
            return null;
        }

        return User::query()
            ->with('designation')
            ->where('level', UserLevel::Cluster->value)
            ->whereIn('status', [UserStatus::Active->value, UserStatus::Locked->value])
            ->when($exceptUserId, fn ($q, $id) => $q->whereKeyNot($id))
            ->whereHas('currentJurisdiction', fn ($q) => $q->where('cluster_id', $clusterId))
            ->oldest('id')
            ->first();
    }

    /** Holder for a user's own current cluster (approval, re-activation). */
    public function holderFor(User $user): ?User
    {
        $j = $user->currentJurisdiction()->first();

        return $j ? $this->holder($user->level, $j->only(['cluster_id']), $user->id) : null;
    }

    /** Standard message naming the existing officer and whom to contact. */
    public function message(User $holder, array $loc, bool $forApplicant = true): string
    {
        $who = trim($holder->name.($holder->designation_label ? ', '.$holder->designation_label : ''));
        $mobile = 'mobile ******'.substr((string) $holder->mobile, -4);

        $base = "{$this->areaName($loc)} already has an active Cluster Level officer: {$who} ({$mobile}). Only one officer can be registered for a cluster.";

        return $forApplicant
            ? $base.' If you have taken over this charge, please contact your higher officials (Block or District officer, or the State Admin) to deactivate or transfer the existing account, then register again.'
            : $base.' Deactivate or transfer that officer first.';
    }

    public function areaName(array $loc): string
    {
        $name = Cluster::find($loc['cluster_id'] ?? 0)?->name;

        return $name ? $name.' cluster' : 'This cluster';
    }
}
