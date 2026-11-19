<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\School;
use App\Models\State;
use App\Models\UserJurisdiction;
use App\Services\AuditLogger;
use App\Services\DistrictBoundaryService;
use App\Support\UrlId;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Location Master screens (PRD 7.1). Locations are never deleted, only made inactive. */
class LocationController extends Controller
{
    private const TYPES = [
        'states' => [State::class, null, null, 'State'],
        'districts' => [District::class, State::class, 'state_id', 'District'],
        'blocks' => [Block::class, District::class, 'district_id', 'Block'],
        'clusters' => [Cluster::class, Block::class, 'block_id', 'Cluster'],
    ];

    /** Location types that carry latitude / longitude. */
    public const WITH_COORDINATES = ['districts', 'blocks'];

    public function index(Request $request, string $type = 'districts')
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        [$model, $parentModel, $fk, $label] = self::TYPES[$type];

        $items = $model::query()
            ->when($fk, fn ($q) => $q->with(str_replace('_id', '', $fk)))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->when($fk && $request->parent, fn ($q) => $q->where($fk, $request->parent))
            ->orderBy('name')->paginate(config('inspection.per_page'))->withQueryString();

        return view('admin.locations.index', [
            'type' => $type, 'label' => $label, 'items' => $items, 'fk' => $fk,
            'geo' => in_array($type, self::WITH_COORDINATES, true),
            'parents' => $parentModel ? $parentModel::orderBy('name')->get() : collect(),
            'counts' => collect(self::TYPES)->map(fn ($t) => $t[0]::count()),
            // V6 Module 7: KML district outlines that a district can be linked to
            'boundaries' => app(DistrictBoundaryService::class),
            'boundaryNames' => $type === 'districts' ? app(DistrictBoundaryService::class)->names() : [],
        ]);
    }

    public function store(Request $request, string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        [$model, $parentModel, $fk] = self::TYPES[$type];
        $data = $request->validate($this->rules($type, $fk, $parentModel));
        $rec = $model::create($data + ['is_active' => true]);
        AuditLogger::log('location_created', $rec, ['type' => $type] + $data);

        return back()->with('success', 'Added '.$rec->name.'.');
    }

    public function update(Request $request, string $type, string $id)
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        [$model, $parentModel, $fk] = self::TYPES[$type];
        // V6 Module 4: the ID in the URL is an encrypted token
        $rec = $model::findOrFail(UrlId::decode($model, $id) ?? abort(404));

        if ($request->has('toggle')) {
            // Inactive hides the location from selection but keeps its history (PRD 7.1)
            $rec->update(['is_active' => ! $rec->is_active]);
            AuditLogger::log('location_'.($rec->is_active ? 'activated' : 'deactivated'), $rec, ['type' => $type]);

            return back()->with('success', $rec->name.' is now '.($rec->is_active ? 'active' : 'inactive').'.');
        }

        $data = $request->validate($this->rules($type, $fk, $parentModel, $rec->id));
        $rec->update($data);
        AuditLogger::log('location_updated', $rec, ['type' => $type] + $data);

        return back()->with('success', 'Updated '.$rec->name.'.');
    }

    private function rules(string $type, ?string $fk, ?string $parentModel, ?int $ignore = null): array
    {
        $table = $type;
        $rules = [
            'code' => ['required', 'regex:/^\d{1,15}$/', Rule::unique($table, 'code')->ignore($ignore)],
            'name' => ['required', 'string', 'max:120'],
        ];
        if ($fk) {
            $rules[$fk] = ['required', Rule::exists((new $parentModel)->getTable(), 'id')];
        }
        if (in_array($type, self::WITH_COORDINATES, true)) {
            // optional headquarters location; both or neither
            $rules['latitude'] = ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'];
            $rules['longitude'] = ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'];
        }
        if ($type === 'districts') {
            $rules['boundary_name'] = ['nullable', Rule::in(app(DistrictBoundaryService::class)->names())];
        }

        return $rules;
    }
}
