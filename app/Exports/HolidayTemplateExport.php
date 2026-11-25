<?php

namespace App\Exports;

use App\Models\District;
use App\Models\Holiday;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * 25-11-2026: the holiday import template. Sheet "Holidays" (date, name, type, district_code) and sheet "Value lists"
 * (the types and the district codes). Blank: two example rows; filled: the Assam 2026 list (assam_holidays_2026.php),
 * ready to import.
 */
class HolidayTemplateExport implements WithMultipleSheets
{
    public const HEADINGS = ['date', 'name', 'type', 'district_code'];

    public function __construct(private array $rows) {}

    /** The two example rows of the blank template. */
    public static function example(?string $districtCode = null): self
    {
        $y = now()->year;

        return new self([
            ["$y-01-14", 'Magh Bihu', 'gazetted', $districtCode ?? ''],
            ["$y-01-13", 'Uruka of Magh Bihu', 'restricted', $districtCode ?? ''],
        ]);
    }

    /** The Government of Assam list 2026 with district codes for the local rows. */
    public static function assam2026(): self
    {
        $data = require database_path('data/assam_holidays_2026.php');
        $rows = array_map(fn ($r) => [$r[0], $r[1], $r[2], ''], $data['state']);
        foreach ($data['local'] as [$date, $name, $type, $districts]) {
            foreach ($districts as $code => $dName) {
                $code = District::where('code', (string) $code)->value('code')
                    ?? District::whereRaw('upper(name) = ?', [mb_strtoupper($dName)])->value('code') ?? (string) $code;
                $rows[] = [$date, $name, $type, (string) $code];
            }
        }
        usort($rows, fn ($a, $b) => [$a[0], $a[3], $a[1]] <=> [$b[0], $b[3], $b[1]]);

        return new self($rows);
    }

    public function sheets(): array
    {
        $types = array_map(fn ($k, $v) => [$k, $v, in_array($k, Holiday::OFF_TYPES, true) ? 'Yes' : 'No (shown only)'],
            array_keys(Holiday::TYPES), Holiday::TYPES);
        $districts = District::orderBy('code')->get(['code', 'name'])->map(fn ($d) => ['', '', '', (string) $d->code, $d->name])->all();
        $lists = [['Type', 'Meaning', 'Office closed', 'district_code', 'District']];
        // value-list rows: types in A–C, districts in D–E, side by side
        $n = max(count($types), count($districts));
        for ($i = 0; $i < $n; $i++) {
            $t = $types[$i] ?? ['', '', ''];
            $d = $districts[$i] ?? ['', '', '', '', ''];
            $lists[] = [$t[0], $t[1], $t[2], $d[3], $d[4]];
        }
        $help = [['Notes'], ['date: YYYY-MM-DD (e.g. 2026-04-14).'], ['type: gazetted (blank = gazetted), restricted, half.'],
            ['district_code: blank = whole state; a code = local holiday of that district.'],
            ['Every row is checked first: nothing is saved while any row has a problem. Rows already in the calendar are skipped.']];

        return [
            new HolidaySheet('Holidays', self::HEADINGS, $this->rows, ['A' => 14, 'B' => 52, 'C' => 14, 'D' => 16]),
            new HolidaySheet('Value lists', array_shift($lists), array_merge($lists, [[]], $help), ['A' => 14, 'B' => 28, 'C' => 18, 'D' => 16, 'E' => 30]),
        ];
    }
}

