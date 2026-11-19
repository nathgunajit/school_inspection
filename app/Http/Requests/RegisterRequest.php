<?php

namespace App\Http\Requests;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    private ?User $reapplying = null;
    private bool $resolved = false;

    public function authorize(): bool
    {
        return true;
    }

    /** A rejected officer re-applying with the same mobile number. */
    public function reapplyingUser(): ?User
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $this->reapplying = User::where('mobile', $this->input('mobile'))
                ->where('status', UserStatus::Rejected->value)->first();
        }

        return $this->reapplying;
    }

    public function rules(): array
    {
        $ignore = $this->reapplyingUser()?->id;
        $level = UserLevel::tryFrom((string) $this->input('level'));
        $cfg = config('inspection.id_proof');

        $rules = [
            'name' => ['required', 'string', 'min:3', 'max:120', "regex:/^[\pL][\pL\pM .'\-]*$/u"],
            'designation_id' => ['required', Rule::exists('master_values', 'id')->where('type', 'designation')->where('is_active', true)],
            // Modification V7: Employee ID and identity proof are optional
            'employee_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/\-_.]+$/', Rule::unique('users', 'employee_id')->ignore($ignore)],
            'id_proof' => ['nullable', 'file', 'mimes:'.implode(',', $cfg['mimes']), 'max:'.$cfg['max_kb']],
            'photo' => ['required', 'image', 'mimes:'.implode(',', config('inspection.employee_photo.mimes')), 'max:'.config('inspection.employee_photo.max_kb'), 'dimensions:min_width=100,min_height=100'],
            'level' => ['required', new Enum(UserLevel::class), Rule::in(array_map(fn ($l) => $l->value, UserLevel::registrable()))],
            'state_id' => ['required', Rule::exists('states', 'id')->where('is_active', true)],
            'district_id' => [$level?->needsDistrict() ? 'required' : 'nullable', 'integer'],
            'block_id' => [$level?->needsBlock() ? 'required' : 'nullable', 'integer'],
            'cluster_id' => [$level?->needsCluster() ? 'required' : 'nullable', 'integer'],
            'mobile' => ['required', 'digits:10', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'mobile')->ignore($ignore)],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'password' => ['required', 'confirmed', 'max:64', Password::min(8)->mixedCase()->numbers()->symbols()],
            'privacy' => ['accepted'],
        ];
        if (config('inspection.captcha_enabled')) {
            $rules['captcha'] = ['required', 'captcha'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Mobile number must be 10 digits starting with 6, 7, 8 or 9.',
            'mobile.unique' => 'This mobile number is already registered.',
            'employee_id.unique' => 'This Employee ID is already registered.',
            'id_proof.max' => 'Identity proof must be 2 MB or smaller.',
            'photo.required' => 'Please upload your photograph.',
            'photo.max' => 'Photograph must be 1 MB or smaller.',
            'photo.dimensions' => 'Photograph must be at least 100 × 100 pixels.',
            'captcha.captcha' => 'The CAPTCHA code is incorrect.',
            'privacy.accepted' => 'Please accept the privacy notice.',
            'name.regex' => 'Name may contain only letters, spaces, dots, apostrophes and hyphens.',
            'employee_id.regex' => 'Employee ID may contain only letters, digits and / - _ .',
        ];
    }

    /** Server re-checks the location hierarchy (PRD 5.2). */
    public function after(): array
    {
        return [function (Validator $v) {
            $level = UserLevel::tryFrom((string) $this->input('level'));
            if (! $level || $v->errors()->isNotEmpty()) {
                return;
            }
            if ($level->needsDistrict()) {
                $d = District::where('is_active', true)->find($this->input('district_id'));
                if (! $d || $d->state_id != $this->input('state_id')) {
                    $v->errors()->add('district_id', 'Select a district of the selected state.');

                    return;
                }
            }
            if ($level->needsBlock()) {
                $b = Block::where('is_active', true)->find($this->input('block_id'));
                if (! $b || $b->district_id != $this->input('district_id')) {
                    $v->errors()->add('block_id', 'Select a block of the selected district.');

                    return;
                }
            }
            if ($level->needsCluster()) {
                $c = Cluster::where('is_active', true)->find($this->input('cluster_id'));
                if (! $c || $c->block_id != $this->input('block_id')) {
                    $v->errors()->add('cluster_id', 'Select a cluster of the selected block.');
                }
            }
        }];
    }
}
