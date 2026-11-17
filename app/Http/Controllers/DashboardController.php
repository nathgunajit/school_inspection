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
            'today' => $mine()->whereDate('inspection_date', today()->toDateString())->count(),
            'month' => $mine()->whereDate('inspection_date', '>=', now()->startOfMonth()->toDateString())->count(),
            'total' => $mine()->count(),
            'my_drafts' => $user->inspections()->whereIn('status', [InspectionStatus::Draft, InspectionStatus::Unlocked])->count(),
        ];

        $drafts = $user->inspections()->with('school')
            ->whereIn('status', [InspectionStatus::Draft, InspectionStatus::Unlocked])
            ->latest('updated_at')->take(3)->get();

        $recent = $user->inspections()->with('school')
            ->where('status', InspectionStatus::Submitted)
            ->latest('submitted_at')->take(5)->get();

        $pending = $user->can('approve_registrations') ? $approvers->pendingFor($user)->count() : 0;

        return view('dashboard', compact('user', 'stats', 'drafts', 'recent', 'pending') + [
            'jurisdiction' => $j->current($user)?->loadMissing('state', 'district', 'block', 'cluster'),
        ]);
    }
}
