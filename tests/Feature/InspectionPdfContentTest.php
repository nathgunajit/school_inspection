<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionEvidence;
use App\Models\InspectionFundPosition;
use App\Models\TlmItem;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 26-11-2026: the inspection PDF prints every piece of data the officer entered, with the right times.
 * The HTML that DomPDF lays out is checked (text in a PDF stream is not searchable).
 */
class InspectionPdfContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function pdfHtml(Inspection $i): string
    {
        $data = null;
        View::composer('inspections.pdf', function ($view) use (&$data) { $data ??= $view->getData(); });
        $this->get(route('inspections.pdf', $i))->assertOk()->assertHeader('content-type', 'application/pdf');

        return html_entity_decode(view('inspections.pdf', $data)->render(), ENT_QUOTES);
    }

    public function test_every_entered_value_is_printed(): void
    {
        Carbon::setTestNow('2026-11-26 10:00:00');
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->actingAs($officer);

        // answers: options for every visible question, the step 2 numbers, a remark, a reason box
        $numbers = ['TSI_01' => 41, 'TSI_02' => 3, 'TSI_03' => 12, 'TSI_04' => 3, 'TSI_05' => 1, 'TSI_06' => 0, 'TSI_07' => 0, 'TSI_08' => 0,
            'TSI_09' => 2, 'TSI_10' => 1, 'TSI_11' => 1, 'TSI_12' => 1, 'TSI_13' => 1];
        $answers = [];
        foreach (Q::all() as $code => $q) {
            if (! InspectionValidator::visible($q, $answers + ['GOVR_02' => 'no']) || ! empty($q['hidden'])) {
                continue;
            }
            $v = $numbers[$code] ?? match ($q['type']) {
                'radio', 'select' => $code === 'GOVR_02' ? 'no' : array_key_last($q['options']),
                'number' => 0,
                'textarea', 'text' => "Reason for $code",
                default => null,
            };
            if ($v === null) {
                continue;
            }
            $answers[$code] = $v;
            $this->postJson(route('inspections.answers', $i), ['code' => $code, 'value' => $v])->assertOk();
        }
        $this->postJson(route('inspections.answers', $i), ['code' => 'NET_01', 'value' => 'yes', 'remarks' => 'Mobile signal very weak'])->assertOk();
        $answers['NET_01'] = 'yes';

        // rows
        $this->postJson(route('inspections.teachers.store', $i), ['teacher_name' => 'Rina Kalita', 'teacher_type' => 'contractual', 'reason' => 'MEDL', 'remarks' => 'Hospital'])->assertOk();
        $tlm = TlmItem::active()->first();
        $this->postJson(route('inspections.classrooms.store', $i), ['teacher_name' => 'B. Devi', 'class' => 'IV', 'subject' => 'Environmental Studies',
            'tlm_used' => 'yes', 'tlm_items' => [$tlm->id], 'participation' => 'not_satisfactory', 'remarks' => 'Group work seen'])->assertOk();
        [$fy1, $fy2] = InspectionFundPosition::financialYears();
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy1, 'asg_received' => '2.5', 'asg_expenditure' => '1.25'])->assertOk();
        $this->postJson(route('inspections.funds.store', $i), ['financial_year' => $fy2, 'mdm_received' => '0.75'])->assertOk();
        $this->postJson(route('inspections.suggestions', $i), ['suggestions' => ['Repair the boundary wall', 'Start a library corner']])->assertOk();

        // a photo taken at 09:58 India time, sent by the phone in UTC
        $file = UploadedFile::fake()->image('p.jpg', 400, 300);
        $this->post(route('evidence.store', $i), ['file' => $file, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'photo', 'category' => 'toilet_block',
            'caption' => 'Girls toilet', 'taken_at' => '2026-11-26T04:28:15.000Z', 'latitude' => 26.11, 'longitude' => 91.71, 'accuracy' => 8,
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('2026-11-26 09:58:15', InspectionEvidence::first()->taken_at->format('Y-m-d H:i:s'));

        // later saves must not move the start time
        Carbon::setTestNow('2026-11-26 11:30:00');
        $this->postJson(route('inspections.answers', $i), ['code' => 'TSI_03', 'value' => 12])->assertOk();
        $this->assertSame('2026-11-26 10:00', $i->fresh()->started_at->format('Y-m-d H:i'));

        $html = $this->pdfHtml($i->fresh());

        foreach ($answers as $code => $v) {
            $q = Q::find($code);
            $this->assertStringContainsString($q['label'], $html, "label of $code");
            if ($q['type'] !== 'number') {
                $this->assertStringContainsString(Q::optionLabel($code, $v), $html, "answer of $code");
            }
        }
        foreach ([
            'Reason for GOVR_03', 'Remarks: Mobile signal very weak',
            'Rina Kalita', 'Contractual Teacher', 'Medical Leave (ML)', 'Hospital',
            'Class IV', 'Environmental Studies', 'B. Devi', 'Not Satisfactory', 'Group work seen', $tlm->name,
            str_replace('FY-', 'FY ', $fy1), str_replace('FY-', 'FY ', $fy2), '2.50', '1.25', '0.75', 'Year-wise summary',
            'Repair the boundary wall', 'Start a library corner',
            'Toilet Block', 'Girls toilet', '26 Nov 2026, 09:58:15 AM', '26.110000, 91.710000',
            'Total students', '44', 'Present 41 · Absent 3', 'Trained / Untrained', '2 / 2', 'Male 2 · Female 2',
            'Inspection started', '26 Nov 2026, 10:00 AM', $officer->name,
        ] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['TSI_14', 'Total Number of Trained Teachers', 'BAS_PERSON', 'Device location'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $hidden);
        }
        Carbon::setTestNow();
    }
}
