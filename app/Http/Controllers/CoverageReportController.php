<?php

namespace App\Http\Controllers;

use App\Models\CoverageSetting;
use App\Models\District;
use App\Services\AuditLogger;
use App\Services\CoverageReportService;
use App\Support\CoveragePeriod;
use App\Support\MasterPdf;
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
            'anyDistance' => $this->report->anyDistance(), // 28-11-2026: radius 0 = any distance
            'summary' => $this->report->summary($user, $f),
            'explain' => $this->report->explain($user, $f), // 26-11-2026: the figures in plain sentences
            'how' => $this->report->howCounted($f), // 27-11-2026: how the report is counted, for every user
            'levels' => $this->report->levels($user, $f),
            'level' => $level,
            'viewLabel' => $this->report->viewLabel($user, $f),
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
        AuditLogger::log('coverage_report_exported', null, ['format' => 'excel', 'type' => $period->type, 'from' => $period->fromDate(), 'to' => $period->toDate(),
            'district_id' => $f['district_id'], 'block_id' => $f['block_id'], 'cluster_id' => $f['cluster_id'], 'level' => $this->report->level($user, $f)]);

        return Excel::download($this->report->export($user, $f), $this->fileName($user, $f, 'xlsx'));
    }

    /** e.g. coverage-monthly-block-wise-2026-11-01-to-2026-11-30.xlsx (the period type and view that were downloaded). */
    private function fileName($user, array $f, string $ext): string
    {
        $period = $this->report->period($f);

        return 'coverage-'.\Illuminate\Support\Str::slug($period->typeLabel()).'-'.\Illuminate\Support\Str::slug($this->report->viewLabel($user, $f))
            .'-'.$period->fromDate().'-to-'.$period->toDate().'.'.$ext;
    }

    /** 23-11-2026: the report as on screen (summary + the level in view, or a cluster's schools) as a PDF. */
    public function exportPdf(Request $request)
    {
        $user = $request->user();
        $f = $this->report->filters($request, $user);
        $count = $this->report->pdfRowCount($user, $f);
        $max = MasterPdf::maxRows();
        if ($count > $max) {
            return back()->with('warning', number_format($count).' rows in this view. A PDF holds at most '.number_format($max)
                .': choose a district or block, or use Excel for the full report.');
        }
        MasterPdf::prepare();
        $period = $this->report->period($f);
        AuditLogger::log('coverage_report_exported', null, ['format' => 'pdf', 'type' => $period->type, 'from' => $period->fromDate(), 'to' => $period->toDate(),
            'district_id' => $f['district_id'], 'block_id' => $f['block_id'], 'cluster_id' => $f['cluster_id'], 'level' => $this->report->level($user, $f)]);

        // 25-11-2026: the title and the period chip name the period picked on screen (Daily, Monthly …)
        $chips = ['Area: '.$this->report->areaTitle($f), 'View: '.$this->report->viewLabel($user, $f),
            'Period: '.$period->typeLabel().' · '.$period->label().' ('.$period->from->format('d-m-Y').' to '.$period->to->format('d-m-Y').')', 'Downloaded by: '.$user->name];

        return MasterPdf::sections('Inspection Coverage Report · '.$period->typeLabel(), $this->report->pdfSections($user, $f), $chips, $this->fileName($user, $f, 'pdf'));
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
            'districts' => $user->isStateAdmin() ? District::orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'preview' => self::preview(),
        ]);
    }

    /**
     * 26-11-2026: the dates each choice gives today, for the "With these settings" sentence on the settings page
     * (the script only looks them up, it does no date maths).
     */
    public static function preview(): array
    {
        $range = fn (CoveragePeriod $p) => $p->from->isSameDay($p->to) ? $p->from->format('d M Y') : $p->from->format('d M Y').' – '.$p->to->format('d M Y');
        $names = CoveragePeriod::CURRENT_NAMES;

        return [
            'periods' => collect($names)->map(fn ($n, $t) => $n.' ('.$range(CoveragePeriod::forType($t, today())).')')->all() + ['custom' => 'the custom dates'],
            'cycles' => collect(CoverageSetting::CYCLES)->map(fn ($l, $c) => $range(CoveragePeriod::cycle($c, today())))->all(),
        ];
    }

    /** The saved setting as one plain sentence (success message). */
    private static function sentence(CoverageSetting $row): string
    {
        $p = self::preview();
        $period = $row->period_type === 'custom' && $row->custom_from
            ? $row->custom_from->format('d M Y').' – '.$row->custom_to?->format('d M Y') : ($p['periods'][$row->period_type] ?? $row->periodLabel());

        return 'The report now opens on '.$period.'. A school counts as inspected when an inspection is submitted in the '
            .strtolower($row->cycleLabel()).' cycle ('.($p['cycles'][$row->inspection_cycle] ?? '').').'
            .($row->district_id === null ? ' '.app(\App\Services\CoverageReportService::class)->radiusSentence() : '');
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
            'verify_any' => ['nullable', 'boolean'], // 28-11-2026: count a visit at any distance
            'verify_radius_m' => ['nullable', 'exclude_if:verify_any,1', 'integer', 'min:50', 'max:5000'],
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
            // 0 = any distance; switching it off without a number goes back to the configured default
            $old = (int) CoverageSetting::state()->verify_radius_m;
            $values['verify_radius_m'] = ! empty($data['verify_any']) ? 0
                : (int) ($data['verify_radius_m'] ?? ($old ?: config('inspection.coverage.verify_radius_m', 500)));
            $row = CoverageSetting::state();
            $row->update($values);
        } else {
            $row = CoverageSetting::updateOrCreate(['district_id' => $districtId], $values);
        }
        AuditLogger::log('coverage_setting_updated', $row, ['district_id' => $districtId] + $values);

        return back()->with('success', 'Coverage settings saved. '.self::sentence($row));
    }
}
