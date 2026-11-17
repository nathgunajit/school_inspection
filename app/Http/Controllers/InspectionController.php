<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\InspectionAbsentTeacher;
use App\Models\InspectionAnswer;
use App\Models\InspectionFundPosition;
use App\Models\MasterValue;
use App\Models\School;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;
use App\Services\AuditLogger;
use App\Services\InspectionNumberGenerator;
use App\Services\JurisdictionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Inspection workflow: start, six-step questionnaire with auto-save, review, submit (PRD 8, 10). */
class InspectionController extends Controller
{
    public function __construct(private JurisdictionService $j) {}

    /* ======================= Start ======================= */

    public function start(Request $request, School $school)
    {
        $user = $request->user();
        abort_unless($user->can('conduct_inspections') && $this->j->coversSchool($user, $school), 403);
        if (! $school->isActive()) {
            return back()->with('error', 'Only active schools can be inspected.');
        }

        $loc = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        // One inspection per officer, per school, per day (PRD 8.2)
        $existing = Inspection::where('officer_id', $user->id)->where('school_id', $school->id)
            ->whereDate('inspection_date', today())->first();

        if ($existing) {
            if ($existing->isEditable()) {
                return redirect()->route('inspections.step', [$existing, 1])->with('info', 'Your draft for this school today has been reopened.');
            }
            if ($existing->status === InspectionStatus::Cancelled) {
                $existing->update(['status' => InspectionStatus::Draft, 'cancelled_reason' => null]);
                AuditLogger::log('inspection_restarted', $existing);

                return redirect()->route('inspections.step', [$existing, 1])->with('info', 'Your cancelled draft for today has been reopened.');
            }

            return redirect()->route('inspections.show', $existing)->with('warning', 'You have already submitted an inspection of this school today.');
        }

        $school->load(['state', 'district', 'block', 'cluster']);

        try {
            $inspection = Inspection::create([
                'officer_id' => $user->id,
                'school_id' => $school->id,
                'inspection_date' => today()->toDateString(),
                'started_at' => now(),
                'district_id' => $school->district_id,
                'block_id' => $school->block_id,
                'cluster_id' => $school->cluster_id,
                'state_code' => $school->state->code,
                'district_code' => $school->district->code,
                'block_code' => $school->block->code,
                'cluster_code' => $school->cluster->code,
                'start_lat' => $loc['lat'] ?? null,
                'start_lng' => $loc['lng'] ?? null,
                'start_accuracy' => $loc['accuracy'] ?? null,
                'status' => InspectionStatus::Draft,
                'questionnaire_version' => Q::VERSION,
            ]);
        } catch (QueryException $e) {
            // concurrent double-tap hit the unique key
            $inspection = Inspection::where('officer_id', $user->id)->where('school_id', $school->id)->whereDate('inspection_date', today())->firstOrFail();
        }

        AuditLogger::log('inspection_started', $inspection, ['school' => $school->udise_code, 'lat' => $loc['lat'] ?? null, 'lng' => $loc['lng'] ?? null]);

        return redirect()->route('inspections.step', [$inspection, 1]);
    }

    /* ======================= Wizard ======================= */

    public function step(Request $request, Inspection $inspection, int $step)
    {
        $this->authorize('update', $inspection);
        abort_unless(isset(Q::steps()[$step]), 404);

        $inspection->load(['school.district', 'school.block', 'school.cluster', 'school.management', 'school.category', 'officer.designation', 'answers', 'absentTeachers', 'fundPositions.category', 'evidence.category', 'remarks']);
        $progress = (new InspectionValidator($inspection))->progress();

        return view('inspections.step', [
            'inspection' => $inspection,
            'step' => $step,
            'steps' => Q::steps(),
            'questions' => Q::questions()[$step],
            'answers' => $inspection->answerMap(),
            'remarks' => $inspection->remarksMap(),
            'progress' => $progress,
            'photoCategories' => MasterValue::ofType('photo_category')->get(),
            'fundCategories' => MasterValue::ofType('fund_category')->get(),
        ]);
    }

