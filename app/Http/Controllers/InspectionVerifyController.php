<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Questionnaire\QuestionnaireDefinition as Q;
use App\Services\AuditLogger;

/**
 * 28-11-2026: the page the QR code on the inspection PDF opens. Public (no sign-in), view only: the report without
 * photographs, file links or any action. Found by a random 40-character code (inspections.verify_token), never by a
 * record ID, so it cannot be guessed; marked noindex, rate-limited, and every view is audited.
 */
class InspectionVerifyController extends Controller
{
    public function show(string $token)
    {
        $inspection = Inspection::where('verify_token', $token)->first();
        if (! $inspection) {
            return $this->noindex(response()->view('inspections.verify', ['inspection' => null], 404));
        }

        $inspection->load(['school.district:id,code,name', 'school.block:id,code,name', 'school.cluster:id,code,name', 'school.management', 'school.category',
            'officer.designation', 'answers', 'absentTeachers', 'classroomObservations.tlmItems', 'fundPositions', 'evidence', 'remarks']);
        AuditLogger::log('inspection_verified_view', $inspection, ['status' => $inspection->status->value]);

        return $this->noindex(response()->view('inspections.verify', [
            'inspection' => $inspection,
            'cancelled' => $inspection->status === InspectionStatus::Cancelled,
            'steps' => Q::steps(),
            'questions' => Q::questions(),
            'answers' => $inspection->answerMap(),
            'remarks' => $inspection->remarksMap(),
            'photos' => collect(), // photographs stay private
            'photoCategories' => collect(),
            'suggestions' => $inspection->remarks->where('type', 'suggestion')->pluck('body')->filter()->values(),
            // 28-11-2026: the printed reference code (report PDF + declaration form) and other records of this school that day
            'refCode' => $inspection->ref_code,
            'sameDay' => $inspection->status === InspectionStatus::Cancelled ? collect() : $inspection->sameDayRecords(),
        ]));
    }

    private function noindex($response)
    {
        return $response->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }
}
