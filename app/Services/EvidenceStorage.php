<?php

namespace App\Services;

use App\Enums\EvidenceStatus;
use App\Models\Inspection;
use App\Models\InspectionEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Server-side evidence checks and private storage (PRD 9.6).
 */
class EvidenceStorage
{
    public const DISK = 'private';

    /**
     * Store a photo or document. Idempotent on evidence_uid.
     *
     * @param  array{evidence_uid:string,kind:string,sha256:?string,category_id:?int,question_code:?string,caption:?string,taken_at:?string,latitude:?float,longitude:?float,accuracy:?float,no_location_reason:?string}  $meta
     */
    public function store(Inspection $inspection, User $user, UploadedFile $file, array $meta): InspectionEvidence
    {
        $existing = InspectionEvidence::withTrashed()->where('evidence_uid', $meta['evidence_uid'])->first();
        if ($existing) {
            if ($existing->inspection_id !== $inspection->id) {
                throw ValidationException::withMessages(['file' => 'Evidence ID already used.']);
            }

            return $existing; // retry of a completed upload: never duplicate
        }

        $kind = $meta['kind'];
        $cfg = config('inspection.evidence');

        // Real type from content, not extension or client header
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowed = $kind === 'photo' ? $cfg['photo_mimes'] : $cfg['document_mimes'];
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['file' => 'File type not allowed ('.$mime.').']);
        }

        $maxKb = $kind === 'photo' ? $cfg['photo_max_kb'] : $cfg['document_max_kb'];
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => "File is larger than {$maxKb} KB."]);
        }

        $hash = hash_file('sha256', $file->getRealPath());
        if (! empty($meta['sha256']) && ! hash_equals(strtolower($meta['sha256']), $hash)) {
            throw ValidationException::withMessages(['file' => 'File fingerprint mismatch. Please retry the upload.']);
        }

        $this->scanForMalware($file->getRealPath());

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $path = $file->storeAs('evidence/'.$inspection->id, Str::random(40).'.'.$ext, self::DISK);

        return InspectionEvidence::create([
            'evidence_uid' => $meta['evidence_uid'],
            'inspection_id' => $inspection->id,
            'kind' => $kind,
            'source' => $meta['source'] ?? 'camera',
            'category_id' => $meta['category_id'] ?? null,
            'question_code' => $meta['question_code'] ?? null,
            'caption' => $meta['caption'] ?? null,
            'taken_at' => $meta['taken_at'] ?? null,
            'received_at' => now(),
            'latitude' => $meta['latitude'] ?? null,
            'longitude' => $meta['longitude'] ?? null,
            'accuracy' => $meta['accuracy'] ?? null,
            'no_location_reason' => $meta['no_location_reason'] ?? null,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
            'storage_path' => $path,
            'sha256' => $hash,
            'status' => EvidenceStatus::Uploaded,
            'uploaded_by' => $user->id,
        ]);
    }

    public function delete(InspectionEvidence $evidence): void
    {
        if ($evidence->storage_path) {
            Storage::disk(self::DISK)->delete($evidence->storage_path);
        }
        $evidence->forceDelete();
    }

    /** ClamAV hook. Skipped when no scanner is configured (dev). */
    private function scanForMalware(string $path): void
    {
        $bin = config('inspection.evidence.clamav_binary');
        if (! $bin || ! is_file($bin)) {
            return;
        }
        $process = new Process([$bin, '--no-summary', $path]);
        $process->setTimeout(60)->run();
        if ($process->getExitCode() === 1) {
            throw ValidationException::withMessages(['file' => 'The file failed the security scan.']);
        }
    }
}
