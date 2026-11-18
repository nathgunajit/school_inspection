<?php

namespace App\Enums;

/** Life cycle of a daily inspection schedule (New Module 2). */
enum ScheduleStatus: string
{
    case Draft = 'draft';          // being planned by the officer
    case Submitted = 'submitted';  // waiting for a higher officer's approval
    case Rejected = 'rejected';    // sent back with a note; the officer can change and resubmit
    case Published = 'published';  // approved, or assigned by a higher officer: the plan for the day
    case Completed = 'completed';  // every school of the plan was inspected or skipped
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Waiting approval',
            default => ucfirst($this->value),
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Submitted => 'info',
            self::Rejected => 'danger',
            self::Published => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'secondary',
        };
    }

    /** The officer may still change the plan (higher officers may change any open plan). */
    public function isEditableByOfficer(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }
}
