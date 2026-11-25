<?php

namespace App\Models;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Concerns\HasEncryptedRouteKey;
use App\Services\JurisdictionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * V14 item 1: an Instruction, Notification or Order letter. It reaches the active officers of the chosen
 * levels whose current posting lies inside the notice's area (district / block / cluster; null = the whole state).
 */
class Notice extends Model
{
    use HasEncryptedRouteKey;

    public const TYPES = ['instruction' => 'Instruction', 'notification' => 'Notification', 'order' => 'Order'];

    public const TYPE_ICONS = ['instruction' => 'bi-list-check', 'notification' => 'bi-megaphone', 'order' => 'bi-file-earmark-text'];

    protected $fillable = ['type', 'ref_no', 'title', 'body', 'notice_date', 'attachment_path', 'attachment_name', 'attachment_mime',
        'levels', 'district_id', 'block_id', 'cluster_id', 'expires_on', 'show_on_home', 'status', 'created_by', 'withdrawn_at'];

    protected $casts = ['levels' => 'array', 'withdrawn_at' => 'datetime', 'show_on_home' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\NoticeService::flushCounts());
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function district() { return $this->belongsTo(District::class); }
    public function block() { return $this->belongsTo(Block::class); }
    public function cluster() { return $this->belongsTo(Cluster::class); }
    public function reads() { return $this->hasMany(NoticeRead::class); }

    public function getNoticeDateAttribute($v): ?string { return $v ? substr((string) $v, 0, 10) : null; }
    public function getExpiresOnAttribute($v): ?string { return $v ? substr((string) $v, 0, 10) : null; }

    public function typeLabel(): string { return self::TYPES[$this->type] ?? ucfirst($this->type); }
    public function typeIcon(): string { return self::TYPE_ICONS[$this->type] ?? 'bi-megaphone'; }
    public function isWithdrawn(): bool { return $this->status === 'withdrawn'; }
    public function isExpired(): bool { return $this->expires_on !== null && $this->expires_on < today()->toDateString(); }

    /** "Cluster, Block officers · KOKRAJHAR (1801)" */
    public function audienceLabel(): string
    {
        $levels = collect($this->levels)->map(fn ($l) => UserLevel::tryFrom($l)?->label())->filter()
            ->map(fn ($l) => str_replace(' Level', '', $l))->implode(', ');
        $area = $this->cluster?->label ?? $this->block?->label ?? $this->district?->label ?? 'Whole state';

        return $levels.' officers · '.$area;
    }

    /** Published, not expired notices addressed to this user. */
    public function scopeFor(Builder $q, User $user): Builder
    {
        $j = app(JurisdictionService::class)->current($user);
        $q->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('expires_on')->orWhere('expires_on', '>=', today()->toDateString()))
            ->whereJsonContains('levels', $user->level->value);
        foreach (['district_id', 'block_id', 'cluster_id'] as $col) {
            $own = $j?->{$col};
            $q->where(fn ($w) => $own ? $w->whereNull("notices.$col")->orWhere("notices.$col", $own) : $w->whereNull("notices.$col"));
        }

        return $q;
    }

    public function isFor(User $user): bool
    {
        return static::query()->whereKey($this->id)->for($user)->exists();
    }

    /** Active officers this notice reaches. */
    public function recipients(): Builder
    {
        return User::query()->where('status', UserStatus::Active->value)->whereIn('level', $this->levels ?: [])
            ->whereKeyNot($this->created_by)
            ->whereHas('currentJurisdiction', function ($w) {
                foreach (['district_id', 'block_id', 'cluster_id'] as $col) {
                    if ($this->{$col}) {
                        $w->where($col, $this->{$col});
                    }
                }
            });
    }
}
