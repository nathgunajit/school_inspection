<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use App\Enums\UserLevel;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasEncryptedRouteKey;
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'gender', 'date_of_birth', 'designation_id', 'designation_other', 'employee_id', 'mobile', 'email', 'password', 'level', 'status',
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
            'date_of_birth' => 'date:Y-m-d',
        ];
    }

    /** 27-11-2026: date of birth rules (registration and the admin user page): 18 to 70 years old. */
    public const MIN_AGE = 18;

    public const MAX_AGE = 70;

    public static function dobRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'date_format:Y-m-d',
            'before_or_equal:'.now()->subYears(self::MIN_AGE)->toDateString(),
            'after_or_equal:'.now()->subYears(self::MAX_AGE)->toDateString()];
    }

    public static function dobMessages(): array
    {
        return [
            'date_of_birth.required' => 'Please enter your date of birth.',
            'date_of_birth.date_format' => 'Please enter the date of birth as a date (day, month, year).',
            'date_of_birth.before_or_equal' => 'You must be at least '.self::MIN_AGE.' years old.',
            'date_of_birth.after_or_equal' => 'Please check the year of birth (at most '.self::MAX_AGE.' years ago).',
        ];
    }

    /* ---------- Relations ---------- */

    public function designation() { return $this->belongsTo(MasterValue::class, 'designation_id'); }

    /** Modification V13 item 7: gender, used for the default photo when none is uploaded. */
    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

    /** Public placeholder image for a user without an uploaded photo (null = show initials). */
    public function defaultPhotoUrl(): ?string
    {
        return in_array($this->gender, ['male', 'female'], true) ? asset('img/avatar-'.$this->gender.'.svg') : null;
    }

    /** Master value code of the "Other" designation (Modification V7). */
    public const OTHER_DESIGNATION = 'other';

    /** 27-11-2026: emails are unique; stored trimmed and lower case, an empty one as null. */
    public function setEmailAttribute($value): void
    {
        $value = is_string($value) ? mb_strtolower(trim($value)) : $value;
        $this->attributes['email'] = $value === '' ? null : $value;
    }

    /** The designation to show:the typed text when "Other" was chosen, else the master label. */
    public function getDesignationLabelAttribute(): ?string
    {
        if ($this->designation?->code === self::OTHER_DESIGNATION && filled($this->designation_other)) {
            return $this->designation_other;
        }

        return $this->designation?->label;
    }

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
