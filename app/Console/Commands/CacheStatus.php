<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Shows which cache and session stores are in use, whether Redis answers, whether OPcache is on, and (26-11-2026)
 * the cached config / routes / events / views, GD, and the queue (waiting and failed jobs).
 */
class CacheStatus extends Command
{
    protected $signature = 'cache:status';

    protected $description = 'Show the active cache store, session driver, Redis reachability and OPcache state';

    public function handle(): int
    {
        $store = config('cache.default');
        $session = config('session.driver');
        $redisExt = extension_loaded('redis');

        $redis = 'phpredis extension not loaded';
        if ($redisExt) {
            try {
                $t = microtime(true);
                Redis::connection('cache')->ping();
                $redis = sprintf('reachable (%.1f ms)', (microtime(true) - $t) * 1000);
            } catch (\Throwable $e) {
                $redis = 'NOT reachable: '.$e->getMessage();
            }
        }

        $opcache = 'not loaded';
        if (function_exists('opcache_get_status')) {
            $s = @opcache_get_status(false);
            $opcache = $s && ($s['opcache_enabled'] ?? false)
                ? sprintf('enabled, hit rate %.1f%%', $s['opcache_statistics']['opcache_hit_rate'] ?? 0)
                : 'loaded, disabled for the CLI (check phpinfo() in the browser)';
        }

        $yesNo = fn (bool $v, string $hint = '') => $v ? 'yes' : 'no'.($hint ? " ({$hint})" : '');
        $viewsCached = count(glob(config('view.compiled').'/*.php') ?: []) > 0;

        $this->table(['Item', 'Value'], [
            ['Cache store', $store.($store === 'failover' ? ' (redis -> file)' : '')],
            ['Session driver', $session],
            ['Redis', $redis],
            ['OPcache (CLI)', $opcache],
            ['Config cached', $yesNo(app()->configurationIsCached(), 'php artisan config:cache')],
            ['Routes cached', $yesNo(app()->routesAreCached(), 'php artisan route:cache')],
            ['Events cached', $yesNo(app()->eventsAreCached(), 'php artisan event:cache')],
            ['Views compiled', $yesNo($viewsCached, 'php artisan view:cache')],
            ['GD (photo thumbnails, PDF photos)', $yesNo(extension_loaded('gd'), 'enable extension=gd in php.ini')],
            ...$this->queueRows(),
        ]);
        $this->line('Gzip and browser caching depend on Apache mod_deflate / mod_headers / mod_expires (public/.htaccess); check a response header in the browser.');

        return self::SUCCESS;
    }

    /** 26-11-2026: large downloads and imports run on the queue, so a stopped worker must be visible. */
    private function queueRows(): array
    {
        $conn = config('queue.default');
        $rows = [['Queue connection', $conn.($conn === 'sync' ? ' (jobs run inside the request: no worker needed, but large files are slow)' : '')]];
        if ($conn !== 'database') {
            return $rows;
        }
        try {
            $table = config('queue.connections.database.table', 'jobs');
            $pending = DB::table($table)->count();
            $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
            $age = $oldest ? now()->timestamp - (int) $oldest : 0;
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
            $rows[] = ['Queued jobs', $pending.($age > 0 ? ', oldest waiting '.gmdate('H:i:s', $age) : '')];
            $rows[] = ['Failed jobs', $failed.($failed ? ' (php artisan queue:failed)' : '')];
            if ($age > 300) {
                $rows[] = ['WARNING', 'jobs have waited over 5 minutes: is `php artisan queue:work` running?'];
            }
        } catch (\Throwable $e) {
            $rows[] = ['Queue tables', 'not readable: '.$e->getMessage()];
        }

        return $rows;
    }
}
