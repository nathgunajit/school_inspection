<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
    public function index(Request $request)
    {
        $schools = School::with(['district', 'block', 'cluster', 'category'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('udise_code', 'like', "$s%")->orWhere('name', 'like', "%$s%")))
            ->when($request->district_id, fn ($q, $v) => $q->where('district_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')->paginate(config('inspection.per_page'))->withQueryString();

        return view('admin.schools.index', ['schools' => $schools, 'districts' => District::orderBy('name')->get()]);
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
        ], ['block_id.exists' => 'Select a block of the selected district.', 'cluster_id.exists' => 'Select a cluster of the selected block.']);

        $data['state_id'] = District::find($data['district_id'])->state_id;

        return $data;
    }
}
