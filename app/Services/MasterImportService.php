<?php

namespace App\Services;

use App\Exports\ArrayExport;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\ImportBatch;
use App\Models\MasterValue;
use App\Models\School;
use App\Models\State;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;

/**
 * Excel upload of Location and School Master (PRD 7.3).
 * Existing codes are updated, new ones added, missing ones are never removed.
 */
class MasterImportService
{
    public const LOCATION_COLUMNS = ['type', 'code', 'name', 'parent_code', 'status'];

    /** Optional columns (district and block only); older files without them still import. */
    public const LOCATION_OPTIONAL = ['latitude', 'longitude'];

    public const SCHOOL_COLUMNS = ['udise_code', 'school_name', 'district_code', 'block_code', 'cluster_code', 'management', 'category', 'address', 'latitude', 'longitude', 'status'];

    /** District-wise upload: block and district come from the cluster, so these columns are enough. */
    public const SCHOOL_REQUIRED = ['udise_code', 'school_name', 'cluster_code'];

    public const SCHOOL_DISTRICT_COLUMNS = ['udise_code', 'school_name', 'cluster_code', 'management', 'category', 'address', 'latitude', 'longitude', 'status'];

    /** Template for one district: a Schools sheet without district/block columns, plus a sheet listing its clusters. */
    public static function districtTemplate(District $district): \App\Exports\SchoolTemplateExport
    {
        $clusters = Cluster::query()->join('blocks', 'blocks.id', '=', 'clusters.block_id')
            ->where('blocks.district_id', $district->id)
            ->orderBy('blocks.name')->orderBy('clusters.name')
            ->get(['clusters.code', 'clusters.name', 'blocks.code as block_code', 'blocks.name as block_name']);
        $first = $clusters->first();

        return new \App\Exports\SchoolTemplateExport(
            new ArrayExport(self::SCHOOL_DISTRICT_COLUMNS, [[
                ($first?->code ?? '00000000').'001', 'Govt. Primary School No. 1', $first?->code ?? '', 'Department of Education', 'Primary (Class 1–5)', 'Village, Block, '.$district->name, '', '', 'active',
            ]]),
            new ArrayExport(['cluster_code', 'cluster_name', 'block_code', 'block_name'], $clusters->map(fn ($c) => [$c->code, $c->name, $c->block_code, $c->block_name])->all()),
            'Clusters of '.$district->name,
        );
    }

    public static function template(string $type): ArrayExport
    {
        return $type === 'location'
            ? new ArrayExport([...self::LOCATION_COLUMNS, ...self::LOCATION_OPTIONAL], [
                ['state', '18', 'Demo State', '', 'active', '', ''],
                ['district', '1801', 'North District', '18', 'active', '26.1445000', '91.7362000'],
                ['block', '180101', 'Riverside Block', '1801', 'active', '26.1800000', '91.7500000'],
                ['cluster', '18010101', 'Riverside Cluster 1', '180101', 'active', '', ''],
            ])
            : new ArrayExport(self::SCHOOL_COLUMNS, [
                ['18010101001', 'Govt. Primary School No. 1', '1801', '180101', '18010101', 'Department of Education', 'Primary (Class 1–5)', 'Village, Block, District', '26.1130000', '91.7110000', 'active'],
            ]);
    }

    /**
     * Check a file without saving anything: same rules as import(), rolled back.
     *
     * @return array{total:int, added:int, updated:int, rejected:int, errors:array, summary:array}
     */
    public function preview(string $type, UploadedFile|string $file, ?District $district = null): array
    {
        $rows = $this->readRows($type, $file, $district);
        DB::beginTransaction();
        try {
            $result = $type === 'location' ? $this->importLocations($rows) : $this->importSchools($rows, $district);
        } finally {
            DB::rollBack();
        }

        return ['total' => $rows->count(), 'added' => $result['added'], 'updated' => $result['updated'], 'rejected' => count($result['errors']),
            'errors' => array_slice(array_map(fn ($e) => ['row' => $e[0], 'reason' => $e[1], 'udise' => $e[2] ?? ''], $result['errors']), 0, 20),
            'summary' => $result['summary'] ?? []];
    }

