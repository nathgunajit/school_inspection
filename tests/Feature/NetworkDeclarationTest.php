<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Questionnaire\InspectionValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 28-11-2026: no internet at the school (NET_01 = Yes): the officer ticks the declaration (NET_02) and attaches the
 * declaration signed by the Block / District officer as a PDF; both are required before submission.
 */
class NetworkDeclarationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function answer(Inspection $i, string $code, mixed $value)
    {
        return $this->postJson(route('inspections.answers', $i), ['code' => $code, 'value' => $value]);
    }

    private function attach(Inspection $i, UploadedFile $file, ?string $code = 'NET_02')
    {
        return $this->postJson(route('evidence.store', $i), ['file' => $file, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'document']
            + ($code ? ['question_code' => $code] : []));
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    /** Missing items of the last step, where the Declaration section is (28-11-2026). */
    private function step1(Inspection $i): \Illuminate\Support\Collection
    {
        return collect((new InspectionValidator($i->fresh()))->missing()[7] ?? []);
    }

    public function test_no_internet_needs_the_declaration_and_the_signed_pdf(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->assertFalse($this->step1($i)->pluck('code')->contains('NET_02'), 'no network problem: nothing asked');

        $this->answer($i, 'NET_01', 'yes')->assertOk();
        $this->assertTrue($this->step1($i)->contains(fn ($m) => $m['code'] === 'NET_02' && str_contains($m['message'], 'answer required')));

        $this->answer($i, 'NET_02', ['agree'])->assertOk();
        $this->assertTrue($this->step1($i)->contains(fn ($m) => $m['code'] === 'NET_02' && str_contains($m['message'], 'signed by the Block / District officer')));

        // only a PDF is accepted here
        $this->attach($i, UploadedFile::fake()->image('signed.jpg'))->assertStatus(422)->assertJsonValidationErrors('file');
        // a question that takes no document refuses one
        $this->attach($i, $this->pdf(), 'CND_01')->assertStatus(422)->assertJsonValidationErrors('question_code');

        $this->attach($i, $this->pdf())->assertCreated()->assertJsonPath('question_code', 'NET_02');
        $this->assertFalse($this->step1($i)->pluck('code')->contains('NET_02'));

        $this->assertSame(7, $i->answers()->where('question_code', 'NET_02')->value('step'));

        // 28-11-2026: NET_01 is the first question of step 1; the Declaration section is the end of the last step
        $step1 = $this->get(route('inspections.step', [$i, 1]))->assertOk()->assertDontSee('data-doc-question="NET_02"', false)->getContent();
        $this->assertLessThan(strpos($step1, 'id="q-CND_01"'), strpos($step1, 'id="q-NET_01"'));
        $step7 = $this->get(route('inspections.step', [$i, 7]))->assertOk()->assertSee('id="declaration"', false)
            ->assertSee('data-doc-question="NET_02"', false)->assertSee(route('inspections.network-declaration', $i), false)->getContent();
        $this->assertLessThan(strpos($step7, 'id="declaration"'), strpos($step7, 'id="doc-list"'), 'after the photographs and documents');
        $this->get(route('inspections.review', $i))->assertOk()->assertSee('Signed declaration (PDF)')->assertSee('signed.pdf');
        $district = $this->officer(UserLevel::District, $officer->currentJurisdiction->cluster);
        $this->actingAs($district)->get(route('reports.network'))->assertOk()->assertSee('Declared')->assertSee('Signed PDF');
    }

    public function test_network_no_clears_the_declaration_and_it_is_not_prefilled(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $i = $this->startInspection($officer, $school);
        $this->answer($i, 'NET_01', 'yes')->assertOk();
        $this->answer($i, 'NET_02', ['agree'])->assertOk();
        $this->answer($i, 'NET_01', 'no')->assertOk();
        $this->assertArrayNotHasKey('NET_02', array_filter($i->fresh()->answerMap()));
        $this->get(route('inspections.step', [$i, 7]))->assertOk()->assertDontSee('id="declaration"', false);
        $this->assertFalse($this->step1($i)->pluck('code')->contains('NET_02'));

        // next visit: NET_01 / NET_02 are never copied
        $this->answer($i, 'NET_01', 'yes')->assertOk();
        $this->answer($i, 'NET_02', ['agree'])->assertOk();
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now()->subMonth(), 'inspection_date' => now()->subMonth()->toDateString(), 'inspection_no' => 'TEST/'.$i->id])->save();
        $next = $this->startInspection($officer, $school);
        $map = $next->answerMap();
        $this->assertNull($map['NET_01'] ?? null);
        $this->assertNull($map['NET_02'] ?? null);
    }

    public function test_declaration_form_downloads_for_the_officer_only(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $i = $this->startInspection($officer, $this->schoolIn($cluster));
        $this->actingAs($officer);
        $this->postJson(route('inspections.answers', $i), ['code' => 'NET_01', 'value' => 'yes', 'remarks' => 'No signal'])->assertOk();
        $this->answer($i, 'NET_02', ['agree'])->assertOk();
        $this->answer($i, 'CND_01', 'pucca')->assertOk();
        $this->answer($i, 'TSI_04', 3)->assertOk();
        $this->answer($i, 'TSI_09', 2)->assertOk();
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'Rina Kalita', 'teacher_type' => 'regular', 'reason' => 'CL'])->assertOk();

        $res = $this->get(route('inspections.network-declaration', $i))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $res->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'network_declaration_downloaded', 'auditable_id' => $i->id]);

        // the declaration form = every detail entered (no photos), then the Declaration with the tick and both signature boxes
        $view = new \ReflectionMethod(\App\Http\Controllers\ReportController::class, 'reportData');
        $data = $view->invoke(app(\App\Http\Controllers\ReportController::class), $i->fresh());
        $html = view('inspections.pdf', $data + ['logos' => ['assam' => null, 'ssa' => null], 'generatedAt' => now(), 'declaration' => true])->render();
        foreach (['NO-INTERNET DECLARATION', $i->school->udise_code, $officer->name, 'Pucca', 'Rina Kalita', 'Declaration · No internet at the school',
            'Verified by the Block / District officer', 'No signal', '✔', 'Samagra Shiksha Axom'] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
        $this->assertStringNotContainsString('Photographs of School', $html);
        $this->assertStringNotContainsString('Signature of Inspecting Officer', $html);

        // the normal report PDF also ends with the Declaration (tick + signed PDF status), without the sign-off boxes
        $report = view('inspections.pdf', $data + ['logos' => ['assam' => null, 'ssa' => null], 'generatedAt' => now()])->render();
        $this->assertStringContainsString('Declaration ticked: Yes · Signed declaration (PDF): not attached', $report);
        $this->assertStringNotContainsString('Verified by the Block / District officer', $report);

        $outside = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));
        $this->actingAs($outside)->get(route('inspections.network-declaration', $i))->assertForbidden();
    }
}
