<?php

namespace App\Enums;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Inactive = 'inactive';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::Active => 'Active',
            self::Rejected => 'Rejected',
            self::Inactive => 'Inactive',
            self::Locked => 'Locked',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Rejected => 'danger',
            self::Inactive => 'secondary',
            self::Locked => 'dark',
        };
    }
}
