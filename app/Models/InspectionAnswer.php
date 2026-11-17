<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionAnswer extends Model
{
    protected $fillable = ['inspection_id', 'step', 'question_code', 'value', 'remarks'];

    protected $casts = ['value' => 'array'];

    public function inspection()
    {
        return $this->belongsTo(Inspection::class);
    }
}
