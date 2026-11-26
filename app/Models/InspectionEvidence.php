<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use App\Enums\EvidenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspectionEvidence extends Model
{
    use HasEncryptedRouteKey;
    use SoftDeletes;

    protected $table = 'inspection_evidence';

    protected $fillable = [
        'evidence_uid', 'inspection_id', 'kind', 'source', 'category_id', 'question_code', 'caption',
        'taken_at', 'received_at', 'latitude', 'longitude', 'accuracy', 'no_location_reason',
        'original_name', 'mime', 'size_bytes', 'storage_path', 'sha256', 'status', 'uploaded_by',
    ];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'status' => EvidenceStatus::class,
            'taken_at' => 'datetime',
            'received_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy' => 'float',
        ];
    }

    public function inspection() { return $this->belongsTo(Inspection::class); }
    public function category() { return $this->belongsTo(MasterValue::class, 'category_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Shape sent to the browser (no storage path). */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'evidence_uid' => $this->evidence_uid,
            'kind' => $this->kind,
            'source' => $this->source ?? 'camera',
            'category' => $this->category?->code,
            'category_label' => $this->category?->label,
            'question_code' => $this->question_code,
            'caption' => $this->caption,
            'status' => $this->status->value,
            'has_location' => $this->hasLocation(),
            'url' => route('evidence.show', $this),
            'thumb_url' => $this->kind === 'photo' ? route('evidence.show', [$this, 'size' => 'thumb']) : null,
            'delete_url' => route('evidence.destroy', $this),
            'original_name' => $this->original_name,
        ];
    }
}
