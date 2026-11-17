@php
    use App\Questionnaire\InspectionValidator as V;
    $code = $q['code'];
    $val = $answers[$code] ?? null;
    $visible = V::visible($q, $answers);
    $required = V::required($q, $answers);
    $remarksOn = V::isFilled($val) && V::remarksRequired($q, $val);
    $photoRule = $q['photo'] ?? null;
    $photoOn = $photoRule === 'required' || $photoRule === 'optional' || (V::isFilled($val) && V::photoRequired($q, $val));
    $photoRequired = V::isFilled($val) && V::photoRequired($q, $val);
    $tone = fn ($v) => match (true) {
        in_array($v, ['unsatisfactory', 'not_available', 'not_received', 'not_held', 'no', 'poor', 'expired', 'inadequate'], true) => 'tone-bad',
        in_array($v, ['satisfactory', 'some', 'irregular', 'partly', 'needs_improvement', 'not_in_use', 'cook_absent'], true) => 'tone-mid',
        in_array($v, ['good', 'very_good', 'yes', 'all', 'monthly', 'daily', 'valid', 'adequate', 'in_use'], true) => 'tone-good',
        default => '',
    };
    $hasRule = ! empty($q['show_if']) || ! empty($q['required_if']) || ! empty($q['required_unless']);
@endphp
<div class="q-block {{ $visible ? '' : 'd-none' }}" id="q-{{ $code }}" data-code="{{ $code }}" data-type="{{ $q['type'] }}">
    <div class="q-label">
        <span class="q-no">{{ $n }}</span>
        <span>{{ $q['label'] }} <span class="q-req {{ $required ? '' : 'd-none' }}" title="Required">*</span></span>
    </div>

    <div class="ps-md-4 ms-md-3">
        @switch($q['type'])
            @case('radio')
                <div class="choice-group {{ count($q['options']) > 3 ? 'stack-sm' : '' }}" role="radiogroup" aria-label="{{ $q['label'] }}">
                    @foreach ($q['options'] as $ov => $ol)
                        <input type="radio" name="{{ $code }}" id="{{ $code }}_{{ $ov }}" value="{{ $ov }}" @checked((string) $val === (string) $ov) data-answer>
                        <label for="{{ $code }}_{{ $ov }}" class="{{ $tone($ov) }}">{{ $ol }}</label>
                    @endforeach
                </div>
                @break
            @case('multi')
                <div class="choice-group" role="group" aria-label="{{ $q['label'] }}">
                    @foreach ($q['options'] as $ov => $ol)
                        <input type="checkbox" name="{{ $code }}[]" id="{{ $code }}_{{ $ov }}" value="{{ $ov }}" @checked(in_array($ov, (array) $val, true)) data-answer>
                        <label for="{{ $code }}_{{ $ov }}"><i class="bi bi-check2"></i>{{ $ol }}</label>
                    @endforeach
                </div>
                @break
            @case('select')
                <select name="{{ $code }}" class="form-select" style="max-width:320px" data-answer aria-label="{{ $q['label'] }}">
                    <option value="">Select</option>
                    @foreach ($q['options'] as $ov => $ol)<option value="{{ $ov }}" @selected((string) $val === (string) $ov)>{{ $ol }}</option>@endforeach
                </select>
                @break
            @case('number')
                <input type="number" min="0" max="100000" step="1" inputmode="numeric" name="{{ $code }}" value="{{ $val }}" class="form-control" style="max-width:200px" data-answer aria-label="{{ $q['label'] }}">
                @break
            @case('date')
                <input type="date" name="{{ $code }}" value="{{ $val }}" max="{{ now()->toDateString() }}" class="form-control" style="max-width:220px" data-answer aria-label="{{ $q['label'] }}">
                @break
            @case('textarea')
                <textarea name="{{ $code }}" rows="3" maxlength="2000" class="form-control" data-answer aria-label="{{ $q['label'] }}">{{ $val }}</textarea>
                @break
            @default
                <input type="text" name="{{ $code }}" value="{{ $val }}" maxlength="200" class="form-control" data-answer aria-label="{{ $q['label'] }}">
        @endswitch

        {{-- Remarks (mandatory for some answers) --}}
        @if (! empty($q['remarks_if']))
            <div class="q-extra q-remarks {{ $remarksOn ? '' : 'd-none' }}">
                <label class="form-label text-sm mb-1" for="{{ $code }}__remarks">Remarks <span class="text-danger">*</span></label>
                <textarea id="{{ $code }}__remarks" rows="2" maxlength="2000" class="form-control" data-remarks="{{ $code }}" placeholder="Describe the problem">{{ $remarks[$code] ?? '' }}</textarea>
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
</div>
