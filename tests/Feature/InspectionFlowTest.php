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

        // redesigned report page (Modification V4): hero, details, section links, answer pills, gallery with downloads, summary
        $this->get(route('inspections.show', $i))->assertOk()->assertSee($i->inspection_no)
            ->assertSee('rp-hero', false)->assertSee('Inspection details')->assertSee('href="#rp-step-7"', false)
            ->assertSee('ans-pill', false)->assertSee('rv-dl', false)->assertSee('At a glance')->assertSee('Download official PDF report')
            ->assertDontSee('Name of the person met at the school');
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

    public function test_own_older_draft_is_reopened_instead_of_a_new_inspection(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $first = $this->startInspection($officer, $school);

        $this->travel(2)->days();
        $this->post(route('inspections.start', $school))
            ->assertRedirect(route('inspections.step', [$first, 1]))
            ->assertSessionHas('info', fn ($m) => str_contains($m, 'from '.$first->inspection_date->format('d M Y')));
        $this->assertSame(1, Inspection::count());
        $this->get(route('schools.show', $school))->assertOk()->assertSee('You have a draft from');
    }

    public function test_another_officers_draft_blocks_the_school(): void
    {
        $a = $this->officer(UserLevel::Cluster);
        $cluster = $a->currentJurisdiction->cluster;
        $b = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);
        $draft = $this->startInspection($a, $school);

        $this->actingAs($b)->post(route('inspections.start', $school))
            ->assertRedirect(route('schools.show', $school))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'being inspected by '.$a->name));
        $this->assertSame(1, Inspection::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_start_blocked']);
        $this->get(route('schools.show', $school))->assertOk()->assertSee('Inspection in progress by another officer')->assertSee($a->name)
            ->assertDontSee('id="start-btn"', false);
        $this->get(route('schools.index', ['q' => $school->udise_code]), ['X-Requested-With' => 'XMLHttpRequest'])->assertSee('Draft by another officer');

        // a cancelled draft does not block
        $draft->update(['status' => InspectionStatus::Cancelled]);
        $this->actingAs($b)->post(route('inspections.start', $school));
        $this->assertSame(2, Inspection::count());
    }

    public function test_school_completed_by_another_officer_is_blocked_for_the_rest_of_the_month(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(9)->setTime(10, 0));
        $a = $this->officer(UserLevel::Cluster);
        $cluster = $a->currentJurisdiction->cluster;
        $b = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);
        $done = $this->startInspection($a, $school);
        $done->update(['status' => InspectionStatus::Submitted, 'inspection_no' => 'SI/2026/0001/000001', 'submitted_at' => now()]);

        $this->travel(5)->days();
        $this->actingAs($b)->post(route('inspections.start', $school))
            ->assertRedirect(route('schools.show', $school))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'already inspected by '.$a->name) && str_contains($m, 'SI/2026/0001/000001'));
        $this->get(route('schools.show', $school))->assertOk()->assertSee('Already inspected this month')
            ->assertSee(now()->addMonthNoOverflow()->startOfMonth()->format('d M Y'));
        $this->get(route('schools.index', ['q' => $school->udise_code]), ['X-Requested-With' => 'XMLHttpRequest'])->assertSee('Inspected this month');
        $this->assertSame(1, Inspection::count());

        // next month the school is open again
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->setTime(9, 0));
        $this->actingAs($b)->post(route('inspections.start', $school));
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

        // ICT functional is greyed out (shown, disabled) when ICT lab = No: answer cleared, refused, not required
        $this->answer($i, 'CND_20', 'yes')->assertOk();
        $this->answer($i, 'CND_19', 'no')->assertOk();
        $this->assertNull($i->answers()->where('question_code', 'CND_20')->first()->value);
        $this->answer($i, 'CND_20', 'yes')->assertOk();
        $this->assertNull($i->answers()->where('question_code', 'CND_20')->first()->value);
        $this->assertNotContains('CND_20', $this->missingCodes($i, 1));
        $this->get(route('inspections.step', [$i, 1]))->assertOk()
            ->assertSee('is-disabled', false)->assertSee('aria-disabled="true"', false);

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

        // Rows can be edited (update), with the same validation; rows of another inspection are refused
        $t = $i->absentTeachers()->first();
        $this->putJson(route('inspections.teachers.update', [$i, $t]), ['teacher_name' => 'R. Kumar Das', 'teacher_type' => 'contractual', 'reason' => 'EL', 'remarks' => 'Approved'])
            ->assertOk()->assertJsonCount(2, 'teachers')->assertJsonPath('teachers.0.values.teacher_type', 'contractual');
        $this->assertSame('R. Kumar Das', $t->fresh()->teacher_name);
        $this->putJson(route('inspections.teachers.update', [$i, $t]), ['teacher_name' => '', 'teacher_type' => 'regular', 'reason' => 'CL'])->assertStatus(422);
        $c = $i->classroomObservations()->first();
        $this->putJson(route('inspections.classrooms.update', [$i, $c]), ['teacher_name' => 'B. Devi', 'class' => 'VII', 'subject' => 'Science', 'tlm_used' => 'no', 'participation' => 'not_satisfactory'])
            ->assertOk()->assertJsonCount(1, 'classrooms')->assertJsonPath('classrooms.0.values.class', 'VII')->assertJsonPath('classrooms.0.tlm_ok', false);
        // another officer's inspection of a school in another cluster (the same school would now be blocked)
        $oc = $this->otherCluster($officer->currentJurisdiction->cluster);
        $other = $this->startInspection($this->officer(UserLevel::Cluster, $oc), $this->schoolIn($oc));
        $this->putJson(route('inspections.classrooms.update', [$other, $c]), ['teacher_name' => 'X Y', 'class' => 'I', 'subject' => 'Maths', 'tlm_used' => 'yes', 'participation' => 'satisfactory'])->assertNotFound();
        $this->assertSame('Science', $c->fresh()->subject);
        $this->actingAs($officer)->get(route('inspections.step', [$i, 5]))->assertOk()->assertSee('Samagra Shiksha (SSA)')->assertSee('fund-src src-govt', false);

        // Review page: status, missing items with direct links, answers per step
        $this->get(route('inspections.review', $i))->assertOk()
            ->assertSee('still to complete')->assertSee('Your answers')->assertSee('B. Devi')->assertSee('Fix');
    }

    public function test_review_page_shows_answer_pills_totals_funds_and_photo_gallery(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->completeAll($i);
        foreach (['TSI_01' => 120, 'TSI_02' => 15, 'TSI_04' => 6, 'TSI_05' => 2, 'TSI_06' => 1, 'TSI_07' => 1, 'TSI_08' => 0, 'TSI_09' => 8] as $c => $v) {
            $this->answer($i, $c, $v)->assertOk();
        }
        $years = \App\Models\InspectionFundPosition::financialYears();
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $years[0], 'asg_received' => 1234.5, 'asg_expenditure' => 1000])->assertOk();
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $years[1], 'govt_received' => 2, 'govt_expenditure' => 3])->assertOk();

        $this->get(route('inspections.review', $i))->assertOk()
            ->assertSee('ans-pill tone-good', false)                 // Yes answers as green pills
            ->assertSee('Total students')->assertSee('135')          // 120 + 15
            ->assertSee('Total teachers')->assertSee('Teachers absent')
            ->assertSee('1,234.50')                                  // Indian-format lakh
            ->assertSee('All 2 years')->assertSee('−1.00')           // grand total row, negative balance
            ->assertSee('rv-photo', false)->assertSee('Required · done')
            ->assertSee(route('evidence.show', InspectionEvidence::first()), false);
    }

    public function test_step_two_shows_automatic_student_and_teacher_totals(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->get(route('inspections.step', [$i, 2]))->assertOk()
            ->assertSee('data-auto-total="students"', false)->assertSee('data-auto-total="teachers"', false)
            ->assertSee('Present + Absent')
            // step tabs: full names for all 7 steps + review, with a status line
            ->assertSeeInOrder(['School Condition', 'Students & Teachers', 'Absent Teachers', 'Classroom Observation', 'Fund Details', 'School Governance', 'Photographs', 'Review & Submit'])
            ->assertSee('You are here')->assertSee('Not started')->assertSee('steps done');

        $this->assertNull(Q::totalStudents(['TSI_01' => 10]));
        $this->assertSame(0, Q::totalStudents(['TSI_01' => 0, 'TSI_02' => '0']));
        $this->assertSame(57, Q::totalStudents(['TSI_01' => '50', 'TSI_02' => 7]));
    }

    public function test_fund_years_are_kept_separately_and_shown_year_wise(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        [$y1, $y2] = \App\Models\InspectionFundPosition::financialYears();
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $y1, 'asg_received' => 5, 'asg_expenditure' => 2])->assertOk();
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $y2, 'mdm_received' => 1.5])->assertOk()->assertJsonCount(2, 'funds');
        // saving one year never overwrites another
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $y2, 'mdm_received' => 2])->assertOk()->assertJsonCount(2, 'funds');
        $this->assertSame(5.0, (float) $i->fundPositions()->where('financial_year', $y1)->value('asg_received'));

        $this->get(route('inspections.step', [$i, 5]))->assertOk()
            ->assertSee('id="fund-balance-card"', false)->assertSee('Year-wise summary')->assertSee('data-fy-rows', false);
        $this->get(route('inspections.show', $i))->assertOk()->assertSee('All 2 years')->assertSee('₹ 5.00');
    }

    public function test_subject_suggestions_from_master_and_free_text_still_allowed(): void
    {
        $this->seed(\Database\Seeders\MasterValueSeeder::class);
        $officer = $this->officer(UserLevel::Cluster);

        auth()->logout();
        $this->getJson(route('subjects.suggest', ['q' => 'math']))->assertUnauthorized();

        $this->actingAs($officer);
        $r = $this->getJson(route('subjects.suggest', ['q' => 'math']))->assertOk();
        $this->assertSame('Mathematics', $r->json('subjects.0'));        // starts-with matches come first
        $this->assertContains('General Mathematics', $r->json('subjects'));
        $this->assertNotContains('English', $r->json('subjects'));
        $this->assertCount(10, $this->getJson(route('subjects.suggest'))->json('subjects')); // at most 10
        $this->assertSame([], $this->getJson(route('subjects.suggest', ['q' => 'zzzz']))->json('subjects'));

        // a subject that is not in the master can still be saved
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->classroom($i, ['subject' => 'Tea Garden Studies'])->assertOk()->assertJsonPath('classrooms.0.subject', 'Tea Garden Studies');
        $this->get(route('inspections.step', [$i, 4]))->assertOk()->assertSee('data-ac', false)->assertSee(route('subjects.suggest'), false);
    }

    public function test_performance_indexes_exist_and_inspection_date_is_stored_as_plain_date(): void
    {
        foreach ([
            'inspections' => ['insp_school_status_date_idx', 'insp_officer_status_date_idx', 'insp_district_status_date_idx', 'insp_status_started_idx'],
            'schools' => ['schools_cluster_status_name_idx'],
            'audit_logs' => ['audit_created_idx'],
            'user_jurisdictions' => ['uj_district_end_idx'],
            'inspection_answers' => ['inspection_answers_inspection_id_question_code_unique'],
        ] as $table => $names) {
            foreach ($names as $name) {
                $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex($table, $name), "$table.$name missing");
            }
        }
        // removed after the volume review (migration 2026_11_18_000007): no query reads answers by question_code alone
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasIndex('inspection_answers', 'answers_question_code_idx'));

        // stored as Y-m-d so plain comparisons (index-friendly) work on every database
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->assertSame(today()->toDateString(), \Illuminate\Support\Facades\DB::table('inspections')->where('id', $i->id)->value('inspection_date'));
        $this->assertTrue($i->fresh()->inspection_date->isToday());
        $this->assertSame(1, Inspection::where('inspection_date', today()->toDateString())->count());
    }

    public function test_lakh_format_uses_indian_grouping(): void
    {
        $fp = \App\Models\InspectionFundPosition::class;
        $this->assertSame('0.00', $fp::lakh(0));
        $this->assertSame('999.50', $fp::lakh(999.5));
        $this->assertSame('1,234.50', $fp::lakh(1234.5));
        $this->assertSame('12,34,567.00', $fp::lakh(1234567));
        $this->assertSame('−2.25', $fp::lakh(-2.25));
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

    public function test_find_school_live_search_returns_scoped_results(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $mine = $this->schoolIn($cluster);
        $foreign = $this->schoolIn($this->otherCluster($cluster));
        $ajax = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

        // by UDISE prefix: own school found and highlighted, foreign school never returned
        $r = $this->actingAs($officer)->getJson(route('schools.index', ['q' => substr($mine->udise_code, 0, 8)]), $ajax)->assertOk();
        $this->assertGreaterThanOrEqual(1, $r->json('total'));
        $this->assertStringContainsString('<mark>'.substr($mine->udise_code, 0, 8).'</mark>', $r->json('html'));
        $this->assertStringNotContainsString($foreign->name, $r->json('html'));

        // exact foreign UDISE: nothing; no redirect for the live search
        $this->actingAs($officer)->getJson(route('schools.index', ['q' => $foreign->udise_code]), $ajax)
            ->assertOk()->assertJsonPath('total', 0);
        // exact own UDISE via normal search still opens the school
        $this->actingAs($officer)->get(route('schools.index', ['q' => $mine->udise_code]))->assertRedirect(route('schools.show', $mine));
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
