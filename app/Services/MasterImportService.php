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

    /** Optional UDISE+ 2025-26 columns (all may be left empty; a column missing from the file leaves the stored value as it is). */
    public const SCHOOL_UDISE_COLUMNS = ['school_type', 'area_type', 'medium', 'assembly_constituency', 'lok_sabha', 'pincode',
        'arts_stream', 'commerce_stream', 'science_stream', 'vocational_stream', 'other_stream'];

    public const SCHOOL_COLUMNS = ['udise_code', 'school_name', 'district_code', 'block_code', 'cluster_code', 'management', 'category', 'address', 'latitude', 'longitude', 'status', ...self::SCHOOL_UDISE_COLUMNS];

    /** District-wise upload: block and district come from the cluster, so these columns are enough. */
    public const SCHOOL_REQUIRED = ['udise_code', 'school_name', 'cluster_code'];

    public const SCHOOL_DISTRICT_COLUMNS = ['udise_code', 'school_name', 'cluster_code', 'management', 'category', 'address', 'latitude', 'longitude', 'status', ...self::SCHOOL_UDISE_COLUMNS];

    /** V12 item 6: heading names used by UDISE+ exports, read as the template columns. */
    public const SCHOOL_ALIASES = ['udise_sch_code' => 'udise_code', 'udise' => 'udise_code', 'udise_code_11' => 'udise_code', 'clcode' => 'cluster_code', 'cluster' => 'cluster_code',
        'crc_code' => 'cluster_code', 'sch_name' => 'school_name', 'name_of_school' => 'school_name', 'dist_code' => 'district_code', 'distcd' => 'district_code', 'blk_code' => 'block_code', 'blkcd' => 'block_code',
        // UDISE+ 2025-26 School Master export (Documents/SchoolMaster_UDISE 202526.xlsx)
        'cluster_lcode' => 'cluster_code', 'school_location' => 'area_type', 'med_final_25' => 'medium', 'medium_of_instruction' => 'medium',
        'assembly_name' => 'assembly_constituency', 'ls' => 'lok_sabha', 'lok_sabha_name' => 'lok_sabha', 'other_streams' => 'other_stream', 'pin_code' => 'pincode'];

    /** UDISE+ category number (the "N - " prefix) to the Category master code. */
    public const UDISE_CATEGORY = [1 => 'primary', 2 => 'primary_upper', 3 => 'pr_to_hsec', 4 => 'upper_primary', 5 => 'upr_to_hsec',
        6 => 'secondary_full', 7 => 'secondary', 8 => 'secondary_only', 10 => 'sec_hsec', 11 => 'higher_secondary'];

    /** UDISE+ management number to the Management master code. */
    public const UDISE_MANAGEMENT = [1 => 'dept_education', 10 => 'kgbv', 17 => 'self_based'];

    /** Example values for SCHOOL_UDISE_COLUMNS in the templates (the UDISE+ wording is accepted as it is). */
    private const UDISE_EXAMPLE = ['3 - Co-ed', '1-Rural', 'Assamese', 'DOTMA (ST)-AC18002', 'KOKRAJHAR (ST)', '783346', 'NA', 'NA', 'NA', 'NA', 'NA'];

    /** @var array<string, int|null> master id per type|value, filled during one import */
    private array $masterCache = [];

    /** V12 item 6: template for all districts at once: the Schools sheet plus every cluster with its block and district. */
    public static function allDistrictsTemplate(): \App\Exports\SchoolTemplateExport
    {
        $clusters = Cluster::query()->join('blocks', 'blocks.id', '=', 'clusters.block_id')->join('districts', 'districts.id', '=', 'blocks.district_id')
            ->orderBy('districts.name')->orderBy('blocks.name')->orderBy('clusters.name')
            ->get(['clusters.code', 'clusters.name', 'blocks.code as block_code', 'blocks.name as block_name', 'districts.code as district_code', 'districts.name as district_name']);
        $first = $clusters->first();

        return new \App\Exports\SchoolTemplateExport(
            new ArrayExport(self::SCHOOL_DISTRICT_COLUMNS, [[
                ($first?->code ?? '00000000').'001', 'Govt. Primary School No. 1', $first?->code ?? '', 'Department of Education', 'Primary (Class 1–5)', 'Village, Block, District', '', '', 'active', ...self::UDISE_EXAMPLE,
            ]]),
            new ArrayExport(['cluster_code', 'cluster_name', 'block_code', 'block_name', 'district_code', 'district_name'],
                $clusters->map(fn ($c) => [$c->code, $c->name, $c->block_code, $c->block_name, $c->district_code, $c->district_name])->all()),
            'Clusters (all districts)',
        );
    }

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
                ($first?->code ?? '00000000').'001', 'Govt. Primary School No. 1', $first?->code ?? '', 'Department of Education', 'Primary (Class 1–5)', 'Village, Block, '.$district->name, '', '', 'active', ...self::UDISE_EXAMPLE,
            ]]),
            new ArrayExport(['cluster_code', 'cluster_name', 'block_code', 'block_name'], $clusters->map(fn ($c) => [$c->code, $c->name, $c->block_code, $c->block_name])->all()),
            'Clusters of '.$district->name,
        );
    }

    /** Every existing location with its current code and name: edit the names, keep the codes, upload again. */
    public static function currentLocations(): ArrayExport
    {
        $coord = fn ($m) => [$m->latitude ?? '', $m->longitude ?? ''];
        $status = fn ($m) => $m->is_active ? 'active' : 'inactive';
        $rows = [];
        foreach (State::orderBy('code')->get() as $s) {
            $rows[] = ['state', $s->code, $s->name, '', $status($s), '', ''];
        }
        foreach (District::with('state:id,code')->orderBy('code')->get() as $d) {
            $rows[] = ['district', $d->code, $d->name, $d->state?->code, $status($d), ...$coord($d)];
        }
        foreach (Block::with('district:id,code')->orderBy('code')->get() as $b) {
            $rows[] = ['block', $b->code, $b->name, $b->district?->code, $status($b), ...$coord($b)];
        }
        foreach (Cluster::with('block:id,code')->orderBy('code')->get() as $c) {
            $rows[] = ['cluster', $c->code, $c->name, $c->block?->code, $status($c), '', ''];
        }

        return new ArrayExport([...self::LOCATION_COLUMNS, ...self::LOCATION_OPTIONAL], $rows);
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
                ['18010101001', 'Govt. Primary School No. 1', '1801', '180101', '18010101', 'Department of Education', 'Primary (Class 1–5)', 'Village, Block, District', '26.1130000', '91.7110000', 'active', ...self::UDISE_EXAMPLE],
            ]);
    }

    /**
     * Check a file without saving anything: same rules as import(), rolled back.
     *
     * @return array{total:int, added:int, updated:int, rejected:int, errors:array, summary:array}
     */
    public function preview(string $type, UploadedFile|string $file, ?District $district = null, bool $all = false): array
    {
        $rows = $this->readRows($type, $file, $district, $all);
        DB::beginTransaction();
        try {
            $result = $type === 'location' ? $this->importLocations($rows) : $this->importSchools($rows, $district, $all);
        } finally {
            DB::rollBack();
        }

        return ['total' => $rows->count(), 'added' => $result['added'], 'updated' => $result['updated'], 'rejected' => count($result['errors']),
            'type' => $type,
            'errors' => array_slice(array_map(fn ($e) => ['row' => $e[0], 'reason' => $e[1], 'udise' => $type === 'location' ? ($e[3] ?? '') : ($e[2] ?? '')], $result['errors']), 0, 20),
            'summary' => $result['summary'] ?? []];
    }

    private function readRows(string $type, UploadedFile|string $file, ?District $district, bool $all = false): Collection
    {
        set_time_limit(0); // a state-wide School Master (~44,000 rows) takes over a minute to read
        $alias = fn ($h) => $type === 'school' ? (self::SCHOOL_ALIASES[$h] ?? $h) : $h;
        $headings = array_map($alias, (new HeadingRowImport)->toArray($file)[0][0] ?? []);
        $expected = $type === 'location' ? self::LOCATION_COLUMNS
            : ($district || $all ? self::SCHOOL_REQUIRED : [...self::SCHOOL_REQUIRED, 'district_code']);
        $missingCols = array_diff($expected, $headings);
        if ($missingCols) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'The file does not match the template. Missing columns: '.implode(', ', $missingCols),
            ]);
        }

        $rows = Excel::toCollection(new class implements \Maatwebsite\Excel\Concerns\WithHeadingRow, \Maatwebsite\Excel\Concerns\ToCollection {
            public function collection(Collection $rows) {}
        }, $file)->first() ?? collect();

        return $rows->map(fn ($r) => collect($r)->mapWithKeys(fn ($v, $k) => [$alias($k) => is_string($v) ? trim($v) : $v]))
            ->filter(fn ($r) => $r->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())
            ->values();
    }

    public function import(string $type, UploadedFile|string $file, User $user, ?District $district = null, ?string $fileName = null, bool $all = false): ImportBatch
    {
        $rows = $this->readRows($type, $file, $district, $all);
        $expected = $type === 'location' ? self::LOCATION_COLUMNS : self::SCHOOL_COLUMNS;

        $result = DB::transaction(fn () => $type === 'location' ? $this->importLocations($rows) : $this->importSchools($rows, $district, $all));

        $batch = ImportBatch::create([
            'type' => $type,
            'district_id' => $district?->id,
            'file_name' => mb_substr($fileName ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file)), 0, 255),
            'uploaded_by' => $user->id,
            'total_rows' => $rows->count(),
            'added' => $result['added'],
            'updated' => $result['updated'],
            'rejected' => count($result['errors']),
            'summary' => ($result['summary'] ?? null) === null ? null : $result['summary'] + ($all ? ['all_districts' => true] : []),
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

        AuditLogger::log('master_upload', $batch, ['type' => $type, 'district_id' => $batch->district_id, 'all_districts' => (bool) ($batch->summary['all_districts'] ?? false),
            'added' => $result['added'], 'updated' => $result['updated'], 'renamed' => $result['summary']['renamed_count'] ?? 0, 'rejected' => count($result['errors']),
            'districts' => collect($result['summary']['districts'] ?? [])->map(fn ($d) => $d['added'] + $d['updated'])->all()]);

        // each name correction is recorded on its own (ID unchanged)
        foreach ($result['summary']['renamed'] ?? [] as $r) {
            AuditLogger::log('location_renamed', $batch, ['type' => $r['type'], 'id' => $r['id'], 'code' => $r['code'], 'old_name' => $r['old'], 'new_name' => $r['new']]);
        }

        return $batch;
    }

    private function importLocations(Collection $rows): array
    {
        $order = ['state' => 0, 'district' => 1, 'block' => 2, 'cluster' => 3];
        $added = $updated = 0;
        $errors = [];
        $seen = [];
        $changes = ['renamed' => [], 'added_items' => [], 'unchanged' => 0];

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

            $rec = $model::firstOrNew(['code' => $code]);

            if ($parentModel) {
                $parent = $parentModel::where('code', $parentCode)->first();
                if (! $parent) { $fail('Parent code not found in Location Master'); continue; }
                // Matching is by code, so the record ID and every link to it are kept. An existing
                // record is never moved to another parent from here (that would re-home its schools).
                if ($rec->exists && (int) $rec->{$fk} !== (int) $parent->id) {
                    $current = $parentModel::find($rec->{$fk});
                    $fail(ucfirst($type)." {$code} belongs to ".($current ? "{$current->name} ({$current->code})" : 'another parent').'; changing the parent is not allowed here (IDs and links are kept)');
                    continue;
                }
                if (! $rec->exists) {
                    $attrs[$fk] = $parent->id;
                }
            }

            $oldName = $rec->exists ? $rec->name : null;
            $rec->fill($attrs);
            if (! $rec->exists) {
                $added++;
                $changes['added_items'][] = ['type' => $type, 'code' => $code, 'name' => $name];
            } elseif ($rec->isDirty()) {
                $updated++;
                if ($oldName !== $name) {
                    $changes['renamed'][] = ['type' => $type, 'code' => $code, 'id' => $rec->id, 'old' => $oldName, 'new' => $name];
                }
            } else {
                $changes['unchanged']++;
            }
            $rec->save();
        }

        usort($errors, fn ($a, $b) => $a[0] <=> $b[0]);
        $summary = ['renamed' => array_slice($changes['renamed'], 0, 200), 'added_items' => array_slice($changes['added_items'], 0, 200),
            'renamed_count' => count($changes['renamed']), 'unchanged' => $changes['unchanged']];

        return compact('added', 'updated', 'errors', 'summary');
    }

    /**
     * With $district (district-wise upload) only udise_code, school_name and cluster_code are needed:
     * block and district come from the cluster, which must lie in that district. district_code /
     * block_code are still checked when a file carries them. Without $district (old files) district_code
     * and block_code are required as before. With $all (V12 item 6: every district in one file) the same three
     * columns are enough and the cluster decides the district. The summary counts rows per district and per block.
     */
    private function importSchools(Collection $rows, ?District $district = null, bool $all = false): array
    {
        $added = $updated = 0;
        $errors = [];
        $seen = [];
        $summary = ['districts' => [], 'blocks' => []];
        $districts = District::all()->keyBy('code');
        $districtsById = $districts->keyBy('id');
        $blocks = Block::all()->keyBy('code');
        $blocksById = $blocks->keyBy('id');
        $clusters = Cluster::all()->keyBy('code');
        $required = $district || $all ? self::SCHOOL_REQUIRED : ['udise_code', 'school_name', 'district_code', 'block_code', 'cluster_code'];
        $tally = function (?Block $b, string $what) use (&$summary, $districtsById) {
            $d = $b ? ($districtsById[$b->district_id] ?? null) : null;
            $dk = $d ? (string) $d->id : '0';
            $summary['districts'][$dk] ??= ['name' => $d?->name ?? 'Unknown district', 'code' => $d?->code, 'added' => 0, 'updated' => 0, 'rejected' => 0];
            $summary['districts'][$dk][$what]++;
            $k = $b ? (string) $b->id : '0';
            $summary['blocks'][$k] ??= ['name' => $b?->name ?? 'Unknown block', 'district_id' => $d?->id, 'district' => $d?->name, 'added' => 0, 'updated' => 0, 'rejected' => 0];
            $summary['blocks'][$k][$what]++;
        };

        set_time_limit(0);
        $this->masterCache = []; // a preview's new master values are rolled back
        $now = now();
        $existing = DB::table('schools')->pluck('id', 'udise_code')->all(); // soft-deleted rows included
        $inserts = [];
        $flush = function () use (&$inserts) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('schools')->insert($chunk);
            }
            $inserts = [];
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

            $status = strtolower((string) (($r['status'] ?? '') ?: 'active'));
            if (! in_array($status, ['active', 'closed'], true)) { $fail('Status must be active or closed'); continue; }

            $attrs = [
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
                'deleted_at' => null,
                'updated_at' => $now,
            ] + $this->udiseFields($r);

            // Query builder, not Eloquent: a state-wide file has ~44,000 rows and is run twice (preview, then confirm).
            if (isset($existing[$udise])) {
                DB::table('schools')->where('id', $existing[$udise])->update($attrs);
                $updated++;
                $tally($b, 'updated');
            } else {
                $inserts[] = $attrs + ['udise_code' => $udise, 'created_at' => $now];
                $added++;
                $tally($b, 'added');
                if (count($inserts) >= 500) {
                    $flush();
                }
            }
        }
        $flush();

        uasort($summary['districts'], fn ($x, $y) => strcmp($x['name'], $y['name']));

        return compact('added', 'updated', 'errors', 'summary');
    }

    /**
     * Match a master value by code or label; add it if new (UDISE exports vary in wording).
     * UDISE+ values such as "1 - Primary" are matched by their number first (UDISE_CATEGORY / UDISE_MANAGEMENT),
     * then by the text after the number. Results are cached for the rest of the import.
     */
    private function masterId(string $type, mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $key = $type.'|'.$value;
        if (array_key_exists($key, $this->masterCache)) {
            return $this->masterCache[$key];
        }

        [$no, $text] = self::udiseNumbered($value);
        $map = ['category' => self::UDISE_CATEGORY, 'management' => self::UDISE_MANAGEMENT][$type] ?? [];
        $mv = $no !== null && isset($map[$no]) ? MasterValue::where('type', $type)->where('code', $map[$no])->first() : null;
        $mv ??= MasterValue::where('type', $type)
            ->where(fn ($q) => $q->where('code', Str::snake($text))->orWhere('label', $text))->first();
        $mv ??= MasterValue::create(['type' => $type, 'code' => Str::limit(Str::snake($text), 40, ''), 'label' => mb_substr($text, 0, 120), 'sort' => 999]);

        return $this->masterCache[$key] = $mv->id;
    }

    /** "3 - Co-ed" → [3, 'Co-ed']; "1-Rural" → [1, 'Rural']; "Co-ed" → [null, 'Co-ed']. */
    public static function udiseNumbered(mixed $value): array
    {
        $value = trim((string) $value);

        return preg_match('/^(\d+)\s*-\s*(.+)$/u', $value, $m) ? [(int) $m[1], trim($m[2])] : [null, $value];
    }

    /**
     * The optional UDISE+ fields of one row. Only columns present in the file are returned, so an older template
     * leaves the stored values alone. A value that cannot be read is stored as null; it never rejects the row.
     */
    private function udiseFields(Collection $r): array
    {
        $has = fn ($c) => $r->has($c);
        $text = fn ($c, $len) => ($v = trim((string) ($r[$c] ?? ''))) === '' || strtoupper($v) === 'NA' ? null : mb_substr($v, 0, $len);
        $out = [];

        if ($has('school_type')) {
            $out['school_type'] = self::pick($r['school_type'], [1 => 'boys', 2 => 'girls', 3 => 'co_ed'], ['boys' => 'boys', 'girls' => 'girls', 'coed' => 'co_ed', 'co_ed' => 'co_ed', 'co_educational' => 'co_ed']);
        }
        if ($has('area_type')) {
            $out['area_type'] = self::pick($r['area_type'], [1 => 'rural', 2 => 'urban'], ['rural' => 'rural', 'urban' => 'urban']);
        }
        foreach (['medium' => 60, 'assembly_constituency' => 60, 'lok_sabha' => 40] as $c => $len) {
            if ($has($c)) {
                $out[$c] = $text($c, $len);
            }
        }
        if ($has('pincode')) {
            $pin = preg_replace('/\D/', '', (string) ($r['pincode'] ?? ''));
            $out['pincode'] = strlen($pin) === 6 ? $pin : null;
        }

        $streamCols = array_filter(array_keys(School::STREAMS), fn ($s) => $has($s.'_stream'));
        if ($streamCols) {
            $streams = [];
            foreach ($streamCols as $s) {
                $v = self::pick($r[$s.'_stream'], [1 => true, 2 => false], ['yes' => true, 'y' => true, 'no' => false, 'n' => false]);
                if ($v !== null) {
                    $streams[$s] = $v;
                }
            }
            $out['streams'] = $streams ? json_encode($streams) : null;
        }

        return $out;
    }

    /** Read a UDISE+ coded value by its number ("2 - Girls") or its words ("Girls"). */
    private static function pick(mixed $value, array $byNumber, array $byWord): mixed
    {
        [$no, $text] = self::udiseNumbered($value);
        $word = Str::snake(str_replace('-', '', strtolower($text)));

        return $byWord[$word] ?? ($no !== null ? ($byNumber[$no] ?? null) : null);
    }
}
