<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Services\ApproverResolver;
use App\Services\AreaOfficerGuard;
use App\Services\AuditLogger;
use App\Services\JurisdictionService;
use App\Services\Otp\OtpSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Pending Approvals (PRD 5.3, 6.1). */
class ApprovalController extends Controller
{
    public function __construct(private ApproverResolver $resolver) {}

    public function index(Request $request)
    {
        return view('admin.approvals.index', ['requests' => $this->resolver->pendingFor($request->user())]);
    }

    public function show(Request $request, RegistrationRequest $registration)
    {
        $this->authorizeAct($request->user(), $registration);
        $registration->load(['user.designation', 'user.currentJurisdiction.state', 'user.currentJurisdiction.district', 'user.currentJurisdiction.block', 'user.currentJurisdiction.cluster']);
        $history = RegistrationRequest::where('user_id', $registration->user_id)->whereKeyNot($registration->id)->latest()->get();

        $guard = app(AreaOfficerGuard::class);
        $holder = $guard->holderFor($registration->user);
        $areaTaken = $holder ? $guard->message($holder, $registration->user->currentJurisdiction?->only(['district_id', 'block_id', 'cluster_id']) ?? [], false) : null;

        return view('admin.approvals.show', compact('registration', 'history', 'holder', 'areaTaken'));
    }

    public function approve(Request $request, RegistrationRequest $registration, OtpSender $sms, AreaOfficerGuard $guard)
    {
        $this->authorizeAct($request->user(), $registration);

        // Modification V9: another officer may have been activated for the cluster since this request
        if ($holder = $guard->holderFor($registration->user)) {
            return back()->with('error', $guard->message($holder, $registration->user->currentJurisdiction->only(['district_id', 'block_id', 'cluster_id']), false)
                .' You can reject this request with that reason.');
        }

        DB::transaction(function () use ($request, $registration) {
            $registration->update(['status' => 'approved', 'approver_id' => $request->user()->id, 'decided_at' => now()]);
            $registration->user->update(['status' => UserStatus::Active]);
        });
        $sms->send($registration->user->mobile, 'Your School Inspection account has been approved. You can now sign in.');
        AuditLogger::log('registration_approved', $registration->user, ['request_id' => $registration->id]);

        return redirect()->route('admin.approvals.index')->with('success', $registration->user->name.' has been approved.');
    }

    public function reject(Request $request, RegistrationRequest $registration, OtpSender $sms)
    {
        $this->authorizeAct($request->user(), $registration);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        DB::transaction(function () use ($request, $registration, $data) {
            $registration->update(['status' => 'rejected', 'approver_id' => $request->user()->id, 'reason' => $data['reason'], 'decided_at' => now()]);
            $registration->user->update(['status' => UserStatus::Rejected]);
        });
        $sms->send($registration->user->mobile, 'Your School Inspection registration was not approved: '.$data['reason'].'. You may correct your details and apply again.');
        AuditLogger::log('registration_rejected', $registration->user, ['request_id' => $registration->id, 'reason' => $data['reason']]);

        return redirect()->route('admin.approvals.index')->with('success', 'Registration rejected.');
    }

    /** Stream an identity proof to the officer themselves (My Profile, 27-11-2026), their approver or a user manager. */
    public function idProof(Request $request, User $user, JurisdictionService $j)
    {
        $viewer = $request->user();
        $allowed = $viewer->is($user)
            || $this->resolver->canApprove($viewer, $user)
            || ($viewer->can('manage_users') && $j->contains($viewer, $user->currentJurisdiction()->first()));
        abort_unless($allowed && $user->id_proof_path && Storage::disk('private')->exists($user->id_proof_path), 404);

        if (! $viewer->is($user)) { // 27-11-2026: My Profile shows the proof on every visit; only views by others are audited
            AuditLogger::log('id_proof_viewed', $user);
        }

        return Storage::disk('private')->response($user->id_proof_path, $user->id_proof_original_name, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Stream an employee photograph to the user, their approver or a user manager. */
    public function photo(Request $request, User $user, JurisdictionService $j)
    {
        $viewer = $request->user();
        $allowed = $viewer->is($user)
            || $this->resolver->canApprove($viewer, $user)
            || ($viewer->can('manage_users') && $j->contains($viewer, $user->currentJurisdiction()->first()));
        abort_unless($allowed && $user->photo_path && Storage::disk('private')->exists($user->photo_path), 404);

        return Storage::disk('private')->response($user->photo_path, null, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function authorizeAct(User $approver, RegistrationRequest $registration): void
    {
        abort_unless($registration->status === 'pending' && $this->resolver->canApprove($approver, $registration->user), 403);
    }
}
