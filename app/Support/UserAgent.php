<?php

namespace App\Support;

/** V6 Module 6: a small user-agent reader for the login report (browser, platform, device); no dependency. */
final class UserAgent
{
    /** @return array{browser: ?string, platform: ?string, device: ?string} */
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return ['browser' => null, 'platform' => null, 'device' => null];
        }

        $browser = match (true) {
            (bool) preg_match('/Edg(?:e|A|iOS)?\/(\d+)/', $ua, $m) => 'Edge '.$m[1],
            (bool) preg_match('/(?:OPR|Opera)\/(\d+)/', $ua, $m) => 'Opera '.$m[1],
            (bool) preg_match('/SamsungBrowser\/(\d+)/', $ua, $m) => 'Samsung Internet '.$m[1],
            (bool) preg_match('/(?:Firefox|FxiOS)\/(\d+)/', $ua, $m) => 'Firefox '.$m[1],
            (bool) preg_match('/(?:Chrome|CriOS)\/(\d+)/', $ua, $m) => 'Chrome '.$m[1],
            (bool) preg_match('/Version\/(\d+)[\d.]* .*Safari\//', $ua, $m) => 'Safari '.$m[1],
            default => 'Other',
        };

        $platform = match (true) {
            (bool) preg_match('/Android ?([\d]+)?/', $ua, $m) => trim('Android '.($m[1] ?? '')),
            (bool) preg_match('/(?:iPhone|iPad|iPod).*OS (\d+)/', $ua, $m) => 'iOS '.$m[1],
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };

        $device = match (true) {
            (bool) preg_match('/iPad|Tablet|Android(?!.*Mobile)/', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android/', $ua) => 'mobile',
            default => 'desktop',
        };

        return ['browser' => $browser, 'platform' => $platform, 'device' => $device];
    }
}