    private function readRows(string $type, UploadedFile|string $file, ?District $district): Collection
    {
        $headings = (new HeadingRowImport)->toArray($file)[0][0] ?? [];
        $expected = $type === 'location' ? self::LOCATION_COLUMNS
            : ($district ? self::SCHOOL_REQUIRED : [...self::SCHOOL_REQUIRED, 'district_code']);
        $missingCols = array_diff($expected, $headings);
        if ($missingCols) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'The file does not match the template. Missing columns: '.implode(', ', $missingCols),
            ]);
        }

        $rows = Excel::toCollection(new class implements \Maatwebsite\Excel\Concerns\WithHeadingRow, \Maatwebsite\Excel\Concerns\ToCollection {
            public function collection(Collection $rows) {}
        }, $file)->first() ?? collect();

        return $rows->map(fn ($r) => collect($r)->map(fn ($v) => is_string($v) ? trim($v) : $v))
            ->filter(fn ($r) => $r->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())
            ->values();
    }

    public function import(string $type, UploadedFile|string $file, User $user, ?District $district = null, ?string $fileName = null): ImportBatch
    {
        $rows = $this->readRows($type, $file, $district);
        $expected = $type === 'location' ? self::LOCATION_COLUMNS : self::SCHOOL_COLUMNS;

        $result = DB::transaction(fn () => $type === 'location' ? $this->importLocations($rows) : $this->importSchools($rows, $district));

        $batch = ImportBatch::create([
            'type' => $type,
            'district_id' => $district?->id,
            'file_name' => mb_substr($fileName ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file)), 0, 255),
            'uploaded_by' => $user->id,
            'total_rows' => $rows->count(),
            'added' => $result['added'],
            'updated' => $result['updated'],
            'rejected' => count($result['errors']),
            'summary' => $result['summary'] ?? null,
        ]);

        return $this->finish($batch, $type, $expected, $result);
    }

    private function finish(ImportBatch $batch, string $type, array $expected, array $result): ImportBatch
    {
        if ($result['errors']) {
            $path = 'imports/errors-'.$batch->id.'-'.Str::random(8).'.xlsx';
            Excel::store(new ArrayExport(['row_number', 'reason', ...$expected, ...($type === 'location' ? self::LOCATION_OPTIONAL : [])], $result['errors']), $path, 'private');
            $batch->update(['error_file_path' => $path]);
        }

        AuditLogger::log('master_upload', $batch, ['type' => $type, 'district_id' => $batch->district_id, 'added' => $result['added'], 'updated' => $result['updated'], 'rejected' => count($result['errors'])]);

        return $batch;
    }

    private function importLocations(Collection $rows): array
    {
        $order = ['state' => 0, 'district' => 1, 'block' => 2, 'cluster' => 3];
        $added = $updated = 0;
        $errors = [];
        $seen = [];

        // parents first so a single file can create a full hierarchy
        $indexed = $rows->map(fn ($r, $i) => [$i + 2, $r])
            ->sortBy(fn ($p) => $order[strtolower((string) $p[1]['type'])] ?? 9);

        foreach ($indexed as [$rowNo, $r]) {
            $type = strtolower((string) $r['type']);
            $code = (string) $r['code'];
            $name = (string) $r['name'];
            $parentCode = (string) ($r['parent_code'] ?? '');
            $status = strtolower((string) ($r['status'] ?: 'active'));
            $fail = function ($reason) use (&$errors, $rowNo, $r) {
                $errors[] = [$rowNo, $reason, ...array_map(fn ($c) => $r[$c] ?? '', [...self::LOCATION_COLUMNS, ...self::LOCATION_OPTIONAL])];
            };

            if (! isset($order[$type])) { $fail('Type must be state, district, block or cluster'); continue; }
            if ($code === '' || $name === '') { $fail('Code and name are required'); continue; }
            if (! preg_match('/^\d{1,15}$/', $code)) { $fail('Code must be numeric'); continue; }
            if (isset($seen[$type.$code])) { $fail('Duplicate code in file'); continue; }
            if (! in_array($status, ['active', 'inactive'], true)) { $fail('Status must be active or inactive'); continue; }
            $seen[$type.$code] = true;

            [$model, $parentModel, $fk] = match ($type) {
                'state' => [State::class, null, null],
                'district' => [District::class, State::class, 'state_id'],
                'block' => [Block::class, District::class, 'district_id'],
                'cluster' => [Cluster::class, Block::class, 'block_id'],
            };

            $attrs = ['name' => $name, 'is_active' => $status === 'active'];

            if (in_array($type, ['district', 'block'], true)) {
                $lat = $r['latitude'] ?? null;
                $lng = $r['longitude'] ?? null;
                $hasLat = $lat !== null && $lat !== '';
                $hasLng = $lng !== null && $lng !== '';
                if ($hasLat !== $hasLng) { $fail('Give both latitude and longitude, or leave both empty'); continue; }
                if ($hasLat) {
                    if (! is_numeric($lat) || $lat < -90 || $lat > 90) { $fail('Latitude must be a number between -90 and 90'); continue; }
                    if (! is_numeric($lng) || $lng < -180 || $lng > 180) { $fail('Longitude must be a number between -180 and 180'); continue; }
                    $attrs += ['latitude' => (float) $lat, 'longitude' => (float) $lng];
                }
                // an empty cell leaves existing coordinates unchanged
            }

            if ($parentModel) {
                $parent = $parentModel::where('code', $parentCode)->first();
                if (! $parent) { $fail('Parent code not found in Location Master'); continue; }
                $attrs[$fk] = $parent->id;
            }

            $rec = $model::firstOrNew(['code' => $code]);
            $rec->exists ? $updated++ : $added++;
            $rec->fill($attrs)->save();
        }

        usort($errors, fn ($a, $b) => $a[0] <=> $b[0]);

        return compact('added', 'updated', 'errors');
    }

    /**
     * With $district (district-wise upload) only udise_code, school_name and cluster_code are needed:
     * block and district come from the cluster, which must lie in that district. district_code /
     * block_code are still checked when a file carries them. Without $district (old files) district_code
     * and block_code are required as before.
     */
    private function importSchools(Collection $rows, ?District $district = null): array
    {
        $added = $updated = 0;
        $errors = [];
        $seen = [];
        $summary = ['blocks' => []];
        $districts = District::all()->keyBy('code');
        $districtsById = $districts->keyBy('id');
        $blocks = Block::all()->keyBy('code');
        $blocksById = $blocks->keyBy('id');
        $clusters = Cluster::all()->keyBy('code');
        $required = $district ? self::SCHOOL_REQUIRED : ['udise_code', 'school_name', 'district_code', 'block_code', 'cluster_code'];
        $tally = function (?Block $b, string $what) use (&$summary) {
            $k = $b ? (string) $b->id : '0';
            $summary['blocks'][$k] ??= ['name' => $b?->name ?? 'Unknown block', 'added' => 0, 'updated' => 0, 'rejected' => 0];
            $summary['blocks'][$k][$what]++;
        };

        foreach ($rows as $i => $r) {
            $rowNo = $i + 2;
            $known = fn () => $blocksById[$clusters[(string) ($r['cluster_code'] ?? '')]->block_id ?? 0] ?? null;
            $fail = function ($reason) use (&$errors, $rowNo, $r, $tally, $known) {
                $errors[] = [$rowNo, $reason, ...array_map(fn ($c) => $r[$c] ?? '', self::SCHOOL_COLUMNS)];
                $tally($known(), 'rejected');
            };
            $udise = preg_replace('/\D/', '', (string) ($r['udise_code'] ?? ''));

            foreach ($required as $col) {
                if (($r[$col] ?? '') === '' || $r[$col] === null) { $fail("Mandatory column '$col' is empty"); continue 2; }
            }
            if (strlen($udise) !== 11) { $fail('UDISE Code must be exactly 11 digits'); continue; }
            if (isset($seen[$udise])) { $fail('Duplicate UDISE Code in file (first at row '.$seen[$udise].')'); continue; }
            $seen[$udise] = $rowNo;

            $c = $clusters[(string) $r['cluster_code']] ?? null;
            if (! $c) { $fail('Cluster code not found in Location Master'); continue; }
            $b = $blocksById[$c->block_id] ?? null;
            $d = $b ? ($districtsById[$b->district_id] ?? null) : null;
            if (! $b || ! $d) { $fail('Cluster is not linked to a block and district'); continue; }
            if ($district && $d->id !== $district->id) { $fail("Cluster {$c->code} is not in the chosen district ({$district->name})"); continue; }

            $dc = (string) ($r['district_code'] ?? '');
            $bc = (string) ($r['block_code'] ?? '');
            if ($dc !== '' && ! isset($districts[$dc])) { $fail('District code not found in Location Master'); continue; }
            if ($bc !== '' && (! isset($blocks[$bc]) || ($dc !== '' && $blocks[$bc]->district_id !== $districts[$dc]->id))) { $fail('Block code not found or not in the given district'); continue; }
            if ($bc !== '' && $blocks[$bc]->id !== $b->id) { $fail("Cluster {$c->code} is not in the given block (it is in {$b->name})"); continue; }
            if ($dc !== '' && $districts[$dc]->id !== $d->id) { $fail("Cluster {$c->code} is not in the given district (it is in {$d->name})"); continue; }

            $lat = $r['latitude'] ?? null;
            $lng = $r['longitude'] ?? null;
            if (($lat !== null && $lat !== '') xor ($lng !== null && $lng !== '')) { $fail('Give both latitude and longitude, or neither'); continue; }
            if ($lat !== null && $lat !== '' && (! is_numeric($lat) || ! is_numeric($lng) || abs($lat) > 90 || abs($lng) > 180)) { $fail('Invalid latitude/longitude'); continue; }

            $status = strtolower((string) ($r['status'] ?: 'active'));
            if (! in_array($status, ['active', 'closed'], true)) { $fail('Status must be active or closed'); continue; }

            $school = School::withTrashed()->firstOrNew(['udise_code' => $udise]);
            $school->exists ? $updated++ : $added++;
            $school->fill([
                'name' => mb_substr((string) $r['school_name'], 0, 200),
                'state_id' => $d->state_id,
                'district_id' => $d->id,
                'block_id' => $b->id,
                'cluster_id' => $c->id,
                'management_id' => $this->masterId('management', $r['management'] ?? null),
                'category_id' => $this->masterId('category', $r['category'] ?? null),
                'address' => $r['address'] ?? null,
                'latitude' => $lat === '' ? null : $lat,
                'longitude' => $lng === '' ? null : $lng,
                'status' => $status,
            ]);
            $school->deleted_at = null;
            $tally($b, $school->exists ? 'updated' : 'added');
            $school->save();
        }

        return compact('added', 'updated', 'errors', 'summary');
    }

    /** Match a master value by code or label; add it if new (UDISE exports vary in wording). */
    private function masterId(string $type, mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $mv = MasterValue::where('type', $type)
            ->where(fn ($q) => $q->where('code', Str::snake($value))->orWhere('label', $value))->first();

        return ($mv ?? MasterValue::create(['type' => $type, 'code' => Str::limit(Str::snake($value), 40, ''), 'label' => $value, 'sort' => 999]))->id;
    }
}
