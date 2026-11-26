<?php

namespace App\Http\Controllers;

use App\Models\DailyDisplaySetting;
use App\Models\DailySchoolList;
use App\Models\District;
use App\Models\Inspection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DailySchoolListService;
use App\Services\HolidayCalendar;
use App\Services\JurisdictionService;
use App\Services\ScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * V14 item 5 (25-11-2026): "Today's schools" for the CRCC, the manual assignment screen for higher officers
 * and the admin settings (State Admin: state default; District officer with Manage users: own district override).
 */
class TodaySchoolsController extends Controller
{
    public function __construct(private DailySchoolListService $lists, private ScheduleService $schedules, private HolidayCalendar $calendar,
        private JurisdictionService $j) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $date = $request->date('date') ?? today();
        // V15: up to a week ahead, so a list made for the next working day (next-day notice) can be opened
        $date = $date->gt(today()->addDays(7)) || $date->lt(today()->subDays(30)) ? today() : $date->startOfDay();
        $list = $this->lists->forOfficer($user, $date);
        $start = $this->schedules->startInfo($user);
        $setting = $this->lists->settingFor($user);
        $next = $this->calendar->nextWorkingDay(today(), $this->calendar->districtOf($user), $setting->weekly_off);

        return view('today.index', [
            'date' => $date, 'list' => $list, 'start' => $start,
            'drafts' => $this->lists->openDrafts($user, $list['rows']->where('status', 'pending')->pluck('school_id')),
            'distance' => fn ($s) => $start['point'] && $s->latitude !== null && $s->longitude !== null
                ? round(Inspection::haversine($start['point'][0], $start['point'][1], (float) $s->latitude, (float) $s->longitude) / 1000, 1) : null,
            'canAssign' => $this->subordinates($user)->isNotEmpty(),
            'setting' => $setting,
            'marker' => $this->lists->dayMarker($user, $date->toDateString()),
            'nextDay' => $next && $this->lists->dayMarker($user, $next->toDateString()) ? $next : null,
        ]);
    }

    public function skip(Request $request, DailySchoolList $row)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], ['reason.required' => 'Write why the school was not visited.']);
        $this->lists->skip($request->user(), $row, $data['reason']);

        return back()->with('success', 'Marked as not visited.');
    }

    /** Higher officers: pick an officer and a day, then tick schools. */
    public function assign(Request $request)
    {
        $user = $request->user();
        $officers = $this->subordinates($user);
        abort_if($officers->isEmpty(), 403);
        $officer = $officers->firstWhere('id', $request->integer('officer')) ?? null;
        $date = $request->date('date') ?? today();
        $date = $date->lt(today()) ? today() : $date->startOfDay();

        $candidates = $officer ? collect($this->schedules->candidateSchools($officer, $date, null, $request->query('q'))) : collect();
        $rows = $officer ? DailySchoolList::where('officer_id', $officer->id)->where('list_date', $date->toDateString())
            ->with('school:id,name,udise_code', 'assigner:id,name')->orderBy('position')->get() : collect();
        $taken = $officer ? DailySchoolList::whereIn('school_id', $candidates->pluck('id'))->where('list_date', $date->toDateString())
            ->where('officer_id', '!=', $officer->id)->with('officer:id,name')->get()->keyBy('school_id') : collect();

        return view('today.assign', [
            'officers' => $officers, 'officer' => $officer, 'date' => $date, 'candidates' => $candidates, 'rows' => $rows, 'taken' => $taken,
            'off' => $officer ? $this->calendar->offReason($date, $this->calendar->districtOf($officer), $this->lists->settingFor($officer)->weekly_off) : null,
            'marker' => $officer ? $this->lists->dayMarker($officer, $date->toDateString()) : null,
            'drafts' => $officer ? $this->lists->openDrafts($officer, $rows->where('status', 'pending')->pluck('school_id')) : collect(),
        ]);
    }

    /** V15 item 9: swap a pending school on an officer's list for another one. */
    public function replace(Request $request, DailySchoolList $row)
    {
        $data = $request->validate(['school_id' => ['required', 'integer']], ['school_id.required' => 'Pick the school to put in its place.']);
        $new = $this->lists->replace($request->user(), $row, (int) $data['school_id']);

        return back()->with('success', 'School replaced: '.($new->school?->name ?? 'the new school').' is on the list now.');
    }

    /** V15 item 11: the State Administrator allows changes to a list locked by a started inspection. */
    public function unlock(Request $request)
    {
        $data = $request->validate([
            'officer_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'unlock_reason' => ['required', 'string', 'min:5', 'max:200'],
        ], ['unlock_reason.required' => 'Write why the list must be changed after the inspection started.',
            'unlock_reason.min' => 'Write a reason of at least 5 characters.']);
        $officer = $this->subordinates($request->user())->firstWhere('id', (int) $data['officer_id']);
        abort_unless($officer, 404);
        $this->lists->unlock($request->user(), $officer, $data['date'], $data['unlock_reason']);

        return back()->with('success', "Changes allowed on {$officer->name}'s list. It locks again when the next inspection starts.");
    }

    public function storeAssign(Request $request)
    {
        $data = $request->validate([
            'officer_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'school_ids' => ['required', 'array', 'min:1', 'max:20'],
            'school_ids.*' => ['integer'],
        ], ['school_ids.required' => 'Tick at least one school.']);
        $officer = $this->subordinates($request->user())->firstWhere('id', (int) $data['officer_id']);
        abort_unless($officer, 403);
        $added = $this->lists->assign($request->user(), $officer, Carbon::parse($data['date']), $data['school_ids']);

        return redirect()->route('today.assign', ['officer' => $officer->id, 'date' => $data['date']])
            ->with('success', "Assigned $added school(s) to {$officer->name} for ".Carbon::parse($data['date'])->format('d M Y').'.');
    }

    public function remove(Request $request, DailySchoolList $row)
    {
        $this->lists->remove($request->user(), $row);

        return back()->with('success', 'Removed from the list.');
    }

    /* ----------------------------- settings ----------------------------- */

    public function settings(Request $request)
    {
        $user = $request->user();
        $isState = $user->isStateAdmin();
        $district = $isState ? null : District::find($this->calendar->districtOf($user));
        abort_unless($isState || $district, 403);

        return view('today.settings', [
            'isState' => $isState, 'district' => $district,
            'state' => DailyDisplaySetting::state()->load('updater:id,name'),
            'own' => $district ? DailyDisplaySetting::where('district_id', $district->id)->with('updater:id,name')->first() : null,
            'overrides' => $isState ? DailyDisplaySetting::whereNotNull('district_id')->with('district:id,name,code', 'updater:id,name')->get() : collect(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $user = $request->user();
        $districtId = $user->isStateAdmin() ? ($request->input('scope') === 'district' ? (int) $request->input('district_id') : null)
            : $this->calendar->districtOf($user);
        abort_if(! $user->isStateAdmin() && ! $districtId, 403);
        if ($districtId && ! District::whereKey($districtId)->exists()) {
            abort(404);
        }

        if ($districtId && $request->boolean('reset')) {
            DailyDisplaySetting::where('district_id', $districtId)->delete();
            AuditLogger::log('daily_settings_reset', null, ['district_id' => $districtId]);
            \App\Jobs\RefreshDailyLists::dispatch($districtId);

            return back()->with('success', 'This district now follows the state default.');
        }

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['string'], // limited below: a district chooses only Cluster / Block
            'daily_limit' => ['required', 'integer', 'min:1', 'max:10'],
            'selection' => ['required', Rule::in(array_keys(DailyDisplaySetting::SELECTIONS))],
            'low_rating_below' => ['required', 'integer', 'min:1', 'max:100'],
            'recent_days' => ['required', 'integer', 'min:0', 'max:365'],
            'weekly_off' => ['nullable', 'array'],
            'weekly_off.*' => ['integer', 'between:0,6'],
            'max_distance_km' => ['nullable', 'integer', 'min:1', 'max:500'],
            'skip_window' => ['nullable', Rule::in(array_keys(DailyDisplaySetting::SKIP_WINDOWS))],
        ], ['daily_limit.max' => 'At most 10 schools a day.', 'skip_window.*' => 'Pick the period for skipping schools taken by another officer: same day, this week or this month.']);
        foreach (['enabled', 'prefer_not_inspected', 'prefer_low_rating', 'prefer_issues', 'prefer_less_visited', 'carry_forward', 'skip_blocked'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        // 25-11-2026: the state default sets every level; a district (whoever saves it) only its Cluster / Block officers,
        // District / State officers there follow the state default (DailyDisplaySetting::forDistrict())
        $allowed = $districtId ? DailyDisplaySetting::DISTRICT_LEVELS : ['cluster', 'block', 'district', 'state'];
        $data['levels'] = array_values(array_intersect($allowed, $data['levels'] ?? []));
        $data['weekly_off'] = array_values(array_map('intval', $data['weekly_off'] ?? []));
        $data['max_distance_km'] = $data['max_distance_km'] ?? null;
        if (empty($data['skip_window'])) { unset($data['skip_window']); } // not sent: keep the stored period (new rows: month)
        // V15: Skip and the notices are set once, by the State Administrator, for everyone
        if (! $districtId && $request->boolean('_alerts')) { // only the state form carries these (hidden _alerts)
            $data += $request->validate([
                'notify_time' => ['required', 'date_format:H:i'],
                'reminder_time' => ['required', 'date_format:H:i'],
            ], ['notify_time.date_format' => 'Enter the notice time as HH:MM, for example 18:00.', 'reminder_time.date_format' => 'Enter the reminder time as HH:MM, for example 14:00.']);
            foreach (['allow_skip', 'notify_enabled', 'reminder_enabled'] as $flag) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $row = DailyDisplaySetting::updateOrCreate(['district_id' => $districtId], $data + ['updated_by' => $user->id]);
        AuditLogger::log('daily_settings_updated', $row, ['district_id' => $districtId] + $data);
        \App\Jobs\RefreshDailyLists::dispatch($districtId); // V15 item 8: every list made already follows the change at once

        return back()->with('success', "Today's schools settings saved".($districtId ? ' for the district' : '').'. Lists already made for today and the next day follow at once (schools already visited, skipped, started or assigned stay; a list locked by a started inspection is not changed).');
    }

    /** Active officers below the user in their area (not the user). */
    private function subordinates(User $user)
    {
        return $this->schedules->officersFor($user)->where('id', '!=', $user->id)->values();
    }
}
