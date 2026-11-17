<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Inspection;
use App\Models\InspectionEvidence;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InspectionFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function answer(Inspection $i, string $code, mixed $value, ?string $remarks = null)
    {
        $payload = ['code' => $code, 'value' => $value];
        if ($remarks !== null) {
            $payload['remarks'] = $remarks;
        }

        return $this->postJson(route('inspections.answers', $i), $payload);
    }

    private function photo(Inspection $i, string $category, ?string $question = null, array $over = [])
    {
        $file = UploadedFile::fake()->image('p.jpg', 400, 300);

        return $this->post(route('evidence.store', $i), array_merge([
            'file' => $file,
            'evidence_uid' => (string) Str::uuid(),
            'kind' => 'photo',
            'category' => $category,
            'question_code' => $question,
            'latitude' => 26.11,
            'longitude' => 91.71,
            'accuracy' => 9,
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ], $over), ['Accept' => 'application/json']);
    }

    private function classroom(Inspection $i, array $over = [])
    {
        return $this->postJson(route('inspections.classrooms.store', $i), array_merge([
            'teacher_name' => 'B. Devi', 'class' => 'V', 'subject' => 'Mathematics', 'tlm_used' => 'yes', 'participation' => 'satisfactory',
        ], $over));
    }

    /** Fill every question (v2) with a valid answer, one classroom record and the 4 mandatory photos. */
    private function completeAll(Inspection $i): void
    {
        $overrides = ['TSI_09' => 4];
        $answers = [];
        foreach (Q::all() as $code => $q) {
            if (! InspectionValidator::visible($q, $answers)) {
                continue;
            }
            $v = $overrides[$code] ?? match ($q['type']) {
                'radio', 'select' => array_key_first($q['options']),
                'multi' => [array_key_first($q['options'])],
                'number' => 1,
                'date' => now()->toDateString(),
                default => 'Observed',
            };
            $answers[$code] = $v;
            $this->answer($i, $code, $v)->assertOk();
        }
        $this->classroom($i)->assertOk();
        foreach (array_keys(config('inspection.mandatory_photos')) as $cat) {
            $this->photo($i, $cat)->assertCreated();
        }
    }

    public function test_full_inspection_submission_generates_id_and_locks(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $i = $this->startInspection($officer, $school);
        $this->assertSame(InspectionStatus::Draft, $i->status);
        $this->assertSame($school->district->code, $i->district_code);

        $this->get(route('inspections.step', [$i, 1]))->assertOk();
        $this->completeAll($i);

        $this->assertTrue((new InspectionValidator($i->fresh()))->progress()['can_submit']);
        $this->post(route('inspections.submit', $i), ['confirm' => 1, 'lat' => 26.1, 'lng' => 91.7])
            ->assertRedirect(route('inspections.submitted', $i));

        $i->refresh();
        $this->assertSame(InspectionStatus::Submitted, $i->status);
        $this->assertMatchesRegularExpression('#^SI/'.now()->year.'/'.$school->district->code.'/\d{6}$#', $i->inspection_no);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_submitted', 'auditable_id' => $i->id]);

        // locked: no more edits or evidence
        $this->answer($i, 'GOVR_02', 'no')->assertForbidden();
        $this->photo($i, 'other')->assertForbidden();
        $this->deleteJson(route('evidence.destroy', InspectionEvidence::first()))->assertForbidden();

        $this->get(route('inspections.show', $i))->assertOk()->assertSee($i->inspection_no);
        $this->get(route('inspections.pdf', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_second_inspection_same_school_same_day_is_blocked(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $first = $this->startInspection($officer, $school);

        // draft is reopened, not duplicated
        $this->post(route('inspections.start', $school))->assertRedirect(route('inspections.step', [$first, 1]));
        $this->assertSame(1, Inspection::count());

        $first->update(['status' => InspectionStatus::Submitted, 'inspection_no' => 'SI/X/1']);
        $this->post(route('inspections.start', $school))->assertRedirect(route('inspections.show', $first));
        $this->assertSame(1, Inspection::count());

        // allowed again next day
        $this->travel(1)->days();
        $this->post(route('inspections.start', $school));
        $this->assertSame(2, Inspection::count());
    }

    private function missingCodes(Inspection $i, int $step)
    {
        return collect((new InspectionValidator($i->fresh()))->missing()[$step] ?? [])->pluck('code');
    }

    public function test_conditional_rules_v2(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));

        // Internet functional only asked when Internet = Yes
        $this->answer($i, 'CND_17', 'no')->assertOk();
        $this->assertNotContains('CND_18', $this->missingCodes($i, 1));
        $this->answer($i, 'CND_17', 'yes')->assertOk();
        $this->assertContains('CND_18', $this->missingCodes($i, 1));

        // ICT functional only when ICT lab = Yes
        $this->answer($i, 'CND_19', 'yes')->assertOk();
        $this->assertContains('CND_20', $this->missingCodes($i, 1));

        // Tinkering lab: agency + functional when Yes
        $this->answer($i, 'CND_24', 'yes')->assertOk();
        $this->assertContains('CND_25', $this->missingCodes($i, 1));
        $this->assertContains('CND_26', $this->missingCodes($i, 1));
        $this->answer($i, 'CND_24', 'no')->assertOk();
        $this->assertNotContains('CND_25', $this->missingCodes($i, 1));

        // Governance: reason required when SMC not formed; monthly sitting hidden
        $this->answer($i, 'GOVR_02', 'no')->assertOk();
        $this->assertContains('GOVR_03', $this->missingCodes($i, 6));
        $this->assertNotContains('GOVR_04', $this->missingCodes($i, 6));
        $this->answer($i, 'GOVR_06', 'no')->assertOk();
        $this->assertContains('GOVR_07', $this->missingCodes($i, 6));
        $this->answer($i, 'GOVR_06', 'yes')->assertOk();
        $this->assertNotContains('GOVR_07', $this->missingCodes($i, 6));

        // invalid option and negative number refused; v1 codes are unknown now
        $this->answer($i, 'GOVR_05', 'sometimes')->assertStatus(422);
        $this->answer($i, 'TSI_01', -2)->assertStatus(422);
        $this->answer($i, 'GOV_02', 'yes')->assertStatus(422);
    }

    public function test_absentee_classroom_and_fund_sections(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));

        // Absentee rows validate type and reason
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'R. Kumar', 'teacher_type' => 'boss', 'reason' => 'CL'])->assertStatus(422);
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'R. Kumar', 'teacher_type' => 'regular', 'reason' => 'CL'])
            ->assertOk()->assertJsonCount(1, 'teachers')->assertJsonPath('teachers.0.reason', 'Casual Leave (CL)');
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'S. Ali', 'teacher_type' => 'tutor', 'reason' => 'UL'])->assertJsonCount(2, 'teachers');

        // Warning (non-blocking) when absentees fewer than implied absence
        foreach (['TSI_04' => 4, 'TSI_05' => 1, 'TSI_06' => 0, 'TSI_07' => 0, 'TSI_08' => 0, 'TSI_09' => 1] as $c => $v) {
            $this->answer($i, $c, $v);
        }
        $warn = (new InspectionValidator($i->fresh()))->warnings();
        $this->assertStringContainsString('4 teacher(s) appear absent; 2 entered', implode(' ', $warn[3] ?? []));
        $this->answer($i, 'TSI_09', 9);
        $this->assertStringContainsString('more than total', implode(' ', (new InspectionValidator($i->fresh()))->warnings()[2] ?? []));

        // At least one classroom record is required
        $this->assertContains('CLASSROOM', $this->missingCodes($i, 4));
        $this->classroom($i, ['class' => 'XIII'])->assertStatus(422);
        $this->classroom($i)->assertOk()->assertJsonCount(1, 'classrooms');
        $this->assertNotContains('CLASSROOM', $this->missingCodes($i, 4));

        // Fund: one row per year, saving the same year updates it
        $fy = \App\Models\InspectionFundPosition::financialYears()[0];
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => '2019-20'])->assertStatus(422);
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'asg_received' => 1.5, 'asg_expenditure' => 1.2, 'govt_received' => 2])
            ->assertOk()->assertJsonCount(1, 'funds')->assertJsonPath('funds.0.balance', '2.30');
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'asg_received' => 3])->assertOk()->assertJsonCount(1, 'funds');
        $this->assertSame(3.0, (float) $i->fundPositions()->first()->asg_received);

        // Friendly validation messages for the forms
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy])
            ->assertStatus(422)->assertJsonPath('errors.asg_received.0', 'Enter at least one amount for '.str_replace('FY-', 'FY ', $fy).'.');
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'mdm_received' => '1.234'])
            ->assertStatus(422)->assertJsonPath('errors.mdm_received.0', 'Use at most 2 decimal places (lakh).');
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'govt_received' => -1])
            ->assertStatus(422)->assertJsonPath('errors.govt_received.0', 'The amount cannot be negative.');
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => '', 'teacher_type' => '', 'reason' => 'CL'])
            ->assertStatus(422)
            ->assertJsonPath('errors.teacher_name.0', "Please enter the teacher's name.")
            ->assertJsonPath('errors.teacher_type.0', "Please select the teacher's type.");
        $this->classroom($i, ['tlm_used' => null])->assertStatus(422)->assertJsonPath('errors.tlm_used.0', 'Please choose Yes or No for use of TLM.');

        // Review page: status, missing items with direct links, answers per step
        $this->get(route('inspections.review', $i))->assertOk()
            ->assertSee('still to complete')->assertSee('Your answers')->assertSee('B. Devi')->assertSee('Fix');
    }

    public function test_submission_blocked_without_mandatory_photos(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->post(route('inspections.submit', $i), ['confirm' => 1])->assertRedirect(route('inspections.review', $i));
        $this->assertSame(InspectionStatus::Draft, $i->fresh()->status);
        $codes = $this->missingCodes($i, 7);
        foreach (['school', 'toilet_block', 'ict_smart', 'classroom_transaction'] as $cat) {
            $this->assertContains('PHOTO_'.$cat, $codes);
        }
    }

    public function test_gallery_photo_is_marked_and_counts_for_mandatory_slot(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->photo($i, 'toilet_block', null, ['source' => 'gallery'])->assertCreated()->assertJsonPath('source', 'gallery');
        $this->photo($i, 'school', null, ['source' => 'phone'])->assertStatus(422);
        $this->assertNotContains('PHOTO_toilet_block', $this->missingCodes($i, 7));
        $this->assertSame('gallery', InspectionEvidence::first()->source);
        $this->get(route('inspections.show', $i))->assertOk();
    }

    public function test_expired_session_gets_401_json(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        auth()->logout();
        $this->postJson(route('inspections.answers', $i), ['code' => 'CND_02', 'value' => 'yes'])->assertUnauthorized();
    }

    public function test_evidence_checks(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));

        // PNG is not accepted as an in-app photo, only JPEG
        $png = UploadedFile::fake()->image('x.png');
        $this->photo($i, 'toilet_block', null, ['file' => $png, 'sha256' => null])->assertStatus(422);

        // oversize document
        $big = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');
        $this->post(route('evidence.store', $i), ['file' => $big, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'document'], ['Accept' => 'application/json'])->assertStatus(422);

        // no location without reason
        $this->photo($i, 'toilet_block', null, ['latitude' => null, 'longitude' => null])->assertStatus(422)->assertJsonValidationErrors('no_location_reason');
        $this->photo($i, 'toilet_block', null, ['latitude' => null, 'longitude' => null, 'no_location_reason' => 'GPS off in building'])->assertCreated();

        // idempotent re-send
        $uid = (string) Str::uuid();
        $this->photo($i, 'classroom_transaction', null, ['evidence_uid' => $uid])->assertCreated();
        $this->photo($i, 'classroom_transaction', null, ['evidence_uid' => $uid])->assertOk();
        $this->assertSame(1, InspectionEvidence::where('evidence_uid', $uid)->count());

        // fingerprint mismatch
        $this->photo($i, 'classroom_transaction', null, ['sha256' => str_repeat('a', 64)])->assertStatus(422);

        // stored privately under a random name
        $e = InspectionEvidence::where('evidence_uid', $uid)->first();
        Storage::disk('private')->assertExists($e->storage_path);
        $this->assertStringNotContainsString('p.jpg', $e->storage_path);
    }

    public function test_jurisdiction_isolation(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $foreign = $this->schoolIn($this->otherCluster($cluster));

        $this->actingAs($officer)->get(route('schools.show', $foreign))->assertNotFound();
        $this->actingAs($officer)->post(route('inspections.start', $foreign))->assertForbidden();
        $this->actingAs($officer)->get(route('schools.index', ['q' => $foreign->udise_code]))->assertOk()->assertDontSee($foreign->name);

        // other officer's inspection outside jurisdiction
        $other = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));
        $theirs = $this->startInspection($other, $foreign);
        $theirs->update(['status' => InspectionStatus::Submitted]);
        $this->actingAs($officer)->get(route('inspections.show', $theirs))->assertForbidden();

        // block officer of that block can view it
        $block = $this->officer(UserLevel::Block, $this->otherCluster($cluster));
        $this->actingAs($block)->get(route('inspections.show', $theirs))->assertOk();
        $this->actingAs($block)->get(route('inspections.area'))->assertOk()->assertSee($foreign->name);
    }

    public function test_only_state_admin_can_unlock_and_resubmission_keeps_number(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->completeAll($i);
        $this->post(route('inspections.submit', $i), ['confirm' => 1]);
        $no = $i->fresh()->inspection_no;

        $district = $this->officer(UserLevel::District);
        $this->actingAs($district)->post(route('inspections.unlock', $i), ['reason' => 'Correction needed'])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('inspections.unlock', $i), ['reason' => 'Wrong attendance figure'])->assertRedirect();
        $this->assertSame(InspectionStatus::Unlocked, $i->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_unlocked', 'auditable_id' => $i->id]);

        $this->actingAs($officer)->post(route('inspections.answers', $i), ['code' => 'TSI_01', 'value' => 121])->assertOk();
        $this->actingAs($officer)->post(route('inspections.submit', $i), ['confirm' => 1])->assertRedirect();
        $this->assertSame(InspectionStatus::Submitted, $i->fresh()->status);
        $this->assertSame($no, $i->fresh()->inspection_no);
    }

    public function test_draft_expiry_command(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->travel(8)->days();
        $this->artisan('inspections:expire-drafts')->assertSuccessful();
        $this->assertSame(InspectionStatus::Cancelled, $i->fresh()->status);
    }

    public function test_excel_export_respects_jurisdiction(): void
    {
        $officer = $this->officer(UserLevel::Block);
        $this->actingAs($officer)->get(route('inspections.export', 'area'))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
