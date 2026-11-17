<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public const APPROVE_REGISTRATIONS = 'approve_registrations';
    public const MANAGE_USERS = 'manage_users';
    public const MANAGE_MASTERS = 'manage_masters';
    public const CONDUCT_INSPECTIONS = 'conduct_inspections';
    public const VIEW_REPORTS = 'view_reports';
    public const EXPORT_EXCEL = 'export_excel';
    public const UNLOCK_INSPECTIONS = 'unlock_inspections';

    /** Permissions a State Admin may grant individually ("If permitted", PRD 3.2). */
    public const GRANTABLE = [self::APPROVE_REGISTRATIONS, self::MANAGE_USERS];

    protected $fillable = ['slug', 'name'];
}
