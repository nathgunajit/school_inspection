<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionFundPosition extends Model
{
    protected $fillable = ['inspection_id', 'financial_year', 'fund_category_id', 'amount_received', 'amount_utilised'];

    protected $casts = ['amount_received' => 'decimal:2', 'amount_utilised' => 'decimal:2'];

    public function category()
    {
        return $this->belongsTo(MasterValue::class, 'fund_category_id');
    }

    public function balance(): float
    {
        return (float) $this->amount_received - (float) $this->amount_utilised;
    }
}
