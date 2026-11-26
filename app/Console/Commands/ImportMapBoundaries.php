<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Services\AuditLogger;
use App\Services\DistrictBoundaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * New Module 1 (Map View): convert the Assam boundary KML files into small GeoJSON files for the map.
 *
 * Source layout (as supplied in public/KML/Assam):
 *   Assam.kml                  state outline
 *   Assam Dist/<District>.kml  one file per district; each holds that district's constituency polygons
 *
 * Output (public/geo):
 *   assam-state.geojson        the state outline
 *   assam-districts.geojson    one dissolved feature per district (26-11-2026; was one per constituency polygon),
 *                              property "name" = district (from the file name), 4 decimals, --tolerance
 *
 *   districts/<slug>.geojson   V6 Module 7: the district outline, its constituency polygons dissolved into one shape
 *   districts/index.json       name, slug and bounding box of every district outline
 *
 * Polygons are simplified (Douglas–Peucker) and coordinates rounded, so the files stay small for slow networks.
 *
 * --check   lists the District Master rows and the outline each one uses (boundary_name, or by name)
 * --assign  sets boundary_name of unmatched districts to the outline containing their headquarters or schools
 */
class ImportMapBoundaries extends Command
{
    /** 26-11-2026: the state and state-wide districts files are drawn zoomed out: 4 decimals ≈ 11 m is plenty. */
    private const OVERVIEW_DECIMALS = 4;

    protected $signature = 'map:boundaries
        {source=public/KML/Assam : Folder with Assam.kml and "Assam Dist/*.kml"}
        {--tolerance=0.0015 : Simplification tolerance in degrees (0.0015 ≈ 150 m)}
        {--detail=0.0004 : Simplification tolerance of the per-district outlines (0.0004 ≈ 40 m)}
        {--out= : Output folder (default public/geo)}
        {--check : Only list which outline each District Master row uses}
        {--assign : Set the outline of unmatched districts from their headquarters or school positions}';

    protected $description = 'Convert the Assam state and district KML boundaries into GeoJSON for the Map View';