    /** Auto-save of a single answer (PRD 8.2: saved automatically after every answer). */
    public function saveAnswer(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $code = (string) $request->input('code');
        $q = Q::find($code);
        abort_unless($q, 422, 'Unknown question.');

        $value = $this->cleanValue($q, $request->input('value'));
        $remarks = $request->has('remarks') ? mb_substr(trim((string) $request->input('remarks')), 0, 2000) : null;

        $answer = InspectionAnswer::firstOrNew(['inspection_id' => $inspection->id, 'question_code' => $code]);
        $answer->step = $q['step'];
        if ($request->has('value')) {
            $answer->value = $value;
        }
        if ($request->has('remarks')) {
            $answer->remarks = $remarks ?: null;
        }
        $answer->save();

        if ($code === 'BAS_PERSON_NAME') {
            $inspection->person_met_name = is_string($value) ? mb_substr($value, 0, 120) : null;
        } elseif ($code === 'BAS_PERSON_DESIG') {
            $inspection->person_met_designation = is_string($value) ? mb_substr($value, 0, 120) : null;
        }

        return $this->progressResponse($inspection);
    }

    public function addAbsentTeacher(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $data = $request->validate([
            'teacher_name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(array_keys(InspectionAbsentTeacher::STATUSES))],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $inspection->absentTeachers()->create($data);

        return $this->progressResponse($inspection, ['teachers' => $this->teacherRows($inspection)]);
    }

    public function removeAbsentTeacher(Inspection $inspection, InspectionAbsentTeacher $teacher)
    {
        $this->authorize('update', $inspection);
        abort_unless($teacher->inspection_id === $inspection->id, 404);
        $teacher->delete();

        return $this->progressResponse($inspection, ['teachers' => $this->teacherRows($inspection)]);
    }

    public function addFund(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $data = $request->validate([
            'financial_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'fund_category_id' => ['required', Rule::exists('master_values', 'id')->where('type', 'fund_category')],
            'amount_received' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'amount_utilised' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ], ['financial_year.regex' => 'Use the format 2026-2027.']);
        $inspection->fundPositions()->create($data);

        return $this->progressResponse($inspection, ['funds' => $this->fundRows($inspection)]);
    }

    public function removeFund(Inspection $inspection, InspectionFundPosition $fund)
    {
        $this->authorize('update', $inspection);
        abort_unless($fund->inspection_id === $inspection->id, 404);
        $fund->delete();

        return $this->progressResponse($inspection, ['funds' => $this->fundRows($inspection)]);
    }

    /** Suggestions (up to five), PRD 8.3 step 6. */
    public function saveSuggestions(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $max = config('inspection.max_suggestions');
        $data = $request->validate(['suggestions' => ['array', "max:$max"], 'suggestions.*' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($inspection, $data) {
            $inspection->remarks()->where('type', 'suggestion')->delete();
            foreach (array_values(array_filter(array_map('trim', $data['suggestions'] ?? []))) as $i => $s) {
                $inspection->remarks()->create(['type' => 'suggestion', 'sort' => $i + 1, 'body' => $s]);
            }
        });

        return $this->progressResponse($inspection);
    }

    /** Progress refresh after evidence changes. */
    public function progress(Inspection $inspection)
    {
        $this->authorize('update', $inspection);

        return $this->progressResponse($inspection);
    }

    /* ======================= Review & submit ======================= */

    public function review(Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $inspection->load(['school', 'answers', 'absentTeachers', 'fundPositions', 'evidence.category', 'remarks']);

        return view('inspections.review', [
            'inspection' => $inspection,
            'steps' => Q::steps(),
            'progress' => (new InspectionValidator($inspection))->progress(),
        ]);
    }

    public function submit(Request $request, Inspection $inspection, InspectionNumberGenerator $numbers)
    {
        $this->authorize('update', $inspection);
        $loc = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Please confirm that the information is correct.']);

        $progress = (new InspectionValidator($inspection))->progress();
        if (! $progress['can_submit']) {
            return redirect()->route('inspections.review', $inspection)->with('error', 'Submission blocked: some mandatory items are missing.');
        }

        $wasUnlocked = $inspection->status === InspectionStatus::Unlocked;

        DB::transaction(function () use ($inspection, $numbers, $loc) {
            $locked = Inspection::whereKey($inspection->id)->lockForUpdate()->first();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['confirm' => 'This inspection has already been submitted.']);
            }
            $locked->fill([
                'inspection_no' => $locked->inspection_no ?: $numbers->next($locked),
                'submitted_at' => now(),
                'submit_lat' => $loc['lat'] ?? null,
                'submit_lng' => $loc['lng'] ?? null,
                'submit_accuracy' => $loc['accuracy'] ?? null,
                'status' => InspectionStatus::Submitted,
                'completion_pct' => 100,
            ])->save();
            $inspection->setRawAttributes($locked->getAttributes(), true);
        });

        AuditLogger::log($wasUnlocked ? 'inspection_resubmitted' : 'inspection_submitted', $inspection, ['inspection_no' => $inspection->inspection_no]);

        return redirect()->route('inspections.submitted', $inspection);
    }

    public function submitted(Inspection $inspection)
    {
        $this->authorize('view', $inspection);
        abort_unless($inspection->status === InspectionStatus::Submitted, 404);

        return view('inspections.submitted', ['inspection' => $inspection->load('school')]);
    }

    public function cancel(Request $request, Inspection $inspection)
    {
        $this->authorize('cancel', $inspection);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $inspection->update(['status' => InspectionStatus::Cancelled, 'cancelled_reason' => $data['reason']]);
        AuditLogger::log('inspection_cancelled', $inspection, ['reason' => $data['reason']]);

        return redirect()->route('inspections.mine')->with('success', 'Draft cancelled.');
    }

    public function unlock(Request $request, Inspection $inspection)
    {
        $this->authorize('unlock', $inspection);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $inspection->update([
            'status' => InspectionStatus::Unlocked,
            'unlocked_by' => $request->user()->id,
            'unlocked_at' => now(),
            'unlock_reason' => $data['reason'],
        ]);
        AuditLogger::log('inspection_unlocked', $inspection, ['reason' => $data['reason'], 'inspection_no' => $inspection->inspection_no]);

        return back()->with('success', 'Inspection unlocked. The officer can now correct and resubmit it.');
    }

    /* ======================= Helpers ======================= */

    private function cleanValue(array $q, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        $opts = array_keys($q['options'] ?? []);

        return match ($q['type']) {
            'radio', 'select' => in_array((string) $value, array_map('strval', $opts), true) ? (string) $value
                : throw ValidationException::withMessages(['value' => 'Invalid option.']),
            'multi' => array_values(array_intersect(array_map('strval', $opts), array_map('strval', (array) $value))) ?: null,
            'number' => is_numeric($value) && (int) $value >= 0 && (int) $value <= 100000 ? (int) $value
                : throw ValidationException::withMessages(['value' => 'Enter a whole number of 0 or more.']),
            'date' => ($d = \DateTime::createFromFormat('Y-m-d', (string) $value)) && $d <= new \DateTime('tomorrow') ? $d->format('Y-m-d')
                : throw ValidationException::withMessages(['value' => 'Enter a valid date, not in the future.']),
            default => mb_substr(trim((string) $value), 0, $q['type'] === 'text' ? 200 : 2000),
        };
    }

    private function progressResponse(Inspection $inspection, array $extra = [])
    {
        $inspection->unsetRelations();
        $progress = (new InspectionValidator($inspection))->progress();
        $inspection->completion_pct = min(99, $progress['pct']);
        $inspection->save(); // also touches updated_at (draft activity)

        return response()->json($extra + [
            'ok' => true,
            'saved_at' => now()->format('h:i:s A'),
            'pct' => $progress['pct'],
            'steps' => $progress['steps'],
            'missing' => $progress['missing'],
        ]);
    }

    private function teacherRows(Inspection $inspection): array
    {
        return $inspection->absentTeachers()->get()->map(fn ($t) => [
            'id' => $t->id, 'teacher_name' => $t->teacher_name, 'status' => $t->statusLabel(), 'remarks' => $t->remarks,
            'delete_url' => route('inspections.teachers.destroy', [$inspection, $t]),
        ])->all();
    }

    private function fundRows(Inspection $inspection): array
    {
        return $inspection->fundPositions()->with('category')->get()->map(fn ($f) => [
            'id' => $f->id, 'financial_year' => $f->financial_year, 'category' => $f->category?->label,
            'received' => number_format((float) $f->amount_received, 2), 'utilised' => number_format((float) $f->amount_utilised, 2),
            'balance' => number_format($f->balance(), 2),
            'delete_url' => route('inspections.funds.destroy', [$inspection, $f]),
        ])->all();
    }
}
