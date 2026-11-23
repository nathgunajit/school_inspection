<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use HasEncryptedRouteKey;
    use SoftDeletes;

    protected $fillable = [
        'udise_code', 'name', 'state_id', 'district_id', 'block_id', 'cluster_id',
        'management_id', 'category_id', 'address', 'latitude', 'longitude', 'status',
        'school_type', 'area_type', 'medium', 'assembly_constituency', 'lok_sabha', 'streams', 'pincode',
    ];

    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'streams' => 'array'];

    /** UDISE+ School_Type (stored codes are stable; labels may change). */
    public const SCHOOL_TYPES = ['boys' => 'Boys', 'girls' => 'Girls', 'co_ed' => 'Co-ed'];

    /** UDISE+ School_Location. */
    public const AREA_TYPES = ['rural' => 'Rural', 'urban' => 'Urban'];

    /** Higher Secondary streams (UDISE+ *_Stream columns). */
    public const STREAMS = ['arts' => 'Arts', 'commerce' => 'Commerce', 'science' => 'Science', 'vocational' => 'Vocational', 'other' => 'Other'];

    public function getSchoolTypeLabelAttribute(): ?string
    {
        return self::SCHOOL_TYPES[$this->school_type] ?? null;
    }

    public function getAreaTypeLabelAttribute(): ?string
    {
        return self::AREA_TYPES[$this->area_type] ?? null;
    }

    /** "AssameseBodoEnglish" → "Assamese, Bodo, English" (UDISE joins the languages). */
    public function getMediumLabelAttribute(): ?string
    {
        if (! $this->medium) {
            return null;
        }

        return implode(', ', preg_split('/(?<=[a-z])(?=[A-Z])|\s*[,\/]\s*/', trim($this->medium)));
    }

    /** Streams offered, e.g. "Arts, Science"; null when the school has no Higher Secondary data. */
    public function getStreamsLabelAttribute(): ?string
    {
        if (! is_array($this->streams)) {
            return null;
        }
        $on = array_keys(array_filter($this->streams));

        return $on ? implode(', ', array_map(fn ($k) => self::STREAMS[$k] ?? $k, $on)) : 'None';
    }

    public function state() { return $this->belongsTo(State::class); }
    public function district() { return $this->belongsTo(District::class); }
    public function block() { return $this->belongsTo(Block::class); }
    public function cluster() { return $this->belongsTo(Cluster::class); }
    public function management() { return $this->belongsTo(MasterValue::class, 'management_id'); }
    public function category() { return $this->belongsTo(MasterValue::class, 'category_id'); }
    public function inspections() { return $this->hasMany(Inspection::class); }

    /**
     * V6 Module 9: the latest submitted inspection that has a rating (eager-load with `latestRatedInspection.rating`).
     * Unlocked inspections are left out while they are being corrected; they count again once resubmitted.
     */
    public function latestRatedInspection()
    {
        return $this->hasOne(Inspection::class)->ofMany(['inspection_date' => 'max', 'id' => 'max'], fn ($q) => $q
            ->where('status', \App\Enums\InspectionStatus::Submitted)
            ->whereHas('rating', fn ($r) => $r->whereNotNull('score_percent')));
    }

    /** The school's current rating (InspectionRating of latestRatedInspection), or null. */
    public function latestRating(): ?InspectionRating
    {
        return $this->latestRatedInspection?->rating;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
