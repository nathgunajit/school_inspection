<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileChangeRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Mobile number change approval by State Admin (PRD 6.3). */
class MobileRequestController extends Controller
{
    public function index()
    {
        return view('admin.mobile-requests', [
            'requests' => MobileChangeRequest::with('user.designation')->latest()->paginate(config('inspection.per_page')),
        ]);
    }

    public function decide(Request $request, MobileChangeRequest $mobileRequest)
    {
        abort_unless($mobileRequest->status === 'pending', 422);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);

        if ($data['decision'] === 'approved' && User::where('mobile', $mobileRequest->new_mobile)->exists()) {
            return back()->with('error', 'That mobile number is already registered to another account.');
        }

        DB::transaction(function () use ($request, $mobileRequest, $data) {
            $mobileRequest->update(['status' => $data['decision'], 'decided_by' => $request->user()->id, 'decided_at' => now()]);
            if ($data['decision'] === 'approved') {
                $mobileRequest->user->update(['mobile' => $mobileRequest->new_mobile]);
            }
        });
        AuditLogger::log('mobile_change_'.$data['decision'], $mobileRequest->user, ['old' => $mobileRequest->old_mobile, 'new' => $mobileRequest->new_mobile]);

        return back()->with('success', 'Mobile change request '.$data['decision'].'.');
    }
}
