@php
    use App\Questionnaire\InspectionValidator as V;
    $code = $q['code'];
    $val = $answers[$code] ?? null;
    if (($val === null || $val === '') && array_key_exists('default', $q)) {
        $val = $q['default']; // Modification V9: optional counts show 0 (stored as 0 at submit)
    }
    $visible = V::visible($q, $answers);
    $enabled = V::enabled($q, $answers);
    $required = V::required($q, $answers);
    $remarksOn = V::isFilled($val) && V::remarksShown($q, $val);
    $remarksOptional = ! empty($q['remarks_optional']);
    $photoRule = $q['photo'] ?? null;
    $photoOn = $photoRule === 'required' || $photoRule === 'optional' || (V::isFilled($val) && V::photoRequired($q, $val));
    $photoRequired = V::isFilled($val) && V::photoRequired($q, $val);
    $tone = fn ($v) => \App\Questionnaire\QuestionnaireDefinition::tone($v);
    $opts = $q['options'] ?? [];
    // Short choice lists (Yes/No, Satisfactory/Unsatisfactory, ...) sit on one line beside the question on wider screens
    // and become an equal-width segmented control on phones.
    $inline = $q['type'] === 'radio' && count($opts) <= 3 && collect($opts)->every(fn ($l) => mb_strlen($l) <= 16);
    // Phones: only very short pairs (Yes/No) stay beside the question; longer ones (Satisfactory/Unsatisfactory) go full width below it
    $compact = $inline && count($opts) === 2 && collect($opts)->every(fn ($l) => mb_strlen($l) <= 4);