    public function handle(DistrictBoundaryService $boundaries): int
    {
        if ($this->option('check') || $this->option('assign')) {
            return $this->check($boundaries, (bool) $this->option('assign'));
        }

        $src = $this->argument('source');
        $src = is_dir($src) ? $src : base_path($src);
        $tol = (float) $this->option('tolerance');
        $out = $this->option('out') ?: public_path('geo');
        if (! is_dir($src)) {
            $this->error("Folder not found: $src");

            return self::FAILURE;
        }
        if (! is_dir($out)) {
            mkdir($out, 0775, true);
        }

        // ---- state outline
        if (is_file("$src/Assam.kml")) {
            $features = $this->features("$src/Assam.kml", ['name' => 'Assam'], $tol, self::OVERVIEW_DECIMALS);
            $this->write("$out/assam-state.geojson", $features);
            $this->info('State outline: '.count($features).' polygon(s)');
        }

        $files = glob("$src/Assam Dist/*.kml") ?: [];

        // ---- V6 Module 7: one dissolved outline per district
        $detail = (float) $this->option('detail');
        $dir = "$out/districts";
        if ($files && ! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $index = [];
        $fallback = [];
        $overview = []; // 26-11-2026: the state-wide districts layer, one dissolved, lighter feature per district
        foreach ($files as $file) {
            $name = mb_convert_case(trim(pathinfo($file, PATHINFO_FILENAME)), MB_CASE_TITLE);
            $slug = Str::slug($name);
            $raw = $this->rawRings($file);
            $rings = $this->dissolve($raw);
            if ($rings === null) {
                $fallback[] = $name;
                $rings = $raw;
            }
            $light = array_values(array_filter(array_map(fn ($r) => $this->simplify($this->dedupe(array_map(
                fn ($p) => [round($p[0], self::OVERVIEW_DECIMALS), round($p[1], self::OVERVIEW_DECIMALS)], $r)), $tol), $rings), fn ($r) => count($r) >= 4));
            if ($light) {
                $overview[] = ['type' => 'Feature', 'properties' => ['name' => $name],
                    'geometry' => ['type' => 'MultiPolygon', 'coordinates' => array_map(fn ($r) => [$r], $light)]];
            }
            $rings = array_values(array_filter(
                array_map(fn ($r) => $this->simplify(array_map(fn ($p) => [round($p[0], 5), round($p[1], 5)], $r), $detail), $rings),
                fn ($r) => count($r) >= 4
            ));
            if (! $rings) {
                continue;
            }
            $this->write("$dir/$slug.geojson", [['type' => 'Feature', 'properties' => ['name' => $name],
                'geometry' => ['type' => 'MultiPolygon', 'coordinates' => array_map(fn ($r) => [$r], $rings)]]]);
            $all = array_merge(...$rings);
            $lngs = array_column($all, 0);
            $lats = array_column($all, 1);
            $index[] = ['name' => $name, 'slug' => $slug, 'bbox' => [min($lngs), min($lats), max($lngs), max($lats)]];
        }
        if ($overview) {
            $this->write("$out/assam-districts.geojson", $overview);
            $this->info(count($files).' district file(s), '.count($overview).' district(s) → '.$out.'/assam-districts.geojson ('.number_format(filesize("$out/assam-districts.geojson") / 1024, 1).' KB)');
        }
        if ($index) {
            file_put_contents("$dir/index.json", json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info(count($index).' district outline(s) → '.$dir
                .($fallback ? ' (not dissolved, drawn by constituency: '.implode(', ', $fallback).')' : ''));
        }

        return self::SUCCESS;
    }

    /** V6 Module 7: District Master rows and their outline; with $assign, fill in the unmatched ones. */
    private function check(DistrictBoundaryService $boundaries, bool $assign): int
    {
        if (! $boundaries->names()) {
            $this->error('No district outlines found. Run `php artisan map:boundaries` first.');

            return self::FAILURE;
        }
        $rows = [];
        $missing = 0;
        foreach (District::orderBy('name')->get() as $d) {
            $name = $boundaries->nameFor($d);
            $how = $name ? ($d->boundary_name ? 'set' : 'by name') : '';
            if (! $name && ($suggest = $boundaries->suggest($d))) {
                if ($assign) {
                    $d->update(['boundary_name' => $suggest]);
                    AuditLogger::log('district_boundary_assigned', $d, ['boundary_name' => $suggest, 'by' => 'map:boundaries --assign']);
                    [$name, $how] = [$suggest, 'assigned now'];
                } else {
                    $how = 'suggested: '.$suggest;
                }
            }
            $missing += $name ? 0 : 1;
            $rows[] = [$d->code, $d->name, $name ?? '—', $how ?: 'no match'];
        }
        $this->table(['Code', 'District', 'Outline', 'How'], $rows);
        if ($missing) {
            $this->warn("$missing district(s) without an outline. Set one in the Location Master or run with --assign.");
        } else {
            $this->info('Every district has an outline.');
        }

        return self::SUCCESS;
    }

    /** Outer rings of every polygon in a KML file, unsimplified (6 decimals, so shared vertices compare equal). */
    private function rawRings(string $file): array
    {
        $xml = @simplexml_load_file($file);
        if (! $xml) {
            return [];
        }
        $xml->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
        $rings = [];
        foreach ($xml->xpath('//k:outerBoundaryIs//k:coordinates') as $c) {
            $pts = [];
            foreach (preg_split('/\s+/', trim((string) $c)) as $tuple) {
                $p = explode(',', $tuple);
                if (count($p) >= 2) {
                    $pts[] = [round((float) $p[0], 6), round((float) $p[1], 6)];
                }
            }
            if (count($pts) >= 4) {
                $rings[] = $pts;
            }
        }

        return $rings;
    }

    /**
     * Dissolves adjacent polygons into their outer outline: an edge shared by two polygons is internal and
     * dropped; the remaining edges are chained back into closed rings. Null when they do not chain cleanly
     * (a vertex with an odd number of outline edges); the caller then keeps the polygons as they are.
     */
    private function dissolve(array $rings): ?array
    {
        if (count($rings) < 2) {
            return $rings;
        }
        $count = [];
        $pt = [];
        foreach ($rings as $r) {
            for ($i = 0, $n = count($r) - 1; $i < $n; $i++) {
                $a = $r[$i][0].','.$r[$i][1];
                $b = $r[$i + 1][0].','.$r[$i + 1][1];
                if ($a === $b) {
                    continue;
                }
                $pt[$a] = $r[$i];
                $pt[$b] = $r[$i + 1];
                $k = $a < $b ? "$a|$b" : "$b|$a";
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }
        $adj = [];
        foreach ($count as $k => $n) {
            if ($n % 2 === 1) {
                [$a, $b] = explode('|', $k);
                $adj[$a][$b] = true;
                $adj[$b][$a] = true;
            }
        }
        foreach ($adj as $next) {
            if (count($next) % 2 === 1) {
                return null;
            }
        }
        $out = [];
        while ($adj) {
            $start = array_key_first($adj);
            $ring = [$pt[$start]];
            $cur = $start;
            do {
                $next = array_key_first($adj[$cur]);
                unset($adj[$cur][$next], $adj[$next][$cur]);
                if (! $adj[$cur]) {
                    unset($adj[$cur]);
                }
                if (isset($adj[$next]) && ! $adj[$next]) {
                    unset($adj[$next]);
                }
                $ring[] = $pt[$next];
                $cur = $next;
            } while ($cur !== $start && isset($adj[$cur]));
            if ($cur !== $start) {
                return null;
            }
            if (count($ring) >= 4) {
                $out[] = $ring;
            }
        }

        return $out;
    }
    /** @return array<int, array> GeoJSON polygon features of one KML file */
    private function features(string $file, array $props, float $tol, int $decimals = 5): array
    {
        $xml = @simplexml_load_file($file);
        if (! $xml) {
            $this->warn("Cannot read $file");

            return [];
        }
        $xml->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
        $features = [];
        foreach ($xml->xpath('//k:Placemark') as $pm) {
            $pm->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
            $extra = [];
            foreach ($pm->xpath('.//k:SimpleData[@name="Con_Name"]') as $d) {
                $extra['constituency'] = mb_convert_case(trim((string) $d), MB_CASE_TITLE);
            }
            foreach ($pm->xpath('.//k:Polygon') as $poly) {
                $poly->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
                $rings = [];
                foreach (['outerBoundaryIs', 'innerBoundaryIs'] as $kind) {
                    foreach ($poly->xpath(".//k:$kind//k:coordinates") as $c) {
                        $ring = $this->simplify($this->coords((string) $c, $decimals), $tol);
                        if (count($ring) >= 4) {
                            $rings[] = $ring;
                        }
                    }
                }
                if ($rings) {
                    $features[] = ['type' => 'Feature', 'properties' => $props + $extra, 'geometry' => ['type' => 'Polygon', 'coordinates' => $rings]];
                }
            }
        }

        return $features;
    }

    private function coords(string $text, int $decimals = 5): array
    {
        $pts = [];
        foreach (preg_split('/\s+/', trim($text)) as $tuple) {
            $p = explode(',', $tuple);
            if (count($p) >= 2) {
                $pts[] = [round((float) $p[0], $decimals), round((float) $p[1], $decimals)];
            }
        }

        return $this->dedupe($pts);
    }

    /** Drop points equal to the one before (rounding can make neighbours equal). */
    private function dedupe(array $pts): array
    {
        $out = [];
        foreach ($pts as $p) {
            if (! $out || end($out) !== $p) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** Douglas–Peucker, keeping the ring closed. */
    private function simplify(array $pts, float $tol): array
    {
        if (count($pts) < 5 || $tol <= 0) {
            return $pts;
        }
        $keep = array_fill(0, count($pts), false);
        $keep[0] = $keep[count($pts) - 1] = true;
        $stack = [[0, count($pts) - 1]];
        while ($stack) {
            [$a, $b] = array_pop($stack);
            $max = 0;
            $idx = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                $d = $this->distance($pts[$i], $pts[$a], $pts[$b]);
                if ($d > $max) {
                    $max = $d;
                    $idx = $i;
                }
            }
            if ($idx > -1 && $max > $tol) {
                $keep[$idx] = true;
                $stack[] = [$a, $idx];
                $stack[] = [$idx, $b];
            }
        }
        $out = [];
        foreach ($pts as $i => $p) {
            if ($keep[$i]) {
                $out[] = $p;
            }
        }
        if ($out[0] !== end($out)) {
            $out[] = $out[0];
        }

        return $out;
    }

    private function distance(array $p, array $a, array $b): float
    {
        [$x, $y] = $p;
        [$x1, $y1] = $a;
        [$x2, $y2] = $b;
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        if ($dx == 0 && $dy == 0) {
            return hypot($x - $x1, $y - $y1);
        }
        $t = max(0, min(1, (($x - $x1) * $dx + ($y - $y1) * $dy) / ($dx * $dx + $dy * $dy)));

        return hypot($x - ($x1 + $t * $dx), $y - ($y1 + $t * $dy));
    }

    private function write(string $path, array $features): void
    {
        file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
