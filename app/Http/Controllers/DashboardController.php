<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Services\ApproverResolver;
use App\Services\JurisdictionService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, JurisdictionService $j, ApproverResolver $approvers)
    {
        $user = $request->user();
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $submitted = fn () => $j->inspections($user)->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked]);

        $stats = [
            'schools' => $j->schools($user)->where('status', 'active')->count(),
            'inspected' => $submitted()->whereDate('inspection_date', '>=', $from)->whereDate('inspection_date', '<=', $to)->distinct('school_id')->count('school_id'),
            'submitted' => $submitted()->whereDate('inspection_date', '>=', $from)->whereDate('inspection_date', '<=', $to)->count(),
            'my_drafts' => $user->inspections()->whereIn('status', [InspectionStatus::Draft, InspectionStatus::Unlocked])->count(),
            'my_total' => $user->inspections()->where('status', InspectionStatus::Submitted)->count(),
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
