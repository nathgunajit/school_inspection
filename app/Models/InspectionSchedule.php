<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use App\Enums\ScheduleStatus;
use Illuminate\Database\Eloquent\Model;

/** A daily inspection plan of one officer (New Module 2). */
class InspectionSchedule extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = [
        'officer_id', 'schedule_date', 'status', 'created_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
        'published_at', 'start_lat', 'start_lng', 'total_distance_km', 'total_minutes', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScheduleStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'total_distance_km' => 'float',
        ];
    }

    /** schedule_date is a DATE column, always stored as Y-m-d (also on SQLite), like inspections.inspection_date. */
    protected function scheduleDate(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($v) => $v === null ? null : \Illuminate\Support\Carbon::parse($v)->startOfDay(),
            set: fn ($v) => $v === null ? null : \Illuminate\Support\Carbon::parse($v)->toDateString(),
        );
    }
    public function officer() { return $this->belongsTo(User::class, 'officer_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function items() { return $this->hasMany(InspectionScheduleItem::class, 'schedule_id')->orderBy('sequence'); }

    /** Audit trail of this schedule (the history shown on its page). */
    public function history()
    {
        return AuditLog::query()->where('auditable_type', 'InspectionSchedule')->where('auditable_id', $this->id)->with('user:id,name')->oldest('id');
    }
}
