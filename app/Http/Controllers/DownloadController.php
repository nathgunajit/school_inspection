<?php

namespace App\Http\Controllers;

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
