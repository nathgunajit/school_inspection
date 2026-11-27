<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\MasterValue;
use App\Models\RegistrationRequest;
use App\Models\State;
use App\Models\User;
use App\Models\UserJurisdiction;
use App\Services\AuditLogger;
use App\Services\RegisterUploadStash;
use App\Support\DesignationMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Officer self-registration (PRD 5). No mobile OTP in Phase 1; identity proof is checked by the approver.
 */
class RegisterController extends Controller
{
    public function show(RegisterUploadStash $stash)
    {
        return view('auth.register', [
            'designations' => MasterValue::ofType('designation')->get(),
            'otherDesignationId' => MasterValue::idFor('designation', User::OTHER_DESIGNATION),
            'kept' => ['photo' => $stash->get('photo'), 'id_proof' => $stash->get('id_proof')],
            'levels' => UserLevel::registrable(),
            'states' => State::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Preview of a file kept from the last attempt (Modification V7). Only the file named in this visitor's
     * own session can be served, so nobody else can reach it.
     */
    public function keptUpload(string $field, RegisterUploadStash $stash)
    {
        $kept = $stash->get($field);
        abort_unless($kept, 404);

        return Storage::disk('private')->response($kept['path'], $kept['name'], [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(RegisterRequest $request, RegisterUploadStash $stash)
    {
        $data = $request->validated();
        $level = UserLevel::from($data['level']);
        $existing = $request->reapplyingUser();

        $user = DB::transaction(function () use ($request, $data, $level, $existing, $stash) {
            // A new file wins; otherwise the file kept from the last attempt (Modification V7)
            if ($photo = $request->file('photo')) {
                $photoPath = $photo->storeAs('employee-photos', Str::random(40).'.'.$photo->extension(), 'private');
            } else {
                $kept = $stash->claim('photo');
                abort_unless($kept, 422, 'Please choose your photograph again.');
                [$photoPath] = $kept;
            }

            // 27-11-2026: a typed "Other" designation joins the master list (an existing one is reused)
            $designationId = $request->isOtherDesignation()
                ? DesignationMaster::resolve($data['designation_other'])[0]
                : $data['designation_id'];

            $attrs = [
                'name' => $data['name'],
                'gender' => $data['gender'],
                'date_of_birth' => $data['date_of_birth'],
                'designation_id' => $designationId,
                'designation_other' => null,
                'employee_id' => $data['employee_id'] ?? null,
                'mobile' => $data['mobile'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'level' => $level,
                'status' => UserStatus::Pending,
                'photo_path' => $photoPath,
            ];

            // Identity proof is optional (Modification V7); a re-application keeps the old one unless a new file is sent
            if ($file = $request->file('id_proof')) {
                $attrs['id_proof_path'] = $file->storeAs('id-proofs', Str::random(40).'.'.$file->extension(), 'private');
                $attrs['id_proof_original_name'] = mb_substr($file->getClientOriginalName(), 0, 255);
            } elseif ($stash->wanted($request, 'id_proof') && ($kept = $stash->claim('id_proof'))) {
                [$attrs['id_proof_path'], $attrs['id_proof_original_name']] = $kept;
            } elseif (! $existing) {
                $attrs['id_proof_path'] = null;
                $attrs['id_proof_original_name'] = null;
            }

            if ($existing) {
                // Re-application after rejection (PRD 5.3 step 8)
                if ($existing->id_proof_path && isset($attrs['id_proof_path'])) {
                    Storage::disk('private')->delete($existing->id_proof_path);
                }
                if ($existing->photo_path) {
                    Storage::disk('private')->delete($existing->photo_path);
                }
                $existing->update($attrs);
                $existing->jurisdictions()->whereNull('end_date')->update(['end_date' => now()->toDateString()]);
                $user = $existing;
            } else {
                $user = User::create($attrs);
            }

            UserJurisdiction::create([
                'user_id' => $user->id,
                'state_id' => $data['state_id'],
                'district_id' => $level->needsDistrict() ? $data['district_id'] : null,
                'block_id' => $level->needsBlock() ? $data['block_id'] : null,
                'cluster_id' => $level->needsCluster() ? $data['cluster_id'] : null,
                'start_date' => now()->toDateString(),
            ]);

            RegistrationRequest::create(['user_id' => $user->id, 'status' => 'pending']);

            return $user;
        });

        $stash->clear(); // anything still kept (e.g. a removed ID proof) is no longer needed

        AuditLogger::log($existing ? 'registration_reapplied' : 'registration_submitted', $user, ['level' => $level->value], $user);

        return redirect()->route('login')->with('success',
            'Registration submitted successfully. Your request is now Pending Approval. You can sign in once a higher officer approves it.');
    }
}
