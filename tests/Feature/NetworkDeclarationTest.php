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

    private function step1(Inspection $i): \Illuminate\Support\Collection
    {
        return collect((new InspectionValidator($i->fresh()))->missing()[1] ?? []);
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

        // shown on the wizard (the box), the review page and the Network Issues list
        $this->get(route('inspections.step', [$i, 1]))->assertOk()->assertSee('data-doc-question="NET_02"', false)
            ->assertSee(route('inspections.network-declaration', $i), false);
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
        $res = $this->actingAs($officer)->get(route('inspections.network-declaration', $i))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $res->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'network_declaration_downloaded', 'auditable_id' => $i->id]);

        $html = view('inspections.network-declaration', ['inspection' => $i, 'school' => $i->school, 'officer' => $officer, 'remark' => 'No signal',
            'logos' => ['assam' => null, 'ssa' => null]])->render();
        foreach (['NO INTERNET', $i->school->udise_code, $officer->name, 'Verified by the Block / District officer', 'No signal', 'Samagra Shiksha Axom'] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }

        $outside = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));
        $this->actingAs($outside)->get(route('inspections.network-declaration', $i))->assertForbidden();
    }
}
