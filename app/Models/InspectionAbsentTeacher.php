<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionAbsentTeacher extends Model
{
    public const STATUSES = [
        'on_leave' => 'On leave',
        'official_duty' => 'On official duty',
        'absent_without_info' => 'Absent without information',
    ];

    protected $fillable = ['inspection_id', 'teacher_name', 'status', 'remarks'];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
