<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Exports\ArrayExport;
use App\Models\District;
use App\Models\ImportBatch;
use App\Models\Inspection;
use App\Models\School;
use App\Models\TlmItem;
use App\Services\MasterImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Modification V12 (Documents/Modification of the ApplicationV12.docx):
 * 1 next visit: Teachers present 0, absentee blank · 2 all present removes the absentee list ·
 * 3/4 previous classrooms fetched for editing · 5 report jump bar shows every step ·
 * 6 School Master for all districts, counted district-wise · 7 TLM / Location edit in a window ·
 * 8 no "Not Found" after an expired session or draft.
 */
class ModificationV12Test extends TestCase
{
    private function answer(Inspection $i, string $code, mixed $value)
    {
        return $this->postJson(route('inspections.answers', $i), ['code' => $code, 'value' => $value])->assertOk();
    }

    private function submitLastMonth(Inspection $i): Inspection
    {
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now()->subMonth(), 'inspection_date' => now()->subMonth()->toDateString(),
            'inspection_no' => 'TEST/'.$i->id])->save();

        return $i->fresh();
    }

    public function test_second_visit_starts_with_present_0_blank_absentees_and_last_classrooms(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $tlm = TlmItem::where('is_active', true)->orderBy('id')->firstOrFail();

        $first = $this->startInspection($officer, $school);
        $this->answer($first, 'TSI_04', 3);
        $this->answer($first, 'TSI_09', 2);
        $this->postJson(route('inspections.teachers.store', $first), ['teacher_name' => 'Rina Kalita', 'teacher_type' => 'regular', 'reason' => 'CL'])->assertOk();
        $this->postJson(route('inspections.classrooms.store', $first), ['teacher_name' => 'Bijoy Das', 'class' => 'I', 'subject' => 'Mathematics',
            'tlm_used' => 'yes', 'tlm_items' => [$tlm->id], 'participation' => 'satisfactory', 'remarks' => 'Good use of charts'])->assertOk();
        $this->submitLastMonth($first);

        $next = $this->startInspection($officer, $school);
        $this->assertSame($first->id, $next->prefilled_from_id);
        $this->assertSame(0, $next->answerMap()['TSI_09']);
        $this->assertSame(3, $next->answerMap()['TSI_04']);
        $this->assertSame(0, $next->absentTeachers()->count());

        $row = $next->classroomObservations()->with('tlmItems')->sole();
        $this->assertSame(['Bijoy Das', 'I', 'Mathematics', 'Good use of charts'], [$row->teacher_name, $row->class, $row->subject, $row->remarks]);
        $this->assertSame([$tlm->id], $row->tlmItems->pluck('id')->all());
        $this->assertNull($row->row_uid);
        $this->assertSame(1, $first->classroomObservations()->count(), 'source untouched');

        $this->get(route('inspections.step', [$next, 4]))->assertOk()->assertSee('The classrooms observed last time are listed below')->assertSee('Bijoy Das');
        $this->get(route('inspections.step', [$next, 2]))->assertSee('Teachers present starts at 0');

        // the copied row can be edited like any other
        $this->putJson(route('inspections.classrooms.update', [$next, $row]), ['teacher_name' => 'Bijoy Das', 'class' => 'II', 'subject' => 'English',
            'tlm_used' => 'no', 'participation' => 'not_satisfactory'])->assertOk();
        $this->assertSame('II', $row->fresh()->class);

        // Present 0 of 3: three absentees are needed until today's figure is entered
        $this->answer($next, 'TSI_09', 3);
        $this->assertSame(0, \App\Questionnaire\InspectionValidator::absentLimit($next->fresh()->answerMap()));
    }

    public function test_report_jump_bar_lists_every_step_even_without_photos(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $page = $this->get(route('inspections.show', $i))->assertOk();
        foreach (range(1, 7) as $n) {
            $page->assertSee('href="#rp-step-'.$n.'"', false);
        }
        $page->assertSee('No photos uploaded yet.');
    }

    public function test_all_districts_school_upload_is_counted_district_wise(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $path = 'tmp/all-'.uniqid().'.xlsx';
        // UDISE+ style headings are read as the template columns
        Excel::store(new ArrayExport(['udise_sch_code', 'school_name', 'clcode', 'status'], [
            ['18010101911', 'North One', '18010101', 'active'],
            ['18010201912', 'North Two', '18010201', 'active'],
            ['18020101913', 'South One', '18020101', 'active'],
            ['18020101914', 'Bad cluster', '99999999', 'active'],
        ]), $path, 'local');
        $file = new UploadedFile(Storage::disk('local')->path($path), 'state.xlsx', null, null, true);
        $north = District::where('code', '1801')->firstOrFail();
        $south = District::where('code', '1802')->firstOrFail();

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.imports.preview'), ['district_id' => 'all', 'file' => $file])
            ->assertRedirect(route('admin.imports.index'))
            ->assertSessionHas('import_preview', fn ($p) => $p['added'] === 3 && $p['rejected'] === 1
                && $p['summary']['districts'][(string) $north->id]['added'] === 2 && $p['summary']['districts'][(string) $south->id]['added'] === 1);
        $this->assertDatabaseMissing('schools', ['udise_code' => '18020101913']);
        $this->get(route('admin.imports.index'))->assertOk()->assertSee('All districts')->assertSee('District-wise (3 districts)');

        $this->post(route('admin.imports.confirm'))->assertRedirect(route('admin.imports.index'));
        $this->assertSame($south->id, School::where('udise_code', '18020101913')->value('district_id'));
        $batch = ImportBatch::latest('id')->firstOrFail();
        $this->assertNull($batch->district_id);
        $this->assertTrue($batch->summary['all_districts']);
        $this->assertSame(2, $batch->summary['districts'][(string) $north->id]['added']);
        $this->get(route('admin.imports.index', ['tab' => 'history']))->assertSee('By district');

        $row = app(\App\Services\SchoolCountReport::class)->districts()->firstWhere('id', $south->id);
        $this->assertNotNull($row->last_upload, 'an all-districts file counts as the district\'s last upload');

        $this->get(route('admin.imports.template', ['school', 'district' => 'all']))->assertOk();
        $this->post(route('admin.imports.preview'), ['district_id' => '999999', 'file' => $file])->assertSessionHasErrors('district_id');
    }

    public function test_tlm_and_location_edit_open_in_a_window_and_errors_stay_there(): void
    {
        $admin = $this->admin();
        $item = TlmItem::orderBy('id')->firstOrFail();
        $other = TlmItem::where('list', $item->list)->where('class', $item->class)->whereKeyNot($item->id)->firstOrFail();

        $this->actingAs($admin)->get(route('admin.tlm.index'))->assertOk()
            ->assertSee('data-bs-target="#tlm-edit-'.$item->id.'"', false)->assertSee('id="tlm-edit-'.$item->id.'"', false);

        // a duplicate name: the error is in the edit bag and the window opens again; the Add form stays empty
        $this->from(route('admin.tlm.index'))->put(route('admin.tlm.update', $item), ['_edit' => $item->id, 'list' => $item->list,
            'class' => $item->class ?? 'all', 'name' => $other->name])->assertRedirect(route('admin.tlm.index'))->assertSessionHasErrorsIn('edit', 'name');
        $html = $this->get(route('admin.tlm.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="tlm-edit-'.$item->id.'"[^>]*data-reopen/', $html);

        $district = District::orderBy('id')->firstOrFail();
        $this->get(route('admin.locations.index', 'districts'))->assertOk()->assertSee('id="loc-edit-'.$district->id.'"', false);
    }

    public function test_expired_draft_and_old_links_do_not_end_on_not_found(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $i->update(['status' => InspectionStatus::Cancelled, 'cancelled_reason' => 'Expired: not submitted within 7 days']);
        $this->get(route('inspections.step', [$i, 1]))->assertRedirect(route('inspections.mine'))->assertSessionHas('warning');
        $this->get(route('inspections.review', $i))->assertRedirect(route('inspections.mine'));

        $i->update(['status' => InspectionStatus::Unlocked]);
        $this->get(route('inspections.submitted', $i))->assertRedirect(route('inspections.show', $i));

        // a friendly page instead of the bare "Not Found"
        $this->get('/inspections/not-a-real-token')->assertNotFound()->assertSee('Page not found')->assertSee('Go to Dashboard');

        // after the session expired, a photo or data link is not the page shown after sign-in
        auth()->logout();
        $user = $this->officer(UserLevel::Cluster, $this->otherCluster($officer->currentJurisdiction->cluster));
        $this->withSession(['url.intended' => url('/session/ping')])
            ->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->withSession(['url.intended' => route('schools.index')])
            ->post(route('login'), ['mobile' => $user->mobile, 'password' => 'Secret@123'])->assertRedirect(route('schools.index'));
    }
}
