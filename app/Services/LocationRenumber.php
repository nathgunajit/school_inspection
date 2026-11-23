<?php

namespace App\Services;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Renumbers districts, blocks and clusters 1…n in Location Master code order (district 1801 → 1),
 * and moves every row that points to them. Codes, names and all links stay the same.
 *
 * Each level runs in two passes (every id + OFFSET, then offset id → new id) so no key collides
 * half way. Foreign keys are switched off (MySQL) or deferred to the commit (SQLite).
 */
class LocationRenumber
{
    private const OFFSET = 1000000;

    /** table => columns that hold its id */
    public const REFS = [
        'districts' => [['blocks', 'district_id'], ['schools', 'district_id'], ['inspections', 'district_id'],
            ['user_jurisdictions', 'district_id'], ['import_batches', 'district_id'], ['coverage_settings', 'district_id']],
        'blocks' => [['clusters', 'block_id'], ['schools', 'block_id'], ['inspections', 'block_id'], ['user_jurisdictions', 'block_id']],
        'clusters' => [['schools', 'cluster_id'], ['inspections', 'cluster_id'], ['user_jurisdictions', 'cluster_id']],
    ];

    /** @return array<string, int> rows renumbered per table (0 = already in order) */
    public function run(): array
    {
        set_time_limit(0);
        $maps = [];
        foreach (array_keys(self::REFS) as $table) {
            $map = [];
            $n = 0;
            foreach (DB::table($table)->orderBy('code')->orderBy('id')->pluck('id') as $old) {
                $map[(int) $old] = ++$n;
            }
            $maps[$table] = collect($map)->contains(fn ($new, $old) => $new !== $old) ? $map : [];
        }
        if (! array_filter($maps)) {
            return array_map(fn () => 0, $maps);
        }

        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            DB::transaction(function () use ($maps, $mysql) {
                if (! $mysql && DB::getDriverName() === 'sqlite') {
                    DB::statement('PRAGMA defer_foreign_keys = ON');
                }
                foreach ($maps as $table => $map) {
                    if ($map) {
                        $this->move($table, 'id', $map);
                        foreach (self::REFS[$table] as [$t, $col]) {
                            $this->move($t, $col, $map);
                        }
                    }
                }
                $this->fixSummaries($maps['districts'], $maps['blocks']);
            });
        } finally {
            if ($mysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        if ($mysql) {
            foreach (array_keys($maps) as $table) {
                $next = (int) DB::table($table)->max('id') + 1;
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}"); // commits on its own, so after the transaction
            }
        }
        Cache::flush(); // map and area caches are keyed by id

        $counts = array_map(fn ($m) => count($m), $maps);
        AuditLogger::log('locations_renumbered', null, $counts);

        return $counts;
    }

    /** @param array<int, int> $map */
    private function move(string $table, string $col, array $map): void
    {
        DB::table($table)->whereNotNull($col)->update([$col => DB::raw("{$col} + ".self::OFFSET)]);
        foreach (array_chunk($map, 500, true) as $chunk) {
            $case = 'CASE '.$col;
            foreach ($chunk as $old => $new) {
                $case .= ' WHEN '.($old + self::OFFSET).' THEN '.$new;
            }
            DB::table($table)->whereIn($col, array_map(fn ($o) => $o + self::OFFSET, array_keys($chunk)))
                ->update([$col => DB::raw($case.' END')]);
        }
    }

    /** School upload summaries keep district / block ids as keys (SchoolCountReport, preview tables). */
    private function fixSummaries(array $districts, array $blocks): void
    {
        if (! $districts && ! $blocks) {
            return;
        }
        $re = fn (array $rows, array $map) => collect($rows)->mapWithKeys(fn ($v, $k) => [(string) ($map[(int) $k] ?? $k) => $v])->all();
        ImportBatch::where('type', 'school')->whereNotNull('summary')->each(function (ImportBatch $b) use ($districts, $blocks, $re) {
            $s = $b->summary;
            if (isset($s['districts']) && $districts) {
                $s['districts'] = $re($s['districts'], $districts);
            }
            if (isset($s['blocks'])) {
                if ($blocks) {
                    $s['blocks'] = $re($s['blocks'], $blocks);
                }
                foreach ($s['blocks'] as &$row) {
                    if (isset($row['district_id']) && isset($districts[(int) $row['district_id']])) {
                        $row['district_id'] = $districts[(int) $row['district_id']];
                    }
                }
                unset($row);
            }
            DB::table('import_batches')->where('id', $b->id)->update(['summary' => json_encode($s)]);
        });
    }
}
