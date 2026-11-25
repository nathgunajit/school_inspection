<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionFundPosition;
use App\Questionnaire\InspectionValidator;
use App\Services\InspectionPrefill;
use Tests\TestCase;

/**
 * Modification V11 (Documents/Modification of the ApplicationV11.docx):
 * 1 bottom bars hide on scroll (JS/CSS only), 2 blank form PDF + network issue (NET_01, reports.network),
 * 3 teacher name suggestions from the same school, 4 next visit pre-filled from the previous inspection.
 */
class ModificationV11Test extends TestCase
{
    private function answer(Inspection $i, string $code, mixed $value, ?string $remarks = null)
    {
        return $this->postJson(route('inspections.answers', $i), ['code' => $code, 'value' => $value] + ($remarks !== null ? ['remarks' => $remarks] : []))->assertOk();
    }

    private function absent(Inspection $i, string $name)
    {
        return $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => $name, 'teacher_type' => 'regular', 'reason' => 'CL']);
    }

    /** Turn a draft into last month's submitted inspection (so the same officer may start a new one today). */
    private function submitLastMonth(Inspection $i): Inspection
    {
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now()->subMonth(), 'inspection_date' => now()->subMonth()->toDateString(),
            'inspection_no' => 'TEST/'.$i->id])->save();

        return $i->fresh();
    }

    public function test_blank_form_pdf_for_a_school_in_the_area_only(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $res = $this->actingAs($officer)->get(route('schools.blank-form', $school))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'blank_form_downloaded', 'auditable_id' => $school->id]);

        $other = $this->schoolIn($this->otherCluster($officer->currentJurisdiction->cluster));
        $this->get(route('schools.blank-form', $other))->assertNotFound();
        $this->get(route('schools.show', $school))->assertSee(route('schools.blank-form', $school), false);
    }

    /** 23-11-2026: the paper form follows the questionnaire definition (groups, numbering, calculated boxes). */
    public function test_blank_form_follows_the_questionnaire(): void
    {
        $school = \App\Models\School::with(['district', 'block', 'cluster'])->first();
        $questions = array_map(fn ($qs) => array_values(array_filter($qs, fn ($q) => empty($q['hidden']))), \App\Questionnaire\QuestionnaireDefinition::questions());
        $html = view('inspections.blank-form', ['school' => $school, 'steps' => \App\Questionnaire\QuestionnaireDefinition::steps(), 'questions' => $questions,
            'logos' => ['assam' => null, 'ssa' => null], 'photoLabels' => ['Front view']])->render();

        foreach (\App\Questionnaire\QuestionnaireDefinition::GROUPS as [$title]) {
            $this->assertStringContainsString(e($title), $html);
        }
        foreach (['Total students', 'Total teachers', 'Total trained', 'Total untrained', 'Total male', 'Total female', 'Teachers absent today', 'How to fill', '2.1', 'Answer only if'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        // the group heading comes before the Total teachers box, which comes before the first teacher question
        $this->assertLessThan(strpos($html, 'Total teachers</div>'), strpos($html, 'Teachers &amp; tutors'));
        $this->assertLessThan(strpos($html, 'Number of Regular Teachers'), strpos($html, 'Total teachers</div>'));
        $this->assertLessThan(strpos($html, 'Teachers absent today'), strpos($html, 'Total female'));
        // print layout: boxes are table cells next to their label (DomPDF alignment), no inline box spans
        $this->assertStringContainsString('<td class="bxc"></td><td class="ol">Yes</td>', $html);
        $this->assertStringContainsString('<td class="nbx"></td>', $html);
        $this->assertStringNotContainsString('class="bx"', $html);
        $this->assertStringNotContainsString('class="num"', $html);
        // standard background: faint SSA logo, title and SSA, ASSAM on every page
        $this->assertStringContainsString('class="pdf-wm"', $html);
        $this->assertStringContainsString('SCHOOL INSPECTION FORM</div>', $html);
        $this->assertStringContainsString('SSA, ASSAM', $html);
        // 23-11-2026: the background is turned 45°, and the absentee table fills its page (12 rows, before section 4)
        $this->assertStringContainsString('rotate(-45deg)', $html);
        $absent = substr($html, strpos($html, 'Teachers Absentee'), strpos($html, 'Class Room Performance') - strpos($html, 'Teachers Absentee'));
        $this->assertSame(12, substr_count($absent, '<td class="sl">'));
        // Class Room Performance is one page of its own: 7 observation rows, and Fund starts the next page
        $class = substr($html, strpos($html, 'Class Room Performance'), strpos($html, 'Fund Received') - strpos($html, 'Class Room Performance'));
        $this->assertSame(7, substr_count($class, '<td class="sl" rowspan="2">'));
        $this->assertMatchesRegularExpression('/<table class="h newpage"><tr><td class="n">\d+<\/td><td class="t">Fund Received/', $html);
    }

    public function test_network_issue_is_optional_needs_a_remark_and_is_listed_for_the_area(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $i = $this->startInspection($officer, $this->schoolIn($cluster));
        $codes = fn () => collect((new InspectionValidator($i->fresh()))->missing()[1] ?? [])->pluck('code');
        $this->assertNotContains('NET_01', $codes()); // optional

        $this->answer($i, 'NET_01', 'yes');
        $this->assertContains('NET_01', $codes()); // Yes needs a remark
        $this->answer($i, 'NET_01', 'yes', 'No mobile signal in the school campus');
        $this->assertNotContains('NET_01', $codes());

        $district = $this->officer(UserLevel::District, $cluster);
        $this->actingAs($district)->get(route('reports.network'))->assertOk()
            ->assertSee('No mobile signal in the school campus')->assertSee($i->school->name);

        $outside = $this->officer(UserLevel::District, $this->otherCluster($cluster));
        $this->actingAs($outside)->get(route('reports.network'))->assertOk()->assertDontSee('No mobile signal in the school campus');
    }

    /** 25-11-2026: the network issues list downloads as PDF, scoped like the page and audited. */
    public function test_network_issues_pdf_is_scoped_and_audited(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $i = $this->startInspection($officer, $this->schoolIn($cluster));
        $this->answer($i, 'NET_01', 'yes', 'No mobile signal in the school campus');

        $district = $this->officer(UserLevel::District, $cluster);
        $this->actingAs($district)->get(route('reports.network'))->assertOk()->assertSee(route('reports.network.export-pdf'), false);
        $this->get(route('reports.network.export-pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $log = \App\Models\AuditLog::where('action', 'network_issues_exported')->latest('id')->first();
        $this->assertSame(1, $log->meta['rows']);

        $outside = $this->officer(UserLevel::District, $this->otherCluster($cluster));
        $this->actingAs($outside)->get(route('reports.network.export-pdf'))->assertOk();
        $this->assertSame(0, \App\Models\AuditLog::where('action', 'network_issues_exported')->latest('id')->first()->meta['rows']);
    }

    public function test_teacher_names_come_from_earlier_inspections_of_the_same_school(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $school = $this->schoolIn($cluster);
        $first = $this->startInspection($officer, $school);
        $this->absent($first, 'Rina Kalita')->assertOk();
        $this->postJson(route('inspections.classrooms.store', $first), ['teacher_name' => 'Bijoy Das', 'class' => 'V', 'subject' => 'Mathematics',
            'tlm_used' => 'no', 'participation' => 'satisfactory'])->assertOk();
        $this->submitLastMonth($first);

        // another school's teacher must not be offered
        $otherCluster = $this->otherCluster($cluster);
        $elsewhere = $this->officer(UserLevel::Cluster, $otherCluster);
        $this->absent($this->startInspection($elsewhere, $this->schoolIn($otherCluster)), 'Rina Outsider')->assertOk();

        $today = $this->startInspection($officer, $school);
        $this->actingAs($officer)->getJson(route('inspections.teacher-names', [$today, 'q' => 'rin']))->assertOk()
            ->assertJsonPath('items', ['Rina Kalita']);
        $all = $this->getJson(route('inspections.teacher-names', $today))->json('items');
        $this->assertEqualsCanonicalizing(['Rina Kalita', 'Bijoy Das'], $all);
        $this->get(route('inspections.step', [$today, 4]))->assertSee(route('inspections.teacher-names', $today), false);

        $this->actingAs($elsewhere)->getJson(route('inspections.teacher-names', $today))->assertForbidden();
    }

    public function test_next_visit_is_prefilled_except_todays_figures_and_rows(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);

        $first = $this->startInspection($officer, $school);
        $this->assertNull($first->prefilled_from_id, 'first visit: nothing to copy');
        $this->answer($first, 'CND_01', 'pucca');
        $this->answer($first, 'TSI_01', 120);
        $this->answer($first, 'TSI_04', 6);
        $this->answer($first, 'TSI_09', 5);
        $this->answer($first, 'GOVR_01', 'conventional');
        $this->answer($first, 'NET_01', 'yes', 'No signal');
        $this->absent($first, 'Rina Kalita')->assertOk();
        $this->postJson(route('inspections.funds.store', $first), ['financial_year' => InspectionFundPosition::financialYears()[0], 'asg_received' => 1.5])->assertOk();
        $first = $this->submitLastMonth($first);

        $next = $this->startInspection($officer, $school);
        $this->assertNotSame($first->id, $next->id);
        $this->assertSame($first->id, $next->prefilled_from_id);
        $map = $next->answerMap();
        $this->assertSame('pucca', $map['CND_01']);
        $this->assertSame(6, $map['TSI_04']);
        $this->assertSame('conventional', $map['GOVR_01']);
        foreach (array_diff(InspectionPrefill::FRESH, InspectionPrefill::ZERO) as $code) {
            $this->assertArrayNotHasKey($code, $map, "$code is asked fresh");
        }
        $this->assertSame(0, $map['TSI_09'], 'V12: Teachers present starts at 0');
        $this->assertSame(0, $next->absentTeachers()->count());
        $this->assertSame(0, $next->classroomObservations()->count());
        $this->assertSame(0, $next->evidence()->count());
        $this->assertSame(1, $next->fundPositions()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_prefilled', 'auditable_id' => $next->id]);
        $this->assertSame('pucca', $first->fresh()->answerMap()['CND_01']); // source untouched

        $this->get(route('inspections.step', [$next, 1]))->assertOk()->assertSee('Filled from the last visit on');
        $this->get(route('inspections.step', [$next, 2]))->assertSee('Teachers present starts at 0');
        $this->get(route('inspections.step', [$next, 3]))->assertDontSee('Filled from the last visit on');

        // reopening the draft does not copy again
        $count = $next->answers()->count();
        $this->assertSame($next->id, $this->startInspection($officer, $school)->id);
        $this->assertSame($count, $next->answers()->count());
    }
}
