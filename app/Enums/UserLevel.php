<?php

namespace App\Enums;

enum UserLevel: string
{
    case StateAdmin = 'state_admin';
    case State = 'state';
    case District = 'district';
    case Block = 'block';
    case Cluster = 'cluster';

    public function label(): string
    {
        return match ($this) {
            self::StateAdmin => 'State Administrator',
            self::State => 'State Level',
            self::District => 'District Level',
            self::Block => 'Block Level',
            self::Cluster => 'Cluster Level',
        };
    }

    /** Hierarchy rank: lower number = higher authority. */
    public function rank(): int
    {
        return match ($this) {
            self::StateAdmin => 0,
            self::State => 1,
            self::District => 2,
            self::Block => 3,
            self::Cluster => 4,
        };
    }

    /** Levels an officer can self-register for (PRD 5.1). */
    public static function registrable(): array
    {
        return [self::State, self::District, self::Block, self::Cluster];
    }

    public function needsDistrict(): bool
    {
        return in_array($this, [self::District, self::Block, self::Cluster], true);
    }

    public function needsBlock(): bool
    {
        return in_array($this, [self::Block, self::Cluster], true);
    }

    public function needsCluster(): bool
    {
        return $this === self::Cluster;
    }

    public function isStateWide(): bool
    {
        return in_array($this, [self::StateAdmin, self::State], true);
    }
}