@endphp
<div class="q-block {{ $inline ? 'q-inline' : '' }} {{ $compact ? 'q-inline-2' : '' }} {{ $q['type'] === 'number' ? 'q-num' : '' }} {{ V::isFilled($val) ? 'is-answered' : '' }} {{ $visible ? '' : 'd-none' }} {{ $enabled ? '' : 'is-disabled' }}"
     id="q-{{ $code }}" data-code="{{ $code }}" data-type="{{ $q['type'] }}" @unless($enabled) aria-disabled="true" @endunless>
    <div class="q-label">
        <span class="q-no" aria-hidden="true"><span class="n">{{ $n }}</span><i class="bi bi-check-lg"></i></span>
        <span class="q-text">{{ $q['label'] }} <span class="q-req {{ $required ? '' : 'd-none' }}" title="Required">*</span>@if (!empty($q['hint']))<small class="q-hint">{{ $q['hint'] }}</small>@endif</span>
    </div>

    <fieldset class="q-input" @disabled(! $enabled)>
        @switch($q['type'])
            @case('radio')
                <div class="choice-group {{ $inline ? 'seg' : (count($opts) > 3 ? 'stack-sm' : '') }}" role="radiogroup" aria-label="{{ $q['label'] }}">
                    @foreach ($opts as $ov => $ol)
                        <input type="radio" name="{{ $code }}" id="{{ $code }}_{{ $ov }}" value="{{ $ov }}" @checked((string) $val === (string) $ov) data-answer>
                        <label for="{{ $code }}_{{ $ov }}" class="{{ $tone($ov) }}">{{ $ol }}</label>
                    @endforeach
                </div>
                @break
            @case('multi')
                <div class="choice-group" role="group" aria-label="{{ $q['label'] }}">
                    @foreach ($opts as $ov => $ol)
                        <input type="checkbox" name="{{ $code }}[]" id="{{ $code }}_{{ $ov }}" value="{{ $ov }}" @checked(in_array($ov, (array) $val, true)) data-answer>
                        <label for="{{ $code }}_{{ $ov }}"><i class="bi bi-check2"></i>{{ $ol }}</label>
                    @endforeach
                </div>
                @break
            @case('select')
                <select name="{{ $code }}" class="form-select q-w-md" data-answer aria-label="{{ $q['label'] }}">
                    <option value="">Select</option>
                    @foreach ($opts as $ov => $ol)<option value="{{ $ov }}" @selected((string) $val === (string) $ov)>{{ $ol }}</option>@endforeach
                </select>
                @break
            @case('number')
                <div class="num-stepper">
                    <button type="button" class="num-btn" data-num-step="-1" aria-label="Decrease"><i class="bi bi-dash-lg"></i></button>
                    <input type="number" min="0" max="100000" step="1" inputmode="numeric" name="{{ $code }}" value="{{ $val }}" placeholder="0"
                           class="form-control" data-answer aria-label="{{ $q['label'] }}">
                    <button type="button" class="num-btn" data-num-step="1" aria-label="Increase"><i class="bi bi-plus-lg"></i></button>
                </div>
                @break
            @case('date')
                <input type="date" name="{{ $code }}" value="{{ $val }}" min="2000-01-01" max="{{ now()->toDateString() }}" class="form-control q-w-sm" data-answer aria-label="{{ $q['label'] }}">
                @break
            @case('textarea')
                <textarea name="{{ $code }}" rows="3" maxlength="2000" class="form-control" data-answer aria-label="{{ $q['label'] }}" placeholder="Type here">{{ $val }}</textarea>
                @break
            @default
                <input type="text" name="{{ $code }}" value="{{ $val }}" maxlength="200" class="form-control q-w-lg" data-answer aria-label="{{ $q['label'] }}" placeholder="Type here">
        @endswitch
    </fieldset>

    {{-- Remarks (mandatory for some answers, optional when remarks_optional) --}}
    @if (! empty($q['remarks_if']))
        <div class="q-extra q-remarks {{ $remarksOn ? '' : 'd-none' }}">
            <label class="form-label text-sm mb-1" for="{{ $code }}__remarks">Remarks @if ($remarksOptional)<span class="text-secondary fw-normal">(optional)</span>@else<span class="req">*</span>@endif</label>
            <textarea id="{{ $code }}__remarks" rows="2" maxlength="2000" class="form-control" data-remarks="{{ $code }}" placeholder="{{ $remarksOptional ? 'Describe the problem (optional)' : 'Describe the problem' }}">{{ $remarks[$code] ?? '' }}</textarea>
        </div>
    @endif

    {{-- 28-11-2026: a document attached to the answer (NET_02: the declaration signed by the Block / District officer, PDF) --}}
    @if (is_array($q['document'] ?? null))
        @php $pdfOnly = ! empty($q['document']['pdf_only']); /* 28-11-2026: always shown with its question, so the form can be downloaded before ticking */ @endphp
        <div class="q-extra q-docs" data-q-docs="{{ $code }}">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <span class="text-sm fw-semibold"><i class="bi bi-file-earmark-pdf text-danger me-1"></i>{{ $q['document']['label'] ?? 'Document' }}
                    <span class="text-danger">required</span></span>
                <div class="d-flex flex-wrap gap-2">
                    @if ($code === 'NET_02' && isset($inspection))
                        <a href="{{ route('inspections.network-declaration', $inspection) }}" class="btn btn-sm btn-primary" download data-no-loader><i class="bi bi-download"></i>Download declaration form</a>
                    @endif
                    <label class="btn btn-sm btn-soft-primary mb-0"><i class="bi bi-paperclip"></i>Attach signed PDF
                        <input type="file" class="d-none" data-doc-question="{{ $code }}" data-pdf-only="{{ $pdfOnly ? '1' : '0' }}"
                               accept="{{ $pdfOnly ? '.pdf,application/pdf' : '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png' }}"></label>
                </div>
            </div>
            <div class="list-card" data-doc-list data-question="{{ $code }}"></div>
            <div class="form-text">Print the form, get it signed and sealed by the Block / District officer, scan it as a PDF (max 5 MB) and attach it here.</div>
        </div>
    @endif

    {{-- Linked photographs --}}
    @if ($photoRule)
        <div class="q-extra q-photos {{ $photoOn ? '' : 'd-none' }}">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                <span class="text-sm fw-semibold"><i class="bi bi-camera me-1"></i>Photograph
                    <span class="photo-req text-danger {{ $photoRequired ? '' : 'd-none' }}">required</span>
                    <span class="photo-opt text-secondary fw-normal {{ $photoRequired ? 'd-none' : '' }}">(optional)</span></span>
                <button type="button" class="btn btn-sm btn-soft-primary" data-camera data-category="{{ $q['photo_category'] ?? 'other' }}" data-question="{{ $code }}" data-caption="{{ $q['label'] }}">
                    <i class="bi bi-camera"></i>Take photo</button>
            </div>
            <div class="photo-grid" data-photo-grid data-question="{{ $code }}"></div>
        </div>
    @endif
</div>
