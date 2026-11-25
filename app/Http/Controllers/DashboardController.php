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
        $mine = fn () => $user->inspections()->where('status', InspectionStatus::Submitted);

        $stats = [
            'today' => $mine()->where('inspection_date', today()->toDateString())->count(),
            'month' => $mine()->where('inspection_date', '>=', now()->startOfMonth()->toDateString())->count(),
            'total' => $mine()->count(),
            'my_drafts' => $user->inspections()->whereIn('status', [InspectionStatus::Draft, InspectionStatus::Unlocked])->count(),
            // Schools in the officer's own area (cluster / block / district / state), scoped by JurisdictionService
            'schools' => $j->schools($user)->where('schools.status', 'active')->count(),
            'schools_inspected_month' => $j->inspections($user)
                ->where('inspections.status', InspectionStatus::Submitted)
                ->where('inspections.inspection_date', '>=', now()->startOfMonth()->toDateString())
                ->distinct()->count('inspections.school_id'),
        ];

        $drafts = $user->inspections()->with('school')
            ->whereIn('status', [InspectionStatus::Draft, InspectionStatus::Unlocked])
            ->latest('updated_at')->take(3)->get();

        $recent = $user->inspections()->with('school')
            ->where('status', InspectionStatus::Submitted)
            ->latest('submitted_at')->take(5)->get();

        $pending = $user->can('approve_registrations') ? $approvers->pendingCountFor($user) : 0;

        // New Module 2: the officer's published (or completed) plan for today
        $todayPlan = \App\Models\InspectionSchedule::query()->where('officer_id', $user->id)->where('schedule_date', today()->toDateString())
            ->whereIn('status', [\App\Enums\ScheduleStatus::Published, \App\Enums\ScheduleStatus::Completed])->with('items.school:id,name')->first();

        // V14 item 1: the latest notices addressed to the officer (unread first)
        $notices = $user->isStateAdmin() ? collect() : \App\Models\Notice::query()->for($user)
            ->with(['reads' => fn ($r) => $r->where('user_id', $user->id), 'creator:id,name'])
            ->orderByDesc('notice_date')->orderByDesc('id')->take(5)->get()
            ->sortBy(fn ($n) => $n->reads->first()?->read_at ? 1 : 0)->values()->take(3);

        return view('dashboard', compact('user', 'stats', 'drafts', 'recent', 'pending', 'todayPlan', 'notices') + [
            'jurisdiction' => $j->current($user)?->loadMissing('state', 'district', 'block', 'cluster'),
        ]);
    }
}
