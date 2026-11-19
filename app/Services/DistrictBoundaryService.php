<?php

namespace App\Services;

use App\Models\District;
use App\Models\School;

/**
 * V6 Module 7: the KML district outlines (public/geo/districts, built by `php artisan map:boundaries`)
 * and which District Master row each belongs to.
 *
 * A district uses its `boundary_name` when set, otherwise the outline whose name matches its own
 * (case, spaces and punctuation ignored). `suggest()` finds the outline that contains the district's
 * headquarters or the average position of its schools, for districts whose names differ from the KML.
 */
class DistrictBoundaryService
{
    private ?array $index = null;

    private array $shapes = [];

    /** @return array<string, array{name: string, slug: string, bbox: array{0: float, 1: float, 2: float, 3: float}}> keyed by name */
    public function index(): array
    {
        if ($this->index === null) {
            $file = public_path(config('inspection.map.geojson.district_dir').'/index.json');
            $rows = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
            $this->index = collect($rows)->keyBy('name')->all();
        }

        return $this->index;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->index());
    }

    /** The outline name for a district, or null when none matches. */
    public function nameFor(District $district): ?string
    {
        $index = $this->index();
        if ($district->boundary_name && isset($index[$district->boundary_name])) {
            return $district->boundary_name;
        }
        $want = self::norm($district->name);
        foreach ($index as $name => $_) {
            if (self::norm($name) === $want) {
                return $name;
            }
        }

        return null;
    }

    /** Public URL of the district's outline GeoJSON, or null. */
    public function urlFor(?District $district): ?string
    {
        $name = $district ? $this->nameFor($district) : null;

        return $name ? asset(config('inspection.map.geojson.district_dir').'/'.$this->index()[$name]['slug'].'.geojson') : null;
    }

    public function urlForId(?int $districtId): ?string
    {
        return $districtId ? $this->urlFor(District::find($districtId)) : null;
    }

    /** The outline containing a point, or null. */
    public function locate(float $lat, float $lng): ?string
    {
        foreach ($this->index() as $name => $row) {
            [$minLng, $minLat, $maxLng, $maxLat] = $row['bbox'];
            if ($lng < $minLng || $lng > $maxLng || $lat < $minLat || $lat > $maxLat) {
                continue;
            }
            if ($this->contains($this->rings($row['slug']), $lng, $lat)) {
                return $name;
            }
        }

        return null;
    }

    /** Best outline for a district: by its headquarters, else by the average position of its schools. */
    public function suggest(District $district): ?string
    {
        if ($district->latitude !== null && $district->longitude !== null
            && ($name = $this->locate($district->latitude, $district->longitude))) {
            return $name;
        }
        $avg = School::where('district_id', $district->id)->whereNotNull('latitude')->whereNotNull('longitude')
            ->selectRaw('avg(latitude) as lat, avg(longitude) as lng, count(*) as n')->first();

        return $avg && $avg->n ? $this->locate((float) $avg->lat, (float) $avg->lng) : null;
    }

    public static function norm(?string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $v));
    }

    /** @return list<list<array{0: float, 1: float}>> all rings of an outline file */
    private function rings(string $slug): array
    {
        if (! isset($this->shapes[$slug])) {
            $file = public_path(config('inspection.map.geojson.district_dir')."/$slug.geojson");
            $geo = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $rings = [];
            foreach ($geo['features'] ?? [] as $f) {
                $g = $f['geometry'] ?? [];
                $polys = ($g['type'] ?? '') === 'MultiPolygon' ? $g['coordinates'] : [$g['coordinates'] ?? []];
                foreach ($polys as $poly) {
                    foreach ($poly as $ring) {
                        $rings[] = $ring;
                    }
                }
            }
            $this->shapes[$slug] = $rings;
        }

        return $this->shapes[$slug];
    }

    /** Even-odd ray casting over every ring (outer rings and holes alike). */
    private function contains(array $rings, float $x, float $y): bool
    {
        $inside = false;
        foreach ($rings as $ring) {
            $n = count($ring);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $ring[$i];
                [$xj, $yj] = $ring[$j];
                if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }
}
