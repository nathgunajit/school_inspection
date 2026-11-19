<?php

namespace App\Support;

/**
 * Modification V7 (item 4): file sizes written one standard way everywhere (hints, chips, messages):
 * "1 MB", "2 MB", "1.5 MB", "500 KB". Binary units (1 MB = 1024 KB), the same as Laravel's `max:` (KB)
 * and the browser-side check in register.js.
 */
final class FileSize
{
    public static function human(int|float $kb): string
    {
        if ($kb >= 1024) {
            $mb = round($kb / 1024, 1);

            return rtrim(rtrim(number_format($mb, 1), '0'), '.').' MB';
        }

        return ((int) round($kb)).' KB';
    }

    /** "JPG / PNG" from a mimes list, ignoring the jpeg alias. */
    public static function types(array $mimes): string
    {
        return implode(' / ', array_map('strtoupper', array_values(array_diff($mimes, ['jpeg']))));
    }
}
