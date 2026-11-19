<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleStatus;
use App\Models\Block;
use App\Models\InspectionSchedule;
use App\Models\InspectionScheduleItem;
use App\Models\User;
use App\Services\JurisdictionService;
use App\Services\ScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Map-based inspection scheduling (New Module 2): calendar, planner, approvals and the plan page. */
class ScheduleController extends Controller
{
    public function __construct(private ScheduleService $schedules, private JurisdictionService $j) {}

    /** Day, week or month calendar of the plans the user may see. */
    public function index(Request $request)
    {
        $user = $request->user();
        $view = in_array($request->query('view'), ['day', 'week', 'month'], true) ? $request->query('view') : 'week';
        $date = $request->date('date') ?? today();
        [$from, $to] = match ($view) {
            'day' => [$date->copy(), $date->copy()],
            'week' => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
            'month' => [$date->copy()->startOfMonth()->startOfWeek(), $date->copy()->endOfMonth()->endOfWeek()],
        };

        $officers = $this->schedules->officersFor($user);
        $officerId = $request->integer('officer') ?: null;
        $ids = $officerId && $officers->contains('id', $officerId) ? [$officerId] : $officers->pluck('id')->all();

        $plans = InspectionSchedule::query()
            ->whereIn('officer_id', $ids)
            ->whereBetween('schedule_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['officer:id,name,level', 'items.school:id,name,udise_code'])
            ->orderBy('schedule_date')->get()
            ->groupBy(fn ($s) => $s->schedule_date->toDateString());

        return view('schedules.index', [
            'view' => $view, 'date' => $date, 'from' => $from, 'to' => $to, 'plans' => $plans,
            'officers' => $officers, 'officerId' => $officerId,
            'pendingCount' => $this->approvalQueue($user)->count(),
        ]);
    }

    /** The drag-and-drop planner for one officer and day. */
    public function plan(Request $request)
    {
        $user = $request->user();
        $date = $request->date('date') ?? today()->addDay();
        $officers = $this->schedules->officersFor($user);
        abort_if($officers->isEmpty(), 403, 'You have no officers to plan for.');
        $officer = $request->filled('officer') ? $officers->firstWhere('id', $request->integer('officer')) : $officers->first();
        abort_unless($officer, 403, 'You cannot plan for this officer.');

        $schedule = InspectionSchedule::query()->where('officer_id', $officer->id)->where('schedule_date', $date->toDateString())
            ->with('items.school')->first();
        $jur = $this->j->current($officer);
        $blocks = in_array($officer->level->value, ['state_admin', 'state', 'district'], true)
            ? Block::query()->when($jur?->district_id, fn ($q, $d) => $q->where('district_id', $d))->orderBy('name')->get(['id', 'name'])
            : collect();
        $start = $this->schedules->startPoint($officer);
        $superior = $this->schedules->isSuperior($user, $officer);

        return view('schedules.plan', [
            'date' => $date, 'officer' => $officer, 'officers' => $officers, 'schedule' => $schedule, 'blocks' => $blocks,
            'superior' => $superior,
            'readonly' => $schedule && ! $superior && $schedule->status->isOpen() && ! $schedule->status->isEditableByOfficer()
                && ! ($schedule->status === ScheduleStatus::Published && ! config('inspection.schedule.approval_required')),
            'config' => [
                'tiles' => config('inspection.map.tiles'),
                'attribution' => config('inspection.map.attribution'),
                'center' => config('inspection.map.center'),
                'zoom' => config('inspection.map.zoom'),
                'start' => $start,
                'roadFactor' => (float) config('inspection.schedule.road_factor'),
                'speed' => (float) config('inspection.schedule.avg_speed_kmh'),
                'visit' => (int) config('inspection.schedule.visit_minutes'),
                'dayStart' => config('inspection.schedule.day_start'),
                'max' => (int) config('inspection.schedule.max_per_day'),
                'needsBlock' => $blocks->isNotEmpty(),
                'urls' => ['schools' => route('schedules.schools'), 'store' => route('schedules.store')],
                'officer' => $officer->id,
                'date' => $date->toDateString(),
                'planned' => $schedule ? $schedule->items->map(fn ($i) => [
                    'id' => $i->school_id, 'udise' => $i->school->udise_code, 'name' => $i->school->name, 'lat' => $i->school->latitude, 'lng' => $i->school->longitude,
                    'status' => $i->status,
                ])->values() : [],
            ],
        ]);
    }

    /** Schools for the planner (JSON). */
    public function schools(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'officer' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'block' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $officer = User::findOrFail($data['officer']);
        abort_unless($this->schedules->canManage($user, $officer), 403);
        $date = Carbon::parse($data['date']);
        $current = InspectionSchedule::where('officer_id', $officer->id)->where('schedule_date', $date->toDateString())->first();

        return response()->json([
            'rows' => $this->schedules->candidateSchools($officer, $date, $data['block'] ?? null, $data['q'] ?? null, $current),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'officer_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'school_ids' => ['required', 'array', 'min:1'],
            'school_ids.*' => ['integer'],
            'action' => ['required', 'in:save,submit,publish'],
            'optimise' => ['nullable', 'boolean'],
            'start_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'start_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['school_ids.required' => 'Add at least one school to the plan.']);

        $schedule = $this->schedules->save($request->user(), User::findOrFail($data['officer_id']), Carbon::parse($data['date']),
            $data['school_ids'], $data['action'], $data);

        $msg = match ($schedule->status) {
            ScheduleStatus::Submitted => 'Plan submitted for approval.',
            ScheduleStatus::Published => 'Plan published.',
            default => 'Plan saved as draft.',
        };

        return $request->expectsJson()
            ? response()->json(['message' => $msg, 'redirect' => route('schedules.show', $schedule)])
            : redirect()->route('schedules.show', $schedule)->with('success', $msg);
    }

    public function show(Request $request, InspectionSchedule $schedule)
    {
        $user = $request->user();
        $schedule->load(['officer.currentJurisdiction', 'creator:id,name', 'reviewer:id,name', 'items.school', 'items.inspection:id,inspection_no,status']);
        abort_unless($this->schedules->canView($user, $schedule), 403);
        $superior = $this->schedules->isSuperior($user, $schedule->officer);

        return view('schedules.show', [
            'schedule' => $schedule,
            'superior' => $superior,
            'own' => $schedule->officer_id === $user->id,
            'history' => $schedule->history()->get(),
            'reassignTo' => $superior ? $this->schedules->officersFor($user)->filter(fn ($o) => $o->id !== $schedule->officer_id && $o->id !== $user->id)->values() : collect(),
            'start' => $schedule->start_lat !== null ? [$schedule->start_lat, $schedule->start_lng] : $this->schedules->startPoint($schedule->officer),
            'mapConfig' => [
                'tiles' => config('inspection.map.tiles'), 'attribution' => config('inspection.map.attribution'),
                'center' => config('inspection.map.center'), 'zoom' => config('inspection.map.zoom'),
            ],
        ]);
    }

    /** Plans waiting for the user's approval. */
    public function approvals(Request $request)
    {
        return view('schedules.approvals', [
            'plans' => $this->approvalQueue($request->user())->with(['officer:id,name,level', 'items.school:id,name'])->orderBy('schedule_date')->paginate(config('inspection.per_page')),
        ]);
    }

    public function approve(Request $request, InspectionSchedule $schedule)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->schedules->approve($request->user(), $schedule, $data['note'] ?? null);

        return redirect()->route('schedules.show', $schedule)->with('success', 'Plan approved and published.');
    }

    public function reject(Request $request, InspectionSchedule $schedule)
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']], ['note.required' => 'Give the reason, so the officer can correct the plan.']);
        $this->schedules->reject($request->user(), $schedule, $data['note']);

        return redirect()->route('schedules.show', $schedule)->with('success', 'Plan sent back to the officer.');
    }

    public function cancel(Request $request, InspectionSchedule $schedule)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->schedules->cancel($request->user(), $schedule, $data['reason']);

        return redirect()->route('schedules.show', $schedule)->with('success', 'Plan cancelled. Its schools are free for that day again.');
    }

    public function reassign(Request $request, InspectionSchedule $schedule)
    {
        $data = $request->validate(['officer_id' => ['required', 'integer', 'exists:users,id']]);
        $this->schedules->reassign($request->user(), $schedule, User::findOrFail($data['officer_id']));

        return redirect()->route('schedules.show', $schedule)->with('success', 'Plan reassigned.');
    }

    public function skip(Request $request, InspectionScheduleItem $item)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->schedules->skip($request->user(), $item, $data['reason']);

        return redirect()->route('schedules.show', InspectionSchedule::routeKeyFor($item->schedule_id))->with('success', 'Visit marked as skipped.');
    }

    private function approvalQueue(User $user)
    {
        $ids = $this->schedules->officersFor($user)->filter(fn ($o) => $o->id !== $user->id)->pluck('id');

        return InspectionSchedule::query()->whereIn('officer_id', $ids)->where('status', ScheduleStatus::Submitted);
    }
}
