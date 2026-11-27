<?php

namespace App\Http\Controllers;

use App\Models\MobileChangeRequest;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // "My activity" summary on the profile (Modification V3)
        $mine = \App\Models\Inspection::where('officer_id', $user->id);
        $done = [\App\Enums\InspectionStatus::Submitted]; // unlocked ones are open again, counted with drafts
        $activity = [
            'drafts' => (clone $mine)->whereIn('status', [\App\Enums\InspectionStatus::Draft, \App\Enums\InspectionStatus::Unlocked])->count(),
            'month' => (clone $mine)->whereIn('status', $done)->whereBetween('inspection_date', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])->count(),
            'total' => (clone $mine)->whereIn('status', $done)->count(),
        ];

        return view('profile', [
            'user' => $user,
            'pendingMobile' => MobileChangeRequest::where('user_id', $user->id)->where('status', 'pending')->first(),
            'activity' => $activity,
        ]);
    }

    /** Upload or replace the officer's own photograph (stored on the private disk). */
    public function updatePhoto(Request $request)
    {
        $cfg = config('inspection.employee_photo');
        $request->validate([
            'photo' => ['required', 'image', 'mimes:'.implode(',', $cfg['mimes']), 'mimetypes:image/jpeg,image/png', 'max:'.$cfg['max_kb'], 'dimensions:min_width=100,min_height=100'],
        ], [
            'photo.required' => 'Please choose a photograph.',
            'photo.max' => 'The photograph is larger than '.\App\Support\FileSize::human((int) $cfg['max_kb']).'. Please choose a photo of '.\App\Support\FileSize::human((int) $cfg['max_kb']).' or less.',
            'photo.image' => 'Only JPG, JPEG or PNG photos are allowed.',
            'photo.mimes' => 'Only JPG, JPEG or PNG photos are allowed.',
            'photo.mimetypes' => 'Only JPG, JPEG or PNG photos are allowed.',
            'photo.dimensions' => 'Photograph must be at least 100 × 100 pixels.',
        ]);

        $user = $request->user();
        $file = $request->file('photo');
        $path = $file->storeAs('employee-photos', Str::random(40).'.'.$file->extension(), 'private');
        $old = $user->photo_path;
        $user->update(['photo_path' => $path]);
        if ($old) {
            Storage::disk('private')->delete($old);
        }
        AuditLogger::log($old ? 'photo_changed' : 'photo_added', $user);

        return back()->with('success', 'Your photo has been updated.');
    }

    public function requestMobileChange(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'new_mobile' => ['required', 'digits:10', 'regex:/^[6-9]\d{9}$/', 'different:current', Rule::unique('users', 'mobile')],
            'reason' => ['required', 'string', 'max:255'],
        ], ['new_mobile.unique' => 'This mobile number is already registered.', 'new_mobile.regex' => 'Mobile number must be 10 digits starting with 6, 7, 8 or 9.']);

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
