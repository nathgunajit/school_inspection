{{-- Answers of one step, shared by the review page and the submitted report page (Modification V4).
     Vars: $n, $questions, $answers, $inspection, $photos, $suggestions, $photoCategories;
     optional $remarks (code => text), $skipBasic (hide BAS_* person-met fields), $downloads (photo download links). --}}
@php
    use App\Questionnaire\InspectionValidator as V;
    use App\Questionnaire\QuestionnaireDefinition as Q;
    $remarks ??= [];
    $skipBasic ??= false;
    $downloads ??= false;
@endphp
@if ($n === 2)
    {{-- Figures: label + large number, with automatic totals --}}
    @php $totS = Q::totalStudents($answers); $totT = Q::totalTeachers($answers); $figs = Q::withDefaults($answers); /* V9: blank optional counts read 0 */ @endphp
    <div class="fig-grid">
        @foreach ($questions[$n] as $q)
            @php $v = $figs[$q['code']] ?? null; $has = V::isFilled($v); @endphp
            <div class="fig-tile {{ $has ? '' : 'is-empty' }}">
                <span class="fig-label">{{ $q['label'] }}</span>
                <span class="fig-value">@if ($has){{ number_format((int) $v) }}@else<span class="{{ V::required($q, $answers) ? 'text-danger' : 'text-secondary' }}">Not entered</span>@endif</span>
            </div>
        @endforeach
    </div>
    <div class="fig-totals">
        <div class="fig-total"><i class="bi bi-backpack"></i><span>Total students <small>Present + Absent</small></span><b>{{ $totS === null ? '—' : number_format($totS) }}</b></div>
        <div class="fig-total"><i class="bi bi-person-workspace"></i><span>Total teachers <small>Regular + Contractual + SSA + Tutors</small></span><b>{{ $totT === null ? '—' : number_format($totT) }}</b></div>
        @if ($bd = Q::teacherBreakdown($answers))
            @php $bdBad = $totT !== null && $bd['sum'] > $totT; @endphp
            <div class="fig-total {{ $bdBad ? 'is-bad' : '' }}"><i class="bi bi-mortarboard"></i>
                <span>Trained {{ $bd['trained'] }} · Untrained {{ $bd['untrained'] }}<small>Male {{ $bd['male'] }} · Female {{ $bd['female'] }}{{ $bdBad ? ' · more than Total teachers' : '' }}</small></span><b>{{ $bd['sum'] }}</b></div>
        @endif
    </div>
@elseif ($n !== 3 && ! empty($questions[$n]))
    {{-- Question → answer rows; the answer is shown as a coloured pill / figure / text block --}}
    <div class="qa-list">
        @foreach ($questions[$n] as $q)
            @continue(! V::visible($q, $answers))
            @continue($skipBasic && str_starts_with($q['code'], 'BAS_'))
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
                @if (! empty($remarks[$q['code']]))
                    <div class="qa-remark"><i class="bi bi-chat-left-text"></i><span><b>Remarks:</b> {{ $remarks[$q['code']] }}</span></div>
                @endif
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
        <div class="review-line text-secondary"><i class="bi bi-person-check text-success"></i>{{ V::allTeachersPresent($answers) ? "All teachers present (no absent teacher)." : "No absent teachers recorded." }}</div>
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
                @if ($ca['tlm_items'])<div class="rs-note tlm-used"><i class="bi bi-box-seam"></i>{{ implode(', ', $ca['tlm_items']) }}</div>@endif
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
                            @php
                                $cap = $p->caption ?: ($catLabels[$code] ?? 'Photo');
                                $dist = $p->hasLocation() ? $inspection->distanceFromSchool($p->latitude, $p->longitude) : null;
                                $where = $p->hasLocation() ? ' · '.($dist !== null ? ($dist < 1000 ? $dist.' m' : round($dist / 1000, 1).' km').' from school' : 'Geo-tagged') : ' · No location';
                            @endphp
                            <div class="rv-photo-wrap">
                            <button type="button" class="rv-photo" data-photo="{{ route('evidence.show', $p) }}" data-cap="{{ $cap }}"
                                    data-meta="{{ $p->taken_at?->format('d M Y, h:i A') }}{{ $p->source === 'gallery' ? ' · From gallery' : '' }}{{ $where }}" aria-label="View {{ $cap }}">
                                <img src="{{ route('evidence.show', $p) }}" alt="{{ $cap }}" loading="lazy">
                                <span class="rv-photo-tags">
                                    @if ($p->source === 'gallery')<span class="rv-tag">From gallery</span>@endif
                                    @if ($p->hasLocation())<span class="rv-tag geo" title="Geo-tagged"><i class="bi bi-geo-alt-fill"></i></span>@endif
                                </span>
                                <span class="rv-photo-cap">{{ $cap }}</span>
                            </button>
                            @if ($downloads)
                                <a href="{{ route('evidence.show', [$p, 'download' => 1]) }}" class="rv-dl" title="Download original" aria-label="Download {{ $cap }}"><i class="bi bi-download"></i></a>
                            @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
