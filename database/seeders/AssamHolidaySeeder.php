<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Holiday;
use App\Services\AuditLogger;
use Illuminate\Database\Seeder;

/**
 * 25-11-2026: loads the Government of Assam holiday list 2026 (database/data/assam_holidays_2026.php): gazetted, half
 * and restricted holidays as state rows, plus Ali-Aye-Ligang as a local holiday of the 11 districts named in the
 * notification. Rows already present (same date, name and district) are skipped, so it is safe to run again.
 *   php artisan db:seed --class=AssamHolidaySeeder
 */
class AssamHolidaySeeder extends Seeder
{
    /** @return array{added:int, skipped:int, missing:array<int,string>} */
    public function run(): array
    {
        $data = require database_path('data/assam_holidays_2026.php');
        $rows = [];
        foreach ($data['state'] as [$date, $name, $type]) {
            $rows[] = ['date' => $date, 'name' => $name, 'type' => $type, 'district_id' => null];
        }

        $missing = [];
        foreach ($data['local'] as [$date, $name, $type, $districts]) {
            foreach ($districts as $code => $dName) {
                $id = District::where('code', (string) $code)->value('id')
                    ?? District::whereRaw('upper(name) = ?', [mb_strtoupper($dName)])->value('id');
                if (! $id) {
                    $missing[] = $dName;

                    continue;
                }
                $rows[] = ['date' => $date, 'name' => $name, 'type' => $type, 'district_id' => (int) $id];
            }
        }

        $added = 0;
        foreach ($rows as $r) {
            $exists = Holiday::where('date', $r['date'])->where('name', $r['name'])
                ->where(fn ($q) => $r['district_id'] ? $q->where('district_id', $r['district_id']) : $q->whereNull('district_id'))->exists();
            if (! $exists) {
                Holiday::create($r);
                $added++;
            }
        }

        if ($added) {
            AuditLogger::log('holidays_imported', null, ['source' => $data['source'], 'rows' => count($rows), 'added' => $added, 'districts_not_found' => $missing]);
        }
        $this->command?->info("Assam holidays 2026: $added added, ".(count($rows) - $added).' already present'.($missing ? '; districts not found: '.implode(', ', $missing) : '').'.');

        return ['added' => $added, 'skipped' => count($rows) - $added, 'missing' => $missing];
    }
}
