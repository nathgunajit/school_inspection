<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * V15 (26-11-2026): an in-app alert for the bell in the top bar (database channel only; no SMS, email or push).
 * data = kind, icon, title, body, url (a relative path inside the app, so a sub-folder install keeps working).
 */
abstract class InAppAlert extends Notification
{
    abstract protected function kind(): string;

    abstract protected function icon(): string;

    abstract protected function title(): string;

    abstract protected function body(): string;

    abstract protected function url(): string;

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind(), 'icon' => $this->icon(), 'title' => $this->title(), 'body' => mb_substr($this->body(), 0, 500), 'url' => $this->url()];
    }
}
