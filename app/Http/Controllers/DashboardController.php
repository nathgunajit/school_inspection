<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Services\ApproverResolver;
use App\Services\JurisdictionService;
use Illuminate\Http\Request;

/** Compact inspector dashboard: daily actions and a short summary (Modification V1). */
class DashboardController extends Controller
{
    public function __invoke(Request $request, JurisdictionService $j, ApproverResolver $approvers)
    {
        $user = $request->user();
        $stats = app(\App\Services\DashboardStats::class)->for($user); // cached a minute, refreshed on any change

        $drafts = $user->inspections()->with('school')
            ->whereIn('status', [InspectionStatus::Draft, InspectionStatus::Unlocked])
            ->latest('updated_at')->take(3)->get();

        $recent = $user->inspections()->with('school')
            ->where('status', InspectionStatus::Submitted)
            ->latest('submitted_at')->take(5)->get();

        $pending = $user->can('approve_registrations') ? $approvers->pendingCountFor($user) : 0;

        // New Module 2: the officer's published (or completed) plan for today
        $todayPlan = \App\Models\InspectionSchedule::query()->where('officer_id', $user->id)->where('schedule_date', today()->toDateString())
            ->whereIn('status', [\App\Enums\ScheduleStatus::Published, \App\Enums\ScheduleStatus::Completed])->with('items.school:id,name', 'creator:id,name')->first();

        // V14 item 1: the latest notices addressed to the officer (unread first); the State Admin sees the latest sent ones
        $noticesSent = $user->isStateAdmin();
        $notices = $noticesSent
            ? \App\Models\Notice::query()->with('creator:id,name')->withCount(['reads as read_count' => fn ($r) => $r->whereNotNull('read_at')])
                ->orderByDesc('notice_date')->orderByDesc('id')->take(4)->get()
            : \App\Models\Notice::query()->for($user)
                ->with(['reads' => fn ($r) => $r->where('user_id', $user->id), 'creator:id,name'])
                ->orderByDesc('notice_date')->orderByDesc('id')->take(6)->get()
                ->sortBy(fn ($n) => $n->reads->first()?->read_at ? 1 : 0)->values()->take(4);
        $noticeUnread = app(\App\Services\NoticeService::class)->unreadCount($user); // cached 60 s

        // 25-11-2026: "Latest notices" news ticker at the top: only the newest live notice (published, not expired)
        $ticker = ($noticesSent
            ? \App\Models\Notice::query()->where('status', 'published')
                ->where(fn ($w) => $w->whereNull('expires_on')->orWhere('expires_on', '>=', today()->toDateString()))
            : \App\Models\Notice::query()->for($user)->with(['reads' => fn ($r) => $r->where('user_id', $user->id)]))
            ->where('show_on_home', true) // ticked "Show in Latest Notice on the homepage"
            // 25-11-2026: the newest pinned Latest Notification first, whatever its date; else the newest ticked one
            ->with('creator:id,name')->orderByRaw('pinned_at is null')->orderByDesc('pinned_at')->orderByDesc('notice_date')->orderByDesc('id')->take(1)->get();

        // 25-11-2026: Calendar & Notices, this month's holidays (all types) and the next ones
        $holidays = app(\App\Services\HolidayCalendar::class);
        $holidayDistrict = $holidays->districtOf($user);
        $calendar = [
            'month' => today()->startOfMonth(),
            'byDate' => $holidays->between(today()->startOfMonth(), today()->endOfMonth(), $holidayDistrict)->groupBy('date'),
            'weeklyOff' => $holidays->weeklyOff(app(\App\Services\DailySchoolListService::class)->settingFor($user)->weekly_off),
            // 25-11-2026: day popup (see a date's holidays; holiday managers add / remove there)
            'service' => $holidays,
            'canManage' => $user->can('manage_holidays'),
            'editableDistricts' => $user->can('manage_holidays') ? $holidays->editableDistricts($user) : collect(),
        ];

        // V14 item 5: Today's schools (generated on the first view of the day)
        $daily = app(\App\Services\DailySchoolListService::class);
        $todaySchools = $daily->appliesTo($user) ? $daily->forOfficer($user) : null;
        $todayDrafts = $todaySchools ? $daily->openDrafts($user, $todaySchools['rows']->where('status', 'pending')->pluck('school_id')) : collect(); // 25-11-2026

        return view('dashboard', compact('user', 'stats', 'drafts', 'recent', 'pending', 'todayPlan', 'notices', 'noticesSent', 'noticeUnread', 'ticker', 'todaySchools', 'todayDrafts', 'calendar') + [
            'jurisdiction' => $j->current($user)?->loadMissing('state', 'district', 'block', 'cluster'),
        ]);
    }
}
