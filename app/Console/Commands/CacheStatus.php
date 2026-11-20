<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/** Shows which cache and session stores are in use, whether Redis answers, and whether OPcache is on. */
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

        $this->table(['Item', 'Value'], [
            ['Cache store', $store.($store === 'failover' ? ' (redis -> file)' : '')],
            ['Session driver', $session],
            ['Redis', $redis],
            ['OPcache (CLI)', $opcache],
            ['Config cached', app()->configurationIsCached() ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
