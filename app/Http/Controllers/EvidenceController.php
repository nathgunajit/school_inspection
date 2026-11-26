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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // 26-11-2026: a photo says what it shows (a category, or the question it belongs to)
            'category' => [$request->input('kind') === 'photo' ? 'required_without:question_code' : 'nullable', 'nullable', Rule::exists('master_values', 'code')->where('type', 'photo_category')],
            'question_code' => ['nullable', 'string', 'max:20'],
            'caption' => ['nullable', 'string', 'max:150'],
            'taken_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(10)->toDateTimeString()], // a phone clock a little ahead is allowed
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'no_location_reason' => [$request->input('kind') === 'photo' ? 'required_without:latitude' : 'nullable', 'nullable', 'string', 'max:255'],
            'sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/i'],
        ], [
            'no_location_reason.required_without' => 'Location is not available: please enter a reason.',
            'category.required_without' => 'Choose what the photo shows (the photo type), then save it again.',
            'category.exists' => 'This photo type is not in the list. Choose one of the photo types shown.',
            'taken_at.before_or_equal' => "The photo time is in the future. Check the phone's date and time, then take the photo again.",
            'file.required' => 'No file was received. Choose or take the photo again.',
        ]);

        // Idempotent retry: sending the same evidence ID again never creates a duplicate
        $existing = InspectionEvidence::where('evidence_uid', $data['evidence_uid'])->where('inspection_id', $inspection->id)->first();
        if ($existing) {
            return response()->json($existing->load('category')->toClientArray());
        }

        if (! empty($data['question_code']) && ! QuestionnaireDefinition::find($data['question_code'])) {
            throw ValidationException::withMessages(['question_code' => 'This photo belongs to a question that is not in the form. Reload the page and take it again.']);
        }

        // 26-11-2026: the count is checked with the inspection row locked, so two uploads at once cannot pass the limit
        $evidence = DB::transaction(function () use ($inspection, $data, $cfg, $request) {
            Inspection::whereKey($inspection->id)->lockForUpdate()->first();
            if ($data['kind'] === 'photo' && $inspection->photos()->count() >= $cfg['max_photos']) {
                throw ValidationException::withMessages(['file' => "You already added {$cfg['max_photos']} photographs, the most allowed. Remove one to add another."]);
            }
            if ($data['kind'] === 'document' && $inspection->documents()->count() >= $cfg['max_documents']) {
                throw ValidationException::withMessages(['file' => "You already added {$cfg['max_documents']} documents, the most allowed. Remove one to add another."]);
            }

            return $this->storeFile($inspection, $request, $data);
        });

        AuditLogger::log('evidence_uploaded', $evidence, ['inspection_id' => $inspection->id, 'kind' => $evidence->kind, 'source' => $evidence->source, 'sha256' => $evidence->sha256]);

        return response()->json($evidence->load('category')->toClientArray(), 201);
    }

    private function storeFile(Inspection $inspection, Request $request, array $data): InspectionEvidence
    {
        return $this->storage->store($inspection, $request->user(), $request->file('file'), [
            'evidence_uid' => $data['evidence_uid'],
            'kind' => $data['kind'],
            'source' => $data['kind'] === 'photo' ? ($data['source'] ?? 'camera') : 'gallery',
            'sha256' => $data['sha256'] ?? null,
            'category_id' => isset($data['category']) ? MasterValue::idFor('photo_category', $data['category']) : null,
            'question_code' => $data['question_code'] ?? null,
            'caption' => $data['caption'] ?? null,
            // 26-11-2026: the phone sends UTC (toISOString); store it in the application time zone, like every other time
            'taken_at' => isset($data['taken_at']) ? \Illuminate\Support\Carbon::parse($data['taken_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'no_location_reason' => isset($data['latitude']) ? null : ($data['no_location_reason'] ?? null),
        ]);
    }

    /** Retake / delete before submission only (PRD 9.2, 9.8). */
    public function destroy(InspectionEvidence $evidence)
    {
        $evidence->loadMissing('inspection');
        $this->authorize('update', $evidence->inspection);
        $this->storage->delete($evidence);
        AuditLogger::log('evidence_deleted', $evidence->inspection, ['evidence_uid' => $evidence->evidence_uid]);

        return response()->json(['ok' => true]);
    }

    /** Every view checks permission; there are no public links (PRD 9.8). */
    public function show(Request $request, InspectionEvidence $evidence)
    {
        $evidence->loadMissing('inspection', 'category');
        $this->authorize('view', $evidence->inspection);
        $disk = Storage::disk(EvidenceStorage::DISK);
        abort_unless($evidence->storage_path && $disk->exists($evidence->storage_path), 404);

        $download = $request->boolean('download');
        if ($download) {
            AuditLogger::log('evidence_downloaded', $evidence, ['inspection_id' => $evidence->inspection_id]);
        }

        // Grids and lists ask for ?size=thumb (360 px); the viewer and downloads get the original
        $path = $evidence->storage_path;
        $mime = $evidence->mime;
        if (! $download && $request->query('size') === 'thumb' && $evidence->kind === 'photo') {
            $thumb = EvidenceStorage::thumbPathFor($path);
            if ($disk->exists($thumb)) {
                [$path, $mime] = [$thumb, 'image/jpeg'];
            }
        }

        $name = ($evidence->category?->code ?? $evidence->kind).'-'.$evidence->evidence_uid.'.'.pathinfo($path, PATHINFO_EXTENSION);

        // Stored evidence never changes, so the browser may keep it for a day (still private to this user)
        return $disk->response($path, $name, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
