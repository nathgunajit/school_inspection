<?php

namespace App\Enums;

enum InspectionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Unlocked = 'unlocked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Submitted => 'success',
            self::Unlocked => 'info',
            self::Cancelled => 'secondary',
        };
    }

    /** Officer may still edit answers and evidence. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Unlocked], true);
    }
}
