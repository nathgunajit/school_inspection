<?php

namespace App\Http\Controllers;

use App\Models\CoverageSetting;
use App\Models\District;
use App\Services\AuditLogger;
use App\Services\CoverageReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Inspection Coverage Report (District / Block / Cluster wise) and its coverage-period settings.
 * Visit Coverage = at least one verified visit to each assigned school in the period;
 * Inspection Coverage = a submitted inspection within the prescribed inspection cycle.
 */
class CoverageReportController extends Controller
{
    public function __construct(private CoverageReportService $report) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $f = $this->report->filters($request, $user);
        $period = $this->report->period($f);
        $level = $this->report->level($user, $f);

        return view('reports.coverage.index', [
            'filters' => $f,
            'period' => $period,
            'cycle' => $this->report->cycle($f),
            'setting' => $this->report->setting($f),
            'radius' => $this->report->radius(),
            'summary' => $this->report->summary($user, $f),
            'levels' => $this->report->levels($user, $f),
            'level' => $level,
            'rows' => $level ? $this->report->areas($user, $f, $level) : collect(),
            'schools' => $f['cluster_id'] ? $this->report->schools($user, $f) : collect(),
            'areaTitle' => $this->report->areaTitle($f),
            'sorts' => CoverageReportService::SORTS,
        ] + $this->report->options($user, $f));
    }

    public function export(Request $request)
    {
        $user = $request->user();
        $f = $this->report->filters($request, $user);
        $period = $this->report->period($f);
        AuditLogger::log('coverage_report_exported', null, ['from' => $period->fromDate(), 'to' => $period->toDate(),
            'district_id' => $f['district_id'], 'block_id' => $f['block_id'], 'cluster_id' => $f['cluster_id']]);

        return Excel::download($this->report->export($user, $f), 'coverage-report-'.$period->fromDate().'-to-'.$period->toDate().'.xlsx');
    }

    /* ======================= settings ======================= */

    /** State Admin: state default + every district override. District officer (Manage users): own district only. */
    public function settings(Request $request)
    {
        $user = $request->user();
        $own = $user->isStateAdmin() ? null : $user->currentJurisdiction?->district_id;
        abort_if(! $user->isStateAdmin() && ! $own, 403);

        return view('reports.coverage.settings', [
            'isState' => $user->isStateAdmin(),
            'state' => CoverageSetting::state(),
            'district' => $own ? District::find($own) : null,
            'own' => $own ? CoverageSetting::where('district_id', $own)->first() : null,
            'overrides' => $user->isStateAdmin() ? CoverageSetting::with(['district', 'updater'])->whereNotNull('district_id')->get()->sortBy('district.name') : collect(),
            'districts' => $user->isStateAdmin() ? District::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $user = $request->user();
        $isState = $user->isStateAdmin();
        $data = $request->validate([
            'scope' => ['required', 'in:state,district'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'period_type' => ['required_unless:reset,1', 'nullable', Rule::in(array_keys(CoverageSetting::PERIODS))],
            'custom_from' => ['nullable', 'date', 'required_if:period_type,custom'],
            'custom_to' => ['nullable', 'date', 'after_or_equal:custom_from', 'required_if:period_type,custom'],
            'inspection_cycle' => ['required_unless:reset,1', 'nullable', Rule::in(array_keys(CoverageSetting::CYCLES))],
            'verify_radius_m' => ['nullable', 'integer', 'min:50', 'max:5000'],
            'reset' => ['nullable', 'boolean'],
        ], [
            'custom_from.required_if' => 'Enter the start date of the custom range.',
            'custom_to.required_if' => 'Enter the end date of the custom range.',
            'custom_to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        // which row: the state default (State Admin only) or a district (State Admin: any; District officer: own only)
        if ($data['scope'] === 'state') {
            abort_unless($isState, 403);
            $districtId = null;
        } else {
            $districtId = $isState ? ($data['district_id'] ?? null) : $user->currentJurisdiction?->district_id;
            abort_unless($districtId, 422, 'Select the district.');
            abort_if(! $isState && (int) ($data['district_id'] ?? $districtId) !== (int) $districtId, 403);
        }

        if ($districtId && ! empty($data['reset'])) {
            $row = CoverageSetting::where('district_id', $districtId)->first();
            $row?->delete();
            AuditLogger::log('coverage_setting_reset', $row, ['district_id' => $districtId]);

            return back()->with('success', 'The district now follows the state default coverage period.');
        }

        $values = [
            'period_type' => $data['period_type'],
            'custom_from' => $data['period_type'] === 'custom' ? $data['custom_from'] : null,
            'custom_to' => $data['period_type'] === 'custom' ? $data['custom_to'] : null,
            'inspection_cycle' => $data['inspection_cycle'],
            'updated_by' => $user->id,
        ];
        if ($districtId === null) {
            $values['verify_radius_m'] = (int) ($data['verify_radius_m'] ?? CoverageSetting::state()->verify_radius_m);
            $row = CoverageSetting::state();
            $row->update($values);
        } else {
            $row = CoverageSetting::updateOrCreate(['district_id' => $districtId], $values);
        }
        AuditLogger::log('coverage_setting_updated', $row, ['district_id' => $districtId] + $values);

        return back()->with('success', 'Coverage period saved: '.$row->periodLabel().', inspection cycle '.strtolower($row->cycleLabel()).'.');
    }
}
