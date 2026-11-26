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
        'school_type', 'area_type', 'medium', 'ac_id', 'pc_id', 'streams', 'pincode',
    ];

    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'streams' => 'array'];

    protected static function booted(): void
    {
        // 26-11-2026: the dashboard's "schools in my area" figure is cached; a school added, closed or moved refreshes it
        static::saved(function (self $s) {
            if ($s->wasRecentlyCreated || $s->wasChanged(['status', 'district_id', 'block_id', 'cluster_id', 'deleted_at'])) {
                \App\Services\DashboardStats::flush();
            }
        });
        static::deleted(fn () => \App\Services\DashboardStats::flush());
    }

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

    /**
     * 23-11-2026: a school may teach in more than one medium. `medium` is stored as a list, "Assamese, Bodo".
     * Known languages (the School Master tick boxes and the list filter).
     */
    public const MEDIUMS = ['Assamese', 'Bengali', 'Bodo', 'English', 'Hindi', 'Garo', 'Manipuri', 'Bishnupriya Manipuri', 'Hmar', 'Karbi', 'Nepali'];

    /**
     * "AssameseBodo", "Assamese,Bodo", ['Assamese', 'Bodo'] → "Assamese, Bodo" (UDISE+ Med_Final_25 joins the languages).
     * Known names are matched first (case-insensitive, longest first, so "Bishnupriya manipuri" stays one language);
     * any other text is split on capitals, commas, slashes and spaces. Duplicates are dropped; null when empty.
     */
    public static function normaliseMedium(string|array|null $value): ?string
    {
        $text = is_array($value) ? implode(',', array_filter(array_map('strval', $value))) : (string) $value;
        if (trim($text) === '') {
            return null;
        }
        $known = self::MEDIUMS;
        usort($known, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/'.implode('|', array_map(fn ($k) => str_replace(' ', '\s*', preg_quote($k, '/')), $known)).'/i';

        $found = []; // offset => name
        $rest = preg_replace_callback($pattern, function ($m) use (&$found, $known, $text) {
            $name = collect($known)->first(fn ($k) => preg_match('/^'.str_replace(' ', '\s*', preg_quote($k, '/')).'$/i', $m[0][0]));
            $found[$m[0][1]] = $name;

            return str_repeat(',', strlen($m[0][0])); // keep the offsets of the other words
        }, $text, -1, $count, PREG_OFFSET_CAPTURE);

        // other words: split on camel case, commas, slashes and spaces
        preg_match_all('/[^\s,\/;]+/u', preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $rest), $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$word, $off]) {
            $found[$off] = mb_convert_case($word, MB_CASE_TITLE);
        }
        ksort($found);
        $list = array_values(array_unique($found));

        return $list ? mb_substr(implode(', ', $list), 0, 120) : null;
    }

    /** The mediums as a list, e.g. ['Assamese', 'Bodo']. */
    public function mediums(): array
    {
        return $this->medium ? array_values(array_filter(array_map('trim', explode(',', self::normaliseMedium($this->medium) ?? '')))) : [];
    }

    /** "Assamese, Bodo" (older joined values such as "AssameseBodo" are read the same way). */
    public function getMediumLabelAttribute(): ?string
    {
        return $this->medium ? self::normaliseMedium($this->medium) : null;
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
    public function ac() { return $this->belongsTo(AssemblyConstituency::class, 'ac_id'); }
    public function pc() { return $this->belongsTo(ParliamentaryConstituency::class, 'pc_id'); }
    public function inspections() { return $this->hasMany(Inspection::class); }

    /**
     * V6 Module 9: the latest submitted inspection that has a rating (eager-load with `latestRatedInspection.rating`).
     * Unlocked inspections are left out while they are being corrected; they count again once resubmitted.
     */
    public function latestRatedInspection()
    {
        return $this->hasOne(Inspection::class)->ofMany(['inspection_date' => 'max', 'id' => 'max'], fn ($q) => $q
            ->where('status', \App\Enums\InspectionStatus::Submitted)
            ->whereHas('rating', fn ($r) => $r->whereNotNull('score_percent'))
            ->tap(fn ($w) => \App\Support\InspectionVisibility::apply($w, auth()->user()))); // 25-11-2026: the latest one the viewer may see
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
