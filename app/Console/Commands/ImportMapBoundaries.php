<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * New Module 1 (Map View): convert the Assam boundary KML files into small GeoJSON files for the map.
 *
 * Source layout (as supplied in public/KML/Assam):
 *   Assam.kml                  state outline
 *   Assam Dist/<District>.kml  one file per district; each holds that district's constituency polygons
 *
 * Output (public/geo):
 *   assam-state.geojson        the state outline
 *   assam-districts.geojson    one feature per polygon, property "name" = district (from the file name)
 *
 * Polygons are simplified (Douglas–Peucker) and coordinates rounded, so the files stay small for slow networks.
 */
class ImportMapBoundaries extends Command
{
    protected $signature = 'map:boundaries
        {source=public/KML/Assam : Folder with Assam.kml and "Assam Dist/*.kml"}
        {--tolerance=0.0015 : Simplification tolerance in degrees (0.0015 ≈ 150 m)}
        {--out= : Output folder (default public/geo)}';

    protected $description = 'Convert the Assam state and district KML boundaries into GeoJSON for the Map View';

    public function handle(): int
    {
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
            $features = $this->features("$src/Assam.kml", ['name' => 'Assam'], $tol);
            $this->write("$out/assam-state.geojson", $features);
            $this->info('State outline: '.count($features).' polygon(s)');
        }

        // ---- districts
        $files = glob("$src/Assam Dist/*.kml") ?: [];
        $features = [];
        foreach ($files as $file) {
            $name = trim(pathinfo($file, PATHINFO_FILENAME));
            $name = mb_convert_case($name, MB_CASE_TITLE); // "nalbari" → "Nalbari"
            array_push($features, ...$this->features($file, ['name' => $name], $tol));
        }
        if ($features) {
            $this->write("$out/assam-districts.geojson", $features);
        }
        $this->info(count($files).' district file(s), '.count($features).' polygon(s) → '.$out.'/assam-districts.geojson');

        return self::SUCCESS;
    }

    /** @return array<int, array> GeoJSON polygon features of one KML file */
    private function features(string $file, array $props, float $tol): array
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
                        $ring = $this->simplify($this->coords((string) $c), $tol);
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

    private function coords(string $text): array
    {
        $pts = [];
        foreach (preg_split('/\s+/', trim($text)) as $tuple) {
            $p = explode(',', $tuple);
            if (count($p) >= 2) {
                $pts[] = [round((float) $p[0], 5), round((float) $p[1], 5)];
            }
        }

        return $pts;
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
