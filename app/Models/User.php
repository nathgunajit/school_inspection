<?php

namespace App\Models;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'designation_id', 'employee_id', 'mobile', 'email', 'password', 'level', 'status',
        'id_proof_path', 'id_proof_original_name', 'photo_path', 'failed_login_attempts', 'locked_until',
        'must_change_password', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'id_proof_path', 'photo_path'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'level' => UserLevel::class,
            'status' => UserStatus::class,
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    /* ---------- Relations ---------- */

    public function designation() { return $this->belongsTo(MasterValue::class, 'designation_id'); }

    public function jurisdictions() { return $this->hasMany(UserJurisdiction::class)->latest('start_date'); }

    public function currentJurisdiction()
    {
        return $this->hasOne(UserJurisdiction::class)->whereNull('end_date')->latestOfMany('start_date');
    }

    public function directPermissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')->withPivot('granted_by', 'granted_at');
    }

    public function registrationRequests() { return $this->hasMany(RegistrationRequest::class)->latest(); }

    public function inspections() { return $this->hasMany(Inspection::class, 'officer_id'); }

    /* ---------- Helpers ---------- */

    public function isStateAdmin(): bool
    {
        return $this->level === UserLevel::StateAdmin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasPermission(string $slug): bool
    {
        return app(\App\Services\PermissionService::class)->has($this, $slug);
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))->filter()->take(2)
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    }
}
