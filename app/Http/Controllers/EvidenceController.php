<?php

namespace App\Http\Controllers;

use App\Models\Inspection;
use App\Models\InspectionEvidence;
use App\Models\MasterValue;
use App\Questionnaire\QuestionnaireDefinition;
use App\Services\AuditLogger;
use App\Services\EvidenceStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Photograph and document evidence (PRD 9). */
class EvidenceController extends Controller
{
    public function __construct(private EvidenceStorage $storage) {}

    public function store(Request $request, Inspection $inspection)
    {
        // Server checks: logged in, inspection is the user's and still open (PRD 9.6)
        $this->authorize('update', $inspection);
        $cfg = config('inspection.evidence');

        $data = $request->validate([
            'file' => ['required', 'file'],
            'evidence_uid' => ['required', 'uuid'],
            'kind' => ['required', 'in:photo,document'],
            'source' => ['nullable', 'in:camera,gallery'],
            'category' => ['nullable', Rule::exists('master_values', 'code')->where('type', 'photo_category')],
            'question_code' => ['nullable', 'string', 'max:20'],
            'caption' => ['nullable', 'string', 'max:150'],
            'taken_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'no_location_reason' => [$request->input('kind') === 'photo' ? 'required_without:latitude' : 'nullable', 'nullable', 'string', 'max:255'],
            'sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/i'],
        ], ['no_location_reason.required_without' => 'Location is not available: please enter a reason.']);

        // Idempotent retry: sending the same evidence ID again never creates a duplicate
        $existing = InspectionEvidence::where('evidence_uid', $data['evidence_uid'])->where('inspection_id', $inspection->id)->first();
        if ($existing) {
            return response()->json($existing->load('category')->toClientArray());
        }

        if ($data['kind'] === 'photo') {
            abort_if($inspection->photos()->count() >= $cfg['max_photos'], 422, "A maximum of {$cfg['max_photos']} photographs is allowed per inspection.");
        } else {
            abort_if($inspection->documents()->count() >= $cfg['max_documents'], 422, "A maximum of {$cfg['max_documents']} documents is allowed.");
        }

        if (! empty($data['question_code']) && ! QuestionnaireDefinition::find($data['question_code'])) {
            abort(422, 'Unknown question.');
        }

        $evidence = $this->storage->store($inspection, $request->user(), $request->file('file'), [
            'evidence_uid' => $data['evidence_uid'],
            'kind' => $data['kind'],
            'source' => $data['kind'] === 'photo' ? ($data['source'] ?? 'camera') : 'gallery',
            'sha256' => $data['sha256'] ?? null,
            'category_id' => isset($data['category']) ? MasterValue::idFor('photo_category', $data['category']) : null,
            'question_code' => $data['question_code'] ?? null,
            'caption' => $data['caption'] ?? null,
            'taken_at' => $data['taken_at'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'no_location_reason' => isset($data['latitude']) ? null : ($data['no_location_reason'] ?? null),
        ]);

        AuditLogger::log('evidence_uploaded', $evidence, ['inspection_id' => $inspection->id, 'kind' => $evidence->kind, 'source' => $evidence->source, 'sha256' => $evidence->sha256]);

        return response()->json($evidence->load('category')->toClientArray(), 201);
    }

    /** Retake / delete before submission only (PRD 9.2, 9.8). */
    public function destroy(InspectionEvidence $evidence)
    {
        $this->authorize('update', $evidence->inspection);
        $this->storage->delete($evidence);
        AuditLogger::log('evidence_deleted', $evidence->inspection, ['evidence_uid' => $evidence->evidence_uid]);

        return response()->json(['ok' => true]);
    }

    /** Every view checks permission; there are no public links (PRD 9.8). */
    public function show(Request $request, InspectionEvidence $evidence)
    {
        $this->authorize('view', $evidence->inspection);
        abort_unless($evidence->storage_path && Storage::disk(EvidenceStorage::DISK)->exists($evidence->storage_path), 404);

        $download = $request->boolean('download');
        if ($download) {
            AuditLogger::log('evidence_downloaded', $evidence, ['inspection_id' => $evidence->inspection_id]);
        }

        $name = ($evidence->category?->code ?? $evidence->kind).'-'.$evidence->evidence_uid.'.'.pathinfo($evidence->storage_path, PATHINFO_EXTENSION);

        return Storage::disk(EvidenceStorage::DISK)->response($evidence->storage_path, $name, [
            'Content-Type' => $evidence->mime,
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
