<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * V15 (26-11-2026): the in-app alerts (Laravel database notifications) behind the bell in the top bar.
 * The unread count is on every page, so it is cached for 60 s and forgotten when an alert is sent or read
 * (NotificationSent listener in AppServiceProvider).
 */
class AlertService
{
    public static function key(int $userId): string
    {
        return "alerts-unread:$userId";
    }

    public static function forget(int $userId): void
    {
        Cache::forget(self::key($userId));
    }

    public function unreadCount(User $user): int
    {
        return (int) Cache::remember(self::key($user->id), 60, fn () => $user->unreadNotifications()->count());
    }

    /** The latest alerts for the bell's menu. */
    public function latest(User $user, int $n = 8): array
    {
        return $user->notifications()->latest()->limit($n)->get()->map(fn ($a) => [
            'id' => $a->id,
            'title' => (string) ($a->data['title'] ?? 'Alert'),
            'body' => (string) ($a->data['body'] ?? ''),
            'icon' => (string) ($a->data['icon'] ?? 'bi-bell'),
            'when' => $a->created_at?->diffForHumans(),
            'unread' => $a->read_at === null,
            'url' => route('alerts.open', $a->id),
        ])->all();
    }
}
