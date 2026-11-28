<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Http\Controllers\ReportController;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\School;
use Tests\TestCase;

/**
 * 28-11-2026: one printed reference code per inspection on the report PDF and the no-internet declaration form (and the
 * scanned page, with a same-day duplicate warning); the time the declaration form was downloaded is kept against the
 * school's record, drafts included; the school category on the inspection form; Find School lists every school.
 */
class DeclarationRefCodeTest extends TestCase
{
    private function noInternet(?UserLevel $level = null): Inspection
    {
        $officer = $this->officer($level ?? UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn(\App\Models\Cluster::orderBy('id')->first()));
        $this->actingAs($officer)->postJson(route('inspections.answers', $i), ['code' => 'NET_01', 'value' => 'yes', 'remarks' => 'No tower'])->assertOk();

        return $i->fresh();
    }

    public function test_report_and_declaration_share_one_reference_code_and_the_download_time_is_kept(): void
    {
        $i = $this->noInternet();
        $this->get(route('inspections.network-declaration', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
        $i->refresh();
        $this->assertMatchesRegularExpression('/^SI-[0-9A-Z]+-[2-9A-HJKMNP-Z]{6}$/', (string) $i->ref_code);
        $this->assertSame(1, $i->declaration_downloads);
        $this->assertNotNull($i->declaration_first_at);
        $this->assertSame(InspectionStatus::Draft, $i->status, 'kept for a draft that is not submitted');

        $this->get(route('inspections.pdf', $i))->assertOk();
        $this->assertSame($i->ref_code, $i->fresh()->ref_code, 'the report PDF prints the same code');
        $this->get(route('inspections.network-declaration', $i))->assertOk();
        $i->refresh();
        $this->assertSame(2, $i->declaration_downloads);
        $this->assertTrue($i->declaration_last_at->gte($i->declaration_first_at));
        $this->assertSame(2, AuditLog::where('action', 'network_declaration_downloaded')->count());

        // the code and the download time are printed on both papers
        $data = app(ReportController::class)->reportData($i) + ['logos' => ['assam' => null, 'ssa' => null], 'generatedAt' => now()] + ReportController::qrData($i);
        $this->assertStringContainsString('Ref. '.$i->ref_code, view('inspections.pdf', $data)->render());
        $decl = view('inspections.pdf', $data + ['declaration' => true])->render();
        $this->assertStringContainsString('Form downloaded:', $decl);
        $this->assertStringContainsString('Reference code: <b>'.$i->ref_code.'</b>', $decl);

        // Network Issues list and PDF show when the form was downloaded
        $this->actingAs($this->admin())->get(route('reports.network'))->assertOk()->assertSee('Form downloaded')->assertSee($i->ref_code);
        $this->get(route('reports.network.export-pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_scanned_page_shows_the_code_and_a_same_day_duplicate(): void
    {
        $i = $this->noInternet();
        $this->get(route('inspections.network-declaration', $i))->assertOk();
        $other = $this->noInternet(UserLevel::Block); // another level, same school, same day
        $this->get(route('inspections.pdf', $other))->assertOk();
        auth()->logout();

        $this->get($i->fresh()->verifyUrl())->assertOk()->assertSee($i->fresh()->ref_code)
            ->assertSee('Check for duplicate entry')->assertSee($other->fresh()->ref_code)->assertSee('Declaration form downloaded');
    }

    public function test_inspection_form_shows_the_school_category(): void
    {
        $i = $this->noInternet();
        $label = $i->school->category?->label ?? 'Not set';
        $this->get(route('inspections.step', [$i, 1]))->assertOk()->assertSee('School category')->assertSee($label);
    }

    public function test_find_school_lists_every_school_of_the_area_without_a_search(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('schools.index'))->assertOk()
            ->assertSee(number_format(School::count()).'</strong> school', false);
        $crcc = $this->officer(UserLevel::Cluster);
        $n = School::where('cluster_id', $crcc->currentJurisdiction->cluster_id)->count();
        $this->actingAs($crcc)->getJson(route('schools.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJsonPath('total', $n);
    }
}
