<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Models\DailyDisplaySetting;
use App\Models\DailySchoolList;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\InspectionScheduleItem;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V14 item 5 (25-11-2026): "Today's schools", the schools a CRCC is asked to visit on a working day.
 *
 * The list is generated once, on the officer's first view of the day (no cron needed), and then stays the same all day
 * unless the settings change (26-11-2026: then the next view refreshes it, keeping what the officer already acted on):
 *   1. schools a higher officer assigned for the day (manual),
 *   2. pending schools from earlier days (carry forward),
 *   3. automatic picks up to the daily limit, from the eligible schools, by priority (never inspected, low rating,
 *      open issues, not visited for long) or at random. Since 26-11-2026 the carried schools are extra: they no longer
 *      use up the limit (2 pending + limit 2 = 4 schools), only the assigned ones do.
 * Nothing is shown on a holiday or a weekly off day, or when the list is off for the officer's level / district.
 *
 * Modification V15 (26-11-2026):
 *  - skipped schools are carried to the next working day too (once, `carried_to`), listed first like pending ones;
 *  - the next working day's list can be made ahead (prepare(), by `today:notify` at the notice time); it is brought
 *    up to date on the officer's first view that day (stamp ':pre' makes generate() run refresh());
 *  - once the officer starts an inspection that day the list is locked (lockOnStart()): no refresh, assign, remove
 *    or replace until the State Administrator allows changes with a reason (unlock()); the next start locks it again;
 *  - Skip can be switched off for everyone (allow_skip, State only).
 */
class DailySchoolListService
{
    public const CARRY_DAYS = 30; // pending schools older than this are not carried forward any more

    public function __construct(private JurisdictionService $j, private HolidayCalendar $calendar, private ScheduleService $schedules) {}

    public function settingFor(User $officer): DailyDisplaySetting
    {
        return DailyDisplaySetting::forDistrict($this->calendar->districtOf($officer));
    }

    public function appliesTo(User $officer, ?DailyDisplaySetting $setting = null): bool
    {
        $setting ??= $this->settingFor($officer);

        return $setting->enabled && in_array($officer->level->value, $setting->levels ?? [], true) && $officer->can('conduct_inspections');
    }

    /**
     * The officer's list for a day: ['state' => 'list' | 'off' | 'disabled' | 'future', 'reason' => ?string, 'rows' => Collection].
     */
    public function forOfficer(User $officer, ?Carbon $date = null): array
    {
        $date = ($date ?? today())->copy()->startOfDay();
        $setting = $this->settingFor($officer);
        $rows = fn () => DailySchoolList::query()->where('officer_id', $officer->id)->where('list_date', $date->toDateString())
            ->with(['school:id,name,udise_code,latitude,longitude,cluster_id', 'school.cluster:id,name,code', 'inspection:id,inspection_no', 'assigner:id,name'])
            ->orderByRaw("case status when 'pending' then 0 when 'visited' then 1 else 2 end")->orderBy('position')->get();

        if (! $this->appliesTo($officer, $setting)) {
            return ['state' => 'disabled', 'reason' => null, 'rows' => $rows()];
        }
        if ($off = $this->calendar->offReason($date, $this->calendar->districtOf($officer), $setting->weekly_off)) {
            return ['state' => 'off', 'reason' => $off, 'rows' => $rows()];
        }
        if ($date->gt(today())) { // V15: a list made ahead (next-day notice) can be looked at, not acted on
            return ['state' => $this->dayMarker($officer, $date->toDateString()) ? 'upcoming' : 'future', 'reason' => null, 'rows' => $rows()];
        }
        $refreshed = $date->isToday() && $this->generate($officer, $date, $setting);

        return ['state' => 'list', 'reason' => null, 'rows' => $rows(), 'refreshed' => $refreshed];
    }

