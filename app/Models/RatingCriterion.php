<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One scored item of a rating scheme: a question answer or a derived metric, with its weight. */
class RatingCriterion extends Model
{
    public $timestamps = false;

    protected $table = 'rating_criteria';

    protected $fillable = ['scheme_id', 'section', 'code', 'label', 'kind', 'weight', 'points', 'sort'];

    protected $casts = ['weight' => 'float', 'points' => 'array'];
}
