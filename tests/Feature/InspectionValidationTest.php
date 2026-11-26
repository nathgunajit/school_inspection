<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Questionnaire\InspectionValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 26-11-2026: inspection form entry validation (Product Details/INSPECTION_VALIDATION.md).
 * A wrong value is refused with a message that says how to fix it; nothing is changed or cut quietly.
 */
class InspectionValidationTest extends TestCase
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

    private function answer(Inspection $i, string $code, mixed $value, array $extra = [])
    {
        return $this->postJson(route('inspections.answers', $i), ['code' => $code, 'value' => $value] + $extra);
    }

    private function stored(Inspection $i, string $code): mixed
    {
        return InspectionAnswer::where('inspection_id', $i->id)->where('question_code', $code)->first()?->value;
    }

    public function test_answers_are_refused_with_the_fix_not_changed(): void
    {
        $i = $this->draft();

        // hidden (old) codes and unknown codes
        $this->answer($i, 'TSI_14', 4)->assertStatus(422)->assertJsonValidationErrors('value');
        $this->answer($i, 'NOPE_01', 'yes')->assertStatus(422)->assertJsonValidationErrors('value');
        $this->assertNull($this->stored($i, 'TSI_14'));

        // a wrong shape is a 422, not a server error
        $this->answer($i, 'CND_02', ['yes'])->assertStatus(422)->assertJsonPath('errors.value.0', 'This answer could not be read. Choose or type it again.');
        $this->postJson(route('inspections.answers', $i), ['code' => ['CND_02'], 'value' => 'yes'])->assertStatus(422);
        $this->answer($i, 'CND_02', 'yes', ['remarks' => ['x']])->assertStatus(422)->assertJsonValidationErrors('remarks');

        // numbers: whole digits only, nothing truncated
        foreach (['1.9', '-1', 'abc', '1e3', '12 5'] as $bad) {
            $this->answer($i, 'TSI_04', $bad)->assertStatus(422)
                ->assertJsonPath('errors.value.0', 'Enter a whole number (0, 1, 2 …) without decimals or a minus sign.');
        }
        $this->answer($i, 'TSI_04', '100001')->assertStatus(422)->assertJsonValidationErrors('value');
        $this->assertNull($this->stored($i, 'TSI_04'));
        $this->answer($i, 'TSI_04', ' 7 ')->assertOk();
        $this->assertSame(7, $this->stored($i, 'TSI_04'));

        // options
        $this->answer($i, 'CND_02', 'maybe')->assertStatus(422)->assertJsonPath('errors.value.0', 'Choose one of the options shown.');

        // text: too long is refused (not cut), one character is too short
        $this->answer($i, 'GOVR_02', 'no')->assertOk();
        $this->answer($i, 'GOVR_03', str_repeat('a', 2001))->assertStatus(422)
            ->assertJsonPath('errors.value.0', 'Too long: at most 2000 characters (you typed 2001). Shorten the answer.');
        $this->answer($i, 'GOVR_03', '.')->assertStatus(422)->assertJsonPath('errors.value.0', 'Please write at least 2 characters.');
        $this->answer($i, 'GOVR_03', 'No meeting held this year')->assertOk();
        $this->answer($i, 'NET_01', 'yes', ['remarks' => str_repeat('r', 2001)])->assertStatus(422)->assertJsonValidationErrors('remarks');
    }

    public function test_a_hidden_follow_up_answer_is_cleared_when_its_parent_changes(): void
    {
        $i = $this->draft();
        $this->answer($i, 'GOVR_02', 'no')->assertOk();
        $this->answer($i, 'GOVR_03', 'No meeting held this year')->assertOk();
        $this->assertSame('No meeting held this year', $this->stored($i, 'GOVR_03'));

        $this->answer($i, 'GOVR_02', 'yes')->assertOk(); // GOVR_03 is shown only for "no"
        $this->assertNull($this->stored($i, 'GOVR_03'));
        $this->answer($i, 'GOVR_03', 'Should not be kept')->assertOk();
        $this->assertNull($this->stored($i, 'GOVR_03'), 'a hidden question cannot hold an answer');
    }

    public function test_absent_teacher_type_follows_the_step_2_counts(): void
    {
        $i = $this->draft();
        foreach (['TSI_04' => 3, 'TSI_05' => 1, 'TSI_09' => 2] as $c => $v) { // 4 teachers, 2 present: 2 absent
            $this->answer($i, $c, $v)->assertOk();
        }
        $row = fn (string $name, string $type) => $this->postJson(route('inspections.teachers.store', $i),
            ['teacher_name' => $name, 'teacher_type' => $type, 'reason' => 'CL', 'row_uid' => (string) Str::uuid()]);

        $row('Tutor One', 'tutor')->assertStatus(422)
            ->assertJsonPath('errors.teacher_type.0', 'Step 2 has no Tutor (0). Choose another type for this teacher, or enter the number of Tutors on step 2.');
        $row('Rina Das', 'contractual')->assertOk();
        $row('Mina Das', 'contractual')->assertStatus(422)
            ->assertJsonPath('errors.teacher_type.0', 'Step 2 has 1 Contractual Teacher(s), but 2 are marked absent. Change the type of the extra row(s), or correct the number on step 2.');
        $row('Mina Das', 'regular')->assertOk();

        // editing a row does not count it against itself
        $t = $i->absentTeachers()->where('teacher_name', 'Rina Das')->first();
        $this->putJson(route('inspections.teachers.update', [$i, $t]), ['teacher_name' => 'Rina Das', 'teacher_type' => 'contractual', 'reason' => 'EL'])->assertOk();

        // names: letters only
        $this->putJson(route('inspections.teachers.update', [$i, $t]), ['teacher_name' => '12345', 'teacher_type' => 'contractual', 'reason' => 'EL'])
            ->assertStatus(422)->assertJsonValidationErrors('teacher_name');

        // step 2 corrected afterwards: the submit checklist names the problem
        $this->answer($i, 'TSI_05', 0)->assertOk();
        $this->answer($i, 'TSI_04', 4)->assertOk();
        $missing = collect((new InspectionValidator($i->fresh()))->missing()[3] ?? [])->pluck('message')->implode(' | ');
        $this->assertStringContainsString('Step 2 has no Contractual Teacher (0)', $missing);
    }

    public function test_an_absent_teacher_cannot_be_the_observed_teacher(): void
    {
        $i = $this->draft();
        foreach (['TSI_04' => 3, 'TSI_09' => 1] as $c => $v) { // 2 absent
            $this->answer($i, $c, $v)->assertOk();
        }
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'Rina  Das', 'teacher_type' => 'regular', 'reason' => 'CL'])->assertOk();

        $class = fn (string $name) => $this->postJson(route('inspections.classrooms.store', $i), [
            'teacher_name' => $name, 'class' => 'V', 'subject' => 'Mathematics', 'tlm_used' => 'no', 'participation' => 'satisfactory']);
        $class('rina das')->assertStatus(422)
            ->assertJsonPath('errors.teacher_name.0', 'rina das is marked absent today on step 3. Choose a teacher who is present, or remove them from the absent list.');
        $class('B. Devi')->assertOk();
        $this->postJson(route('inspections.classrooms.store', $i), ['teacher_name' => 'B. Devi', 'class' => 'V', 'subject' => 'M', 'tlm_used' => 'no', 'participation' => 'satisfactory'])
            ->assertStatus(422)->assertJsonValidationErrors('subject');

        // the name was added to the absent list later: the submit checklist names it
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'B. Devi', 'teacher_type' => 'regular', 'reason' => 'CL'])->assertOk();
        $missing = collect((new InspectionValidator($i->fresh()))->missing()[4] ?? [])->pluck('message')->implode(' | ');
        $this->assertStringContainsString('B. Devi is marked absent today on step 3', $missing);

        // the classroom name suggestions leave out absent teachers
        $this->getJson(route('inspections.teacher-names', [$i, 'present' => 1]))->assertOk()->assertJsonMissing(['items' => ['Rina Das']]);
    }

    public function test_suggestions_name_the_box_and_the_fix(): void
    {
        $i = $this->draft();
        $url = route('inspections.suggestions', $i);
        $r = $this->postJson($url, ['suggestions' => ['Good', str_repeat('s', 501)]])->assertStatus(422);
        $this->assertSame('Suggestion 2 is too long: at most 500 characters. Shorten it.', $r->json('errors')['suggestions.1'][0]);
        $r = $this->postJson($url, ['suggestions' => ['x']])->assertStatus(422);
        $this->assertSame('Suggestion 1 is too short: write at least 2 characters, or clear the box.', $r->json('errors')['suggestions.0'][0]);
        $this->postJson($url, ['suggestions' => ['Repair the toilet', '', null]])->assertOk();
    }

    public function test_photo_and_document_checks(): void
    {
        $i = $this->draft();
        $photo = function (array $over = []) use ($i) {
            $file = UploadedFile::fake()->image('p.jpg', 400, 300);

            return $this->post(route('evidence.store', $i), array_merge([
                'file' => $file, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'photo', 'category' => 'school',
                'latitude' => 26.11, 'longitude' => 91.71, 'accuracy' => 9,
            ], $over), ['Accept' => 'application/json']);
        };
        $photo(['category' => null])->assertStatus(422)->assertJsonPath('errors.category.0', 'Choose what the photo shows (the photo type), then save it again.');
        $photo(['taken_at' => now()->addDay()->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors('taken_at');
        $photo(['latitude' => null, 'longitude' => null])->assertStatus(422)->assertJsonValidationErrors('no_location_reason');
        $photo(['question_code' => 'NOPE_01'])->assertStatus(422)->assertJsonValidationErrors('question_code');
        $photo()->assertCreated();

        config(['inspection.evidence.max_documents' => 1]);
        $doc = fn () => $this->post(route('evidence.store', $i), [
            'file' => UploadedFile::fake()->image('order.jpg', 300, 200), 'evidence_uid' => (string) Str::uuid(), 'kind' => 'document',
        ], ['Accept' => 'application/json']);
        $doc()->assertCreated();
        $doc()->assertStatus(422)->assertJsonPath('errors.file.0', 'You already added 1 documents, the most allowed. Remove one to add another.');
    }
}
