<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One school visit in a daily schedule, in visit order. */
class InspectionScheduleItem extends Model
{
    public const PLANNED = 'planned';
    public const INSPECTED = 'inspected';
    public const SKIPPED = 'skipped';

    protected $fillable = ['schedule_id', 'school_id', 'schedule_date', 'sequence', 'distance_km', 'travel_minutes', 'planned_arrival', 'status', 'inspection_id'];

    protected $casts = ['distance_km' => 'float'];

    /** schedule_date is a DATE column, always stored as Y-m-d (also on SQLite), like inspections.inspection_date. */
    protected function scheduleDate(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($v) => $v === null ? null : \Illuminate\Support\Carbon::parse($v)->startOfDay(),
            set: fn ($v) => $v === null ? null : \Illuminate\Support\Carbon::parse($v)->toDateString(),
        );
    }


    public function schedule() { return $this->belongsTo(InspectionSchedule::class, 'schedule_id'); }
    public function school() { return $this->belongsTo(School::class); }
    public function inspection() { return $this->belongsTo(Inspection::class); }
}
