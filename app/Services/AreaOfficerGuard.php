<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\User;

/**
 * Modification V9: one active officer per cluster (Cluster level). V16 item 1 (27-11-2026): also one District Programmer
 * per district and one Block MIS per block. Other State, District and Block officers are not limited and may share an area.
 *
 * An Active or Locked officer holds the area. Pending, rejected and inactive officers do not, so a
 * new officer can register once the old account is deactivated or transferred. The rule is checked at
 * registration, approval, re-activation and transfer.
 */
class AreaOfficerGuard
{
    /** Which area the rule uses for this level and designation: 'cluster', 'district', 'block', or null (no limit). */
    public function rule(UserLevel $level, ?int $designationId = null): ?string
    {
        return match (true) {
            $level === UserLevel::Cluster => 'cluster',
            $level === UserLevel::District && $designationId && $designationId === User::designationIdFor(User::DISTRICT_PROGRAMMER) => 'district',
            $level === UserLevel::Block && $designationId && $designationId === User::designationIdFor(User::BLOCK_MIS) => 'block',
            default => null,
        };
    }

    public function appliesTo(UserLevel $level, ?int $designationId = null): bool
    {
        return $this->rule($level, $designationId) !== null;
    }

    /** The active officer already holding this area, if any. $loc carries cluster_id / district_id / block_id. */
    public function holder(UserLevel $level, array $loc, ?int $exceptUserId = null, ?int $designationId = null): ?User
    {
        $rule = $this->rule($level, $designationId ? (int) $designationId : null);
        $areaId = $rule ? ($loc[$rule.'_id'] ?? null) : null;
        if (! $rule || ! $areaId) {
            return null;
        }

        return User::query()
            ->with('designation')
            ->where('level', $level->value)
            ->when($rule !== 'cluster', fn ($q) => $q->where('designation_id', $designationId))
            ->whereIn('status', [UserStatus::Active->value, UserStatus::Locked->value])
            ->when($exceptUserId, fn ($q, $id) => $q->whereKeyNot($id))
            ->whereHas('currentJurisdiction', fn ($q) => $q->where($rule.'_id', $areaId))
            ->oldest('id')
            ->first();
    }

    /** Holder for a user's own current area (approval, re-activation). */
    public function holderFor(User $user): ?User
    {
        $j = $user->currentJurisdiction()->first();

        return $j ? $this->holder($user->level, $j->only(['district_id', 'block_id', 'cluster_id']), $user->id, $user->designation_id ? (int) $user->designation_id : null) : null;
    }

    /** Standard message naming the existing officer and whom to contact. */
    public function message(User $holder, array $loc, bool $forApplicant = true): string
    {
        $who = trim($holder->name.($holder->designation_label ? ', '.$holder->designation_label : ''));
        $mobile = 'mobile ******'.substr((string) $holder->mobile, -4);
        $rule = $this->rule($holder->level, $holder->designation_id ? (int) $holder->designation_id : null) ?? 'cluster';
        [$role, $unit] = match ($rule) {
            'district' => ['District Programmer', 'district'],
            'block' => ['Block MIS', 'block'],
            default => ['Cluster Level officer', 'cluster'],
        };

        $base = "{$this->areaName($loc, $rule)} already has an active {$role}: {$who} ({$mobile}). Only one {$role} can be registered for a {$unit}.";

        return $forApplicant
            ? $base.' If you have taken over this charge, please contact your higher officials (Block or District officer, or the State Admin) to deactivate or transfer the existing account, then register again.'
            : $base.' Deactivate or transfer that officer first.';
    }

    public function areaName(array $loc, string $rule = 'cluster'): string
    {
        $name = match ($rule) {
            'district' => District::find($loc['district_id'] ?? 0)?->name,
            'block' => Block::find($loc['block_id'] ?? 0)?->name,
            default => Cluster::find($loc['cluster_id'] ?? 0)?->name,
        };

        return $name ? $name.' '.$rule : 'This '.$rule;
    }
}
