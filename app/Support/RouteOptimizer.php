<?php

namespace App\Support;

use App\Models\Inspection;

/**
 * Visit order for a day's schools (New Module 2): nearest neighbour from the start point,
 * then 2-opt improvement. It is an open route: the officer does not need to return to the start.
 *
 * Straight-line distance × a road factor estimates the road distance. No external routing service
 * is called, so no data leaves the server and it works on slow networks.
 * The same algorithm runs in resources/js/schedule-planner.js for the live preview.
 */
class RouteOptimizer
{
    /**
     * @param  array{0: float, 1: float}|null  $start  [lat, lng]
     * @param  array<int|string, array{0: float, 1: float}|null>  $points  key => [lat, lng] (null = no GPS)
     * @return array<int, int|string> keys in visit order; points without GPS go last, in their given order
     */
    public static function order(?array $start, array $points): array
    {
        $located = array_filter($points, fn ($p) => $p !== null);
        $unlocated = array_keys(array_diff_key($points, $located));
        $keys = array_keys($located);
        if (count($keys) <= 1) {
            return array_merge($keys, $unlocated);
        }

        $d = fn ($a, $b) => Inspection::haversine($a[0], $a[1], $b[0], $b[1]);

        // nearest neighbour
        $route = [];
        $left = $keys;
        $cur = $start ?? $located[$keys[0]];
        while ($left) {
            $best = null;
            $bestD = PHP_INT_MAX;
            foreach ($left as $i => $k) {
                $dist = $d($cur, $located[$k]);
                if ($dist < $bestD) {
                    $bestD = $dist;
                    $best = $i;
                }
            }
            $route[] = $left[$best];
            $cur = $located[$left[$best]];
            unset($left[$best]);
        }

        // 2-opt on the open path (the start point is fixed in front)
        $pt = fn ($i) => $i < 0 ? $start : $located[$route[$i]];
        $n = count($route);
        $improved = true;
        $guard = 0;
        while ($improved && $guard++ < 50) {
            $improved = false;
            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $pt($i - 1);
                    $b = $pt($i);
                    $c = $pt($j);
                    $e = $j + 1 < $n ? $pt($j + 1) : null;
                    $before = ($a ? $d($a, $b) : 0) + ($e ? $d($c, $e) : 0);
                    $after = ($a ? $d($a, $c) : 0) + ($e ? $d($b, $e) : 0);
                    if ($after + 1 < $before) {
                        $route = array_merge(array_slice($route, 0, $i), array_reverse(array_slice($route, $i, $j - $i + 1)), array_slice($route, $j + 1));
                        $improved = true;
                    }
                }
            }
        }

        return array_merge($route, $unlocated);
    }

    /** Estimated road distance in km between two points. */
    public static function roadKm(array $a, array $b): float
    {
        return round(Inspection::haversine($a[0], $a[1], $b[0], $b[1]) / 1000 * (float) config('inspection.schedule.road_factor', 1.3), 2);
    }
}
