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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Officer self-registration (PRD 5). No mobile OTP in Phase 1; identity proof is checked by the approver.
 */
class RegisterController extends Controller
{
    public function show()
    {
        return view('auth.register', [
            'designations' => MasterValue::ofType('designation')->get(),
            'otherDesignationId' => MasterValue::idFor('designation', User::OTHER_DESIGNATION),
            'levels' => UserLevel::registrable(),
            'states' => State::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(RegisterRequest $request)
    {
        $data = $request->validated();
        $level = UserLevel::from($data['level']);
        $existing = $request->reapplyingUser();

        $user = DB::transaction(function () use ($request, $data, $level, $existing) {
            $photo = $request->file('photo');
            $photoPath = $photo->storeAs('employee-photos', Str::random(40).'.'.$photo->extension(), 'private');

            $attrs = [
                'name' => $data['name'],
                'designation_id' => $data['designation_id'],
                'designation_other' => $request->isOtherDesignation() ? trim($data['designation_other']) : null,
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

        AuditLogger::log($existing ? 'registration_reapplied' : 'registration_submitted', $user, ['level' => $level->value], $user);

        return redirect()->route('login')->with('success',
            'Registration submitted successfully. Your request is now Pending Approval. You can sign in once a higher officer approves it.');
    }
}
