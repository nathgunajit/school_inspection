<?php

namespace App\Http\Controllers;

use App\Models\MobileChangeRequest;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Own profile. Level and jurisdiction are read-only for the user (PRD 6.3).
 * Mobile number change needs State Admin approval (no OTP in Phase 1).
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load(['designation', 'jurisdictions.state', 'jurisdictions.district', 'jurisdictions.block', 'jurisdictions.cluster', 'directPermissions']);

        return view('profile', [
            'user' => $user,
            'pendingMobile' => MobileChangeRequest::where('user_id', $user->id)->where('status', 'pending')->first(),
        ]);
    }

    public function requestMobileChange(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'new_mobile' => ['required', 'digits:10', 'regex:/^[6-9]\d{9}$/', 'different:current', Rule::unique('users', 'mobile')],
            'reason' => ['required', 'string', 'max:255'],
        ], ['new_mobile.unique' => 'This mobile number is already registered.']);

        if (MobileChangeRequest::where('user_id', $user->id)->where('status', 'pending')->exists()) {
            return back()->with('warning', 'You already have a pending mobile change request.');
        }

        $req = MobileChangeRequest::create([
            'user_id' => $user->id,
            'old_mobile' => $user->mobile,
            'new_mobile' => $data['new_mobile'],
            'reason' => $data['reason'],
        ]);
        AuditLogger::log('mobile_change_requested', $req, ['new_mobile' => $data['new_mobile']]);

        return back()->with('success', 'Mobile number change request sent to the State Administrator.');
    }
}
