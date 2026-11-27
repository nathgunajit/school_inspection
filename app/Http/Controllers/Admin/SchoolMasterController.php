<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssemblyConstituency;
use App\Models\ParliamentaryConstituency;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\MasterValue;
use App\Models\School;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** School Master screens (PRD 7.2). */
class SchoolMasterController extends Controller
{
    /** Modification V13 item 2: list filters (query string, numeric IDs), shared by the list and its Excel export. */
    public const FILTERS = ['q', 'district_id', 'block_id', 'cluster_id', 'ac_id', 'pc_id', 'management_id', 'category_id', 'area_type', 'school_type', 'status'];

    public function index(Request $request)
    {
        [$lockDistrict, $lockBlock] = $this->lockArea($request);
        $base = $this->filtered($request);
        $schools = (clone $base)->with(['district:id,name', 'block:id,name', 'cluster:id,name', 'category:id,label', 'management:id,label', 'ac:id,code,name', 'pc:id,code,name'])
            ->orderBy('name')->orderBy('id')->paginate(config('inspection.per_page'))->withQueryString();

        $districtId = (int) $request->district_id;
        $blockId = (int) $request->block_id;

        return view('admin.schools.index', [
            'schools' => $schools,
            'total' => School::query()->when($lockDistrict, fn ($q, $v) => $q->where('district_id', $v))->when($lockBlock, fn ($q, $v) => $q->where('block_id', $v))->count(),
            'filtered' => collect(self::FILTERS)->contains(fn ($k) => filled($request->query($k))),
            'districts' => District::when($lockDistrict, fn ($q, $v) => $q->whereKey($v))->orderBy('name')->get(['id', 'code', 'name']),
            'blocks' => $districtId ? Block::where('district_id', $districtId)->when($lockBlock, fn ($q, $v) => $q->whereKey($v))->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'locked' => (bool) $lockDistrict,
            'clusters' => $blockId ? Cluster::where('block_id', $blockId)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'acs' => AssemblyConstituency::orderBy('code')->get(['id', 'code', 'name']),
            'pcs' => ParliamentaryConstituency::orderBy('code')->get(['id', 'code', 'name']),
            'managements' => MasterValue::ofType('management')->get(),
            'categories' => MasterValue::ofType('category')->get(),
        ]);
    }

    /** Modification V13 item 2: the filtered list as Excel, in the upload column order (edit and upload again). */
    public function export(Request $request)
    {
        $this->lockArea($request);
        // a state-wide list (44,000 rows) takes about 2–3 minutes and ~650 MB
        set_time_limit((int) config('inspection.import_timeout', 0));
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }
        AuditLogger::log('school_list_exported', null, ['filters' => array_filter($request->only(self::FILTERS))]);

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\SchoolListExport($this->filtered($request)), 'school_master_'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * 23-11-2026: the filtered list as PDF. DomPDF cannot lay out the whole state (44,000 rows), so the PDF
     * is limited to config('inspection.master_pdf_max_rows'); a larger list asks for a district / block filter.
     */
    public function exportPdf(Request $request)
    {
        $this->lockArea($request);
        $base = $this->filtered($request);
        $count = (clone $base)->count();
        $max = (int) config('inspection.master_pdf_max_rows', 3000);
        if ($count > $max) {
            return back()->with('warning', number_format($count).' schools match. A PDF holds up to '.number_format($max)
                .' schools: filter by district, block or cluster, or use Export Excel for the full list.');
        }
        // one district (2,200 schools): about 70 s, 170 pages and ~650 MB
        set_time_limit(300);
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }

        $rows = $base->with(['district:id,code,name', 'block:id,code,name', 'cluster:id,code,name', 'category:id,label', 'management:id,label'])
            ->orderBy('name')->orderBy('id')->get()->values()
            ->map(fn (School $s, $i) => [$i + 1, $s->udise_code, $s->name, $s->cluster?->label, $s->block?->label, $s->district?->label,
                $s->category?->label, $s->management?->label, ucfirst((string) $s->status)])->all();

