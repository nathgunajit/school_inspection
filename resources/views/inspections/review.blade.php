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
                                    @if ($n === 2)
                                        {{-- Figures: label + large number, with automatic totals --}}
                                        @php $totS = Q::totalStudents($answers); $totT = Q::totalTeachers($answers); $presT = $answers['TSI_09'] ?? null; @endphp
                                        <div class="fig-grid">
                                            @foreach ($questions[$n] as $q)
                                                @php $v = $answers[$q['code']] ?? null; $has = V::isFilled($v); @endphp
                                                <div class="fig-tile {{ $has ? '' : 'is-empty' }}">
                                                    <span class="fig-label">{{ $q['label'] }}</span>
                                                    <span class="fig-value">@if ($has){{ number_format((int) $v) }}@else<span class="{{ V::required($q, $answers) ? 'text-danger' : 'text-secondary' }}">Not entered</span>@endif</span>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="fig-totals">
                                            <div class="fig-total"><i class="bi bi-backpack"></i><span>Total students <small>Present + Absent</small></span><b>{{ $totS === null ? '—' : number_format($totS) }}</b></div>
                                            <div class="fig-total"><i class="bi bi-person-workspace"></i><span>Total teachers <small>Regular + Contractual + SSA + Tutors</small></span><b>{{ $totT === null ? '—' : number_format($totT) }}</b></div>
                                            @if ($totT !== null && V::isFilled($presT))
                                                @php $absT = $totT - (int) $presT; @endphp
                                                <div class="fig-total {{ $absT < 0 ? 'is-bad' : '' }}"><i class="bi bi-person-x"></i><span>Teachers absent <small>Total − Present ({{ (int) $presT }})</small></span><b>{{ $absT < 0 ? '−'.abs($absT) : $absT }}</b></div>
                                            @endif
                                        </div>
                                    @elseif (! empty($questions[$n]))
                                        {{-- Question → answer rows; the answer is shown as a coloured pill / figure / text block --}}
                                        <div class="qa-list">
                                            @foreach ($questions[$n] as $q)
                                                @continue(! V::visible($q, $answers))
                                                @php $v = $answers[$q['code']] ?? null; $has = V::isFilled($v); $type = $q['type']; @endphp
                                                <div class="qa-row {{ $has ? '' : 'is-empty' }} {{ in_array($type, ['text', 'textarea']) ? 'qa-wide' : '' }}">
                                                    <div class="qa-q"><span class="qa-no">{{ $loop->iteration }}</span><span>{{ $q['label'] }}</span></div>
                                                    <div class="qa-a">
                                                        @if (! $has)
                                                            @if (V::required($q, $answers))<span class="ans-missing"><i class="bi bi-exclamation-circle"></i>Not answered</span>@else<span class="ans-none">Not applicable</span>@endif
                                                        @elseif (in_array($type, ['radio', 'select', 'multi']))
                                                            @foreach ((array) $v as $one)
                                                                <span class="ans-pill {{ Q::tone($one) }}"><i class="bi bi-check2"></i>{{ Q::optionLabel($q['code'], $one) }}</span>
                                                            @endforeach
                                                        @elseif ($type === 'number')
                                                            <span class="ans-num">{{ number_format((float) $v) }}</span>
                                                        @else
                                                            <span class="ans-text">{{ $v }}</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($n === 3)
                                        <div class="rs-list">
                                        @forelse ($inspection->absentTeachers as $i => $t)
                                            <div class="rs-item">
                                                <span class="rs-avatar tone-danger">{{ collect(preg_split('/\s+/', trim($t->teacher_name)))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</span>
                                                <div class="rs-body">
                                                    <div class="rs-title"><span class="rs-no">{{ $i + 1 }}.</span>{{ $t->teacher_name }}</div>
                                                    <div class="rs-chips"><span class="rs-chip">{{ $t->typeLabel() }}</span><span class="rs-chip warn"><i class="bi bi-calendar-x"></i>{{ $t->reasonLabel() }}</span></div>
                                                    @if ($t->remarks)<div class="rs-note"><i class="bi bi-chat-left-text"></i>{{ $t->remarks }}</div>@endif
                                                </div>
                                            </div>
                                        @empty
                                            <div class="review-line text-secondary"><i class="bi bi-person-check text-success"></i>No absent teachers recorded.</div>
                                        @endforelse
                                        </div>
                                    @elseif ($n === 4)
                                        <div class="rs-list">
                                        @forelse ($inspection->classroomObservations as $i => $c)
                                            @php $ca = $c->toClientArray(); @endphp
                                            <div class="rs-item">
                                                <span class="rs-avatar tone-primary rs-class">{{ $c->class }}</span>
                                                <div class="rs-body">
                                                    <div class="rs-title"><span class="rs-no">{{ $i + 1 }}.</span>{{ $ca['subject'] }} <span class="rs-muted">· {{ $ca['class'] }}</span></div>
                                                    <div class="rs-sub"><i class="bi bi-person"></i>{{ $ca['teacher_name'] }}</div>
                                                    <div class="rs-chips"><span class="rs-chip {{ $ca['tlm_ok'] ? 'ok' : 'bad' }}">TLM: {{ $ca['tlm'] }}</span><span class="rs-chip {{ $ca['participation_ok'] ? 'ok' : 'bad' }}">Participation: {{ $ca['participation'] }}</span></div>
                                                    @if ($ca['remarks'])<div class="rs-note"><i class="bi bi-chat-left-text"></i>{{ $ca['remarks'] }}</div>@endif
                                                </div>
                                            </div>
                                        @empty
                                            <div class="review-line text-danger"><i class="bi bi-exclamation-circle"></i>At least one classroom record is needed.</div>
                                        @endforelse
                                        </div>
                                    @elseif ($n === 5)
                                        @include('inspections.partials.fund-summary', ['funds' => $inspection->fundPositions])
                                    @elseif ($n === 6 && $suggestions->isNotEmpty())
                                        <div class="review-sub-title">Suggestions</div>
                                        @foreach ($suggestions as $i => $sg)
                                            <div class="review-line"><span class="sug-no">{{ $i + 1 }}</span><span>{{ $sg }}</span></div>
                                        @endforeach
                                    @elseif ($n === 7)
                                        @php
                                            $byCat = $photos->groupBy(fn ($p) => $p->category?->code ?? 'other');
                                            $catLabels = $photoCategories->pluck('label', 'code');
                                            $order = collect(array_keys(config('inspection.mandatory_photos')))->merge($byCat->keys())->unique();
                                        @endphp
                                        @if ($photos->isEmpty())
                                            <div class="review-line text-danger"><i class="bi bi-exclamation-circle"></i>No photos uploaded yet.</div>
                                        @endif
                                        <div class="rv-gallery" data-gallery-root>
                                            @foreach ($order as $code)
                                                @php $list = $byCat->get($code, collect()); $min = config('inspection.mandatory_photos')[$code] ?? 0; @endphp
                                                @continue($list->isEmpty() && ! $min)
                                                <div class="rv-cat">
                                                    <div class="rv-cat-head">
                                                        <span>{{ $catLabels[$code] ?? ucfirst(str_replace('_', ' ', $code)) }}</span>
                                                        <span class="rv-count">{{ $list->count() }}</span>
                                                        @if ($min)
                                                            <span class="rv-req {{ $list->count() >= $min ? 'ok' : 'bad' }}"><i class="bi {{ $list->count() >= $min ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }}"></i>{{ $list->count() >= $min ? 'Required · done' : 'Required · missing' }}</span>
                                                        @endif
                                                    </div>
                                                    @if ($list->isNotEmpty())
                                                        <div class="rv-photos">
                                                            @foreach ($list as $p)
                                                                @php $cap = $p->caption ?: ($catLabels[$code] ?? 'Photo'); @endphp
                                                                <button type="button" class="rv-photo" data-photo="{{ route('evidence.show', $p) }}" data-cap="{{ $cap }}"
                                                                        data-meta="{{ $p->taken_at?->format('d M Y, h:i A') }}{{ $p->source === 'gallery' ? ' · From gallery' : '' }}{{ $p->hasLocation() ? ' · Geo-tagged' : '' }}" aria-label="View {{ $cap }}">
                                                                    <img src="{{ route('evidence.show', $p) }}" alt="{{ $cap }}" loading="lazy">
                                                                    <span class="rv-photo-tags">
                                                                        @if ($p->source === 'gallery')<span class="rv-tag">From gallery</span>@endif
                                                                        @if ($p->hasLocation())<span class="rv-tag geo" title="Geo-tagged"><i class="bi bi-geo-alt-fill"></i></span>@endif
                                                                    </span>
                                                                    <span class="rv-photo-cap">{{ $cap }}</span>
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Submit --}}
            <form method="POST" action="{{ route('inspections.submit', $inspection) }}" id="submit-form" class="review-card review-submit {{ $canSubmit ? '' : 'is-locked' }}">
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

    {{-- Photo viewer --}}
    <div class="modal fade" id="photoViewer" tabindex="-1" aria-label="Photo viewer" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content pv-content">
            <div class="pv-top">
                <div class="min-w-0"><div class="pv-cap" id="pv-cap"></div><div class="pv-meta" id="pv-meta"></div></div>
                <span class="pv-pos" id="pv-pos"></span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="pv-stage">
                <button type="button" class="pv-nav prev" data-pv="-1" aria-label="Previous photo"><i class="bi bi-chevron-left"></i></button>
                <img id="pv-img" alt="">
                <button type="button" class="pv-nav next" data-pv="1" aria-label="Next photo"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div></div>
    </div>

    @push('scripts')
    <script>
        (function () {
            // Photo viewer with previous / next (buttons, arrow keys and swipe)
            const tiles = [...document.querySelectorAll('.rv-photo')];
            const pv = document.getElementById('photoViewer');
            if (tiles.length && pv) {
                const modal = bootstrap.Modal.getOrCreateInstance(pv);
                const img = document.getElementById('pv-img');
                let at = 0;
                const show = (i) => {
                    at = (i + tiles.length) % tiles.length;
                    const t = tiles[at];
                    img.src = t.dataset.photo; img.alt = t.dataset.cap;
                    document.getElementById('pv-cap').textContent = t.dataset.cap;
                    document.getElementById('pv-meta').textContent = t.dataset.meta || '';
                    document.getElementById('pv-pos').textContent = `${at + 1} / ${tiles.length}`;
                    pv.querySelectorAll('.pv-nav').forEach((b) => b.classList.toggle('d-none', tiles.length < 2));
                };
                tiles.forEach((t, i) => t.addEventListener('click', () => { show(i); modal.show(); }));
                pv.querySelectorAll('[data-pv]').forEach((b) => b.addEventListener('click', () => show(at + Number(b.dataset.pv))));
                pv.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') show(at - 1); if (e.key === 'ArrowRight') show(at + 1); });
                let x0 = null;
                pv.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
                pv.addEventListener('touchend', (e) => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 50) show(at + (dx < 0 ? 1 : -1)); x0 = null; });
            }

            const f = document.getElementById('submit-form');
            const box = document.getElementById('confirm');
            const btn = document.getElementById('submit-btn');
            const hint = document.getElementById('submit-hint');
            const can = {{ $canSubmit ? 'true' : 'false' }};
            box?.addEventListener('change', () => {
                btn.disabled = !(can && box.checked);
                if (can) hint.textContent = box.checked ? 'Ready. Your current location is recorded on submission.' : 'Tick the confirmation above to enable Submit.';
            });
            f?.addEventListener('submit', function (e) {
                if (f.dataset.go) return;
                e.preventDefault();
                if (!box.checked) { window.toast('Please tick the confirmation first.', 'warning'); return; }
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Getting location…';
                const go = () => { f.dataset.go = 1; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting…'; f.submit(); };
                if (!navigator.geolocation) return go();
                navigator.geolocation.getCurrentPosition((p) => {
                    f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.accuracy.value = Math.round(p.coords.accuracy); go();
                }, go, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
            });
            // Expand / collapse all steps
            const toggle = document.querySelector('[data-review-toggle-all]');
            toggle?.addEventListener('click', () => {
                const panels = [...document.querySelectorAll('#reviewAcc .collapse')];
                const anyClosed = panels.some((p) => !p.classList.contains('show'));
                panels.forEach((p) => bootstrap.Collapse.getOrCreateInstance(p, { toggle: false })[anyClosed ? 'show' : 'hide']());
                toggle.textContent = anyClosed ? 'Collapse all' : 'Expand all';
            });
        })();
    </script>
    @endpush
</x-layouts.app>
