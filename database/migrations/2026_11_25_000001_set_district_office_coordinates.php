<?php

use App\Services\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * 25-11-2026: District headquarter office locations (Documents/Lat Long of district headquarter.xlsx).
 * Fills only districts that have no location yet, so values entered in the Location Master are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = require database_path('data/assam_district_offices.php');
        $set = [];
        foreach ($rows as $code => [$lat, $lng]) {
            $n = DB::table('districts')->where('code', (string) $code)
                ->whereNull('latitude')->whereNull('longitude')
                ->update(['latitude' => $lat, 'longitude' => $lng, 'updated_at' => now()]);
            if ($n) {
                $set[] = (string) $code;
            }
        }

        if ($set) {
            AuditLogger::log('district_offices_set', null, [
                'source' => 'Lat Long of district headquarter.xlsx',
                'count' => count($set),
                'codes' => $set,
            ]);
            Cache::flush();
        }
    }

    public function down(): void
    {
        // data only: values may have been edited since, so nothing is removed
    }
};