        $id = fn (string $k) => (int) $request->query($k) ?: null;
        $filters = array_values(array_filter([
            trim((string) $request->query('q')) !== '' ? 'Search: '.trim((string) $request->query('q')) : null,
            ($v = $id('district_id')) ? 'District: '.District::find($v)?->label : null,
            ($v = $id('block_id')) ? 'Block: '.Block::find($v)?->label : null,
            ($v = $id('cluster_id')) ? 'Cluster: '.Cluster::find($v)?->label : null,
            ($v = $id('ac_id')) ? 'AC: '.AssemblyConstituency::find($v)?->name : null,
            ($v = $id('pc_id')) ? 'PC: '.ParliamentaryConstituency::find($v)?->name : null,
            ($v = $id('management_id')) ? 'Management: '.MasterValue::find($v)?->label : null,
            ($v = $id('category_id')) ? 'Category: '.MasterValue::find($v)?->label : null,
            ($v = $request->query('area_type')) ? 'Area: '.(School::AREA_TYPES[$v] ?? $v) : null,
            ($v = $request->query('school_type')) ? 'Type: '.(School::SCHOOL_TYPES[$v] ?? $v) : null,
            ($v = $request->query('status')) ? 'Status: '.ucfirst((string) $v) : null,
        ]));
        AuditLogger::log('school_list_exported', null, ['format' => 'pdf', 'filters' => array_filter($request->only(self::FILTERS)), 'rows' => count($rows)]);

