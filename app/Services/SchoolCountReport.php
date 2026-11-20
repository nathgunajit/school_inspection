<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\ImportBatch;
use App\Models\School;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * District-wise school counts for the Excel Upload page (State Admin): blocks, clusters,
 * schools (active / closed), schools with GPS and the last district-wise upload.
 * One grouped query per measure, so the page cost does not grow with the number of districts.
 */
class SchoolCountReport
{
    private function schoolCounts(string $by, ?int $districtId = null): Collection
    {
        return School::query()->when($districtId, fn ($q) => $q->where('district_id', $districtId))
            ->groupBy($by)->select($by)
            ->selectRaw('count(*) as schools')
            ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
            ->selectRaw("sum(case when status = 'closed' then 1 else 0 end) as closed")
            ->selectRaw('sum(case when latitude is not null and longitude is not null then 1 else 0 end) as gps')
            ->get()->keyBy($by);
    }

    /** One row per district, ordered by name. */
    public function districts(): Collection
    {
        $schools = $this->schoolCounts('district_id');
        $blocks = Block::groupBy('district_id')->select('district_id', DB::raw('count(*) as n'))->pluck('n', 'district_id');
        $clusters = Cluster::join('blocks', 'blocks.id', '=', 'clusters.block_id')->groupBy('blocks.district_id')
            ->select('blocks.district_id', DB::raw('count(*) as n'))->pluck('n', 'district_id');
        $uploads = ImportBatch::whereNotNull('district_id')->groupBy('district_id')
            ->select('district_id', DB::raw('max(created_at) as last'))->pluck('last', 'district_id');

        return District::orderBy('name')->get(['id', 'code', 'name'])->map(fn ($d) => (object) [
            'id' => $d->id, 'code' => $d->code, 'name' => $d->name,
            'blocks' => (int) ($blocks[$d->id] ?? 0),
            'clusters' => (int) ($clusters[$d->id] ?? 0),
            'schools' => (int) ($schools[$d->id]->schools ?? 0),
            'active' => (int) ($schools[$d->id]->active ?? 0),
            'closed' => (int) ($schools[$d->id]->closed ?? 0),
            'gps' => (int) ($schools[$d->id]->gps ?? 0),
            'last_upload' => isset($uploads[$d->id]) ? \Illuminate\Support\Carbon::parse($uploads[$d->id]) : null,
        ]);
    }

    /** One row per block of a district. */
    public function blocks(int $districtId): Collection
    {
        $schools = $this->schoolCounts('block_id', $districtId);
        $clusters = Cluster::join('blocks', 'blocks.id', '=', 'clusters.block_id')->where('blocks.district_id', $districtId)
            ->groupBy('clusters.block_id')->select('clusters.block_id', DB::raw('count(*) as n'))->pluck('n', 'block_id');

        return Block::where('district_id', $districtId)->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($b) => (object) [
            'id' => $b->id, 'code' => $b->code, 'name' => $b->name,
            'clusters' => (int) ($clusters[$b->id] ?? 0),
            'schools' => (int) ($schools[$b->id]->schools ?? 0),
            'active' => (int) ($schools[$b->id]->active ?? 0),
            'closed' => (int) ($schools[$b->id]->closed ?? 0),
            'gps' => (int) ($schools[$b->id]->gps ?? 0),
        ]);
    }

    /** Workbook: District summary + every block. */
    public function export(): \App\Exports\SchoolTemplateExport
    {
        $districts = $this->districts();
        $dRows = $districts->map(fn ($d) => [$d->code, $d->name, $d->blocks, $d->clusters, $d->schools, $d->active, $d->closed, $d->gps, $d->last_upload?->format('d M Y')])->all();
        $dRows[] = ['', 'Total', $districts->sum('blocks'), $districts->sum('clusters'), $districts->sum('schools'), $districts->sum('active'), $districts->sum('closed'), $districts->sum('gps'), ''];
        $bRows = [];
        foreach ($districts as $d) {
            foreach ($this->blocks($d->id) as $b) {
                $bRows[] = [$d->name, $b->code, $b->name, $b->clusters, $b->schools, $b->active, $b->closed, $b->gps];
            }
        }

        return new \App\Exports\SchoolTemplateExport([
            [new \App\Exports\ArrayExport(['district_code', 'district', 'blocks', 'clusters', 'schools', 'active', 'closed', 'with_gps', 'last_upload'], $dRows), 'Districts'],
            [new \App\Exports\ArrayExport(['district', 'block_code', 'block', 'clusters', 'schools', 'active', 'closed', 'with_gps'], $bRows), 'Blocks'],
        ]);
    }
}
