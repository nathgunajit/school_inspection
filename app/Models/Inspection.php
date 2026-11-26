<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use App\Enums\EvidenceStatus;
use App\Enums\InspectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inspection extends Model
{
    use HasEncryptedRouteKey;
    use SoftDeletes;

    protected $fillable = [
        'inspection_no', 'officer_id', 'school_id', 'inspection_date', 'started_at', 'submitted_at',
        'district_id', 'block_id', 'cluster_id', 'state_code', 'district_code', 'block_code', 'cluster_code',
        'start_lat', 'start_lng', 'start_accuracy', 'submit_lat', 'submit_lng', 'submit_accuracy',
        'person_met_name', 'person_met_designation', 'status', 'completion_pct', 'questionnaire_version',
        'unlocked_by', 'unlocked_at', 'unlock_reason', 'cancelled_reason',
        'prefilled_from_id', // V11 item 4
        'officer_level', // 25-11-2026: visibility (InspectionVisibility) and same-level start rules
    ];

    protected static function booted(): void
    {
        // 25-11-2026: keep the officer's level with the inspection (set by InspectionController; this covers seeders and tests)
        static::creating(function (self $i) {
            if (! $i->officer_level && $i->officer_id) {
                $i->officer_level = User::withTrashed()->find($i->officer_id)?->level?->value;
            }
        });

        // 26-11-2026: dashboard figures are cached; a new, removed or status-changed inspection refreshes them.
        // Draft autosaves (answers, progress) change neither, so they do not empty the cache for everyone.
        static::saved(function (self $i) {
            if ($i->wasRecentlyCreated || $i->wasChanged(['status', 'inspection_date', 'officer_id', 'school_id'])) {
                \App\Services\DashboardStats::flush();
            }
        });
        static::deleted(fn () => \App\Services\DashboardStats::flush());
    }

    protected function casts(): array
    {
        return [
            'status' => InspectionStatus::class,
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'unlocked_at' => 'datetime',
            'start_lat' => 'float', 'start_lng' => 'float',
            'submit_lat' => 'float', 'submit_lng' => 'float',
        ];
    }

    /**
     * inspection_date is a DATE column. It is always stored as Y-m-d (also on SQLite), so queries can compare
     * the plain column (`where('inspection_date', '>=', '2026-11-01')`) and use its indexes, instead of
     * whereDate(), which wraps the column in DATE() and prevents index use (Modification V4 indexing).
     */
    protected function inspectionDate(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($v) => $v === null ? null : \Illuminate\Support\Carbon::parse($v)->startOfDay(),
            set: fn ($v) => $v === null ? null : \Illuminate\Support\Carbon::parse($v)->toDateString(),
        );
    }

    public function officer() { return $this->belongsTo(User::class, 'officer_id')->withTrashed(); }
    public function school() { return $this->belongsTo(School::class)->withTrashed(); }
    public function prefilledFrom() { return $this->belongsTo(Inspection::class, 'prefilled_from_id'); } // V11 item 4
    public function district() { return $this->belongsTo(District::class); }
    public function block() { return $this->belongsTo(Block::class); }
    public function cluster() { return $this->belongsTo(Cluster::class); }
    public function answers() { return $this->hasMany(InspectionAnswer::class); }
    public function absentTeachers() { return $this->hasMany(InspectionAbsentTeacher::class); }
    public function classroomObservations() { return $this->hasMany(InspectionClassroomObservation::class); }
    public function fundPositions() { return $this->hasMany(InspectionFundPosition::class); }
    public function evidence() { return $this->hasMany(InspectionEvidence::class); }
    public function photos() { return $this->evidence()->where('kind', 'photo'); }
    public function documents() { return $this->evidence()->where('kind', 'document'); }
    public function remarks() { return $this->hasMany(InspectionRemark::class)->orderBy('sort'); }
    public function rating() { return $this->hasOne(InspectionRating::class); } // New Module 3

    /**
     * Modification V7: the school's rating from the submitted, rated inspection just before this one
     * (earlier date, or the same date with a lower id), for the "change since last inspection" on the report.
     */
    public function previousRating(): ?InspectionRating
    {
        $date = $this->getRawOriginal('inspection_date') ?? $this->inspection_date?->toDateString();

        return InspectionRating::query()
            ->select('inspection_ratings.*')
            ->join('inspections', 'inspections.id', '=', 'inspection_ratings.inspection_id')
            ->where('inspections.school_id', $this->school_id)
            ->where('inspections.id', '!=', $this->id)
            ->where('inspections.status', \App\Enums\InspectionStatus::Submitted->value)
            ->whereNull('inspections.deleted_at')
            ->tap(fn ($q) => \App\Support\InspectionVisibility::apply($q, auth()->user())) // 25-11-2026: only visible ones
            ->whereNotNull('inspection_ratings.score_percent')
            ->where(fn ($q) => $q->where('inspections.inspection_date', '<', $date)
                ->orWhere(fn ($w) => $w->where('inspections.inspection_date', $date)->where('inspections.id', '<', $this->id)))
            ->orderByDesc('inspections.inspection_date')->orderByDesc('inspections.id')
            ->with('inspection:id,inspection_no,inspection_date')
            ->first();
    }
    public function unlocker() { return $this->belongsTo(User::class, 'unlocked_by'); }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /** Answers keyed by question code => decoded value. */
    public function answerMap(): array
    {
        return $this->answers->mapWithKeys(fn ($a) => [$a->question_code => $a->value])->all();
    }

    public function remarksMap(): array
    {
        return $this->answers->mapWithKeys(fn ($a) => [$a->question_code => $a->remarks])->filter()->all();
    }

    public function uploadedPhotos()
    {
        return $this->photos()->where('status', EvidenceStatus::Uploaded->value);
    }

    /** Distance in metres between a point and the school coordinates (haversine). */
    public function distanceFromSchool(?float $lat, ?float $lng): ?int
    {
        $school = $this->school;
        if ($lat === null || $lng === null || ! $school?->hasCoordinates()) {
            return null;
        }

        return self::haversine($lat, $lng, $school->latitude, $school->longitude);
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
