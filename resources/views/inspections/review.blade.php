@php
    use App\Questionnaire\InspectionValidator as V;
    use App\Questionnaire\QuestionnaireDefinition as Q;

    $canSubmit = $progress['can_submit'];
    $missingCount = collect($progress['missing'])->flatten(1)->count();
    $doneSteps = collect($progress['steps'])->where('complete', true)->count();
    $totalSteps = count($steps);
    $photos = $inspection->evidence->where('kind', 'photo');
    $docs = $inspection->evidence->where('kind', 'document');
    $suggestions = $inspection->remarks->where('type', 'suggestion')->pluck('body')->filter()->values();
    $counts = [
        3 => $inspection->absentTeachers->count().' absent teacher(s)',
        4 => $inspection->classroomObservations->count().' classroom record(s)',
        5 => $inspection->fundPositions->count().' year(s) entered',
        7 => $photos->count().' photo(s)'.($docs->count() ? ' · '.$docs->count().' document(s)' : ''),
    ];
@endphp
<x-layouts.app title="Review & Submit" :back="route('inspections.step', [$inspection, $totalSteps])">
    @include('inspections.partials.stepper', ['current' => 'review'])

    <div class="row justify-content-center">
        <div class="col-xl-9">

            {{-- Status --}}
            <section class="review-status {{ $canSubmit ? 'is-ready' : 'is-pending' }}">
                <div class="review-ring" style="--p: {{ (int) $progress['pct'] }}"><span>{{ (int) $progress['pct'] }}%</span></div>
                <div class="min-w-0 flex-grow-1">
                    <h2 class="review-title">
                        @if ($canSubmit)<i class="bi bi-check-circle-fill"></i>Ready to submit
                        @else<i class="bi bi-hourglass-split"></i>{{ $missingCount }} item{{ $missingCount === 1 ? '' : 's' }} still to complete @endif
                    </h2>
                    <p class="review-sub">{{ $doneSteps }} of {{ $totalSteps }} steps complete ·
                        {{ $canSubmit ? 'Check your answers below, then confirm and submit.' : 'Tap an item below to go straight to it.' }}</p>
                </div>
            </section>

            {{-- Missing items --}}
            @unless ($canSubmit)
                <section class="review-card review-missing">
                    <div class="review-card-head"><i class="bi bi-exclamation-circle text-danger"></i>Complete these before submitting</div>
                    @foreach ($progress['missing'] as $stepNo => $items)
                        <div class="review-missing-step">
                            <div class="review-missing-title"><span class="dot">{{ $stepNo }}</span>{{ $steps[$stepNo]['title'] }}</div>
                            @foreach ($items as $m)
                                @php $hash = $m['code'] && Q::find($m['code']) ? '#q-'.$m['code'] : ''; @endphp
                                <a href="{{ route('inspections.step', [$inspection, $stepNo]) }}{{ $hash }}" class="review-missing-item">
                                    <span>{{ $m['message'] }}</span><span class="fix">Fix<i class="bi bi-chevron-right"></i></span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </section>
            @endunless

            {{-- Step by step summary with answers --}}
            <section class="review-card">
                <div class="review-card-head"><i class="bi bi-list-check text-primary"></i>Your answers
                    <button type="button" class="btn btn-link btn-sm ms-auto p-0 text-decoration-none" data-review-toggle-all>Expand all</button></div>
                <div class="accordion review-acc" id="reviewAcc">
                    @foreach ($steps as $n => $s)
                        @php $st = $progress['steps'][$n]; $open = ! $st['complete'] && $loop->first; @endphp
                        <div class="review-step {{ $st['complete'] ? 'is-complete' : '' }}">
                            <div class="review-step-head">
                                <button class="review-step-btn {{ $open ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#rs-{{ $n }}" aria-expanded="{{ $open ? 'true' : 'false' }}">
                                    <span class="review-step-icon"><i class="bi {{ $st['complete'] ? 'bi-check-lg' : $s['icon'] }}"></i></span>
                                    <span class="min-w-0 flex-grow-1 text-start">
                                        <span class="d-block review-step-title">{{ $n }}. {{ $s['title'] }}</span>
                                        <span class="d-block review-step-meta">{{ $counts[$n] ?? count(array_filter($questions[$n] ?? [], fn ($q) => V::isFilled($answers[$q['code']] ?? null))).' answered' }}</span>
                                    </span>
                                    <span class="review-chip {{ $st['complete'] ? 'ok' : 'warn' }}">{{ $st['complete'] ? 'Complete' : $st['pct'].'%' }}</span>
                                    <i class="bi bi-chevron-down review-caret"></i>
                                </button>
                                <a href="{{ route('inspections.step', [$inspection, $n]) }}" class="review-edit" title="Edit step {{ $n }}" aria-label="Edit step {{ $n }}"><i class="bi bi-pencil"></i></a>
                            </div>
                            <div id="rs-{{ $n }}" class="collapse {{ $open ? 'show' : '' }}">
                                <div class="review-step-body">
                                    @include('inspections.partials.step-answers')
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Submit --}}
            <form method="POST" action="{{ route('inspections.submit', $inspection) }}" id="submit-form" data-can-submit="{{ $canSubmit ? 1 : 0 }}" class="review-card review-submit {{ $canSubmit ? '' : 'is-locked' }}">
                @csrf
                <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy">
                <label class="review-confirm" for="confirm">
                    <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm" @disabled(! $canSubmit) required>
                    <span>I confirm that I conducted this inspection at the school and the information is correct.
                        <small>After submission the report is <strong>locked</strong> and cannot be edited.</small></span>
                </label>
                @error('confirm')<div class="field-error"><i class="bi bi-exclamation-circle"></i>{{ $message }}</div>@enderror
                <button class="btn btn-primary btn-lg w-100 mt-3" id="submit-btn" disabled><i class="bi bi-send-check"></i>Submit inspection</button>
                <div class="review-submit-hint" id="submit-hint">
                    @if ($canSubmit) Tick the confirmation above to enable Submit. Your current location is recorded on submission.
                    @else Complete the {{ $missingCount }} item{{ $missingCount === 1 ? '' : 's' }} listed above to enable Submit. @endif
                </div>
            </form>
        </div>
    </div>

    @include('inspections.partials.photo-viewer')

    @push('scripts')
        @vite('resources/js/review.js')
    @endpush
</x-layouts.app>
