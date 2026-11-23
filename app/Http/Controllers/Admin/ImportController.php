<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\ImportBatch;
use App\Services\MasterImportService;
use App\Services\SchoolCountReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Excel Upload (PRD 7.3). School Master is uploaded for one district or (V12 item 6) for all districts in one
 * file: check the file (dry run, nothing saved), then confirm. The result is counted district-wise and block-wise.
 * The District report counts schools per district / block.
 */
class ImportController extends Controller
{
    private const PENDING = 'import_pending';

    /** V12 item 6: district_id value for one file covering every district. */
    public const ALL = 'all';

    public function index(Request $request, SchoolCountReport $report)
    {
        return view('admin.imports.index', [
            'batches' => ImportBatch::with('uploader', 'district:id,name')->latest()->paginate(config('inspection.per_page')),
            'districts' => District::orderBy('name')->get(['id', 'code', 'name']),
            'report' => $report->districts(),
            'preview' => session('import_preview'),
            'pending' => $request->session()->get(self::PENDING),
        ]);
    }

    public function template(Request $request, string $type)
    {
        abort_unless(in_array($type, ['location', 'school'], true), 404);
        if ($type === 'location' && $request->boolean('current')) {
            return Excel::download(MasterImportService::currentLocations(), 'location_master_current_'.now()->format('Y-m-d').'.xlsx');
        }
        if ($type === 'school' && $request->query('district') === self::ALL) {
            return Excel::download(MasterImportService::allDistrictsTemplate(), 'school_master_all_districts.xlsx');
        }
        if ($type === 'school' && $request->filled('district')) {
            $district = District::findOrFail((int) $request->query('district'));

            return Excel::download(MasterImportService::districtTemplate($district), 'school_master_'.Str::slug($district->name).'.xlsx');
        }

        return Excel::download(MasterImportService::template($type), $type.'_master_template.xlsx');
    }

    /** Location Master (and older clients): import straight away. */
    public function store(Request $request, MasterImportService $service)
    {
        $this->longRunning(); // Modification V13 item 5
        $data = $request->validate([
            'type' => ['required', 'in:location,school'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:'.config('inspection.import_max_kb', 51200)],
        ]);
        $district = isset($data['district_id']) ? District::find($data['district_id']) : null;

        $batch = $service->import($data['type'], $request->file('file'), $request->user(), $data['type'] === 'school' ? $district : null);

        return $this->done($batch);
    }

    /** Step 3: check the file (School Master for one district, or Location Master); nothing is saved yet. */
    public function preview(Request $request, MasterImportService $service)
    {
        $this->longRunning(); // Modification V13 item 5
        $data = $request->validate([
            'type' => ['nullable', 'in:location,school'],
            'district_id' => ['required_unless:type,location', 'nullable', function ($attr, $v, $fail) {
                if ($v !== self::ALL && ! District::whereKey((int) $v)->exists()) {
                    $fail('Choose a district from the list, or All districts.');
                }
            }],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:'.config('inspection.import_max_kb', 51200)],
        ], ['district_id.required_unless' => 'Choose the district (or All districts) first.']);
        $type = $data['type'] ?? 'school';
        $all = $type === 'school' && ($data['district_id'] ?? null) === self::ALL;
        $district = $type === 'school' && ! $all ? District::findOrFail((int) $data['district_id']) : null;
        $file = $request->file('file');

        $this->discardPending($request);
        $path = $file->storeAs('imports/pending', Str::random(32).'.'.($file->getClientOriginalExtension() ?: 'xlsx'), 'private');

        try {
            $result = $service->preview($type, Storage::disk('private')->path($path), $district, $all);
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }

        $request->session()->put(self::PENDING, ['path' => $path, 'type' => $type, 'district_id' => $district?->id, 'all' => $all,
            'district' => $all ? 'All districts' : $district?->name, 'name' => mb_substr($file->getClientOriginalName(), 0, 255)]);

        return redirect()->route('admin.imports.index')->with('import_preview', $result);
    }

    /** Save the file that was checked in preview(). */
    public function confirm(Request $request, MasterImportService $service)
    {
        $this->longRunning(); // Modification V13 item 5
        $p = $request->session()->get(self::PENDING);
        if (! $p || ! Storage::disk('private')->exists($p['path'])) {
            $request->session()->forget(self::PENDING);

            return redirect()->route('admin.imports.index')->with('error', 'The checked file has expired. Please upload the file again.');
        }

        $type = $p['type'] ?? 'school';
        $batch = $service->import($type, Storage::disk('private')->path($p['path']), $request->user(),
            $type === 'school' && empty($p['all']) ? District::findOrFail($p['district_id']) : null, $p['name'], $type === 'school' && ! empty($p['all']));
        $this->discardPending($request);

        return $this->done($batch);
    }

    public function cancel(Request $request)
    {
        $this->discardPending($request);

        return redirect()->route('admin.imports.index');
    }

    /** Block rows of one district for the report drill-down. */
    public function district(Request $request, SchoolCountReport $report)
    {
        $id = (int) $request->query('district');
        abort_unless(District::whereKey($id)->exists(), 404);

        return response()->json(['blocks' => $report->blocks($id)->values()]);
    }

    public function report(SchoolCountReport $report)
    {
        return Excel::download($report->export(), 'school_count_by_district_'.now()->format('Y-m-d').'.xlsx');
    }

    public function errors(ImportBatch $batch)
    {
        abort_unless($batch->error_file_path && Storage::disk('private')->exists($batch->error_file_path), 404);

        return Storage::disk('private')->download($batch->error_file_path, "import_{$batch->id}_errors.xlsx");
    }

    private function done(ImportBatch $batch)
    {
        return redirect()->route('admin.imports.index')->with('import_result', $batch->id)->with(
            $batch->rejected ? 'warning' : 'success',
            "Upload complete: {$batch->added} added, {$batch->updated} updated, {$batch->rejected} rejected."
        );
    }

    private function discardPending(Request $request): void
    {
        if ($p = $request->session()->pull(self::PENDING)) {
            Storage::disk('private')->delete($p['path']);
        }
    }

    /**
     * Modification V13 item 5: a state-wide School Master (44,000 rows) runs for minutes. Lift the PHP time and
     * memory limits for this request, keep going if the browser is closed (no half-saved file), and give MySQL
     * longer session timeouts.
     */
    private function longRunning(): void
    {
        set_time_limit((int) config('inspection.import_timeout', 0));
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }
        ignore_user_abort(true);
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('SET SESSION wait_timeout = 28800, net_read_timeout = 600, net_write_timeout = 600');
        }
    }
}
