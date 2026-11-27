<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * 26-11-2026 performance, module B: one large download made in the background (App\Jobs\GenerateExport).
 * The file lives on the private disk and is served only to its owner (DownloadController).
 */
class ExportJob extends Model
{
    use HasEncryptedRouteKey;

    public const ACTIVE = ['queued', 'running'];

    protected $fillable = ['user_id', 'route_name', 'url', 'title', 'status', 'file_path', 'file_name', 'size_bytes', 'error',
        'ip_address', 'user_agent', 'started_at', 'finished_at', 'downloaded_at'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime', 'downloaded_at' => 'datetime', 'size_bytes' => 'integer'];

    public function user() { return $this->belongsTo(User::class); }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isReady(): bool
    {
        return $this->status === 'done' && $this->file_path;
    }

    /** Still waiting after half a minute: usually no queue worker is running. */
    public function isStalled(): bool
    {
        return $this->status === 'queued' && $this->created_at->lt(now()->subSeconds(30));
    }

    public function expiresAt(): \Illuminate\Support\Carbon
    {
        return $this->created_at->copy()->addHours(\App\Support\QueuedExports::keepHours());
    }

    /** Shape sent to downloads.js. */
    public function toClientArray(): array
    {
        $stalled = $this->isStalled();

        return [
            'key' => $this->getRouteKey(),
            'title' => $this->title,
            'status' => $this->status,
            'file_name' => $this->file_name,
            'size' => $this->size_bytes ? \App\Support\FileSize::human((int) ceil($this->size_bytes / 1024)) : null,
            'error' => $this->error,
            'stalled' => $stalled,
            // 27-11-2026: the browser asks the server to make a stalled job itself (DownloadController::run)
            'run_url' => $stalled ? route('downloads.run', $this) : null,
            'url' => $this->isReady() ? route('downloads.file', $this) : null,
        ];
    }
}
