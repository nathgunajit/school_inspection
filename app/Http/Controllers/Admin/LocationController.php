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

/** Location Master screens (PRD 7.1). A location is made inactive; an unused one may be deleted (Modification V13). */
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

    /** V16 item 1 (27-11-2026): below the State Admin (District Programmer / Block MIS) only these lists, own area, read-only. */
    public const AREA_TYPES = ['districts', 'blocks'];

    public function index(Request $request, string $type = 'districts')
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $canEdit = $request->user()->can('manage_masters');
        abort_unless($canEdit || in_array($type, self::AREA_TYPES, true), 403);
        [$model, $parentModel, $fk, $label] = self::TYPES[$type];

        $items = $this->listQuery($request, $type)
            ->when($fk, fn ($q) => $q->with(str_replace('_id', '', $fk)))
            ->paginate(config('inspection.per_page'))->withQueryString();

        return view('admin.locations.index', [
            'type' => $type, 'label' => $label, 'items' => $items, 'fk' => $fk,
            'geo' => in_array($type, self::WITH_COORDINATES, true),
            'parents' => $parentModel ? $this->scoped($request, $parentModel === District::class ? 'districts' : null, $parentModel::query())->orderBy('name')->get() : collect(),
            'counts' => collect(self::TYPES)->map(fn ($t, $k) => $this->scoped($request, $k, $t[0]::query())->count()),
            'tabs' => $canEdit ? array_keys(self::TYPES) : self::AREA_TYPES,
            'canEdit' => $canEdit,
            // V6 Module 7: KML district outlines that a district can be linked to
            'boundaries' => app(DistrictBoundaryService::class),
            'boundaryNames' => $type === 'districts' ? app(DistrictBoundaryService::class)->names() : [],
        ]);
    }

    /** The list with the screen's filters (search by name / code, parent), shared by the page and its downloads. */
    private function listQuery(Request $request, string $type)
    {
        [$model, , $fk] = self::TYPES[$type];

        return $this->scoped($request, $type, $model::query())
            ->when(trim((string) $request->query('q')), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->when($fk && (int) $request->query('parent'), fn ($q) => $q->where($fk, (int) $request->query('parent')))
            ->orderBy('name')->orderBy('id');
    }

    /** V16: a District Programmer sees their district and its blocks, a Block MIS their district and their block. */
    private function scoped(Request $request, ?string $type, $query)
    {
        $user = $request->user();
        if ($type === null || $user->can('manage_masters')) {
            return $query;
        }
        $j = app(\App\Services\JurisdictionService::class)->current($user);
        $district = (int) $j?->district_id ?: -1;

        return match ($type) {
            'districts' => $query->whereKey($district),
            'blocks' => $query->where('district_id', $district)->when($user->isBlockMis(), fn ($q) => $q->whereKey((int) $j?->block_id ?: -1)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** 23-11-2026: the State / District / Block / Cluster Master list (with the screen's filters) as Excel or PDF. */
    public function export(Request $request, string $type, string $format)
    {
        abort_unless(isset(self::TYPES[$type]) && in_array($format, ['excel', 'pdf'], true), 404);
        abort_unless($request->user()->can('manage_masters') || in_array($type, self::AREA_TYPES, true), 403);
        [, $parentModel, $fk, $label] = self::TYPES[$type];
        $status = fn ($r) => $r->is_active ? 'Active' : 'Inactive';
        $coord = fn ($v) => $v === null ? '' : number_format((float) $v, 6, '.', '');

        // all 4,607 clusters as PDF: about 2 minutes, 200 pages and ~650 MB
        set_time_limit(300);
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }
        $query = $this->listQuery($request, $type);
        [$headings, $map] = match ($type) {
            'states' => [['Sl', 'Code', 'Name', 'Districts', 'Status'],
                fn ($r) => [$r->code, $r->name, $r->districts_count, $status($r)]],
            'districts' => [['Sl', 'Code', 'Name', 'State', 'Latitude', 'Longitude', 'Blocks', 'Clusters', 'Schools', 'Status'],
                fn ($r) => [$r->code, $r->name, $r->state?->name, $coord($r->latitude), $coord($r->longitude), $r->blocks_count, $r->clusters_count, $r->schools_count, $status($r)]],
            'blocks' => [['Sl', 'Code', 'Name', 'District', 'Latitude', 'Longitude', 'Clusters', 'Schools', 'Status'],
                fn ($r) => [$r->code, $r->name, $r->district?->label, $coord($r->latitude), $coord($r->longitude), $r->clusters_count, $r->schools_count, $status($r)]],
            'clusters' => [['Sl', 'Code', 'Name', 'Block', 'District', 'Schools', 'Status'],
                fn ($r) => [$r->code, $r->name, $r->block?->label, $r->block?->district?->label, $r->schools_count, $status($r)]],
        };
        match ($type) {
            'states' => $query->withCount('districts'),
            'districts' => $query->with('state:id,name')->withCount(['blocks', 'clusters', 'schools']),
            'blocks' => $query->with('district:id,code,name')->withCount(['clusters', 'schools']),
            'clusters' => $query->with('block:id,code,name,district_id', 'block.district:id,code,name')->withCount('schools'),
        };
        $rows = $query->get()->values()->map(fn ($r, $i) => array_merge([$i + 1], $map($r)))->all();

        $filters = array_values(array_filter([
            trim((string) $request->query('q')) !== '' ? 'Search: '.trim((string) $request->query('q')) : null,
            $fk && (int) $request->query('parent') && ($p = $parentModel::find((int) $request->query('parent')))
                ? class_basename($parentModel).': '.($p->label ?? $p->name) : null,
        ]));
        AuditLogger::log('master_exported', null, ['master' => $type, 'format' => $format, 'filters' => $filters, 'rows' => count($rows)]);

        $file = strtolower($label).'_master_'.now()->format('Y-m-d');
        if ($format === 'pdf') {
            return \App\Support\MasterPdf::download($label.' Master', $headings, $rows, $filters, $file.'.pdf');
        }

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ArrayExport($headings, $rows), $file.'.xlsx');
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

        // V12 item 7: edit errors go to their own bag, so they show in the Edit window, not under Add
        $data = $request->validateWithBag('edit', $this->rules($type, $fk, $parentModel, $rec->id));
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
            // optional office location (23-11-2026: shown on the Map View with distances); both or neither
            $rules['latitude'] = ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'];
            $rules['longitude'] = ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'];
            $rules['office_address'] = ['nullable', 'string', 'max:255'];
        }
        if ($type === 'districts') {
            $rules['boundary_name'] = ['nullable', Rule::in(app(DistrictBoundaryService::class)->names())];
        }

        return $rules;
    }

    /** Modification V13 item 3: delete a district / block / cluster that nothing uses. */
    public function destroy(string $type, string $id)
    {
        abort_unless(isset(self::TYPES[$type]) && $type !== 'states', 404);
        [$model] = self::TYPES[$type];
        $rec = $model::findOrFail(UrlId::decode($model, $id) ?? abort(404));
        $reason = app(\App\Services\MasterDeleteGuard::class)->delete($rec, 'location_deleted', $rec->name);

        return $reason ? back()->with('error', $reason) : back()->with('success', 'Deleted '.$rec->name.'.');
    }
}
