<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateExport;
use App\Models\ExportJob;
use App\Services\AuditLogger;
use App\Support\QueuedExports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * 26-11-2026 performance, module B: the officer's own queued downloads (App\Support\QueuedExports).
 * Every file is private: only its owner can see, download or delete it.
 */
class DownloadController extends Controller
{
    public function index(Request $request)
    {
        $jobs = ExportJob::where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subHours(QueuedExports::keepHours()))
            ->latest('id')->limit(50)->get();

        return view('downloads.index', ['jobs' => $jobs, 'keepHours' => QueuedExports::keepHours()]);
    }

    /** JSON for downloads.js: the officer's recent jobs (or the ones asked for in ?keys=a,b). */
    public function status(Request $request)
    {
        $query = ExportJob::where('user_id', $request->user()->id)->where('created_at', '>=', now()->subHours(QueuedExports::keepHours()));
        $keys = array_filter(explode(',', (string) $request->query('keys')));
        if ($keys) {
            $ids = array_filter(array_map(fn ($k) => \App\Support\UrlId::decode(ExportJob::class, $k), array_slice($keys, 0, 20)));
            $query->whereIn('id', $ids ?: [0]);
        }

        return response()->json(['items' => $query->latest('id')->limit(50)->get()->map->toClientArray()->values()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function file(Request $request, ExportJob $exportJob)
    {
        $this->own($request, $exportJob);
        $disk = Storage::disk('private');
        abort_unless($exportJob->isReady() && $disk->exists($exportJob->file_path), 404, 'This file is no longer available. Please download it again.');

        if (! $exportJob->downloaded_at) {
            $exportJob->update(['downloaded_at' => now()]);
        }
        AuditLogger::log('export_downloaded', $exportJob, ['title' => $exportJob->title, 'file' => $exportJob->file_name]);

        return $disk->download($exportJob->file_path, $exportJob->file_name, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * 27-11-2026: a job that has waited over 30 s (no queue worker running) is made here, in the officer's own
     * request. GenerateExport claims it atomically, so a worker that starts meanwhile does not make it twice.
     */
    public function run(Request $request, ExportJob $exportJob)
    {
        $this->own($request, $exportJob);
        if (! $exportJob->isStalled()) {
            return $this->runAnswer($request, $exportJob->fresh(), 409);
        }
        QueuedExports::allowLongRequest();
        $job = new GenerateExport($exportJob->id);
        try {
            $job->handle();
        } catch (\Throwable $e) {
            report($e);
            $job->failed($e);
        }

        return $this->runAnswer($request, $exportJob->fresh());
    }

    private function runAnswer(Request $request, ExportJob $job, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json(['item' => $job->toClientArray()], $status)->header('Cache-Control', 'no-store, private');
        }

        return $job->status === 'failed'
            ? back()->with('warning', $job->title.' could not be made: '.$job->error)
            : back()->with('success', $job->status === 'done' ? $job->title.' is ready.' : $job->title.' is being prepared.');
    }

    public function destroy(Request $request, ExportJob $exportJob)
    {
        $this->own($request, $exportJob);
        if ($exportJob->file_path) {
            Storage::disk('private')->delete($exportJob->file_path);
        }
        $exportJob->delete(); // a queued job finds no row and stops

        return back()->with('success', 'Removed '.$exportJob->title.'.');
    }

    private function own(Request $request, ExportJob $job): void
    {
        abort_unless($job->user_id === $request->user()->id, 404); // never reveal another officer's files
    }
}
