<?php

namespace App\Support;

use App\Enums\UserLevel;
use App\Models\User;

/**
 * 25-11-2026: who sees whose inspections. Every officer sees their own; a higher officer also sees those made by
 * LOWER levels (District: Block + Cluster, Block: Cluster). Nobody sees the same or a higher level, except the
 * state-wide users (State officers, State Admin), who see all. Based on `inspections.officer_level` (kept at start).
 * Area scoping (JurisdictionService) still applies on top of this.
 */
class InspectionVisibility
{
    /** @return list<string>|null level values below the viewer; null = everything */
    public static function levelsBelow(User $viewer): ?array
    {
        if ($viewer->level->isStateWide()) {
            return null;
        }
        $rank = $viewer->level->rank();

        return array_values(array_map(fn (UserLevel $l) => $l->value, array_filter(UserLevel::cases(), fn (UserLevel $l) => $l->rank() > $rank)));
    }

    /** Limit an Eloquent / query builder to the inspections the viewer may see. No viewer (console, seeders) = no limit. */
    public static function apply($query, ?User $viewer, string $table = 'inspections')
    {
        if (! $viewer || ($levels = self::levelsBelow($viewer)) === null) {
            return $query;
        }

        return $query->where(fn ($w) => $w->where("$table.officer_id", $viewer->id)
            ->when($levels !== [], fn ($x) => $x->orWhereIn("$table.officer_level", $levels)));
    }

    /** The same rule as a raw SQL condition (" and (...)"), for hand-written exists() strings. Values come from the enum / an int id. */
    public static function sql(?User $viewer, string $alias): string
    {
        if (! $viewer || ($levels = self::levelsBelow($viewer)) === null) {
            return '';
        }
        $in = $levels ? " or $alias.officer_level in ('".implode("','", $levels)."')" : '';

        return " and ($alias.officer_id = ".(int) $viewer->id.$in.')';
    }

    /** Start rules: only an officer of the same level blocks a school (different levels may inspect it too). */
    public static function sameLevel($query, User $officer, string $table = 'inspections')
    {
        return $query->where("$table.officer_level", $officer->level->value);
    }
}
