<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\AssemblyConstituency;
use App\Models\MasterValue;
use App\Models\ParliamentaryConstituency;
use App\Models\School;
use App\Services\MasterImportService;
use Database\Seeders\ConstituencySeeder;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ConstituencySeeder::class);
    }

    private function xlsx(array $rows, array $headings = self::HEADINGS): UploadedFile
    {
        $path = 'tmp/udise-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport($headings, $rows), $path, 'local');

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

    private function upload(array $rows, array $headings = self::HEADINGS): void
    {
        $this->post(route('admin.imports.preview'), ['district_id' => 'all', 'file' => $this->xlsx($rows, $headings)])->assertRedirect(route('admin.imports.index'));
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
        $this->assertSame('AC18002', $a->ac->code);
        $this->assertSame('DOTMA (ST)', $a->ac->name);
        $this->assertSame('PC1801', $a->pc->code);
        $this->assertSame('KOKRAJHAR (ST)', $a->pc->name);
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

        $this->get(route('schools.show', $b))->assertOk()->assertSee('Girls · Urban')->assertSee('Arts, Science')
            ->assertSee('DOTMA (ST) · AC18002')->assertSee('KOKRAJHAR (ST) · PC1801');

        // the seeded master already has every AC / PC of the file: nothing new is added
        $this->assertSame(126, AssemblyConstituency::count());
        $this->assertSame(14, ParliamentaryConstituency::count());
    }

    public function test_template_order_is_school_location_details_then_coordinates(): void
    {
        $this->assertSame(['udise_code', 'school_name'], array_slice(MasterImportService::SCHOOL_COLUMNS, 0, 2));
        $this->assertSame(['district_code', 'block_code', 'cluster_code'], array_slice(MasterImportService::SCHOOL_COLUMNS, 2, 3));
        $this->assertSame(['latitude', 'longitude'], array_slice(MasterImportService::SCHOOL_COLUMNS, -2));
        $this->assertContains('ac', MasterImportService::SCHOOL_COLUMNS);
        $this->assertContains('pc', MasterImportService::SCHOOL_COLUMNS);
        $this->assertSame(MasterImportService::SCHOOL_COLUMNS, array_merge(...array_values(MasterImportService::SCHOOL_GROUPS)));

        $sheets = MasterImportService::allDistrictsTemplate()->sheets();
        $this->assertSame(['Schools', 'Clusters (all districts)', 'AC list', 'PC list', 'Value lists'], array_map(fn ($s) => $s->title(), array_values($sheets)));
        $sheets = array_values($sheets);
        $this->assertSame(MasterImportService::SCHOOL_COLUMNS, $sheets[0]->headings());
        $this->assertCount(count(MasterImportService::SCHOOL_COLUMNS), $sheets[0]->array()[0], 'the example row fills every column');
        $this->assertSame(['ac', 'ac_name', 'pc', 'pc_name', 'districts'], $sheets[2]->headings());
        $this->assertContains(['AC18002', 'DOTMA (ST)', 'PC1801', 'KOKRAJHAR (ST)', ''], $sheets[2]->array());
        $this->assertSame(['pc', 'pc_name', 'ac_count'], $sheets[3]->headings());
        $this->assertCount(14, $sheets[3]->array());

        $this->actingAs($this->admin())->get(route('admin.imports.template', 'school'))->assertOk()->assertDownload();
    }

    public function test_template_file_imports_and_ac_matches_by_code_name_or_number(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $this->actingAs($this->admin());
        $cols = MasterImportService::SCHOOL_COLUMNS;
        $row = fn ($udise, $ac, $pc) => array_values(array_replace(array_fill_keys($cols, ''), [
            'udise_code' => $udise, 'school_name' => 'School '.$udise, 'cluster_code' => '18010101', 'ac' => $ac, 'pc' => $pc, 'status' => 'active',
            'latitude' => '26.5', 'longitude' => '90.1',
        ]));

        $this->upload([
            $row('18010100901', 'AC18002', 'PC1801'),
            $row('18010100902', 'DOTMA (ST)', ''),
            $row('18010100903', '18002', ''),
            $row('18010100904', '', 'DHUBRI'),
            // not in the master: added under its PC
            $row('18010100905', 'NEW PLACE-AC18999', 'PC1801'),
            $row('18010100906', 'AC18998', 'NEW LOK SABHA'),
        ], $cols);

        foreach (['18010100901', '18010100902', '18010100903'] as $u) {
            $s = School::where('udise_code', $u)->firstOrFail();
            $this->assertSame(['AC18002', 'PC1801'], [$s->ac?->code, $s->pc?->code], $u);
        }
        $d = School::where('udise_code', '18010100904')->firstOrFail();
        $this->assertNull($d->ac_id);
        $this->assertSame('PC1802', $d->pc->code);

        $new = AssemblyConstituency::where('code', 'AC18999')->firstOrFail();
        $this->assertSame(['NEW PLACE', 'PC1801'], [$new->name, $new->pc->code]);
        $this->assertSame($new->id, School::where('udise_code', '18010100905')->value('ac_id'));
        $pc = ParliamentaryConstituency::where('name', 'NEW LOK SABHA')->firstOrFail();
        $this->assertSame('PC1815', $pc->code);
        $this->assertSame($pc->id, AssemblyConstituency::where('code', 'AC18998')->value('pc_id'));

        $batch = \App\Models\ImportBatch::latest('id')->first();
        $this->assertCount(3, $batch->summary['new_constituencies']);
        $this->assertSame(0, $batch->rejected);
    }

    public function test_upload_page_defaults_to_all_districts(): void
    {
        $this->actingAs($this->admin())->get(route('admin.imports.index'))->assertOk()
            ->assertSee('<option value="all" selected', false);
    }

    public function test_constituency_master_screen(): void
    {
        $admin = $this->admin();
        $ac = AssemblyConstituency::where('code', 'AC18002')->firstOrFail();
        $school = School::orderBy('id')->firstOrFail();
        $school->update(['ac_id' => $ac->id, 'pc_id' => $ac->pc_id]);

        $this->actingAs($admin)->get(route('admin.constituencies.index'))->assertOk()->assertSee('AC18002')->assertSee('DOTMA (ST)');
        $this->get(route('admin.constituencies.index', ['tab' => 'pc']))->assertOk()->assertSee('PC1814')->assertSee('JORHAT');

        // edit: rename and move to another PC; its schools follow the PC
        $dhubri = ParliamentaryConstituency::where('code', 'PC1802')->firstOrFail();
        $this->put(route('admin.constituencies.ac.update', $ac), ['code' => 'ac18002', 'name' => 'Dotma  (ST)', 'pc_id' => $dhubri->id])->assertRedirect();
        $this->assertSame(['AC18002', 'Dotma (ST)', $dhubri->id], [$ac->fresh()->code, $ac->fresh()->name, $ac->fresh()->pc_id]);
        $this->assertSame($dhubri->id, $school->fresh()->pc_id);

        $this->put(route('admin.constituencies.ac.update', $ac), ['_edit' => $ac->id, 'code' => 'AC18001', 'name' => 'X', 'pc_id' => $dhubri->id])->assertSessionHasErrors('code', null, 'edit');
        $this->put(route('admin.constituencies.ac.update', $ac), ['toggle' => 1]);
        $this->assertFalse($ac->fresh()->is_active);

        $this->post(route('admin.constituencies.store', 'pc'), ['code' => 'PC1815', 'name' => 'TEST PC'])->assertRedirect();
        $this->assertDatabaseHas('parliamentary_constituencies', ['code' => 'PC1815', 'name' => 'TEST PC']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ac_updated']);

        $officer = \App\Models\User::where('level', 'cluster')->where('status', 'active')->first() ?? $this->officer(\App\Enums\UserLevel::Cluster);
        $this->actingAs($officer)->get(route('admin.constituencies.index'))->assertForbidden();
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
            'has_hs' => '1', 'streams' => ['science'], 'ac_id' => AssemblyConstituency::where('code', 'AC18002')->value('id'),
        ])->assertRedirect(route('admin.schools.index'));

        $s->refresh();
        $this->assertSame('PC1801', $s->pc->code, 'the PC follows the chosen AC');
        $this->get(route('admin.schools.edit', $s))->assertOk()->assertSee('KOKRAJHAR (ST) · PC1801');
        $this->assertSame(['boys', 'rural', 'Bengali', '781001'], [$s->school_type, $s->area_type, $s->medium, $s->pincode]);
        $this->assertSame('Science', $s->streams_label);

        $this->put(route('admin.schools.update', $s), [
            'udise_code' => $s->udise_code, 'name' => $s->name, 'district_id' => $s->district_id, 'block_id' => $s->block_id, 'cluster_id' => $s->cluster_id,
            'status' => 'active', 'pincode' => '12',
        ])->assertSessionHasErrors('pincode');
    }
}
