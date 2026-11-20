<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionAbsentTeacher;
use App\Models\InspectionEvidence;
use App\Models\InspectionFundPosition;
use App\Models\InspectionRating;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;
use App\Services\RatingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Modification V9: person met hidden, optional teacher counts default to 0, absent rows limited by step 2,
 * fund details optional, ratings hidden (RATING_DISPLAY), rating PDF sections removed.
 */
class ModificationV9Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function draft(): Inspection
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->actingAs($officer);

        return $i;
    }

    private function answer(Inspection $i, string $code, mixed $value)
    {
        return $this->postJson(route('inspections.answers', $i), ['code' => $code, 'value' => $value])->assertOk();
    }

    private function teacher(Inspection $i, array $over = [])
    {
        return $this->postJson(route('inspections.teachers.store', $i), $over + [
            'teacher_name' => 'R. Das', 'teacher_type' => array_key_first(InspectionAbsentTeacher::TYPES),
            'reason' => array_key_first(InspectionAbsentTeacher::REASONS),
        ]);
    }

    /** Everything required, with TSI_05..TSI_09 left blank and no fund year. */
    private function completeWithoutOptional(Inspection $i): void
    {
        $answers = [];
        foreach (Q::all() as $code => $q) {
            if (! InspectionValidator::visible($q, $answers) || ! InspectionValidator::enabled($q, $answers) || array_key_exists('default', $q)) {
                continue;
            }
            $v = match ($q['type']) {
                'radio', 'select' => array_key_first($q['options']),
                'multi' => [array_key_first($q['options'])],
                'number' => in_array($code, ['TSI_10', 'TSI_11', 'TSI_12', 'TSI_13'], true) ? 0 : 3,
                default => 'Observed',
            };
            $answers[$code] = $v;
            $this->answer($i, $code, $v);
        }
        $this->postJson(route('inspections.classrooms.store', $i), ['teacher_name' => 'B. Devi', 'class' => 'V', 'subject' => 'Mathematics',
            'tlm_used' => 'yes', 'participation' => 'satisfactory'])->assertOk();
        foreach (array_keys(config('inspection.mandatory_photos')) as $cat) {
            $file = UploadedFile::fake()->image('photo.jpg', 400, 300);
            $this->post(route('evidence.store', $i), ['file' => $file, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'photo', 'category' => $cat,
                'latitude' => 26.11, 'longitude' => 91.71, 'accuracy' => 9, 'sha256' => hash_file('sha256', $file->getRealPath())], ['Accept' => 'application/json'])->assertCreated();
        }
    }

    public function test_person_met_is_not_asked_and_not_required(): void
    {
        $i = $this->draft();
        $this->get(route('inspections.step', [$i, 1]))->assertOk()
            ->assertDontSee('name="BAS_PERSON_NAME"', false)->assertDontSee('Designation of the person met');
        $missing = collect((new InspectionValidator($i->fresh()))->missing()[1] ?? [])->pluck('code');
        $this->assertNotContains('BAS_PERSON_NAME', $missing);
        $this->assertNotContains('BAS_PERSON_DESIG', $missing);
        $this->assertNotNull(Q::find('BAS_PERSON_NAME')); // code kept for older answers
    }

    public function test_optional_teacher_counts_show_zero_count_as_zero_and_are_stored_at_submit(): void
    {
        $i = $this->draft();
        $html = $this->get(route('inspections.step', [$i, 2]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="TSI_06" value="0"/', $html);
        $this->assertStringContainsString('Enter 0 if none', $html);
        $this->assertDoesNotMatchRegularExpression('/data-auto-total="teachers".*?data-at="present"/s', explode('data-auto-total="breakdown"', $html)[0]);

        $this->assertSame(4, Q::totalTeachers(['TSI_04' => 4]));
        $this->assertNull(Q::totalTeachers([]));

        $this->completeWithoutOptional($i);
        $p = (new InspectionValidator($i->fresh()))->progress();
        $this->assertTrue($p['can_submit'], json_encode($p['missing']));
        $this->assertTrue($p['steps'][5]['complete']); // no fund year: fine

        $this->post(route('inspections.submit', $i), ['confirm' => 1])->assertRedirect(route('inspections.submitted', $i));
        $answers = $i->fresh()->answerMap();
        foreach (['TSI_05', 'TSI_06', 'TSI_07', 'TSI_08', 'TSI_09'] as $c) {
            $this->assertSame(0, $answers[$c], $c);
        }
        $this->assertSame(InspectionStatus::Submitted, $i->fresh()->status);
    }

    public function test_absent_rows_cannot_exceed_total_minus_present(): void
    {
        $i = $this->draft();
        $this->answer($i, 'TSI_04', 3);
        $this->answer($i, 'TSI_09', 1); // 2 may be absent

        $this->teacher($i)->assertOk();
        $uid = (string) Str::uuid();
        $this->teacher($i, ['row_uid' => $uid])->assertOk();
        $this->teacher($i, ['row_uid' => $uid])->assertOk(); // replay of a saved row is allowed
        $this->teacher($i)->assertStatus(422)->assertJsonValidationErrors('teacher_name');
        $this->assertSame(2, $i->absentTeachers()->count());

        // step 2 corrected afterwards: the extra row now blocks submission
        $this->answer($i, 'TSI_09', 2);
        $codes = collect((new InspectionValidator($i->fresh()))->missing()[3] ?? [])->pluck('code');
        $this->assertContains('ABSENTEE', $codes);
        $this->assertSame(1, InspectionValidator::absentLimit($i->fresh()->answerMap()));
    }

    public function test_ratings_are_hidden_by_default_and_never_in_the_pdf(): void
    {
        $this->assertFalse(config('inspection.rating.display'));
        $i = $this->draft();
        $this->completeWithoutOptional($i);
        $this->post(route('inspections.submit', $i), ['confirm' => 1])->assertRedirect();
        $this->assertTrue(InspectionRating::where('inspection_id', $i->id)->exists(), 'still calculated in the background');

        $this->get(route('inspections.show', $i))->assertOk()->assertDontSee('School performance rating');
        $this->get(route('inspections.mine'))->assertOk()->assertDontSee('rating-pill', false);
        $this->get(route('schools.show', $i->school))->assertOk()->assertDontSee('school-rating-box', false);
        $this->get(route('inspections.pdf', $i))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('map.index'))->assertOk()->assertDontSee('colourRating', false);
        $this->actingAs($admin)->get(route('map.schools', ['cluster' => $i->cluster_id]))->assertOk()->assertJsonMissingPath('rows.0.rating');
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('Rating Report');
        $this->actingAs($admin)->get(route('reports.ratings'))->assertNotFound();

        // shown again with the switch on, except in the inspection PDF (section removed in V9)
        config(['inspection.rating.display' => true]);
        $this->actingAs($admin)->get(route('inspections.show', $i))->assertOk()->assertSee('School performance rating');
        $this->actingAs($admin)->get(route('reports.ratings'))->assertOk();
        $this->actingAs($admin)->get(route('map.index'))->assertOk()->assertSee('colourRating', false);
        $this->assertStringNotContainsString('School Performance Summary', file_get_contents(resource_path('views/inspections/pdf.blade.php')));
    }

    public function test_fund_amount_formatter_rule_on_the_server_accepts_short_decimals(): void
    {
        $i = $this->draft();
        $fy = InspectionFundPosition::financialYears()[0];
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'asg_received' => '.2'])->assertOk();
        $this->assertSame('0.20', (string) $i->fundPositions()->first()->asg_received);
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy, 'asg_received' => '1.234'])->assertStatus(422);
    }
}
