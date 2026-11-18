<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The automatic rating of one inspection (New Module 3). */
class InspectionRating extends Model
{
    protected $fillable = ['inspection_id', 'scheme_id', 'obtained', 'applicable', 'score_percent', 'band', 'color', 'sections', 'details', 'strengths', 'weaknesses', 'calculated_at'];

    protected $casts = [
        'obtained' => 'float', 'applicable' => 'float', 'score_percent' => 'float',
        'sections' => 'array', 'details' => 'array', 'strengths' => 'array', 'weaknesses' => 'array', 'calculated_at' => 'datetime',
    ];

    public function inspection() { return $this->belongsTo(Inspection::class); }
    public function scheme() { return $this->belongsTo(RatingScheme::class, 'scheme_id'); }

    public function isRated(): bool
    {
        return $this->score_percent !== null;
    }
}
