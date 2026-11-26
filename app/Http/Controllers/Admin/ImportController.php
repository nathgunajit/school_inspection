<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportMasterFile;
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
        // 26-11-2026: the officer's current upload (checking, checked, saving) comes from its batch row
        $current = $this->current($request);
        if ($current && in_array($current->status, ['done', 'failed', 'check_failed'], true)) {
            $request->session()->forget(self::PENDING); // shown once below, then the page is back to a new upload
            if ($current->status === 'done') {
                session()->now('import_result', $current->id);
                session()->now($current->rejected ? 'warning' : 'success', $this->doneMessage($current));
            } else {
                session()->now('error', $current->error ?: 'The upload could not be finished.');
            }
            $current = null;
        }

        return view('admin.imports.index', [
            'batches' => ImportBatch::with('uploader', 'district:id,code,name')->whereIn('status', ImportBatch::FINISHED)->latest()->paginate(config('inspection.per_page')),
            'districts' => District::orderBy('name')->get(['id', 'code', 'name']),
            'report' => $report->districts(),
            'preview' => $current?->status === 'checked' ? $current->preview : null,
            'pending' => $current?->status === 'checked' ? $current->pendingInfo() : null,
            'working' => $current?->isWorking() ? $current : null,
        ]);
    }

    /** 26-11-2026: JSON for imports.js while the officer's upload is being checked or saved. */
    public function status(Request $request)
    {
        $b = $this->current($request);

        return response()->json(['status' => $b?->status, 'label' => $b?->statusLabel()])->header('Cache-Control', 'no-store, private');
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

    /** Location Master (and older clients): import straight away (on the queue since 26-11-2026). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:location,school'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:'.config('inspection.import_max_kb', 51200)],
        ]);
        $district = $data['type'] === 'school' && isset($data['district_id']) ? District::find($data['district_id']) : null;
        $this->discardPending($request);
        $batch = $this->newBatch($request, $data['type'], $district, false, 'queued');

        return $this->started($request, $batch, 'import');
    }

    /** Step 3: check the file (School Master for one district, or Location Master); nothing is saved yet. */
    public function preview(Request $request)
    {
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

        $this->discardPending($request);
        $batch = $this->newBatch($request, $type, $district, $all, 'checking');

        return $this->started($request, $batch, 'check');
    }

    /** Save the file that was checked in preview() (on the queue since 26-11-2026). */
    public function confirm(Request $request)
    {
        $batch = $this->current($request);
        if (! $batch || $batch->status !== 'checked' || ! $batch->pending_path || ! Storage::disk('private')->exists($batch->pending_path)) {
            if ($batch && ! $batch->isWorking()) {
                $this->discardPending($request);
            }

            return redirect()->route('admin.imports.index')->with('error', 'The checked file has expired. Please upload the file again.');
        }
        $batch->update(['status' => 'queued']);

        return $this->started($request, $batch, 'import');
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

    private function doneMessage(ImportBatch $batch): string
    {
        return "Upload complete: {$batch->added} added, {$batch->updated} updated, {$batch->rejected} rejected.";
    }

    /** The officer's current upload: the batch id kept in the session, only their own. */
    private function current(Request $request): ?ImportBatch
    {
        $id = $request->session()->get(self::PENDING);
        if (! is_int($id)) {
            return null; // nothing, or an array left by the version before 26-11-2026
        }

        return ImportBatch::with('district:id,code,name')->where('uploaded_by', $request->user()->id)->find($id);
    }

    /** Keep the uploaded file on the private disk and make the batch row that follows it through check and save. */
    private function newBatch(Request $request, string $type, ?District $district, bool $all, string $status): ImportBatch
    {
        $file = $request->file('file');
        $path = $file->storeAs('imports/pending', Str::random(32).'.'.($file->getClientOriginalExtension() ?: 'xlsx'), 'private');
        $batch = ImportBatch::create([
            'type' => $type, 'status' => $status, 'district_id' => $district?->id, 'district_all' => $all, 'uploaded_by' => $request->user()->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'pending_path' => $path,
        ]);
        $request->session()->put(self::PENDING, $batch->id);

        return $batch;
    }

    /**
     * Hand the batch to the queue. On the "sync" queue (tests, or no worker) it is already finished here, so the
     * answer is what the page used to show; with a worker the page shows the progress card and polls.
     */
    private function started(Request $request, ImportBatch $batch, string $mode)
    {
        try {
            ImportMasterFile::dispatch($batch->id, $mode);
        } catch (\Throwable $e) { // the sync queue runs the job here and rethrows its error (failed() has recorded it)
            report($e);
        }
        $batch->refresh();
        $back = redirect()->route('admin.imports.index');

        return match ($batch->status) {
            'checked' => $back->with('import_preview', $batch->preview),
            'check_failed' => $this->failedCheck($request, $batch),
            'done' => tap($back->with('import_result', $batch->id)->with($batch->rejected ? 'warning' : 'success', $this->doneMessage($batch)),
                fn () => $request->session()->forget(self::PENDING)),
            'failed' => tap($back->with('error', $batch->error), fn () => $request->session()->forget(self::PENDING)),
            default => $back->with('info', $mode === 'check'
                ? 'The file is being checked in the background. You can leave this page; come back to see the result.'
                : 'The file is being saved in the background. You can leave this page; the result appears in History.'),
        };
    }

    /** A file that does not match the template is refused on the form, as before. */
    private function failedCheck(Request $request, ImportBatch $batch)
    {
        $request->session()->forget(self::PENDING);
        $message = $batch->error ?: 'The file could not be checked.';
        $batch->delete(); // nothing was checked: no history row

        return redirect()->route('admin.imports.index')->withErrors(['file' => $message])->withInput($request->except('file'));
    }

    private function discardPending(Request $request): void
    {
        $batch = $this->current($request);
        $request->session()->forget(self::PENDING);
        if ($batch && in_array($batch->status, ['checking', 'checked', 'check_failed'], true)) {
            if ($batch->pending_path) {
                Storage::disk('private')->delete($batch->pending_path);
            }
            $batch->delete(); // a queued check finds no row and stops
        }
    }
}
