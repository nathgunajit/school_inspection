<?php

namespace App\Services;

use App\Models\Inspection;

/**
 * Inspection ID: SI/{year}/{district code}/{running number} (PRD 8.2).
 * Must be called inside a DB transaction.
 */
class InspectionNumberGenerator
{
    public function next(Inspection $inspection): string
    {
        $prefix = sprintf('SI/%s/%s/', now()->format('Y'), $inspection->district_code);

        $last = Inspection::withTrashed()
            ->where('inspection_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('inspection_no')
            ->value('inspection_no');

        $running = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $running, 6, '0', STR_PAD_LEFT);
    }
}
