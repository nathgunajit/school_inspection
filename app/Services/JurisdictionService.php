<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\School;
use App\Models\User;
use App\Models\UserJurisdiction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts every query to the user's own jurisdiction (PRD 1.1, 3.1).
 */
class JurisdictionService
{
    public function current(User $user): ?UserJurisdiction
    {
        return $user->relationLoaded('currentJurisdiction')
            ? $user->currentJurisdiction
            : $user->currentJurisdiction()->first();
    }

    /**
     * Apply a jurisdiction filter to a query on a table that has
     * district_id / block_id / cluster_id columns (schools, inspections).
     */
    public function scope(Builder $query, User $user, string $table): Builder
    {
        $j = $this->current($user);

        if ($user->level->isStateWide()) {
            return $query; // single-state deployment: whole state
        }
        if (! $j) {
            return $query->whereRaw('1 = 0');
        }

        return match ($user->level) {
            UserLevel::District => $query->where("$table.district_id", $j->district_id),
            UserLevel::Block => $query->where("$table.block_id", $j->block_id),
            UserLevel::Cluster => $query->where("$table.cluster_id", $j->cluster_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function schools(User $user): Builder
    {
        return $this->scope(School::query(), $user, 'schools');
    }

    public function inspections(User $user): Builder
    {
        return $this->scope(Inspection::query(), $user, 'inspections');
    }

    public function coversSchool(User $user, School $school): bool
    {
        return $this->schools($user)->whereKey($school->id)->exists();
    }

    public function coversInspection(User $user, Inspection $inspection): bool
    {
        if ($inspection->officer_id === $user->id) {
            return true;
        }

        return $this->inspections($user)->whereKey($inspection->id)->exists();
    }

    /** Users whose current jurisdiction lies within $user's jurisdiction. */
    public function users(User $user): Builder
    {
        $q = User::query();
        if ($user->level->isStateWide()) {
            return $q;
        }
        $j = $this->current($user);
        if (! $j) {
            return $q->whereRaw('1 = 0');
        }
        $col = match ($user->level) {
            UserLevel::District => ['district_id', $j->district_id],
            UserLevel::Block => ['block_id', $j->block_id],
            UserLevel::Cluster => ['cluster_id', $j->cluster_id],
            default => null,
        };

        return $q->whereHas('currentJurisdiction', fn ($w) => $w->where($col[0], $col[1]));
    }

    /** Is jurisdiction $inner inside (or equal to) the jurisdiction of $outer user? */
    public function contains(User $outer, ?UserJurisdiction $inner): bool
    {
        if (! $inner) {
            return false;
        }
        if ($outer->level->isStateWide()) {
            return true;
        }
        $o = $this->current($outer);
        if (! $o) {
            return false;
        }

        return match ($outer->level) {
            UserLevel::District => $inner->district_id === $o->district_id,
            UserLevel::Block => $inner->block_id === $o->block_id,
            UserLevel::Cluster => $inner->cluster_id === $o->cluster_id,
            default => false,
        };
    }
}
