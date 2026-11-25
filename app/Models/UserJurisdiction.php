<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserJurisdiction extends Model
{
    protected $fillable = ['user_id', 'state_id', 'district_id', 'block_id', 'cluster_id', 'start_date', 'end_date', 'assigned_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    /** 25-11-2026: JurisdictionService::current() remembers postings for the request; a change forgets them. */
    protected static function booted(): void
    {
        $forget = fn () => \App\Support\RequestMemo::forget('posting.');
        static::saved($forget);
        static::deleted($forget);
    }

    public function user() { return $this->belongsTo(User::class); }
    public function state() { return $this->belongsTo(State::class); }
    public function district() { return $this->belongsTo(District::class); }
    public function block() { return $this->belongsTo(Block::class); }
    public function cluster() { return $this->belongsTo(Cluster::class); }

    public function label(): string
    {
        return collect([$this->cluster?->label, $this->block?->label, $this->district?->label, $this->state?->name])
            ->filter()->implode(' · ');
    }
}
