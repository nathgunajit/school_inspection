<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\MasterImportService;
use App\Support\RequestMemo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * 26-11-2026 performance, module C: checks ($mode "check") or saves ($mode "import") an uploaded School / Location
 * Master file on the queue. A state-wide School Master takes minutes for each step, which no longer holds a web
 * request open. Results are written to the ImportBatch row; the Excel Upload page polls it.
 */
class ImportMasterFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public int $batchId, public string $mode) {}

    public function handle(MasterImportService $service): void
    {
        $batch = ImportBatch::with('district', 'uploader')->find($this->batchId);
        $from = $this->mode === 'check' ? 'checking' : 'queued';
        if (! $batch || $batch->status !== $from) {
            return; // cancelled, or already handled
        }
        $disk = Storage::disk('private');
        if (! $batch->pending_path || ! $disk->exists($batch->pending_path)) {
            $batch->update(['status' => $this->mode === 'check' ? 'check_failed' : 'failed', 'pending_path' => null, 'finished_at' => now(),
                'error' => 'The uploaded file is no longer available. Please upload it again.']);

            return;
        }
        self::longRunning();
        RequestMemo::forget(); // a long-running worker keeps one console request: never reuse an earlier job's look-ups
        $path = $disk->path($batch->pending_path);
        $district = $batch->type === 'school' && ! $batch->district_all ? $batch->district : null;

        if ($this->mode === 'check') {
            try {
                $result = $service->preview($batch->type, $path, $district, $batch->district_all);
                $batch->update(['status' => 'checked', 'preview' => $result, 'total_rows' => $result['total'], 'error' => null]);
            } catch (ValidationException $e) { // wrong template, missing columns
                $disk->delete($batch->pending_path);
                $batch->update(['status' => 'check_failed', 'pending_path' => null, 'finished_at' => now(),
                    'error' => mb_substr((string) (collect($e->errors())->flatten()->first() ?? $e->getMessage()), 0, 1000)]);
            }

            return;
        }

        $batch->update(['status' => 'importing', 'started_at' => now()]);
        try {
            $service->import($batch->type, $path, $batch->uploader, $district, $batch->file_name, $batch->district_all, $batch);
        } catch (ValidationException $e) { // wrong template on a one-step upload (store): nothing was saved
            $disk->delete($batch->pending_path);
            $batch->update(['status' => 'check_failed', 'pending_path' => null, 'finished_at' => now(),
                'error' => mb_substr((string) (collect($e->errors())->flatten()->first() ?? $e->getMessage()), 0, 1000)]);

            return;
        }
        $disk->delete($batch->pending_path);
        $batch->update(['status' => 'done', 'pending_path' => null, 'finished_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch || in_array($batch->status, ['done', 'checked', 'check_failed'], true)) {
            return;
        }
        $msg = $e instanceof ValidationException ? (collect($e->errors())->flatten()->first() ?? $e->getMessage()) : class_basename($e).': '.$e->getMessage();
        if ($batch->pending_path) {
            Storage::disk('private')->delete($batch->pending_path);
        }
        $batch->update(['status' => $this->mode === 'check' ? 'check_failed' : 'failed', 'pending_path' => null, 'finished_at' => now(),
            'error' => mb_substr(($this->mode === 'check' ? 'The file could not be checked: ' : 'Nothing was saved: ').$msg, 0, 1000)]);
    }

    /**
     * Modification V13 item 5 (moved here from ImportController): a state-wide School Master (44,000 rows) runs
     * for minutes. Lift the PHP time and memory limits and give MySQL longer session timeouts.
     */
    public static function longRunning(): void
    {
        set_time_limit((int) config('inspection.import_timeout', 0));
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }
        ignore_user_abort(true);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET SESSION wait_timeout = 28800, net_read_timeout = 600, net_write_timeout = 600');
        }
    }
}
