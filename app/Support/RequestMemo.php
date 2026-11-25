<?php

namespace App\Support;

use Closure;

/**
 * 25-11-2026, database optimisation: remembers a value for the rest of the current request (kept on
 * the request itself, so the next request, also in tests, starts empty). Used for look-ups that one
 * page repeats many times: the user's current posting and the coverage setting.
 * forget() is called by the models' saved / deleted events, so a change in the same request is seen.
 */
final class RequestMemo
{
    private const KEY = 'si.memo';

    public static function remember(string $key, Closure $fn): mixed
    {
        $bag = request()->attributes;
        $memo = $bag->get(self::KEY, []);
        if (! array_key_exists($key, $memo)) {
            $memo[$key] = $fn();
            $bag->set(self::KEY, $memo);
        }

        return $memo[$key];
    }

    /** Forget every key starting with $prefix (all keys when empty). */
    public static function forget(string $prefix = ''): void
    {
        $bag = request()->attributes;
        $memo = $bag->get(self::KEY, []);
        $bag->set(self::KEY, $prefix === '' ? [] : array_filter($memo, fn ($k) => ! str_starts_with($k, $prefix), ARRAY_FILTER_USE_KEY));
    }
}
