<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * 25-11-2026: Notices & Orders letter types (master). `code` is stored in notices.type and never changes;
 * a type is never deleted, only made inactive (no longer offered when writing a notice; old notices keep it).
 */
class NoticeType extends Model
{
    use Concerns\HasEncryptedRouteKey; // record IDs in URLs are encrypted (domain rule 13)

    private const VERSION_KEY = 'notice_types:version';

    /** Icons offered on the master form. */
    public const ICONS = [
        'bi-list-check' => 'Checklist', 'bi-megaphone' => 'Megaphone', 'bi-file-earmark-text' => 'Document', 'bi-journal-richtext' => 'Journal',
        'bi-people' => 'People', 'bi-info-circle' => 'Information', 'bi-bell' => 'Bell', 'bi-calendar-event' => 'Calendar',
        'bi-envelope-paper' => 'Letter', 'bi-clipboard-data' => 'Report', 'bi-exclamation-triangle' => 'Alert', 'bi-award' => 'Award',
        'bi-book' => 'Book', 'bi-pin-angle' => 'Pin',
    ];

    protected $fillable = ['code', 'name', 'icon', 'color', 'sort_order', 'is_active', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1));
    }

    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }
    public function scopeOrdered(Builder $q): Builder { return $q->orderBy('sort_order')->orderBy('name'); }

    /** @return array<string, array{name:string, icon:string, color:string, active:bool}> every type by code, cached */
    public static function map(): array
    {
        return Cache::remember('notice_types:'.Cache::get(self::VERSION_KEY, 0), 3600, fn () => static::query()->ordered()->get()
            ->mapWithKeys(fn ($t) => [$t->code => ['name' => $t->name, 'icon' => $t->icon, 'color' => $t->color, 'active' => $t->is_active]])->all());
    }

    /** @return array<string, string> code => name, for selects */
    public static function options(bool $activeOnly = true): array
    {
        return collect(static::map())->filter(fn ($t) => ! $activeOnly || $t['active'])->map(fn ($t) => $t['name'])->all();
    }
}
