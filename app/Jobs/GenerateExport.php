<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Models\ExportJob;
use App\Models\User;
use App\Support\QueuedExports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 26-11-2026 performance, module B: makes one queued download (see App\Support\QueuedExports).
 * One try only: a failed export is shown on the Downloads page and the officer starts it again.
 */
class GenerateExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public int $exportJobId) {}

    public function handle(): void
    {
        // claimed atomically: the worker and the officer's browser (DownloadController::run) never both make it
        if (! ExportJob::whereKey($this->exportJobId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()])) {
            return; // deleted by the officer, or already handled
        }
        $job = ExportJob::find($this->exportJobId);
        $user = User::find($job->user_id);
        if (! $user || $user->status !== UserStatus::Active) {
            $job->update(['status' => 'failed', 'error' => 'Your account is not active.', 'finished_at' => now()]);

            return;
        }
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }

        // On the "sync" queue (tests, or no worker) the job runs inside the officer's own request: put it back after
        $previous = app()->bound('request') ? app('request') : null;
        $previousUser = Auth::guard('web')->user();
        try {
            $request = QueuedExports::replayRequest($job->url, $user, $job->ip_address, $job->user_agent);
            $route = $request->route();
            $response = QueuedExports::throughRouteMiddleware($request, $route, false, fn ($r) => $route->run());
            $this->store($job, $response, $request);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            // refused by a gate, policy or abort() (e.g. access changed after the click): not an error of the worker
            $denied = $e instanceof \Illuminate\Auth\Access\AuthorizationException || $e->getStatusCode() === 403;
            $job->update(['status' => 'failed', 'finished_at' => now(),
                'error' => $denied ? 'You are not allowed to download this.' : mb_substr($e->getMessage() ?: 'The file could not be made.', 0, 500)]);
        } finally {
            $previousUser ? Auth::guard('web')->setUser($previousUser) : Auth::guard('web')->forgetUser();
            if (app()->bound('session.store')) {
                app('redirect')->setSession(app('session.store'));
            }
            if ($previous) {
                app()->instance('request', $previous);
                \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
                \Illuminate\Support\Facades\URL::setRequest($previous);
            }
        }
    }

    private function store(ExportJob $job, Response $response, $request): void
    {
        if ($response instanceof RedirectResponse || $response->getStatusCode() >= 400) {
            // the controller refused (e.g. too many rows for a PDF): show its message
            $session = $request->session();
            $msg = $session->get('warning') ?? $session->get('error') ?? $session->get('errors')?->first()
                ?? ($response->getStatusCode() === 403 ? 'You are not allowed to download this.' : 'The file could not be made.');
            $job->update(['status' => 'failed', 'error' => mb_substr((string) $msg, 0, 500), 'finished_at' => now()]);

            return;
        }

        $name = QueuedExports::fileName($response) ?? Str::slug($job->title).'.bin';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $path = 'exports/'.$job->user_id.'/'.Str::random(40).'.'.$ext;
        $disk = Storage::disk('private');

        if ($response instanceof BinaryFileResponse) { // Excel::download() writes a temporary file
            $tmp = $response->getFile()->getPathname();
            $stream = fopen($tmp, 'rb');
            $disk->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            @unlink($tmp);
        } elseif ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $disk->put($path, ob_get_clean());
        } else {
            $disk->put($path, (string) $response->getContent());
        }

        $job->update(['status' => 'done', 'file_path' => $path, 'file_name' => $name, 'size_bytes' => $disk->size($path), 'finished_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        ExportJob::whereKey($this->exportJobId)->whereIn('status', ExportJob::ACTIVE)->update([
            'status' => 'failed', 'finished_at' => now(),
            'error' => 'The file could not be made ('.mb_substr(class_basename($e).': '.$e->getMessage(), 0, 300).'). Try again, or narrow the filters.',
        ]);
    }
}
