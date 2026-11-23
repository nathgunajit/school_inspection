<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\MasterValue;
use App\Models\School;
use App\Services\ImportController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** School Master upload of the UDISE+ 2025-26 export as it is (Documents/SchoolMaster_UDISE 202526.xlsx). */
class SchoolUdiseImportTest extends TestCase
{
    /** The 21 headings of the UDISE+ file, written exactly as in the export. */
    private const HEADINGS = ['District Code', 'Block Code', 'cluster lcode', 'udise_sch_code', 'school_name', 'Category', 'Management', 'School_Type',
        'School_Location', 'Med_Final_25', 'Assembly_Name', 'LS', 'Arts_Stream', 'Commerce_Stream', 'Science_Stream', 'Vocational_Stream',
        'Other_Streams', 'Address', 'Pincode', 'Latitude', 'Longitude'];

    private function xlsx(array $rows): UploadedFile
    {
        $path = 'tmp/udise-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport(self::HEADINGS, $rows), $path, 'local');

        return new UploadedFile(Storage::disk('local')->path($path), 'SchoolMaster_UDISE.xlsx', null, null, true);
    }

    private function row(string $udise, array $over = []): array
    {
        return array_values(array_replace([
            1801, 180101, 18010101, $udise, 'SHYAMTHAIBARI LPS', '1 - Primary', '1 - Department of Education', '3 - Co-ed',
            '1-Rural', 'AssameseBodo', 'DOTMA (ST)-AC18002', 'KOKRAJHAR (ST)', 'NA', 'NA', 'NA', 'NA', 'NA',
            'SHYAMTHAIBARI,PO-GOSSAINICHINA-783346', 783346, 26.501767, 90.1231,
        ], $over));
    }

    private function upload(array $rows): void
    {
        $this->post(route('admin.imports.preview'), ['district_id' => 'all', 'file' => $this->xlsx($rows)])->assertRedirect(route('admin.imports.index'));
        $this->post(route('admin.imports.confirm'))->assertRedirect(route('admin.imports.index'));
    }

    public function test_udise_export_imports_every_column(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $this->actingAs($this->admin());
        $categories = MasterValue::where('type', 'category')->count();
        $managements = MasterValue::where('type', 'management')->count();

        $this->upload([
            $this->row('18010100102'),
            // Higher Secondary school: streams Yes / No; a 5-digit pincode is dropped, not rejected
            $this->row('18010100208', [5 => '5 - Up. Pr. Secondary and Higher Sec', 6 => '10 - Kasturba Gandhi Bailika Vidyalaya/Residential Schools (KGBV/RS)',
                7 => '2 - Girls', 8 => '2-Urban', 9 => 'English', 12 => '1 - Yes', 13 => '2 - No', 14 => '1 - Yes', 15 => '2 - No', 16 => '2 - No', 18 => 78334]),
        ]);

        $a = School::where('udise_code', '18010100102')->firstOrFail();
        $this->assertSame('SHYAMTHAIBARI LPS', $a->name);
        $this->assertSame('primary', $a->category->code);
        $this->assertSame('dept_education', $a->management->code);
        $this->assertSame('co_ed', $a->school_type);
        $this->assertSame('rural', $a->area_type);
        $this->assertSame('AssameseBodo', $a->medium);
        $this->assertSame('Assamese, Bodo', $a->medium_label);
        $this->assertSame('DOTMA (ST)-AC18002', $a->assembly_constituency);
        $this->assertSame('KOKRAJHAR (ST)', $a->lok_sabha);
        $this->assertSame('783346', $a->pincode);
        $this->assertNull($a->streams, 'NA streams mean no Higher Secondary');
        $this->assertEqualsWithDelta(26.501767, $a->latitude, 0.000001);

        $b = School::where('udise_code', '18010100208')->firstOrFail();
        $this->assertSame('upr_to_hsec', $b->category->code);
        $this->assertSame('kgbv', $b->management->code);
        $this->assertSame(['girls', 'urban'], [$b->school_type, $b->area_type]);
        $this->assertSame(['arts' => true, 'commerce' => false, 'science' => true, 'vocational' => false, 'other' => false], $b->streams);
        $this->assertSame('Arts, Science', $b->streams_label);
        $this->assertNull($b->pincode);

        // UDISE numbers map to the existing masters: no duplicate master values are created
        $this->assertSame($categories, MasterValue::where('type', 'category')->count());
        $this->assertSame($managements, MasterValue::where('type', 'management')->count());

        $this->get(route('schools.show', $b))->assertOk()->assertSee('Girls · Urban')->assertSee('Arts, Science')->assertSee('KOKRAJHAR (ST)');
    }

    public function test_reupload_updates_and_an_older_template_keeps_the_udise_fields(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $this->actingAs($this->admin());
        $this->upload([$this->row('18010100102')]);
        $this->upload([$this->row('18010100102', [4 => 'SHYAMTHAIBARI LP SCHOOL', 8 => '2-Urban'])]);

        $s = School::where('udise_code', '18010100102')->firstOrFail();
        $this->assertSame('SHYAMTHAIBARI LP SCHOOL', $s->name);
        $this->assertSame('urban', $s->area_type);
        $this->assertSame(1, School::where('udise_code', '18010100102')->count());

        // a file in the old short format (no UDISE+ columns) does not wipe them
        $path = 'tmp/old-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport(['udise_code', 'school_name', 'cluster_code', 'status'], [['18010100102', 'Old Format Name', '18010101', 'active']]), $path, 'local');
        $this->post(route('admin.imports.preview'), ['district_id' => 'all', 'file' => new UploadedFile(Storage::disk('local')->path($path), 'old.xlsx', null, null, true)]);
        $this->post(route('admin.imports.confirm'));

        $s->refresh();
        $this->assertSame('Old Format Name', $s->name);
        $this->assertSame('urban', $s->area_type);
        $this->assertSame('783346', $s->pincode);
    }

    public function test_admin_form_saves_udise_fields(): void
    {
        $this->actingAs($this->admin());
        $s = School::orderBy('id')->firstOrFail();

        $this->put(route('admin.schools.update', $s), [
            'udise_code' => $s->udise_code, 'name' => $s->name, 'district_id' => $s->district_id, 'block_id' => $s->block_id, 'cluster_id' => $s->cluster_id,
            'status' => 'active', 'school_type' => 'boys', 'area_type' => 'rural', 'medium' => 'Bengali', 'pincode' => '781001',
            'has_hs' => '1', 'streams' => ['science'],
        ])->assertRedirect(route('admin.schools.index'));

        $s->refresh();
        $this->assertSame(['boys', 'rural', 'Bengali', '781001'], [$s->school_type, $s->area_type, $s->medium, $s->pincode]);
        $this->assertSame('Science', $s->streams_label);

        $this->put(route('admin.schools.update', $s), [
            'udise_code' => $s->udise_code, 'name' => $s->name, 'district_id' => $s->district_id, 'block_id' => $s->block_id, 'cluster_id' => $s->cluster_id,
            'status' => 'active', 'pincode' => '12',
        ])->assertSessionHasErrors('pincode');
    }
}
