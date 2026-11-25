<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Enums\ScheduleStatus;
use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\Inspection;
use App\Models\InspectionSchedule;
use App\Models\InspectionScheduleItem;
use App\Models\School;
use App\Models\User;
use App\Support\RouteOptimizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily inspection schedules (New Module 2).
 *
 * Who can do what:
 *  - an officer who conducts inspections plans their own days: draft → submit → (approval) → published
 *  - a higher officer whose jurisdiction covers the officer (State Admin, State, District, Block) can assign,
 *    change, reassign, approve or reject the officer's plans; plans they assign are published at once
 *
 * Rules for each school of a plan:
 *  - active, and inside the officer's jurisdiction
 *  - not on another officer's plan for the same day (also a DB unique key)
 *  - not being inspected by another officer (open draft), and not already inspected by another officer
 *    in the plan's calendar month (the same rules as InspectionStartGuard)
 *  - at most config('inspection.schedule.max_per_day') schools per day; the date is today or later
 */
class ScheduleService
{
    public function __construct(private JurisdictionService $j) {}

    /* ======================= Access ======================= */

    /** $actor is above $officer in the hierarchy and their jurisdiction covers the officer's posting. */
    public function isSuperior(User $actor, User $officer): bool
    {
        return $actor->id !== $officer->id
            && $actor->isActive()
            && $actor->level->rank() < $officer->level->rank()
            && $this->j->contains($actor, $this->j->current($officer));
    }

    public function canManage(User $actor, User $officer): bool
    {
        return ($actor->id === $officer->id && $actor->can('conduct_inspections')) || $this->isSuperior($actor, $officer);
    }

    public function canView(User $actor, InspectionSchedule $schedule): bool
    {
        return $schedule->officer_id === $actor->id || $this->isSuperior($actor, $schedule->officer);
    }

    /** Officers the actor may plan for: themselves (if they inspect) and the active officers below them. */
    public function officersFor(User $actor): Collection
    {
        $below = $this->j->users($actor)
            ->where('status', UserStatus::Active)
            ->where('id', '!=', $actor->id)
            ->whereIn('level', collect(UserLevel::cases())->filter(fn ($l) => $l->rank() > $actor->level->rank() && $l !== UserLevel::StateAdmin)->map->value->all())
            ->with('currentJurisdiction.cluster', 'currentJurisdiction.block', 'currentJurisdiction.district', 'designation')
            ->orderBy('level')->orderBy('name')->get();

        return $actor->can('conduct_inspections') ? $below->prepend($actor->loadMissing('currentJurisdiction')) : $below;
    }

    /* ======================= Planner data ======================= */

    /** Schools the officer may put on a plan for $date, with flags for the planner (optionally within one block). */
    public function candidateSchools(User $officer, Carbon $date, ?int $blockId = null, ?string $search = null, ?InspectionSchedule $current = null): array
    {
        $q = $this->j->schools($officer)->where('schools.status', 'active')
            ->when($blockId, fn ($w) => $w->where('schools.block_id', $blockId))
            ->when($search, fn ($w) => $w->where(fn ($s) => $s->where('udise_code', 'like', $search.'%')->orWhere('name', 'like', '%'.$search.'%')))
            ->with('cluster:id,code,name')
            ->orderBy('name')->limit(1500);
        $schools = $q->get(['id', 'udise_code', 'name', 'latitude', 'longitude', 'cluster_id', 'block_id']);
        $ids = $schools->pluck('id');

        $planned = InspectionScheduleItem::query()->whereIn('school_id', $ids)->where('schedule_date', $date->toDateString())
            ->when($current, fn ($w) => $w->where('schedule_id', '!=', $current->id))
            ->with('schedule.officer:id,name')->get()->keyBy('school_id');
        $blocked = $this->blockedSchools($officer, $date, $ids);
        $lastDone = Inspection::query()->whereIn('school_id', $ids)->where('status', InspectionStatus::Submitted)
            ->groupBy('school_id')->selectRaw('school_id, max(inspection_date) as last_date')->pluck('last_date', 'school_id');

        return $schools->map(fn (School $s) => [
            'id' => $s->id,
            'udise' => $s->udise_code,
            'name' => $s->name,
            'cluster' => $s->cluster?->label,
            'lat' => $s->latitude,
            'lng' => $s->longitude,
            'last' => isset($lastDone[$s->id]) ? Carbon::parse($lastDone[$s->id])->format('d-m-Y') : null,
            'done_month' => isset($lastDone[$s->id]) && Carbon::parse($lastDone[$s->id])->isSameMonth($date),
            'blocked' => isset($planned[$s->id])
                ? 'Planned for '.($planned[$s->id]->schedule->officer?->name ?? 'another officer').' on this day'
                : ($blocked[$s->id] ?? null),
        ])->values()->all();
    }