    /**
     * Build the day's list once (the day row is the lock and the "already generated" marker). 26-11-2026: the marker
     * keeps the settings stamp; when the settings changed since, the list is refreshed (refresh()). Returns true when
     * this call changed an existing list.
     */
    public function generate(User $officer, Carbon $date, DailyDisplaySetting $setting, bool $ahead = false): bool
    {
        $day = $date->toDateString();
        $stamp = $setting->listStamp().($ahead ? ':pre' : '');
        $marker = fn () => DB::table('daily_school_list_days')->where('officer_id', $officer->id)->where('list_date', $day);

        $existing = $marker()->first(['setting_stamp', 'locked_at', 'unlocked_at']);
        if ($existing && ($existing->setting_stamp === $stamp || $ahead)) {
            return false; // already generated with these settings (or, ahead of the day, already made)
        }

        return DB::transaction(function () use ($officer, $date, $day, $setting, $stamp, $marker, $existing, $ahead) {
            if ($existing) {
                $current = $marker()->lockForUpdate()->value('setting_stamp'); // another request may have refreshed it meanwhile
                if ($current === $stamp) {
                    return false;
                }
                if ($existing->locked_at && ! $existing->unlocked_at) {
                    return false; // V15 item 10: an inspection has started, so no automatic reset; the change waits for an unlock
                }
                $marker()->update(['setting_stamp' => $stamp, 'updated_at' => now()]);

                return $current !== null && $this->refresh($officer, $date, $setting); // null: made before the stamp, kept as it is
            }
            $inserted = DB::table('daily_school_list_days')->insertOrIgnore(['officer_id' => $officer->id, 'list_date' => $day, 'setting_stamp' => $stamp,
                'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                return false; // another request generated it just now
            }
            $rows = DailySchoolList::where('officer_id', $officer->id)->where('list_date', $day)->get(['school_id', 'source']); // manual rows
            $taken = $rows->pluck('school_id')->all();
            $position = count($taken);
            // 26-11-2026: only the assigned rows use the daily limit; carried schools are extra
            $manual = $rows->where('source', 'manual')->count();

            if ($setting->carry_forward) {
                $this->carry($officer, $date, $taken, $position, ! $ahead); // ahead: copy only, today's rows can still be acted on
            }

            $room = max(0, $setting->daily_limit - $manual); // carried ones are still excluded from the pick ($taken)
            if ($room > 0) {
                foreach ($this->pick($officer, $date, $setting, $taken, $room) as [$schoolId, $reason]) {
                    $this->claim($officer, $day, $schoolId, ++$position, $reason, 'auto');
                }
            }
            AuditLogger::log('daily_list_generated', null, ['officer_id' => $officer->id, 'date' => $day, 'schools' => $position] + ($ahead ? ['ahead' => true] : []), $officer);

            return false;
        });
    }

    /**
     * 26-11-2026: the settings changed after today's list was made, so bring it in line, touching only what the officer
     * has not acted on. Assigned, visited, skipped and draft rows stay; carried rows stay while the pending list is on.
     * The automatic schools are picked again for the new limit / priorities; today's ones are preferred, so a higher
     * limit only adds and a lower one keeps the best. Returns true when the list changed.
     */
    private function refresh(User $officer, Carbon $date, DailyDisplaySetting $setting): bool
    {
        $day = $date->toDateString();
        $rows = DailySchoolList::where('officer_id', $officer->id)->where('list_date', $day)->get();
        $drafts = $this->openDrafts($officer, $rows->where('status', 'pending')->pluck('school_id'));
        $free = fn ($r) => $r->status === 'pending' && ! $drafts->has($r->school_id); // nothing done on it yet
        $added = $removed = 0;
        $position = (int) $rows->max('position');

        if (! $setting->carry_forward) { // pending list switched off: today's untouched carried rows go back to their earlier day
            foreach ($rows->where('source', 'carried')->filter($free) as $r) {
                $earlier = DailySchoolList::where('officer_id', $officer->id)->where('school_id', $r->school_id)->where('list_date', '<', $day)
                    ->where('status', 'carried')->orderByDesc('list_date')->value('id');
                if ($earlier) {
                    DailySchoolList::whereKey($earlier)->update(['status' => 'pending', 'updated_at' => now()]);
                }
                DailySchoolList::where('officer_id', $officer->id)->where('school_id', $r->school_id)->where('carried_to', $day) // V15: a skipped one
                    ->update(['carried_to' => null, 'updated_at' => now()]);
                $r->delete();
                $removed++;
            }
            $rows = $rows->reject(fn ($r) => $r->source === 'carried' && $free($r));
        }

        $freeAuto = $rows->where('source', 'auto')->filter($free);
        $taken = $rows->pluck('school_id')->all();
        if ($setting->carry_forward) {
            $added += $this->carry($officer, $date, $taken, $position);
        }

        $kept = $rows->where('source', 'manual')->count() + $rows->where('source', 'auto')->count() - $freeAuto->count();
        $room = max(0, $setting->daily_limit - $kept);
        $exclude = array_values(array_diff($taken, $freeAuto->pluck('school_id')->all()));
        $picks = $room > 0 ? $this->pick($officer, $date, $setting, $exclude, $room, $freeAuto->pluck('school_id')->all()) : [];
        $picked = array_column($picks, 0);

        foreach ($freeAuto as $r) {
            if (! in_array($r->school_id, $picked, true)) {
                $r->delete();
                $removed++;
            }
        }
        $have = $freeAuto->pluck('school_id')->all();
        foreach ($picks as [$schoolId, $reason]) {
            if (! in_array($schoolId, $have, true) && $this->claim($officer, $day, $schoolId, ++$position, $reason, 'auto')) {
                $added++;
            }
        }
        if ($added || $removed) {
            AuditLogger::log('daily_list_refreshed', null, ['officer_id' => $officer->id, 'date' => $day, 'added' => $added, 'removed' => $removed], $officer);
        }

        return $added > 0 || $removed > 0;
    }

    /**
     * Carry the officer's pending schools of earlier days (within CARRY_DAYS) onto $date: the earlier row becomes
     * 'carried' and a 'carried' row is added unless the school is already on the day's list or was inspected since.
     * Returns the number of rows added; $taken and $position are updated.
     */
    private function carry(User $officer, Carbon $date, array &$taken, int &$position, bool $markEarlier = true): int
    {
        $day = $date->toDateString();
        // V15 item 3: skipped schools move on too, once (carried_to), and keep their status and reason on the earlier day
        $pending = DailySchoolList::where('officer_id', $officer->id)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'skipped')->whereNull('carried_to')))
            ->where('list_date', '<', $day)->where('list_date', '>=', $date->copy()->subDays(self::CARRY_DAYS)->toDateString())
            ->orderBy('list_date')->orderBy('position')->get();
        $doneSince = $this->inspectedSince($pending->pluck('school_id'), $date->copy()->subDays(self::CARRY_DAYS), $officer);
        $added = 0;
        foreach ($pending as $p) {
            $skipped = $p->status === 'skipped';
            if ($markEarlier) {
                $p->update($skipped ? ['carried_to' => $day] : ['status' => 'carried']);
            }
            if (in_array($p->school_id, $taken, true) || isset($doneSince[$p->school_id])) {
                if ($skipped) { // made ahead while still pending, skipped since: say so on the carried row
                    DailySchoolList::where('officer_id', $officer->id)->where('list_date', $day)->where('school_id', $p->school_id)
                        ->where('reason', 'carried')->update(['reason' => 'carried_skipped', 'updated_at' => now()]);
                }
                continue;
            }
            if ($this->claim($officer, $day, $p->school_id, ++$position, $skipped ? 'carried_skipped' : 'carried', 'carried', null, $p->carried_from ?? $p->list_date)) {
                $taken[] = $p->school_id;
                $added++;
            }
        }

        return $added;
    }

    /**
     * 26-11-2026 repair: a day made before the fix may hold only carried schools (they used up the limit). Drop the
     * "generated" marker of those days, so the next view adds the day's new schools; the rows already there stay.
     * Returns the number of reopened days.
     */
    public static function reopenCarriedOnlyDays(string $day): int
    {
        $officers = DB::table('daily_school_lists')->where('list_date', $day)->groupBy('officer_id')
            ->havingRaw("sum(case when source = 'carried' then 1 else 0 end) > 0")
            ->havingRaw("sum(case when source = 'auto' then 1 else 0 end) = 0")
            ->pluck('officer_id');

        return $officers->isEmpty() ? 0
            : DB::table('daily_school_list_days')->where('list_date', $day)->whereIn('officer_id', $officers)->delete();
    }

    /**
     * The automatic picks: [[school_id, reason], ...], best first.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    public function pick(User $officer, Carbon $date, DailyDisplaySetting $setting, array $exclude, int $limit, array $prefer = []): array
    {
        $prefer = array_flip($prefer); // 26-11-2026 (refresh): today's schools win a tie, so a settings change moves as few as it can
        $schools = $this->j->schools($officer)->where('schools.status', 'active')->whereNotIn('schools.id', $exclude ?: [0])
            ->get(['schools.id', 'schools.latitude', 'schools.longitude'])->keyBy('id');
        if ($schools->isEmpty()) {
            return [];
        }
        $ids = $schools->keys();
        $day = $date->toDateString();

        // on another officer's list or plan today
        $busy = DailySchoolList::whereIn('school_id', $ids)->where('list_date', $day)->where('officer_id', '!=', $officer->id)->pluck('school_id') // own rows: $exclude
            ->merge(InspectionScheduleItem::whereIn('school_id', $ids)->where('schedule_date', $day)
                ->whereHas('schedule', fn ($s) => $s->where('officer_id', '!=', $officer->id))->pluck('school_id'))->flip();
        $recent = $setting->recent_days > 0 ? $this->inspectedSince($ids, $date->copy()->subDays($setting->recent_days), $officer) : [];
        $blocked = $setting->skip_blocked ? $this->schedules->blockedSchools($officer, $date, $ids, $setting->skip_window ?: 'month') : [];
        $start = $setting->max_distance_km ? $this->schedules->startInfo($officer)['point'] : null;

        $eligible = $schools->filter(function ($s) use ($busy, $recent, $blocked, $start, $setting) {
            if (isset($busy[$s->id]) || isset($recent[$s->id]) || isset($blocked[$s->id])) {
                return false;
            }
            if ($start) { // no GPS: cannot be measured, so it stays in
                return $s->latitude === null || $s->longitude === null
                    || Inspection::haversine($start[0], $start[1], (float) $s->latitude, (float) $s->longitude) / 1000 <= $setting->max_distance_km;
            }

            return true;
        });
        if ($eligible->isEmpty()) {
            return [];
        }
        $eids = $eligible->keys();

        if ($setting->selection === 'random') {
            return $eids->shuffle()->sortBy(fn ($id) => isset($prefer[$id]) ? 0 : 1)->take($limit)->map(fn ($id) => [(int) $id, 'random'])->values()->all();
        }

        // 25-11-2026: only the inspections this officer may see (own + lower levels) count
        $visits = $this->visits($eids, $officer);
        $ratings = $setting->prefer_low_rating ? $this->latestRatings($eids, $officer) : [];
        $issues = $setting->prefer_issues ? $this->openIssues($eids, $officer) : [];

        $scored = $eids->map(function ($id) use ($visits, $ratings, $issues, $setting, $date, $prefer) {
            $parts = [];
            $v = $visits[$id] ?? null;
            if ($setting->prefer_not_inspected && ! $v) {
                $parts['not_inspected'] = 100;
            }
            if ($setting->prefer_low_rating && isset($ratings[$id]) && $ratings[$id] < $setting->low_rating_below) {
                $parts['low_rating'] = 40 + ($setting->low_rating_below - $ratings[$id]);
            }
            if ($setting->prefer_issues && isset($issues[$id])) {
                $parts['issue'] = 50 + 5 * min(5, $issues[$id]);
            }
            if ($setting->prefer_less_visited && $v) {
                $parts['less_visited'] = min(365, Carbon::parse($v['last'])->diffInDays($date)) / 365 * 60;
            }
            arsort($parts);

            return ['id' => (int) $id, 'score' => array_sum($parts) + (isset($prefer[$id]) ? 1 : 0) + mt_rand(0, 999) / 1000, 'reason' => array_key_first($parts) ?? 'random'];
        })->sortByDesc('score')->take($limit);

        return $scored->map(fn ($s) => [$s['id'], $s['reason']])->values()->all();
    }

    /** 25-11-2026: the officer's own open drafts for these schools, keyed by school_id (the row shows "Draft in progress" + Continue). */
    public function openDrafts(User $officer, Collection $schoolIds): Collection
    {
        return $schoolIds->isEmpty() ? collect() : Inspection::where('officer_id', $officer->id)->where('status', InspectionStatus::Draft)
            ->whereIn('school_id', $schoolIds->unique()->values())->latest('updated_at')
            ->get(['id', 'school_id', 'completion_pct', 'updated_at'])->unique('school_id')->keyBy('school_id');
    }

    /** Submitting an inspection marks that school as visited on the officer's list (today's row, or a pending earlier one). */
    public function markVisited(Inspection $inspection): void
    {
        DailySchoolList::where('officer_id', $inspection->officer_id)->where('school_id', $inspection->school_id)
            ->where('status', 'pending')->where('list_date', '<=', $inspection->inspection_date->toDateString())
            ->update(['status' => 'visited', 'inspection_id' => $inspection->id, 'updated_at' => now()]);
        // V15: a later list made ahead (next-day notice) no longer needs this school; that day's first view fills the gap
        DailySchoolList::where('officer_id', $inspection->officer_id)->where('school_id', $inspection->school_id)->where('status', 'pending')
            ->where('list_date', '>', $inspection->inspection_date->toDateString())->whereIn('source', ['auto', 'carried'])->delete();
    }

    public function skip(User $actor, DailySchoolList $row, string $reason): void
    {
        if ($row->officer_id !== $actor->id && ! $this->schedules->isSuperior($actor, $row->officer)) {
            abort(403);
        }
        if (! $this->settingFor($row->officer)->allow_skip) { // V15 item 1
            throw ValidationException::withMessages(['reason' => 'Skipping is turned off by the State Administrator. Visit the school, or ask your higher officer to change the list.']);
        }
        if ($row->status !== 'pending') {
            throw ValidationException::withMessages(['reason' => 'This school is no longer pending.']);
        }
        if ($row->list_date > today()->toDateString()) {
            throw ValidationException::withMessages(['reason' => 'A school can be marked as not visited only on its day.']);
        }
        $row->update(['status' => 'skipped', 'skip_reason' => mb_substr(trim($reason), 0, 200)]);
        AuditLogger::log('daily_list_skipped', $row, ['school_id' => $row->school_id, 'date' => $row->list_date, 'reason' => $row->skip_reason]);
    }

    /**
     * A higher officer assigns schools to an officer's list for a day (today or later, a working day).
     *
     * @return int how many schools were added
     */
    public function assign(User $actor, User $officer, Carbon $date, array $schoolIds): int
    {
        if (! $this->schedules->isSuperior($actor, $officer)) {
            throw ValidationException::withMessages(['officer_id' => 'You can assign schools only to officers below you in your area.']);
        }
        if ($date->lt(today())) {
            throw ValidationException::withMessages(['date' => 'Pick today or a later date.']);
        }
        $setting = $this->settingFor($officer);
        if ($off = $this->calendar->offReason($date, $this->calendar->districtOf($officer), $setting->weekly_off)) {
            throw ValidationException::withMessages(['date' => $date->format('d M Y').' is not a working day ('.$off.').']);
        }
        $this->ensureUnlocked($officer, $date->toDateString());
        $schoolIds = array_values(array_unique(array_map('intval', $schoolIds)));
        $valid = $this->j->schools($officer)->where('schools.status', 'active')->whereIn('schools.id', $schoolIds)->pluck('schools.id')->all();
        if (count($valid) !== count($schoolIds) || ! $schoolIds) {
            throw ValidationException::withMessages(['school_ids' => 'Pick active schools in the officer\'s area.']);
        }
        $day = $date->toDateString();
        $taken = DailySchoolList::whereIn('school_id', $schoolIds)->where('list_date', $day)->with('officer:id,name')->get()->keyBy('school_id');
        $added = 0;
        DB::transaction(function () use ($actor, $officer, $day, $schoolIds, $taken, &$added) {
            $position = (int) DailySchoolList::where('officer_id', $officer->id)->where('list_date', $day)->max('position');
            foreach ($schoolIds as $id) {
                if ($taken->has($id)) {
                    continue;
                }
                if ($this->claim($officer, $day, $id, ++$position, 'manual', 'manual', $actor->id)) {
                    $added++;
                }
            }
        });
        $skipped = $taken->filter(fn ($r) => $r->officer_id !== $officer->id)->count();
        AuditLogger::log('daily_list_assigned', null, ['officer_id' => $officer->id, 'date' => $day, 'schools' => $schoolIds, 'added' => $added, 'taken_by_others' => $skipped]);
        if (! $added && $skipped) {
            throw ValidationException::withMessages(['school_ids' => 'These schools are already on another officer\'s list for that day.']);
        }
        if ($added) {
            $this->tell($officer, $day, $added.' school'.($added === 1 ? '' : 's').' assigned to your list', $actor);
        }

        return $added;
    }

    /** V15 item 9: a higher officer removes any pending school (assigned, automatic or carried) before its inspection starts. */
    public function remove(User $actor, DailySchoolList $row): void
    {
        $this->ensureChangeable($actor, $row);
        AuditLogger::log('daily_list_removed', $row, ['officer_id' => $row->officer_id, 'school_id' => $row->school_id, 'date' => $row->list_date, 'source' => $row->source]);
        $row->delete();
        $this->tell($row->officer, $row->list_date, ($row->school?->name ?? 'A school').' removed from your list', $actor);
    }

    /** V15 item 9: swap a pending school for another one of the officer's area (added as assigned, same place in the list). */
    public function replace(User $actor, DailySchoolList $row, int $schoolId): DailySchoolList
    {
        $this->ensureChangeable($actor, $row);
        $officer = $row->officer;
        $school = $this->j->schools($officer)->where('schools.status', 'active')->where('schools.id', $schoolId)->first(['schools.id', 'schools.name']);
        if (! $school) {
            throw ValidationException::withMessages(['school_id' => 'Pick an active school in the officer\'s area.']);
        }
        if ($other = DailySchoolList::where('school_id', $schoolId)->where('list_date', $row->list_date)->with('officer:id,name')->first()) {
            throw ValidationException::withMessages(['school_id' => $other->officer_id === $officer->id ? 'This school is already on the list.'
                : 'This school is on '.$other->officer?->name.'\'s list that day. Pick another school.']);
        }
        $old = $row->school?->name;
        $new = DB::transaction(function () use ($actor, $row, $officer, $schoolId) {
            $row->delete();
            $this->claim($officer, $row->list_date, $schoolId, $row->position, 'manual', 'manual', $actor->id);

            return DailySchoolList::where('officer_id', $officer->id)->where('list_date', $row->list_date)->where('school_id', $schoolId)->firstOrFail();
        });
        AuditLogger::log('daily_list_replaced', $new, ['officer_id' => $officer->id, 'date' => $row->list_date, 'from_school_id' => $row->school_id, 'to_school_id' => $schoolId]);
        $this->tell($officer, $row->list_date, ($old ?? 'A school').' replaced by '.$school->name, $actor);

        return $new;
    }

    /* ----------------------------- V15: lock ----------------------------- */

    /** Starting an inspection locks that day's list of the officer (items 10–11). Called after a new draft is created. */
    public function lockOnStart(Inspection $inspection): void
    {
        DB::table('daily_school_list_days')->where('officer_id', $inspection->officer_id)->where('list_date', $inspection->inspection_date->toDateString())
            ->update(['locked_at' => now(), 'unlocked_at' => null, 'unlocked_by' => null, 'unlock_reason' => null, 'updated_at' => now()]);
    }

    /** The day's marker row (setting stamp, notice / reminder sent, lock), or null when no list was made. */
    public function dayMarker(User|int $officer, string $day): ?object
    {
        return DB::table('daily_school_list_days')->where('officer_id', $officer instanceof User ? $officer->id : $officer)->where('list_date', $day)->first();
    }

    public function isLocked(User|int $officer, string $day): bool
    {
        $m = $this->dayMarker($officer, $day);

        return $m && $m->locked_at && ! $m->unlocked_at;
    }

    /** Item 11: only the State Administrator allows changes to a locked list, with a reason. */
    public function unlock(User $admin, User $officer, string $day, string $reason): void
    {
        abort_unless($admin->isStateAdmin(), 403);
        if (! $this->isLocked($officer, $day)) {
            throw ValidationException::withMessages(['unlock_reason' => 'This list is not locked.']);
        }
        $reason = trim($reason);
        DB::table('daily_school_list_days')->where('officer_id', $officer->id)->where('list_date', $day)
            ->update(['unlocked_at' => now(), 'unlocked_by' => $admin->id, 'unlock_reason' => mb_substr($reason, 0, 200), 'updated_at' => now()]);
        AuditLogger::log('daily_list_unlocked', null, ['officer_id' => $officer->id, 'date' => $day, 'reason' => $reason]);
        $this->tell($officer, $day, 'Changes to your list were allowed: '.$reason, $admin);
    }

    private function ensureUnlocked(User $officer, string $day): void
    {
        if ($this->isLocked($officer, $day)) {
            throw ValidationException::withMessages(['locked' => 'This list is locked because an inspection has started. Ask the State Administrator to allow changes.']);
        }
    }

    private function ensureChangeable(User $actor, DailySchoolList $row): void
    {
        if (! $this->schedules->isSuperior($actor, $row->officer) || $row->status !== 'pending') {
            abort(403);
        }
        $this->ensureUnlocked($row->officer, $row->list_date);
        if ($this->openDrafts($row->officer, collect([$row->school_id]))->isNotEmpty()) {
            throw ValidationException::withMessages(['locked' => 'The officer has already started the inspection of this school, so it stays on the list.']);
        }
    }

    /** An in-app alert to the officer about a change made by someone else. */
    private function tell(User $officer, string $day, string $what, User $by): void
    {
        if ($officer->id !== $by->id) {
            $officer->notify(new \App\Notifications\TodayListChanged($day, $what, $by->name));
        }
    }

    /* ----------------------------- V15: ahead of the day ----------------------------- */

    /**
     * Make the officer's list for a later working day now (next-day notice). Returns the rows, or null when the list
     * is off for the officer or the day is not a working day.
     */
    public function prepare(User $officer, \Carbon\CarbonInterface $date): ?Collection
    {
        $date = Carbon::parse($date->toDateString());
        $setting = $this->settingFor($officer);
        if (! $this->appliesTo($officer, $setting) || $this->calendar->offReason($date, $this->calendar->districtOf($officer), $setting->weekly_off)) {
            return null;
        }
        $this->generate($officer, $date, $setting, ahead: $date->gt(today()));

        return DailySchoolList::where('officer_id', $officer->id)->where('list_date', $date->toDateString())->with('school:id,name')->orderBy('position')->get();
    }

    /**
     * Settings changed (item 8): a list made ahead is made again with the new settings. Assigned rows stay; the
     * untouched automatic and carried rows are dropped and picked again. The 'notice sent' mark is kept.
     */
    public function rebuildAhead(User $officer, \Carbon\CarbonInterface $date): void
    {
        $day = $date->toDateString();
        $marker = $this->dayMarker($officer, $day);
        DB::transaction(function () use ($officer, $day) {
            DailySchoolList::where('officer_id', $officer->id)->where('list_date', $day)->where('status', 'pending')->whereIn('source', ['auto', 'carried'])->delete();
            DB::table('daily_school_list_days')->where('officer_id', $officer->id)->where('list_date', $day)->delete();
        });
        if ($this->prepare($officer, $date) !== null && $marker?->notified_at) {
            DB::table('daily_school_list_days')->where('officer_id', $officer->id)->where('list_date', $day)->update(['notified_at' => $marker->notified_at]);
        }
    }

    /* ----------------------------- helpers ----------------------------- */

    /** Insert a row unless the school is already on a list that day (unique key). */
    private function claim(User $officer, string $day, int $schoolId, int $position, string $reason, string $source, ?int $by = null, ?string $carriedFrom = null): bool
    {
        return DB::table('daily_school_lists')->insertOrIgnore([
            'officer_id' => $officer->id, 'list_date' => $day, 'school_id' => $schoolId, 'position' => $position, 'reason' => $reason,
            'source' => $source, 'status' => 'pending', 'carried_from' => $carriedFrom, 'assigned_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]) > 0;
    }

    /** school_id => true for schools with a submitted / unlocked inspection since $since. */
    private function inspectedSince(Collection $ids, Carbon $since, ?User $viewer = null): array
    {
        return $ids->isEmpty() ? [] : \App\Support\InspectionVisibility::apply(Inspection::query(), $viewer)->whereIn('school_id', $ids)->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->where('inspection_date', '>=', $since->toDateString())->distinct()->pluck('school_id')->flip()->map(fn () => true)->all();
    }

    /** school_id => ['count' => n, 'last' => Y-m-d] over submitted / unlocked inspections. */
    private function visits(Collection $ids, ?User $viewer = null): array
    {
        return \App\Support\InspectionVisibility::apply(Inspection::query(), $viewer)->whereIn('school_id', $ids)->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->groupBy('school_id')->selectRaw('school_id, count(*) as n, max(inspection_date) as last')->get()
            ->mapWithKeys(fn ($r) => [$r->school_id => ['count' => (int) $r->n, 'last' => substr((string) $r->last, 0, 10)]])->all();
    }

    /** school_id => score % of the latest rated inspection. */
    private function latestRatings(Collection $ids, ?User $viewer = null): array
    {
        return \App\Support\InspectionVisibility::apply(DB::table('inspections'), $viewer)->join('inspection_ratings', 'inspection_ratings.inspection_id', '=', 'inspections.id')
            ->whereIn('inspections.school_id', $ids)->whereIn('inspections.status', [InspectionStatus::Submitted->value, InspectionStatus::Unlocked->value])
            ->whereNotNull('inspection_ratings.score_percent')
            ->orderByDesc('inspections.inspection_date')->orderByDesc('inspections.id')
            ->get(['inspections.school_id', 'inspection_ratings.score_percent'])
            ->unique('school_id')->mapWithKeys(fn ($r) => [$r->school_id => (float) $r->score_percent])->all();
    }

    /** school_id => number of issue answers (config inspection.daily_list.issue_answers) on the latest submitted inspection. */
    private function openIssues(Collection $ids, ?User $viewer = null): array
    {
        $rules = config('inspection.daily_list.issue_answers', []);
        if (! $rules) {
            return [];
        }
        $latest = \App\Support\InspectionVisibility::apply(Inspection::query(), $viewer)->whereIn('school_id', $ids)->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->orderByDesc('inspection_date')->orderByDesc('id')->get(['id', 'school_id'])->unique('school_id')->pluck('school_id', 'id');
        if ($latest->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (InspectionAnswer::whereIn('inspection_id', $latest->keys())->whereIn('question_code', array_keys($rules))->get(['inspection_id', 'question_code', 'value']) as $a) {
            if (in_array($a->value, $rules[$a->question_code], true)) {
                $school = $latest[$a->inspection_id];
                $out[$school] = ($out[$school] ?? 0) + 1;
            }
        }

        return $out;
    }
}
