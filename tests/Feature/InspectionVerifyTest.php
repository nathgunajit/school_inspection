<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Http\Controllers\ReportController;
use App\Models\Inspection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 28-11-2026: QR code on the inspection PDF opens a public, view-only page of that inspection (no photos, no actions). */
class InspectionVerifyTest extends TestCase
{
    private function inspection(): Inspection
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->actingAs($officer);
        $this->postJson(route('inspections.answers', $i), ['code' => 'CND_01', 'value' => 'pucca'])->assertOk();
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_04', 'value' => 3])->assertOk();
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_09', 'value' => 2])->assertOk();
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'Rina Kalita', 'teacher_type' => 'regular', 'reason' => 'CL'])->assertOk();
        // a photograph that must never appear on the public page
        DB::table('inspection_evidence')->insert(['inspection_id' => $i->id, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'photo',
            'storage_path' => 'x.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
            'status' => 'uploaded', 'uploaded_by' => $officer->id, 'created_at' => now(), 'updated_at' => now()]);

        return $i->fresh();
    }

    public function test_the_pdf_carries_a_qr_code_to_the_public_page(): void
    {
        $i = $this->inspection();
        $this->get(route('inspections.pdf', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
        $i->refresh();
        $this->assertSame(40, strlen((string) $i->verify_token));
        $this->assertSame($i->verify_token, $i->fresh()->verifyToken(), 'made once');

        $data = app(ReportController::class)->reportData($i) + ['logos' => ['assam' => null, 'ssa' => null], 'generatedAt' => now()] + ReportController::qrData($i);
        $html = view('inspections.pdf', $data)->render();
        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Scan to view this', $html);
        $this->assertStringContainsString('/verify/'.$i->verify_token, $data['verifyUrl']);

        // a cancelled inspection gets no QR
        $i->forceFill(['status' => InspectionStatus::Cancelled])->save();
        $this->assertSame(['verifyUrl' => null, 'qr' => null, 'refCode' => null], ReportController::qrData($i));
    }

    public function test_anyone_scanning_sees_the_report_read_only_without_photos(): void
    {
        $i = $this->inspection();
        $url = $i->verifyUrl();
        auth()->logout();

        $res = $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $res->assertSee($i->school->name)->assertSee($i->school->udise_code)->assertSee('Inspection record found')
            ->assertSee('Type of School Building')->assertSee('Pucca')->assertSee('Rina Kalita')->assertSee('draft');
        foreach (['/evidence/', route('inspections.pdf', $i), route('inspections.step', [$i, 1]), '/unlock', 'Download PDF', 'Continue editing'] as $absent) {
            $res->assertDontSee($absent, false);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_verified_view', 'auditable_id' => $i->id, 'user_id' => null]);
    }

    public function test_unknown_codes_and_cancelled_inspections(): void
    {
        $this->get('/verify/'.str_repeat('A', 40))->assertNotFound()->assertSee('This QR code is not valid');
        $this->get('/verify/short')->assertNotFound();
        $i = $this->inspection();
        $this->get('/verify/'.$i->id)->assertNotFound(); // a record ID is never a code

        $url = $i->verifyUrl();
        $i->forceFill(['status' => InspectionStatus::Cancelled])->save();
        $this->get($url)->assertOk()->assertSee('Cancelled inspection')->assertSee('Its details are not shown')->assertDontSee('Rina Kalita');
    }
}