    /** The point the day's route starts from: the given GPS, else the centre of the officer's area. */
    public function startPoint(User $officer, ?float $lat = null, ?float $lng = null): ?array
    {
        if ($lat !== null && $lng !== null) {
            return [$lat, $lng];
        }

        return $this->startInfo($officer)['point'];
    }

    /**
     * 23-11-2026 (V14): the default start point and where it comes from. The office location of the officer's
     * cluster, block or district (Location Master latitude / longitude) when it is set; an office without a location
     * is skipped (never shown); otherwise the centre of the officer's schools with GPS; otherwise none.
     *
     * @return array{point: ?array{0: float, 1: float}, source: ?string, label: ?string}
     */
    public function startInfo(User $officer): array
    {
        $jur = $this->j->current($officer);
        $names = ['cluster' => 'Cluster', 'block' => 'Block office', 'district' => 'District office'];
        foreach (['cluster' => [Cluster::class, $jur?->cluster_id], 'block' => [Block::class, $jur?->block_id], 'district' => [District::class, $jur?->district_id]] as $source => [$model, $id]) {
            if ($id && ($a = $model::find($id)) && $a->latitude !== null && $a->longitude !== null) {
                return ['point' => [(float) $a->latitude, (float) $a->longitude], 'source' => $source, 'label' => $names[$source].' · '.$a->label];
            }
        }
        $avg = $this->j->schools($officer)->whereNotNull('latitude')->whereNotNull('longitude')
            ->selectRaw('avg(latitude) as lat, avg(longitude) as lng')->toBase()->first();

        return $avg && $avg->lat !== null
            ? ['point' => [(float) $avg->lat, (float) $avg->lng], 'source' => 'schools', 'label' => 'centre of the schools (office location not set)']
            : ['point' => null, 'source' => null, 'label' => null];
    }

    /* ======================= Saving ======================= */

