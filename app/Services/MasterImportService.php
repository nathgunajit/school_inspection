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

    public const SCHOOL_COLUMNS = ['udise_code', 'school_name', 'district_code', 'block_code', 'cluster_code', 'management', 'category', 'address', 'latitude', 'longitude', 'status'];

    public static function template(string $type): ArrayExport
    {
        return $type === 'location'
            ? new ArrayExport(self::LOCATION_COLUMNS, [
                ['state', '18', 'Demo State', '', 'active'],
                ['district', '1801', 'North District', '18', 'active'],
                ['block', '180101', 'Riverside Block', '1801', 'active'],
                ['cluster', '18010101', 'Riverside Cluster 1', '180101', 'active'],
            ])
            : new ArrayExport(self::SCHOOL_COLUMNS, [
                ['18010101001', 'Govt. Primary School No. 1', '1801', '180101', '18010101', 'Department of Education', 'Primary (Class 1–5)', 'Village, Block, District', '26.1130000', '91.7110000', 'active'],
            ]);
    }

    public function import(string $type, UploadedFile $file, User $user): ImportBatch
    {
        $headings = (new HeadingRowImport)->toArray($file)[0][0] ?? [];
        $expected = $type === 'location' ? self::LOCATION_COLUMNS : self::SCHOOL_COLUMNS;
        $missingCols = array_diff($expected, $headings);
        if ($missingCols) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'The file does not match the template. Missing columns: '.implode(', ', $missingCols),
            ]);
        }

        $rows = Excel::toCollection(new class implements \Maatwebsite\Excel\Concerns\WithHeadingRow, \Maatwebsite\Excel\Concerns\ToCollection {
            public function collection(Collection $rows) {}
        }, $file)->first() ?? collect();

        $rows = $rows->map(fn ($r) => collect($r)->map(fn ($v) => is_string($v) ? trim($v) : $v))
            ->filter(fn ($r) => $r->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())
            ->values();

        $result = DB::transaction(fn () => $type === 'location' ? $this->importLocations($rows) : $this->importSchools($rows));

        $batch = ImportBatch::create([
            'type' => $type,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'uploaded_by' => $user->id,
            'total_rows' => $rows->count(),
            'added' => $result['added'],
            'updated' => $result['updated'],
            'rejected' => count($result['errors']),
        ]);

        if ($result['errors']) {
            $path = 'imports/errors-'.$batch->id.'-'.Str::random(8).'.xlsx';
            Excel::store(new ArrayExport(['row_number', 'reason', ...$expected], $result['errors']), $path, 'private');
            $batch->update(['error_file_path' => $path]);
        }

        AuditLogger::log('master_upload', $batch, ['type' => $type, 'added' => $result['added'], 'updated' => $result['updated'], 'rejected' => count($result['errors'])]);

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
                $errors[] = [$rowNo, $reason, ...array_values($r->only(self::LOCATION_COLUMNS)->all())];
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

    private function importSchools(Collection $rows): array
    {
        $added = $updated = 0;
        $errors = [];
        $seen = [];
        $districts = District::all()->keyBy('code');
        $blocks = Block::all()->keyBy('code');
        $clusters = Cluster::all()->keyBy('code');

        foreach ($rows as $i => $r) {
            $rowNo = $i + 2;
            $fail = function ($reason) use (&$errors, $rowNo, $r) {
                $errors[] = [$rowNo, $reason, ...array_values($r->only(self::SCHOOL_COLUMNS)->all())];
            };
            $udise = preg_replace('/\D/', '', (string) $r['udise_code']);

            foreach (['udise_code', 'school_name', 'district_code', 'block_code', 'cluster_code'] as $col) {
                if (($r[$col] ?? '') === '' || $r[$col] === null) { $fail("Mandatory column '$col' is empty"); continue 2; }
            }
            if (strlen($udise) !== 11) { $fail('UDISE Code must be exactly 11 digits'); continue; }
            if (isset($seen[$udise])) { $fail('Duplicate UDISE Code in file (first at row '.$seen[$udise].')'); continue; }
            $seen[$udise] = $rowNo;

            $d = $districts[(string) $r['district_code']] ?? null;
            $b = $blocks[(string) $r['block_code']] ?? null;
            $c = $clusters[(string) $r['cluster_code']] ?? null;
            if (! $d) { $fail('District code not found in Location Master'); continue; }
            if (! $b || $b->district_id !== $d->id) { $fail('Block code not found or not in the given district'); continue; }
            if (! $c || $c->block_id !== $b->id) { $fail('Cluster code not found or not in the given block'); continue; }

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
            $school->save();
        }

        return compact('added', 'updated', 'errors');
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
