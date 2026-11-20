<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\InspectionAbsentTeacher;
use App\Models\InspectionAnswer;
use App\Models\InspectionClassroomObservation;
use App\Models\InspectionFundPosition;
use App\Models\TlmItem;
use App\Models\MasterValue;
use App\Models\School;
use App\Models\User;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;
use App\Services\AuditLogger;
use App\Services\InspectionNumberGenerator;
use App\Services\InspectionStartGuard;
use App\Services\JurisdictionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Inspection workflow: start, six-step questionnaire with auto-save, review, submit (PRD 8, 10). */
class InspectionController extends Controller
{
    public function __construct(private JurisdictionService $j, private InspectionStartGuard $startGuard) {}

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

        // Serialise starts for one school, so two officers cannot both start it at the same moment
        return DB::transaction(function () use ($user, $school, $loc) {
            School::whereKey($school->id)->lockForUpdate()->first();

            // Own draft (any date), own submission today, another officer's draft, or completed by another officer this month
            if ($block = $this->startGuard->check($user, $school)) {
                $found = $block['inspection'];
                if ($block['type'] === InspectionStartGuard::OWN_DRAFT) {
                    return redirect()->route('inspections.step', [$found, 1])->with('info', $block['message']);
                }
                if ($block['type'] === InspectionStartGuard::OWN_TODAY) {
                    return redirect()->route('inspections.show', $found)->with('warning', $block['message']);
                }
                AuditLogger::log('inspection_start_blocked', $school, ['reason' => $block['type'], 'inspection_id' => $found->id, 'by_officer' => $found->officer_id]);

                return redirect()->route('schools.show', $school)->with('warning', $block['message']);
            }

            // One inspection per officer, per school, per day (PRD 8.2): a cancelled draft of today is reopened
            $existing = Inspection::where('officer_id', $user->id)->where('school_id', $school->id)
                ->where('inspection_date', today()->toDateString())->first();
            if ($existing && $existing->status === InspectionStatus::Cancelled) {
                $existing->update(['status' => InspectionStatus::Draft, 'cancelled_reason' => null]);
                AuditLogger::log('inspection_restarted', $existing);

                return redirect()->route('inspections.step', [$existing, 1])->with('info', 'Your cancelled draft for today has been reopened.');
            }

            return $this->createInspection($user, $school, $loc);
        });
    }

    private function createInspection(User $user, School $school, array $loc)
    {
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
            $inspection = Inspection::where('officer_id', $user->id)->where('school_id', $school->id)->where('inspection_date', today()->toDateString())->firstOrFail();
        }

        AuditLogger::log('inspection_started', $inspection, ['school' => $school->udise_code, 'lat' => $loc['lat'] ?? null, 'lng' => $loc['lng'] ?? null]);

        return redirect()->route('inspections.step', [$inspection, 1]);
    }

    /* ======================= Wizard ======================= */

    public function step(Request $request, Inspection $inspection, int $step)
    {
        $this->authorize('update', $inspection);
        abort_unless(isset(Q::steps()[$step]), 404);

        // A v1 draft continues on the current questionnaire
        if ($inspection->questionnaire_version < Q::VERSION) {
            $inspection->update(['questionnaire_version' => Q::VERSION]);
        }

        $inspection->load(['school.district', 'school.block', 'school.cluster', 'school.management', 'school.category', 'officer.designation',
            'answers', 'absentTeachers', 'classroomObservations.tlmItems', 'fundPositions', 'evidence.category', 'remarks']);
        $progress = (new InspectionValidator($inspection))->progress();

        return view('inspections.step', [
            'inspection' => $inspection,
            'step' => $step,
            'steps' => Q::steps(),
            'questions' => array_values(array_filter(Q::questions()[$step], fn ($q) => empty($q['hidden']))), // V9: hidden questions are not asked
            'answers' => $inspection->answerMap(),
            'absentLimit' => InspectionValidator::absentLimit($inspection->answerMap()),
            'remarks' => $inspection->remarksMap(),
            'progress' => $progress,
            'photoCategories' => MasterValue::ofType('photo_category')->get(),
            'teacherRows' => $this->teacherRows($inspection),
            'classroomRows' => $this->classroomRows($inspection),
            'fundRows' => $this->fundRows($inspection),
            'financialYears' => InspectionFundPosition::financialYears(),
            'tlmItems' => TlmItem::active()->orderBy('sort')->orderBy('name')->get()->map->toPickArray()->all(),
        ]);
    }

    /** Auto-save of a single answer (PRD 8.2: saved automatically after every answer). */
    /** Friendly field names / messages for the repeating rows (shown next to each field in the form). */
    private const ROW_ATTRIBUTES = [
        'teacher_name' => "teacher's name", 'teacher_type' => "teacher's type", 'reason' => 'reason for absence',
        'class' => 'class', 'subject' => 'subject', 'tlm_used' => 'use of TLM', 'tlm_items' => 'TLM items', 'participation' => 'participation of students',
        'remarks' => 'remarks',
    ];

    private const ROW_MESSAGES = [
        'required' => 'Please enter the :attribute.',
        'teacher_type.required' => "Please select the teacher's type.",
        'reason.required' => 'Please select the reason for absence.',
        'class.required' => 'Please select the class.',
        'tlm_used.required' => 'Please choose Yes or No for use of TLM.',
        'tlm_items.required' => 'Tick at least one TLM item used.',
        'tlm_items.*.in' => 'A ticked TLM item is not in the list for this class.',
        'participation.required' => 'Please choose the participation of students.',
        'in' => 'Please choose a valid :attribute from the list.',
        'min' => 'The :attribute is too short.',
        'max' => 'The :attribute is too long (max :max characters).',
    ];

    public function saveAnswer(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $code = (string) $request->input('code');
        $q = Q::find($code);
        abort_unless($q, 422, 'Unknown question.');

        $value = $this->cleanValue($q, $request->input('value'));
        $remarks = $request->has('remarks') ? mb_substr(trim((string) $request->input('remarks')), 0, 2000) : null;

        // A greyed-out question (enable_if parent not matched) cannot hold an answer
        if (! empty($q['enable_if'])) {
            $parent = $inspection->answers()->where('question_code', $q['enable_if'][0])->first()?->value;
            if (! InspectionValidator::enabled($q, [$q['enable_if'][0] => $parent])) {
                $value = null;
            }
        }

        $answer = InspectionAnswer::firstOrNew(['inspection_id' => $inspection->id, 'question_code' => $code]);
        $answer->step = $q['step'];
        if ($request->has('value')) {
            $answer->value = $value;
        }
        if ($request->has('remarks')) {
            $answer->remarks = $remarks ?: null;
        }
        $answer->save();

        // Parent changed (e.g. Internet Facility = No): clear the answers of questions it now disables
        if ($request->has('value')) {
            foreach (Q::all() as $child) {
                if (($child['enable_if'][0] ?? null) === $code && ! InspectionValidator::enabled($child, [$code => $value])) {
                    $inspection->answers()->where('question_code', $child['code'])->update(['value' => null]);
                }
            }
        }

        if ($code === 'BAS_PERSON_NAME') {
            $inspection->person_met_name = is_string($value) ? mb_substr($value, 0, 120) : null;
        } elseif ($code === 'BAS_PERSON_DESIG') {
            $inspection->person_met_designation = is_string($value) ? mb_substr($value, 0, 120) : null;
        }

        return $this->progressResponse($inspection);
    }

    /** Teachers Absentee Form row (v2 step 3). */
    public function addAbsentTeacher(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $data = $this->teacherData($request);
        $this->guardAbsentLimit($inspection, $request);
        $this->addRow($inspection->absentTeachers(), $data, $request);

        return $this->progressResponse($inspection, ['teachers' => $this->teacherRows($inspection)]);
    }

    public function updateAbsentTeacher(Request $request, Inspection $inspection, InspectionAbsentTeacher $teacher)
    {
        $this->authorize('update', $inspection);
        abort_unless($teacher->inspection_id === $inspection->id, 404);
        $teacher->update($this->teacherData($request));

        return $this->progressResponse($inspection, ['teachers' => $this->teacherRows($inspection)]);
    }

    public function removeAbsentTeacher(Inspection $inspection, InspectionAbsentTeacher $teacher)
    {
        $this->authorize('update', $inspection);
        abort_unless($teacher->inspection_id === $inspection->id, 404);
        $teacher->delete();

        return $this->progressResponse($inspection, ['teachers' => $this->teacherRows($inspection)]);
    }

    /**
     * Adds a repeating-section row. A row sent again with the same `row_uid` (replayed after a lost network
     * answer, see resources/js/offline-queue.js) is not added twice.
     */
    /**
     * Modification V9: no more absent teachers than Total teachers - Present (step 2).
     * A replay of a row already saved (same row_uid) is let through, so the offline queue stays safe.
     */
    private function guardAbsentLimit(Inspection $inspection, Request $request): void
    {
        $uid = $request->input('row_uid');
        if (is_string($uid) && $inspection->absentTeachers()->where('row_uid', strtolower($uid))->exists()) {
            return;
        }
        $limit = InspectionValidator::absentLimit($inspection->answerMap());
        if ($limit !== null && $inspection->absentTeachers()->count() >= $limit) {
            throw ValidationException::withMessages(['teacher_name' => $limit === 0
                ? 'Step 2 shows no teacher absent (Total teachers = Present). Correct step 2 to add an absent teacher.'
                : "Only {$limit} teacher(s) can be absent (Total teachers - Present on step 2). All {$limit} are already added."]);
        }
    }

    private function addRow(\Illuminate\Database\Eloquent\Relations\HasMany $rows, array $data, Request $request): \Illuminate\Database\Eloquent\Model
    {
        $uid = $request->validate(['row_uid' => ['nullable', 'uuid']])['row_uid'] ?? null;
        if ($uid === null) {
            return $rows->create($data);
        }

        return $rows->createOrFirst(['row_uid' => strtolower($uid)], $data); // safe even if two copies arrive at once
    }

    private function teacherData(Request $request): array
    {
        return $request->validate([
            'teacher_name' => ['required', 'string', 'min:2', 'max:120'],
            'teacher_type' => ['required', Rule::in(array_keys(InspectionAbsentTeacher::TYPES))],
            'reason' => ['required', Rule::in(array_keys(InspectionAbsentTeacher::REASONS))],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], self::ROW_MESSAGES, self::ROW_ATTRIBUTES);
    }

    /** Class Room Performance row (v2 step 4). */
    public function addClassroom(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        [$data, $tlm] = $this->classroomData($request);
        $this->addRow($inspection->classroomObservations(), $data, $request)->tlmItems()->sync($tlm); // a replay syncs the same ids

        return $this->progressResponse($inspection, ['classrooms' => $this->classroomRows($inspection)]);
    }

    public function updateClassroom(Request $request, Inspection $inspection, InspectionClassroomObservation $classroom)
    {
        $this->authorize('update', $inspection);
        abort_unless($classroom->inspection_id === $inspection->id, 404);
        [$data, $tlm] = $this->classroomData($request, $classroom);
        $classroom->update($data);
        $classroom->tlmItems()->sync($tlm);

        return $this->progressResponse($inspection, ['classrooms' => $this->classroomRows($inspection)]);
    }

    public function removeClassroom(Inspection $inspection, InspectionClassroomObservation $classroom)
    {
        $this->authorize('update', $inspection);
        abort_unless($classroom->inspection_id === $inspection->id, 404);
        $classroom->delete();

        return $this->progressResponse($inspection, ['classrooms' => $this->classroomRows($inspection)]);
    }

    /** @return array{0: array, 1: int[]} the row columns and the ticked TLM item ids */
    private function classroomData(Request $request, ?InspectionClassroomObservation $existing = null): array
    {
        // FLN TLM: when TLM was used, tick at least one item offered for the class (if the class has any)
        $class = is_string($request->input('class')) ? $request->input('class') : null;
        $offered = $class !== null && array_key_exists($class, InspectionClassroomObservation::CLASSES)
            ? TlmItem::forClass($class)->pluck('id')->all() : [];
        $tlmYes = $request->input('tlm_used') === 'yes';
        // an item made inactive later stays valid on the row that already has it (same class)
        $kept = $existing && $existing->class === $class ? $existing->tlmItems()->pluck('tlm_items.id')->all() : [];

        $data = $request->validate([
            'teacher_name' => ['required', 'string', 'min:2', 'max:120'],
            'class' => ['required', Rule::in(array_keys(InspectionClassroomObservation::CLASSES))],
            'subject' => ['required', 'string', 'max:100'],
            'tlm_used' => ['required', 'in:yes,no'],
            'tlm_items' => [$tlmYes && $offered ? 'required' : 'nullable', 'array'],
            'tlm_items.*' => $tlmYes ? ['integer', Rule::in(array_merge($offered, $kept))] : ['nullable'],
            'participation' => ['required', Rule::in(array_keys(InspectionClassroomObservation::PARTICIPATION))],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], self::ROW_MESSAGES, self::ROW_ATTRIBUTES);
        $data['tlm_used'] = $data['tlm_used'] === 'yes';
        $data['remarks'] ??= null;
        $tlm = $data['tlm_used'] ? array_values(array_unique(array_map('intval', $data['tlm_items'] ?? []))) : [];
        unset($data['tlm_items']);

        return [$data, $tlm];
    }

    /** Fund received & expenditure for a financial year, in lakh; one row per year (v2 step 5). */
    public function addFund(Request $request, Inspection $inspection)
    {
        $this->authorize('update', $inspection);
        $amount = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999'];
        $data = $request->validate(
            ['financial_year' => ['required', Rule::in(InspectionFundPosition::financialYears())]]
            + array_fill_keys(InspectionFundPosition::AMOUNT_COLUMNS, $amount),
            [
                'financial_year.required' => 'Select the financial year first.',
                'financial_year.in' => 'Select a financial year from the list.',
                '*.numeric' => 'Enter the amount as a number, e.g. 1.25.',
                '*.decimal' => 'Use at most 2 decimal places (lakh).',
                '*.min' => 'The amount cannot be negative.',
                '*.max' => 'The amount looks too large. Enter it in lakh.',
            ]
        );
        $filled = collect(InspectionFundPosition::AMOUNT_COLUMNS)->filter(fn ($c) => ($data[$c] ?? null) !== null && $data[$c] !== '');
        if ($filled->isEmpty()) {
            throw ValidationException::withMessages(['asg_received' => 'Enter at least one amount for '.str_replace('FY-', 'FY ', $data['financial_year']).'.']);
        }
        $values = collect(InspectionFundPosition::AMOUNT_COLUMNS)->mapWithKeys(fn ($c) => [$c => (float) ($data[$c] ?? 0)])->all();

        $inspection->fundPositions()->updateOrCreate(['financial_year' => $data['financial_year']], $values);

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
        $inspection->load(['school', 'answers', 'absentTeachers', 'classroomObservations.tlmItems', 'fundPositions', 'evidence.category', 'remarks']);

        return view('inspections.review', [
            'inspection' => $inspection,
            'steps' => Q::steps(),
            'questions' => Q::questions(),
            'answers' => $inspection->answers->pluck('value', 'question_code')->all(),
            'progress' => (new InspectionValidator($inspection))->progress(),
            'photoCategories' => MasterValue::ofType('photo_category')->get(),
        ]);
    }

    /**
     * Subject names from the subject master for the Classroom Performance autocomplete (Modification V2 item 10).
     * Matches at the start of the name first, then anywhere; at most 10. Free text stays allowed on save.
     */
    public function subjectSuggestions(Request $request)
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 50));
        $all = MasterValue::ofType('subject')->pluck('label');
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $all = $all->filter(fn ($l) => str_contains(mb_strtolower($l), $needle))
                ->sortBy(fn ($l) => [str_starts_with(mb_strtolower($l), $needle) ? 0 : 1, $l]);
        }

        return response()->json(['subjects' => $all->take(10)->values()]);
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
            // Modification V9: optional counts left blank are stored as their default (0)
            $answers = $locked->answerMap();
            foreach (Q::defaults() as $code => $default) {
                if (! isset($answers[$code]) || $answers[$code] === '') {
                    $locked->answers()->updateOrCreate(['question_code' => $code], ['step' => Q::find($code)['step'], 'value' => $default]);
                }
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
            $inspection->unsetRelation('answers'); // reload with the stored defaults
        });

        AuditLogger::log($wasUnlocked ? 'inspection_resubmitted' : 'inspection_submitted', $inspection, ['inspection_no' => $inspection->inspection_no]);
        app(\App\Services\ScheduleService::class)->markInspected($inspection); // New Module 2: the visit on today's published plan is done
        try {
            app(\App\Services\RatingService::class)->rate($inspection); // New Module 3: rating on submit and on resubmit after unlock
        } catch (\Throwable $e) {
            report($e); // never block a submission; `php artisan ratings:recalculate` rates it later
        }

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
            'warnings' => $progress['warnings'],
        ]);
    }

    private function teacherRows(Inspection $inspection): array
    {
        return $inspection->absentTeachers()->get()->map->toClientArray()->all();
    }

    private function classroomRows(Inspection $inspection): array
    {
        return $inspection->classroomObservations()->with('tlmItems')->get()->map->toClientArray()->all();
    }

    private function fundRows(Inspection $inspection): array
    {
        return $inspection->fundPositions()->orderByDesc('financial_year')->get()->map->toClientArray()->all();
    }
}
