<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Writes append-only audit entries (PRD 12). */
class AuditLogger
{
    public static function log(string $action, ?Model $record = null, array $meta = [], ?User $user = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $record ? class_basename($record) : null,
            'auditable_id' => $record?->getKey(),
            'meta' => $meta ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
        ]);
    }
}