    /**
     * Create or replace the officer's plan for a day.
     *
     * @param  string  $action  save (draft) | submit (officer: for approval) | publish (higher officer, or no approval needed)
     */
    public function save(User $actor, User $officer, Carbon $date, array $schoolIds, string $action, array $opts = []): InspectionSchedule
    {
        if (! $this->canManage($actor, $officer)) {
            throw ValidationException::withMessages(['officer_id' => 'You cannot plan inspections for this officer.']);
        }
        $this->checkDate($date, $officer);
        $schoolIds = array_values(array_unique(array_map('intval', $schoolIds)));
        $max = (int) config('inspection.schedule.max_per_day', 8);
        if (! $schoolIds) {
            throw ValidationException::withMessages(['school_ids' => 'Add at least one school to the plan.']);
        }
        if (count($schoolIds) > $max) {
            throw ValidationException::withMessages(['school_ids' => "At most $max schools can be planned for one day."]);
        }
        $superior = $this->isSuperior($actor, $officer);

        try {
            return DB::transaction(function () use ($actor, $officer, $date, $schoolIds, $action, $opts, $superior) {
                $schedule = InspectionSchedule::query()->where('officer_id', $officer->id)->where('schedule_date', $date->toDateString())->lockForUpdate()->first();

                if ($schedule && ! $superior && $schedule->status->isOpen() && ! $schedule->status->isEditableByOfficer()
                    && ! ($schedule->status === ScheduleStatus::Published && ! config('inspection.schedule.approval_required'))) {
                    throw ValidationException::withMessages(['school_ids' => 'This plan is '.strtolower($schedule->status->label()).' and can no longer be changed by you.']);
                }
                if ($schedule && $schedule->status === ScheduleStatus::Completed) {
                    throw ValidationException::withMessages(['school_ids' => 'This day\'s plan is already completed.']);
                }

                $schools = $this->validSchools($officer, $date, $schoolIds, $schedule);
                $status = match (true) {
                    $superior, $action === 'publish' && ! config('inspection.schedule.approval_required'),
                    $action === 'submit' && ! config('inspection.schedule.approval_required') => ScheduleStatus::Published,
                    $action === 'submit' => ScheduleStatus::Submitted,
                    default => ScheduleStatus::Draft,
                };
                if ($superior && $action === 'save') {
                    $status = $schedule?->status === ScheduleStatus::Published ? ScheduleStatus::Published : ScheduleStatus::Draft;
                }

                $schedule ??= new InspectionSchedule(['officer_id' => $officer->id, 'schedule_date' => $date->toDateString(), 'created_by' => $actor->id]);
                $wasNew = ! $schedule->exists;
                $schedule->fill([
                    'status' => $status,
                    'notes' => $opts['notes'] ?? $schedule->notes,
                    'start_lat' => $opts['start_lat'] ?? null,
                    'start_lng' => $opts['start_lng'] ?? null,
                    'submitted_at' => $status === ScheduleStatus::Submitted ? now() : $schedule->submitted_at,
                    'published_at' => $status === ScheduleStatus::Published ? ($schedule->published_at ?? now()) : null,
                    'reviewed_by' => $superior && $status === ScheduleStatus::Published ? $actor->id : ($status === ScheduleStatus::Published ? $schedule->reviewed_by : null),
                    'reviewed_at' => $superior && $status === ScheduleStatus::Published ? now() : ($status === ScheduleStatus::Published ? $schedule->reviewed_at : null),
                    'review_note' => $status === ScheduleStatus::Published ? $schedule->review_note : null,
                ])->save();

                $start = $this->startPoint($officer, $opts['start_lat'] ?? null, $opts['start_lng'] ?? null);
                $order = ! empty($opts['optimise'])
                    ? RouteOptimizer::order($start, $schools->mapWithKeys(fn ($s) => [$s->id => $s->hasCoordinates() ? [$s->latitude, $s->longitude] : null])->all())
                    : $schoolIds;
                $this->writeItems($schedule, $schools->keyBy('id'), $order, $start);

                AuditLogger::log($wasNew ? 'schedule_created' : 'schedule_updated', $schedule, [
                    'officer_id' => $officer->id, 'date' => $date->toDateString(), 'status' => $status->value, 'schools' => array_values($order),
                ]);
                if ($status === ScheduleStatus::Submitted) {
                    AuditLogger::log('schedule_submitted', $schedule, ['date' => $date->toDateString()]);
                }
                if ($status === ScheduleStatus::Published && ($wasNew || $schedule->wasChanged('status'))) {
                    AuditLogger::log('schedule_published', $schedule, ['date' => $date->toDateString(), 'by' => $superior ? 'higher_officer' : 'officer']);
                }

                return $schedule->fresh(['items.school', 'officer']);
            });
        } catch (QueryException $e) {
            // the unique key (school, date) caught a plan saved at the same moment by someone else
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), '1062')) {
                throw ValidationException::withMessages(['school_ids' => 'One of the schools was just planned by another officer for this day. Reload and try again.']);
            }
            throw $e;
        }
    }

    public function approve(User $actor, InspectionSchedule $schedule, ?string $note = null): void
    {
        $this->review($actor, $schedule);
        // the schools are checked again: something may have changed since the officer submitted
        DB::transaction(function () use ($actor, $schedule, $note) {
            $this->validSchools($schedule->officer, $schedule->schedule_date, $schedule->items->pluck('school_id')->all(), $schedule);
            $schedule->update(['status' => ScheduleStatus::Published, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note, 'published_at' => now()]);
            AuditLogger::log('schedule_approved', $schedule, ['note' => $note]);
            AuditLogger::log('schedule_published', $schedule, ['date' => $schedule->schedule_date->toDateString(), 'by' => 'approval']);
        });
    }

    public function reject(User $actor, InspectionSchedule $schedule, string $note): void
    {
        $this->review($actor, $schedule);
        $schedule->update(['status' => ScheduleStatus::Rejected, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
        AuditLogger::log('schedule_rejected', $schedule, ['note' => $note]);
    }

    /** Cancel a plan: its schools are freed for that day. */
    public function cancel(User $actor, InspectionSchedule $schedule, string $reason): void
    {
        if (! $this->canManage($actor, $schedule->officer) || ! $schedule->status->isOpen()) {
            throw ValidationException::withMessages(['reason' => 'This plan cannot be cancelled.']);
        }
        if ($schedule->items()->where('status', InspectionScheduleItem::INSPECTED)->exists()) {
            throw ValidationException::withMessages(['reason' => 'Some schools of this plan are already inspected. Mark the others as skipped instead.']);
        }
        DB::transaction(function () use ($schedule, $reason) {
            $schedule->items()->delete();
            $schedule->update(['status' => ScheduleStatus::Cancelled, 'review_note' => $reason, 'total_distance_km' => 0, 'total_minutes' => 0]);
            AuditLogger::log('schedule_cancelled', $schedule, ['reason' => $reason]);
        });
    }

    /** Move a plan to another officer (higher officers only). The schools are checked against the new officer. */
    public function reassign(User $actor, InspectionSchedule $schedule, User $to): InspectionSchedule
    {
        if (! $this->isSuperior($actor, $schedule->officer) || ! $this->isSuperior($actor, $to)) {
            throw ValidationException::withMessages(['officer_id' => 'You can reassign only between officers below you in your area.']);
        }
        if (! $schedule->status->isOpen() || $schedule->items()->where('status', InspectionScheduleItem::INSPECTED)->exists()) {
            throw ValidationException::withMessages(['officer_id' => 'This plan can no longer be reassigned.']);
        }
        if ($to->id === $schedule->officer_id) {
            throw ValidationException::withMessages(['officer_id' => 'Choose a different officer.']);
        }

        return DB::transaction(function () use ($actor, $schedule, $to) {
            $existing = InspectionSchedule::query()->where('officer_id', $to->id)->where('schedule_date', $schedule->schedule_date->toDateString())->lockForUpdate()->first();
            if ($existing && $existing->status !== ScheduleStatus::Cancelled) {
                throw ValidationException::withMessages(['officer_id' => $to->name.' already has a plan for this day.']);
            }
            $this->validSchools($to, $schedule->schedule_date, $schedule->items->pluck('school_id')->all(), $schedule);
            $existing?->delete(); // a cancelled plan of the new officer for the same day (its history stays in the audit log)

            $from = $schedule->officer_id;
            $schedule->update(['officer_id' => $to->id, 'status' => ScheduleStatus::Published, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'published_at' => now(), 'start_lat' => null, 'start_lng' => null]);
            $schedule->setRelation('officer', $to);
            $schools = School::whereIn('id', $schedule->items->pluck('school_id'))->get()->keyBy('id');
            $this->writeItems($schedule, $schools, $schedule->items->pluck('school_id')->all(), $this->startPoint($to));
            AuditLogger::log('schedule_reassigned', $schedule, ['from_officer' => $from, 'to_officer' => $to->id]);

            return $schedule->fresh(['items.school', 'officer']);
        });
    }

    /**
     * V14 item 4: attach (or replace) the order / document and order number of a plan. A higher officer assigning
     * visits to a CRCC attaches the order; the officer may attach one to their own plan. Stored on the private disk.
     */
    public function attachOrder(User $actor, InspectionSchedule $schedule, ?\Illuminate\Http\UploadedFile $file, ?string $ref): void
    {
        $ref = $ref !== null && trim($ref) !== '' ? mb_substr(trim($ref), 0, 60) : null;
        if (! $file && $ref === $schedule->order_ref) {
            return;
        }
        $disk = \Illuminate\Support\Facades\Storage::disk('private');
        $old = $schedule->attachment_path;
        $attrs = ['order_ref' => $ref];
        if ($file) {
            $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
            $attrs += [
                'attachment_path' => $file->storeAs('schedule-orders', \Illuminate\Support\Str::random(40).'.'.$ext, 'private'),
                'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'attachment_mime' => $file->getMimeType(),
            ];
        }
        $schedule->update($attrs);
        if ($file && $old && $old !== $schedule->attachment_path) {
            $disk->delete($old);
        }
        AuditLogger::log('schedule_order_attached', $schedule, ['order_ref' => $ref, 'file' => $attrs['attachment_name'] ?? $schedule->attachment_name]);
    }

    /** Mark a planned school as skipped (by the officer or a higher officer), with a reason. */
    public function skip(User $actor, InspectionScheduleItem $item, string $reason): void
    {
        $schedule = $item->schedule;
        if (! $this->canManage($actor, $schedule->officer) || $schedule->status !== ScheduleStatus::Published || $item->status !== InspectionScheduleItem::PLANNED) {
            throw ValidationException::withMessages(['reason' => 'This visit cannot be skipped.']);
        }
        $item->update(['status' => InspectionScheduleItem::SKIPPED]);
        AuditLogger::log('schedule_item_skipped', $schedule, ['school_id' => $item->school_id, 'reason' => $reason]);
        $this->completeIfDone($schedule);
    }

    /** Called when an inspection is submitted: the matching visit of the officer's published plan that day is done. */
    public function markInspected(Inspection $inspection): void
    {
        $item = InspectionScheduleItem::query()
            ->where('school_id', $inspection->school_id)
            ->where('schedule_date', $inspection->inspection_date->toDateString())
            ->where('status', InspectionScheduleItem::PLANNED)
            ->whereHas('schedule', fn ($q) => $q->where('officer_id', $inspection->officer_id)->where('status', ScheduleStatus::Published))
            ->first();
        if (! $item) {
            return;
        }
        $item->update(['status' => InspectionScheduleItem::INSPECTED, 'inspection_id' => $inspection->id]);
        AuditLogger::log('schedule_item_inspected', $item->schedule, ['school_id' => $item->school_id, 'inspection_id' => $inspection->id]);
        $this->completeIfDone($item->schedule);
    }

    /* ======================= Helpers ======================= */

    private function review(User $actor, InspectionSchedule $schedule): void
    {
        if (! $this->isSuperior($actor, $schedule->officer)) {
            throw ValidationException::withMessages(['note' => 'Only a higher officer of this officer\'s area can review the plan.']);
        }
        if ($schedule->status !== ScheduleStatus::Submitted) {
            throw ValidationException::withMessages(['note' => 'This plan is not waiting for approval.']);
        }
    }

    private function checkDate(Carbon $date, ?User $officer = null): void
    {
        // V14 item 3: no plan on a state holiday or a local holiday of the officer's district
        $calendar = app(HolidayCalendar::class);
        if ($officer && ($name = $calendar->holiday($date, $calendar->districtOf($officer)))) {
            throw ValidationException::withMessages(['date' => $date->format('d M Y')." is a holiday: $name. Pick another date."]);
        }
        if ($date->lt(today())) {
            throw ValidationException::withMessages(['date' => 'A plan cannot be made for a past date.']);
        }
        $ahead = (int) config('inspection.schedule.max_days_ahead', 92);
        if ($date->gt(today()->addDays($ahead))) {
            throw ValidationException::withMessages(['date' => "Plans can be made up to $ahead days ahead."]);
        }
    }

    /** The schools, checked against every rule; throws with one message per problem. */
    private function validSchools(User $officer, Carbon $date, array $ids, ?InspectionSchedule $schedule): Collection
    {
        $schools = $this->j->schools($officer)->whereIn('schools.id', $ids)->get()->keyBy('id');
        $errors = [];
        foreach ($ids as $id) {
            $s = $schools->get($id);
            if (! $s) {
                $name = School::whereKey($id)->value('name') ?? "#$id";
                $errors[] = "$name is outside the officer's jurisdiction.";
            } elseif (! $s->isActive()) {
                $errors[] = "{$s->name} is not an active school.";
            }
        }

        $taken = InspectionScheduleItem::query()->whereIn('school_id', $ids)->where('schedule_date', $date->toDateString())
            ->when($schedule?->exists, fn ($q) => $q->where('schedule_id', '!=', $schedule->id))
            ->with('school:id,name', 'schedule.officer:id,name')->get();
        foreach ($taken as $t) {
            $errors[] = "{$t->school->name} is already planned for {$t->schedule->officer?->name} on {$date->format('d-m-Y')}.";
        }
        foreach ($this->blockedSchools($officer, $date, collect($ids)) as $id => $why) {
            $errors[] = ($schools->get($id)?->name ?? "#$id").': '.$why;
        }

        if ($errors) {
            throw ValidationException::withMessages(['school_ids' => $errors]);
        }

        return $schools;
    }

    /** school_id => reason, for schools another officer is inspecting now or already inspected in the plan's month. */
    private function blockedSchools(User $officer, Carbon $date, Collection $ids): array
    {
        $others = fn (): Builder => Inspection::query()->whereIn('school_id', $ids)->where('officer_id', '!=', $officer->id)->with('officer:id,name');
        $out = [];
        foreach ($others()->where('status', InspectionStatus::Draft)->get() as $i) {
            $out[$i->school_id] = 'being inspected by '.($i->officer?->name ?? 'another officer').'.';
        }
        $month = $others()->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->whereBetween('inspection_date', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()])->get();
        foreach ($month as $i) {
            $out[$i->school_id] ??= 'already inspected by '.($i->officer?->name ?? 'another officer').' on '.$i->inspection_date->format('d-m-Y').' this month.';
        }

        return $out;
    }

    /** Replace the items in the given order, with distances and planned arrival times. */
    private function writeItems(InspectionSchedule $schedule, Collection $schools, array $order, ?array $start): void
    {
        $keep = $schedule->items()->get()->keyBy('school_id'); // keep the visit status of schools that stay on the plan
        $schedule->items()->delete();

        $speed = max(1, (float) config('inspection.schedule.avg_speed_kmh', 25));
        $visit = (int) config('inspection.schedule.visit_minutes', 60);
        $clock = Carbon::parse($schedule->schedule_date->toDateString().' '.config('inspection.schedule.day_start', '09:30'));
        $prev = $start;
        $totalKm = 0;
        $totalMin = 0;

        foreach (array_values($order) as $i => $id) {
            $s = $schools->get($id);
            $here = $s->hasCoordinates() ? [$s->latitude, $s->longitude] : null;
            $km = $prev && $here ? RouteOptimizer::roadKm($prev, $here) : null;
            $min = $km !== null ? (int) ceil($km / $speed * 60) : null;
            $clock->addMinutes($min ?? 0);
            $old = $keep->get($id);
            InspectionScheduleItem::create([
                'schedule_id' => $schedule->id,
                'school_id' => $id,
                'schedule_date' => $schedule->schedule_date->toDateString(),
                'sequence' => $i + 1,
                'distance_km' => $km,
                'travel_minutes' => $min,
                'planned_arrival' => $clock->format('H:i'),
                'status' => $old?->status ?? InspectionScheduleItem::PLANNED,
                'inspection_id' => $old?->inspection_id,
            ]);
            $clock->addMinutes($visit);
            $totalKm += $km ?? 0;
            $totalMin += ($min ?? 0) + $visit;
            $prev = $here ?? $prev;
        }

        $schedule->update(['total_distance_km' => round($totalKm, 2), 'total_minutes' => min($totalMin, 65535)]);
        $schedule->unsetRelation('items');
    }

    private function completeIfDone(InspectionSchedule $schedule): void
    {
        if (! $schedule->items()->where('status', InspectionScheduleItem::PLANNED)->exists()) {
            $schedule->update(['status' => ScheduleStatus::Completed]);
            AuditLogger::log('schedule_completed', $schedule);
        }
    }
}
