<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\MasterValue;
use App\Models\Permission;
use App\Models\State;
use App\Models\User;
use App\Models\UserJurisdiction;
use App\Services\AuditLogger;
use App\Services\JurisdictionService;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** User management (PRD 6.1). */
class UserController extends Controller
{
    public function __construct(private JurisdictionService $j) {}

    public function index(Request $request)
    {
        $f = $request->only(['q', 'level', 'status', 'district_id', 'block_id']);

        $users = $this->j->users($request->user())
            ->with(['designation', 'currentJurisdiction.district', 'currentJurisdiction.block', 'currentJurisdiction.cluster', 'currentJurisdiction.state'])
            ->where('level', '!=', UserLevel::StateAdmin->value)
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('mobile', 'like', "%$s%")->orWhere('employee_id', 'like', "%$s%")))
            ->when($f['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->whereHas('currentJurisdiction', fn ($w) => $w->where('district_id', $v)))
            ->when($f['block_id'] ?? null, fn ($q, $v) => $q->whereHas('currentJurisdiction', fn ($w) => $w->where('block_id', $v)))
            ->orderBy('name')
            ->paginate(config('inspection.per_page'))->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'filters' => $f,
            'districts' => District::orderBy('name')->get(),
            // Block filter: blocks of the chosen district, or of the manager's own district
            'blocks' => ($districtId = ($f['district_id'] ?? null) ?: $this->j->current($request->user())?->district_id)
                ? Block::where('district_id', $districtId)->orderBy('name')->get()
                : collect(),
            'levels' => UserLevel::registrable(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function show(Request $request, User $user)
    {
        $this->authorizeManage($request->user(), $user);
        $user->load(['designation', 'directPermissions', 'jurisdictions.state', 'jurisdictions.district', 'jurisdictions.block', 'jurisdictions.cluster', 'registrationRequests.approver']);

        return view('admin.users.show', [
            'user' => $user,
            'designations' => MasterValue::ofType('designation')->get(),
            'states' => State::orderBy('name')->get(),
            'grantable' => Permission::whereIn('slug', PermissionService::grantableFor($user->level))->get(),
            'inspectionCount' => $user->inspections()->count(),
        ]);
    }

    /** Edit name, designation, employee ID: State Admin only. */
    public function update(Request $request, User $user)
    {
        $this->authorizeAdmin($request->user(), $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'designation_id' => ['required', Rule::exists('master_values', 'id')->where('type', 'designation')],
            'employee_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/\-_.]+$/', Rule::unique('users', 'employee_id')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:150'],
        ]);
        $before = $user->only(array_keys($data));
        $user->update($data);
        AuditLogger::log('user_updated', $user, ['before' => $before, 'after' => $data]);

        return back()->with('success', 'User details updated.');
    }

    /** Activate / deactivate: State Admin, or District if permitted. */
    public function toggleStatus(Request $request, User $user)
    {
        $this->authorizeManage($request->user(), $user);
        abort_if($user->id === $request->user()->id, 403, 'You cannot change your own status.');
        abort_unless(in_array($user->status, [UserStatus::Active, UserStatus::Inactive, UserStatus::Locked], true), 422);

        $new = $user->status === UserStatus::Inactive ? UserStatus::Active : UserStatus::Inactive;
        $user->update(['status' => $new, 'failed_login_attempts' => 0, 'locked_until' => null]);
        AuditLogger::log($new === UserStatus::Active ? 'user_activated' : 'user_deactivated', $user);

        return back()->with('success', $user->name.' is now '.$new->label().'.');
    }

    /** Transfer: end the old jurisdiction and assign a new one from a date (PRD 6.1). */
    public function transfer(Request $request, User $user)
    {
        $this->authorizeAdmin($request->user(), $user);
        $level = $user->level;
        $data = $request->validate([
            'state_id' => ['required', 'exists:states,id'],
            'district_id' => [$level->needsDistrict() ? 'required' : 'nullable', 'nullable', 'exists:districts,id'],
            'block_id' => [$level->needsBlock() ? 'required' : 'nullable', 'nullable', 'exists:blocks,id'],
            'cluster_id' => [$level->needsCluster() ? 'required' : 'nullable', 'nullable', 'exists:clusters,id'],
            'effective_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        // Hierarchy consistency
        if ($level->needsDistrict() && District::find($data['district_id'])?->state_id != $data['state_id']
            || $level->needsBlock() && Block::find($data['block_id'])?->district_id != $data['district_id']
            || $level->needsCluster() && Cluster::find($data['cluster_id'])?->block_id != $data['block_id']) {
            return back()->withErrors(['district_id' => 'The selected locations do not belong together.']);
        }

        DB::transaction(function () use ($request, $user, $level, $data) {
            $old = $user->currentJurisdiction()->first();
            $old?->update(['end_date' => \Carbon\Carbon::parse($data['effective_date'])->subDay()->max($old->start_date)]);
            UserJurisdiction::create([
                'user_id' => $user->id,
                'state_id' => $data['state_id'],
                'district_id' => $level->needsDistrict() ? $data['district_id'] : null,
                'block_id' => $level->needsBlock() ? $data['block_id'] : null,
                'cluster_id' => $level->needsCluster() ? $data['cluster_id'] : null,
                'start_date' => $data['effective_date'],
                'assigned_by' => $request->user()->id,
            ]);
            AuditLogger::log('user_transferred', $user, ['from' => $old?->only(['district_id', 'block_id', 'cluster_id']), 'to' => $data]);
        });

        return back()->with('success', 'Jurisdiction changed. Past inspections remain with the old jurisdiction.');
    }

    /** Admin reset: temporary password, forced change at next login. */
    public function resetPassword(Request $request, User $user)
    {
        $this->authorizeAdmin($request->user(), $user);
        $temp = 'Si@'.Str::upper(Str::random(3)).random_int(1000, 9999).Str::lower(Str::random(2));
        $user->forceFill([
            'password' => $temp, 'must_change_password' => true,
            'failed_login_attempts' => 0, 'locked_until' => null,
            'status' => $user->status === UserStatus::Locked ? UserStatus::Active : $user->status,
        ])->save();
        AuditLogger::log('password_reset_by_admin', $user);

        return back()->with('success', "Temporary password for {$user->name}: {$temp}. Share it securely; the user must change it at next sign-in.");
    }

    /** Grant "If permitted" rights (PRD 3.2). */
    public function permissions(Request $request, User $user)
    {
        $this->authorizeAdmin($request->user(), $user);
        $allowed = PermissionService::grantableFor($user->level);
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => [Rule::in($allowed)]]);

        $ids = Permission::whereIn('slug', $data['permissions'] ?? [])->pluck('id');
        $user->directPermissions()->sync($ids->mapWithKeys(fn ($id) => [$id => ['granted_by' => $request->user()->id, 'granted_at' => now()]])->all());
        AuditLogger::log('permissions_changed', $user, ['permissions' => $data['permissions'] ?? []]);

        return back()->with('success', 'Permissions updated.');
    }

    private function authorizeManage(User $actor, User $target): void
    {
        abort_unless($actor->can('manage_users') && ! $target->isStateAdmin()
            && $this->j->contains($actor, $target->currentJurisdiction()->first() ?? new UserJurisdiction), 403);
    }

    private function authorizeAdmin(User $actor, User $target): void
    {
        abort_unless($actor->isStateAdmin() && ! $target->isStateAdmin(), 403);
    }
}
