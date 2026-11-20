<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\User;

/**
 * Modification V9: one active officer per cluster (levels in config('inspection.single_officer_levels')).
 *
 * An Active or Locked officer holds the area. Pending, rejected and inactive officers do not, so a
 * new officer can register once the old account is deactivated or transferred. The rule is checked at
 * registration, approval, re-activation and transfer.
 */
class AreaOfficerGuard
{
    private const COLUMN = [
        'cluster' => 'cluster_id',
        'block' => 'block_id',
        'district' => 'district_id',
    ];

    public function appliesTo(UserLevel $level): bool
    {
        return isset(self::COLUMN[$level->value])
            && in_array($level->value, config('inspection.single_officer_levels', []), true);
    }

    /** The active officer already holding this area, if any. $loc has district_id / block_id / cluster_id. */
    public function holder(UserLevel $level, array $loc, ?int $exceptUserId = null): ?User
    {
        if (! $this->appliesTo($level)) {
            return null;
        }
        $column = self::COLUMN[$level->value];
        $areaId = $loc[$column] ?? null;
        if (! $areaId) {
            return null;
        }

        return User::query()
            ->with('designation')
            ->where('level', $level->value)
            ->whereIn('status', [UserStatus::Active->value, UserStatus::Locked->value])
            ->when($exceptUserId, fn ($q, $id) => $q->whereKeyNot($id))
            ->whereHas('currentJurisdiction', fn ($q) => $q->where($column, $areaId))
            ->oldest('id')
            ->first();
    }

    /** Holder for a user's own current jurisdiction (approval, re-activation). */
    public function holderFor(User $user): ?User
    {
        $j = $user->currentJurisdiction()->first();

        return $j ? $this->holder($user->level, $j->only(['district_id', 'block_id', 'cluster_id']), $user->id) : null;
    }

    /** Standard message naming the existing officer and whom to contact. */
    public function message(User $holder, array $loc, bool $forApplicant = true): string
    {
        $level = $holder->level;
        $area = $this->areaName($level, $loc);
        $who = trim($holder->name.($holder->designation_label ? ', '.$holder->designation_label : ''));
        $mobile = 'mobile ******'.substr((string) $holder->mobile, -4);

        $base = "{$area} already has an active {$level->label()} officer: {$who} ({$mobile}). Only one officer can be registered for a {$this->noun($level)}.";

        return $forApplicant
            ? $base.' If you have taken over this charge, please contact your higher officials ('.$this->higher($level).') to deactivate or transfer the existing account, then register again.'
            : $base.' Deactivate or transfer that officer first.';
    }

    public function areaName(UserLevel $level, array $loc): string
    {
        $name = match ($level->value) {
            'cluster' => Cluster::find($loc['cluster_id'] ?? 0)?->name,
            'block' => Block::find($loc['block_id'] ?? 0)?->name,
            'district' => District::find($loc['district_id'] ?? 0)?->name,
            default => null,
        };

        return ($name ? $name.' ' : 'This ').$this->noun($level);
    }

    private function noun(UserLevel $level): string
    {
        return strtolower($level->value);
    }

    private function higher(UserLevel $level): string
    {
        return match ($level->value) {
            'cluster' => 'Block or District officer, or the State Admin',
            'block' => 'District officer or the State Admin',
            default => 'State officer or the State Admin',
        };
    }
}