        return \App\Support\MasterPdf::download('School Master',
            ['Sl', 'UDISE', 'School name', 'Cluster', 'Block', 'District', 'Category', 'Management', 'Status'],
            $rows, $filters, 'school_master_'.now()->format('Y-m-d').'.pdf');
    }

    /** Modification V13 item 3: delete a school with no inspection and no schedule. */
    public function destroy(School $school)
    {
        $reason = app(\App\Services\MasterDeleteGuard::class)->delete($school, 'school_deleted', $school->name);

        return $reason ? back()->with('error', $reason) : back()->with('success', 'Deleted '.$school->name.' ('.$school->udise_code.').');
    }

    /**
     * Modification V13 item 3: remove the schools of one district that have no inspection and no schedule.
     * The district name must be typed to confirm. Schools in use are kept and counted.
     */
    public function removeDistrict(Request $request)
    {
        $data = $request->validate(['district_id' => ['required', 'exists:districts,id'], 'confirm_name' => ['required', 'string']]);
        $district = District::findOrFail($data['district_id']);
        if (mb_strtoupper(trim($data['confirm_name'])) !== mb_strtoupper($district->name)) {
            return redirect()->route('admin.imports.index', ['tab' => 'report'])->withErrors(['confirm_name' => 'Type the district name exactly to confirm: '.$district->name.'.'], 'remove')->withInput();
        }
        set_time_limit(0);

        $used = fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('inspections')->whereColumn('inspections.school_id', 'schools.id'))
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('inspection_schedule_items')->whereColumn('inspection_schedule_items.school_id', 'schools.id'));
        $total = School::withTrashed()->where('district_id', $district->id)->count();
        $removed = 0;
        School::withTrashed()->where('district_id', $district->id)->whereNot($used)->select('id')->chunkById(1000, function ($rows) use (&$removed) {
            $removed += School::withTrashed()->whereIn('id', $rows->pluck('id'))->forceDelete();
        });
        $kept = $total - $removed;
        AuditLogger::log('schools_removed', $district, ['district' => $district->name, 'removed' => $removed, 'kept' => $kept]);

        return redirect()->route('admin.imports.index', ['tab' => 'report'])->with('success', number_format($removed).' school(s) of '.$district->name.' removed.'.($kept ? ' '.number_format($kept).' kept: they have inspections or schedules.' : ''));
    }

    /** The list query with the filters applied (numeric IDs from the query string). */
    /**
     * V16 item 1 (27-11-2026): below the State Admin (District Programmer / Block MIS, read-only) the list is fixed to the
     * officer's own district, and for a Block MIS their block, whatever the query string says. Returns [district, block].
     */
    private function lockArea(Request $request): array
    {
        $user = $request->user();
        if ($user->can('manage_masters')) {
            return [null, null];
        }
        $j = app(\App\Services\JurisdictionService::class)->current($user);
        $district = (int) $j?->district_id ?: -1; // no posting: nothing
        $block = $user->isBlockMis() ? ((int) $j?->block_id ?: -1) : null;
        $request->query->set('district_id', $district);
        if ($block) {
            $request->query->set('block_id', $block);
        }

        return [$district, $block];
    }

    private function filtered(Request $request)
    {
        $id = fn (string $k) => (int) $request->query($k) ?: null;

        return School::query()
            ->when(trim((string) $request->query('q')), fn ($q, $s) => \App\Support\SchoolSearch::apply($q, $s))
            ->when($id('district_id'), fn ($q, $v) => $q->where('district_id', $v))
            ->when($id('block_id'), fn ($q, $v) => $q->where('block_id', $v))
            ->when($id('cluster_id'), fn ($q, $v) => $q->where('cluster_id', $v))
            ->when($id('ac_id'), fn ($q, $v) => $q->where('ac_id', $v))
            ->when($id('pc_id'), fn ($q, $v) => $q->where('pc_id', $v))
            ->when($id('management_id'), fn ($q, $v) => $q->where('management_id', $v))
            ->when($id('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->query('area_type'), fn ($q, $v) => $q->where('area_type', $v))
            ->when($request->query('school_type'), fn ($q, $v) => $q->where('school_type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v));
    }

    public function create()
    {
        return view('admin.schools.form', $this->formData(new School(['status' => 'active'])));
    }

    public function store(Request $request)
    {
        $school = School::create($this->validated($request));
        AuditLogger::log('school_created', $school);

        return redirect()->route('admin.schools.index')->with('success', 'School added.');
    }

    public function edit(School $school)
    {
        return view('admin.schools.form', $this->formData($school));
    }

    public function update(Request $request, School $school)
    {
        $school->update($this->validated($request, $school));
        AuditLogger::log('school_updated', $school);

        return redirect()->route('admin.schools.index')->with('success', 'School updated.');
    }

    private function formData(School $school): array
    {
        return [
            'school' => $school,
            'districts' => District::where('is_active', true)->orderBy('name')->get(),
            'blocks' => $school->district_id ? Block::where('district_id', $school->district_id)->orderBy('name')->get() : collect(),
            'clusters' => $school->block_id ? Cluster::where('block_id', $school->block_id)->orderBy('name')->get() : collect(),
            'managements' => MasterValue::ofType('management')->get(),
            'categories' => MasterValue::ofType('category')->get(),
            // AC select grouped by PC; an inactive AC the school already has stays selectable
            'pcs' => ParliamentaryConstituency::orderBy('code')->with(['acs' => fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->orWhere('id', $school->ac_id))->orderBy('code')])->get(),
        ];
    }

    private function validated(Request $request, ?School $school = null): array
    {
        $data = $request->validate([
            'udise_code' => ['required', 'digits:11', Rule::unique('schools', 'udise_code')->ignore($school?->id)],
            'name' => ['required', 'string', 'max:200'],
            'district_id' => ['required', 'exists:districts,id'],
            'block_id' => ['required', Rule::exists('blocks', 'id')->where('district_id', $request->district_id)],
            'cluster_id' => ['required', Rule::exists('clusters', 'id')->where('block_id', $request->block_id)],
            'management_id' => ['nullable', Rule::exists('master_values', 'id')->where('type', 'management')],
            'category_id' => ['nullable', Rule::exists('master_values', 'id')->where('type', 'category')],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'status' => ['required', 'in:active,closed'],
            // UDISE+ 2025-26 fields
            'school_type' => ['nullable', Rule::in(array_keys(School::SCHOOL_TYPES))],
            'area_type' => ['nullable', Rule::in(array_keys(School::AREA_TYPES))],
            // 23-11-2026: one or more mediums (tick boxes) plus an optional other language
            'medium' => ['nullable'],
            'medium.*' => ['string', 'max:60'],
            'medium_other' => ['nullable', 'string', 'max:60'],
            'ac_id' => ['nullable', Rule::exists('assembly_constituencies', 'id')],
            'pincode' => ['nullable', 'digits:6'],
            'has_hs' => ['nullable', 'boolean'],
            'streams' => ['nullable', 'array'],
            'streams.*' => [Rule::in(array_keys(School::STREAMS))],
        ], ['block_id.exists' => 'Select a block of the selected district.', 'cluster_id.exists' => 'Select a cluster of the selected block.',
            'pincode.digits' => 'Pincode must be 6 digits.']);

        $data['state_id'] = District::find($data['district_id'])->state_id;
        // the PC always follows the chosen AC (AC / PC master)
        $data['pc_id'] = ($data['ac_id'] ?? null) ? AssemblyConstituency::whereKey($data['ac_id'])->value('pc_id') : null;
        // Streams apply to Higher Secondary schools only: unticked "Has Higher Secondary" clears them.
        $picked = $data['streams'] ?? [];
        $data['streams'] = $request->boolean('has_hs')
            ? collect(School::STREAMS)->mapWithKeys(fn ($l, $k) => [$k => in_array($k, $picked, true)])->all()
            : null;
        unset($data['has_hs']);
        // tick boxes (array) or older single text; saved as "Assamese, Bodo"
        $data['medium'] = School::normaliseMedium(array_merge((array) ($data['medium'] ?? []), [$data['medium_other'] ?? '']));
        unset($data['medium_other']);

        return $data;
    }
}
