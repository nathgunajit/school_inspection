<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use Illuminate\Http\Request;

/** Cascading dropdown data: each list shows only the areas under the selected parent (PRD 5.2). */
class LocationLookupController extends Controller
{
    public function __invoke(Request $request, string $type)
    {
        $parent = (int) $request->query('parent');
        [$model, $fk] = match ($type) {
            'districts' => [District::class, 'state_id'],
            'blocks' => [Block::class, 'district_id'],
            'clusters' => [Cluster::class, 'block_id'],
            default => abort(404),
        };

        return $model::query()
            ->where($fk, $parent)
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
