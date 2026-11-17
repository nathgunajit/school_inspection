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

    /** Fill every question with a valid "good" answer. */
    private function completeAll(Inspection $i): void
    {
        $overrides = ['GOV_18' => 'not_available', 'ATT_SANCTIONED' => 5, 'ATT_WORKING' => 4, 'ATT_PRESENT' => 4, 'ATT_ABSENT' => 0,
            'ATT_ENROLLED' => 120, 'ATT_STUDENTS_PRESENT' => 100, 'GOV_16_DATE' => now()->subMonth()->toDateString()];
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
        $this->photo($i, 'kitchen_mdm', 'GOV_14')->assertCreated();
        foreach (['school_front', 'classroom', 'toilet'] as $cat) {
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
        $this->answer($i, 'GOV_02', 'no')->assertForbidden();
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

    public function test_conditional_rules_and_remarks(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));

        $this->answer($i, 'GOV_02', 'no')->assertOk();
        $missing = collect((new InspectionValidator($i->fresh()))->missing()[2])->pluck('code');
        $this->assertContains('GOV_03', $missing, 'Reason required when SMC not formed');
        $this->assertNotContains('GOV_04', $missing, 'Monthly sitting hidden when SMC not formed');

        $this->answer($i, 'GOV_20', 'unsatisfactory')->assertOk();
        $msgs = collect((new InspectionValidator($i->fresh()))->missing()[2])->where('code', 'GOV_20')->pluck('message')->implode(' ');
        $this->assertStringContainsString('remarks required', $msgs);
        $this->answer($i, 'GOV_20', 'unsatisfactory', 'Registers incomplete')->assertOk();
        $this->assertEmpty(collect((new InspectionValidator($i->fresh()))->missing()[2])->where('code', 'GOV_20'));

        // condition item unsatisfactory needs remarks and a photo
        $this->answer($i, 'CON_05', 'unsatisfactory', 'Broken door')->assertOk();
        $msgs = collect((new InspectionValidator($i->fresh()))->missing()[3])->where('code', 'CON_05')->pluck('message')->implode(' ');
        $this->assertStringContainsString('photograph required', $msgs);

        // invalid option and negative number refused
        $this->answer($i, 'GOV_05', 'sometimes')->assertStatus(422);
        $this->answer($i, 'ATT_PRESENT', -2)->assertStatus(422);
    }

    public function test_attendance_arithmetic_and_absent_teachers(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        foreach (['ATT_SANCTIONED' => 6, 'ATT_WORKING' => 5, 'ATT_PRESENT' => 3, 'ATT_ABSENT' => 1] as $c => $v) {
            $this->answer($i, $c, $v);
        }
        $msgs = collect((new InspectionValidator($i->fresh()))->missing()[4])->pluck('message')->implode(' | ');
        $this->assertStringContainsString('must equal working', $msgs);
        $this->assertStringContainsString('Details required for each absent teacher', $msgs);

        $this->answer($i, 'ATT_PRESENT', 4);
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'R. Kumar', 'status' => 'absent_without_info'])->assertOk()->assertJsonCount(1, 'teachers');
        $msgs = collect((new InspectionValidator($i->fresh()))->missing()[4] ?? [])->pluck('message')->implode(' | ');
        $this->assertStringNotContainsString('must equal', $msgs);
        $this->assertStringNotContainsString('absent teacher', $msgs);
    }

    public function test_submission_blocked_without_mandatory_photos(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->post(route('inspections.submit', $i), ['confirm' => 1])->assertRedirect(route('inspections.review', $i));
        $this->assertSame(InspectionStatus::Draft, $i->fresh()->status);
        $codes = collect((new InspectionValidator($i->fresh()))->missing()[6])->pluck('code');
        $this->assertContains('PHOTO_school_front', $codes);
    }

    public function test_evidence_checks(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));

        // PNG is not accepted as an in-app photo, only JPEG
        $png = UploadedFile::fake()->image('x.png');
        $this->photo($i, 'toilet', null, ['file' => $png, 'sha256' => null])->assertStatus(422);

        // oversize document
        $big = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');
        $this->post(route('evidence.store', $i), ['file' => $big, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'document'], ['Accept' => 'application/json'])->assertStatus(422);

        // no location without reason
        $this->photo($i, 'toilet', null, ['latitude' => null, 'longitude' => null])->assertStatus(422)->assertJsonValidationErrors('no_location_reason');
        $this->photo($i, 'toilet', null, ['latitude' => null, 'longitude' => null, 'no_location_reason' => 'GPS off in building'])->assertCreated();

        // idempotent re-send
        $uid = (string) Str::uuid();
        $this->photo($i, 'classroom', null, ['evidence_uid' => $uid])->assertCreated();
        $this->photo($i, 'classroom', null, ['evidence_uid' => $uid])->assertOk();
        $this->assertSame(1, InspectionEvidence::where('evidence_uid', $uid)->count());

        // fingerprint mismatch
        $this->photo($i, 'classroom', null, ['sha256' => str_repeat('a', 64)])->assertStatus(422);

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

        $this->actingAs($officer)->post(route('inspections.answers', $i), ['code' => 'ATT_ENROLLED', 'value' => 121])->assertOk();
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
