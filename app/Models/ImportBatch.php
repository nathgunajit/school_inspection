<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One Excel upload. Since 26-11-2026 (performance, module C) the row exists from the check on:
 * checking -> checked | check_failed, then (Confirm) queued -> importing -> done | failed.
 */
class ImportBatch extends Model
{
    use HasEncryptedRouteKey;

    public const FINISHED = ['done', 'failed'];

    public const WORKING = ['checking', 'queued', 'importing'];

    protected $fillable = ['type', 'status', 'district_id', 'district_all', 'file_name', 'uploaded_by', 'total_rows', 'added', 'updated', 'rejected',
        'error_file_path', 'pending_path', 'summary', 'preview', 'error', 'started_at', 'finished_at'];

    protected $casts = ['summary' => 'array', 'preview' => 'array', 'district_all' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** District chosen for a district-wise School Master upload (null for Location uploads and old files). */
    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function isWorking(): bool
    {
        return in_array($this->status, self::WORKING, true);
    }

    /** The Excel Upload page's "pending" shape (file checked, waiting for Confirm). */
    public function pendingInfo(): array
    {
        return ['type' => $this->type, 'district_id' => $this->district_id, 'all' => $this->district_all,
            'district' => $this->district_all ? 'All districts' : $this->district?->name, 'name' => $this->file_name];
    }

    public function statusLabel(): string
    {
        return [
            'checking' => 'Checking the file…', 'checked' => 'Checked', 'check_failed' => 'Check failed',
            'queued' => 'Waiting to save…', 'importing' => 'Saving…', 'done' => 'Saved', 'failed' => 'Failed',
        ][$this->status] ?? ucfirst((string) $this->status);
    }
}
