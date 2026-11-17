<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionRemark extends Model
{
    protected $fillable = ['inspection_id', 'type', 'sort', 'body'];
}
