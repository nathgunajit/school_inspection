<?php

namespace App\Services;

use App\Models\AssemblyConstituency;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\ParliamentaryConstituency;
use App\Models\School;
use App\Models\TlmItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Modification V13 item 3: a master record may be deleted only when nothing uses it.
 * blockers() lists what still points at the record ("120 schools"); an empty list means it can go.
 * The foreign keys are `restrict`, so the database refuses a delete this check misses.
 */
class MasterDeleteGuard
{
    /** @return array<string, int> label => count of linked rows (only the non-zero ones) */
    public function blockers(Model $m): array
    {
        $count = fn (string $table, string $col) => DB::table($table)->where($col, $m->getKey())->count();
        $links = match (true) {
            $m instanceof District => ['blocks' => $count('blocks', 'district_id'), 'schools' => $count('schools', 'district_id'),
                'officers' => $count('user_jurisdictions', 'district_id'), 'inspections' => $count('inspections', 'district_id')],
            $m instanceof Block => ['clusters' => $count('clusters', 'block_id'), 'schools' => $count('schools', 'block_id'),
                'officers' => $count('user_jurisdictions', 'block_id'), 'inspections' => $count('inspections', 'block_id')],
            $m instanceof Cluster => ['schools' => $count('schools', 'cluster_id'),
                'officers' => $count('user_jurisdictions', 'cluster_id'), 'inspections' => $count('inspections', 'cluster_id')],
            $m instanceof School => ['inspections' => $count('inspections', 'school_id'), 'schedules' => $count('inspection_schedule_items', 'school_id')],
            $m instanceof AssemblyConstituency => ['schools' => $count('schools', 'ac_id')],
            $m instanceof ParliamentaryConstituency => ['ACs' => $count('assembly_constituencies', 'pc_id'), 'schools' => $count('schools', 'pc_id')],
            $m instanceof TlmItem => ['classroom observations' => $count('inspection_classroom_tlm', 'tlm_item_id')],
            default => throw new \InvalidArgumentException('Not a master record: '.$m::class),
        };

        return array_filter($links);
    }

    /** "used by 120 schools and 2 officers" */
    public function message(array $blockers): string
    {
        $parts = array_map(fn ($n, $l) => number_format($n).' '.($n === 1 ? rtrim($l, 's') : $l), $blockers, array_keys($blockers));

        return 'used by '.(count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)).' and '.end($parts) : $parts[0]);
    }

    /**
     * Delete the record when unused; audited as "<action>" with its code and name.
     *
     * @return string|null null when deleted, else the reason it was kept
     */
    public function delete(Model $m, string $action, string $name): ?string
    {
        if ($b = $this->blockers($m)) {
            return "{$name} cannot be deleted: it is {$this->message($b)}. Make it inactive instead.";
        }
        $meta = array_filter($m->only(['code', 'name', 'udise_code', 'class', 'list']), fn ($v) => $v !== null);
        DB::transaction(function () use ($m, $action, $meta) {
            // a removed school frees its UDISE code for a later upload, so it is deleted for good
            method_exists($m, 'forceDelete') ? $m->forceDelete() : $m->delete();
            AuditLogger::log($action, $m, $meta);
        });

        return null;
    }
}
