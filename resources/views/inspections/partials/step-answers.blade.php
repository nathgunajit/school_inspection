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
    @php
        $totS = Q::totalStudents($answers); $totT = Q::totalTeachers($answers); $figs = Q::withDefaults($answers); /* V9: blank optional counts read 0 */
        // the male / female split (TSI_10..13), or the V10 totals (TSI_14 / TSI_15, rolled back) for an inspection that has only those
        $skipFig = array_diff(array_merge(Q::TEACHER_BREAKDOWN_CODES, Q::TOTALS_ONLY_CODES), Q::shownBreakdownCodes($answers));
    @endphp
    <div class="fig-grid">
        @foreach ($questions[$n] as $q)
            @continue(in_array($q['code'], $skipFig, true))
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
            @php $bdBad = $totT !== null && $bd['sum'] !== $totT; /* must be equal to Total teachers; 23-11-2026: male / female totals shown too */ @endphp
            <div class="fig-total {{ $bdBad ? 'is-bad' : '' }}"><i class="bi bi-mortarboard"></i>
                <span>Trained {{ $bd['trained'] }} · Untrained {{ $bd['untrained'] }}@if ($bd['split']) · Male {{ $bd['male'] }} · Female {{ $bd['female'] }}@endif<small>Trained + Untrained{{ $bd['split'] ? ' = Male + Female' : '' }}{{ $bdBad ? ' · not equal to Total teachers' : '' }}</small></span><b>{{ $bd['sum'] }}</b></div>
        @endif
        @if (($absT = V::absentLimit($answers)) !== null)
            <div class="fig-total is-highlight {{ $absT > 0 ? 'is-need' : '' }}"><i class="bi bi-person-x"></i>
                <span>Teachers absent today<small>Total teachers − Present ({{ $totT }} − {{ $figs['TSI_09'] ?? '—' }})</small></span><b>{{ $absT }}</b></div>
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
    @if ($inspection->absentTeachers->isNotEmpty())
        @php $abs = V::absentLimit($answers); $absN = $inspection->absentTeachers->count(); @endphp
        @if ($abs !== null)<div class="review-line text-secondary"><i class="bi bi-person-x text-danger"></i>{{ $absN }} of {{ $abs }} absent teacher(s) recorded (Total {{ Q::totalTeachers($answers) }} − Present {{ $answers['TSI_09'] ?? '—' }}).</div>@endif
        <div class="rv-table-wrap">
            {{-- Modification V10: each absent teacher on one line (name, type, reason, remarks) --}}
            <table class="rv-table rv-absent">
                <thead><tr><th>Sl.</th><th>Name of the teacher</th><th>Type</th><th>Reason for absence</th><th>Remarks</th></tr></thead>
                <tbody>
                @foreach ($inspection->absentTeachers as $i => $t)
                    <tr>
                        <td class="rv-sl">{{ $i + 1 }}</td>
                        <td class="rv-wide"><div class="rs-who"><span class="rs-avatar tone-danger">{{ collect(preg_split('/\s+/', trim($t->teacher_name)))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</span><span class="rv-name">{{ $t->teacher_name }}</span></div></td>
                        <td data-label="Type" class="rv-nowrap"><span class="rs-chip type"><i class="bi bi-person-badge"></i>{{ $t->typeLabel() }}</span></td>
                        <td data-label="Reason" class="rv-nowrap"><span class="rs-chip {{ $t->reason === 'UL' ? 'bad' : 'warn' }}"><i class="bi bi-calendar-x"></i>{{ $t->reasonLabel() }}</span></td>
                        <td data-label="Remarks" class="rv-wide">{!! $t->remarks ? e($t->remarks) : '<span class="rs-dash">—</span>' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        @php $abs = V::absentLimit($answers); @endphp
        <div class="review-line text-secondary"><i class="bi bi-person-check text-success"></i>{{ $abs === 0 || V::allTeachersPresent($answers)
            ? 'All teachers present (Total '.Q::totalTeachers($answers).', Present '.($answers['TSI_09'] ?? '—').'). No absent teacher to record.'
            : 'No absent teachers recorded.' }}</div>
    @endif
@elseif ($n === 4)
    @if ($inspection->classroomObservations->isNotEmpty())
        @php
            $obs = $inspection->classroomObservations;
            $grpCount = fn (string $list) => $obs->sum(fn ($c) => count(collect($c->tlmGroups())->firstWhere('list', $list)['items'] ?? []));
            $tlmIcon = ['fln' => 'bi-book-half', 'ecce' => 'bi-balloon-heart'];
        @endphp
        {{-- Summary strip: observed, using TLM, FLN / ECCE items ticked, satisfactory participation --}}
        <div class="cls-summary">
            <span class="cls-sum"><i class="bi bi-easel"></i><b>{{ $obs->count() }}</b> observed</span>
            <span class="cls-sum ok"><i class="bi bi-check2-circle"></i><b>{{ $obs->where('tlm_used', true)->count() }}</b> using TLM</span>
            <span class="cls-sum is-fln"><i class="bi bi-book-half"></i><b>{{ $grpCount('fln') }}</b> FLN items</span>
            <span class="cls-sum is-ecce"><i class="bi bi-balloon-heart"></i><b>{{ $grpCount('ecce') }}</b> ECCE items</span>
            <span class="cls-sum ok"><i class="bi bi-emoji-smile"></i><b>{{ $obs->where('participation', 'satisfactory')->count() }}</b> satisfactory</span>
        </div>
        <div class="rv-table-wrap">
            <table class="rv-table rv-cls">
                <thead><tr><th>Sl.</th><th>Class</th><th>Subject &amp; teacher</th><th>Use of TLM</th><th>Participation</th><th>Remarks</th></tr></thead>
                @foreach ($obs as $i => $c)
                    @php $ca = $c->toClientArray(); $groups = $ca['tlm_groups']; @endphp
                    <tbody class="rv-cls-rec">
                    <tr>
                        <td class="rv-sl">{{ $i + 1 }}</td>
                        <td class="rv-badge"><span class="rs-classbadge" title="{{ $ca['class'] }}">{{ $c->class }}</span></td>
                        <td class="rv-wide"><span class="rv-name">{{ $ca['subject'] }}</span><span class="rv-sub"><i class="bi bi-person me-1"></i>{{ $ca['teacher_name'] }} · {{ $ca['class'] }}</span></td>
                        <td data-label="Use of TLM">
                            <div class="tlm-use">
                                <span class="rs-chip {{ $ca['tlm_ok'] ? 'ok' : 'bad' }}"><i class="bi {{ $ca['tlm_ok'] ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }}"></i>{{ $ca['tlm_ok'] ? 'Yes' : 'No' }}</span>
                                @foreach ($groups as $g)<span class="tlm-badge is-{{ $g['list'] }}">{{ $g['short'] }} <b>{{ count($g['items']) }}</b></span>@endforeach
                            </div>
                        </td>
                        <td data-label="Participation"><span class="rs-chip {{ $ca['participation_ok'] ? 'ok' : 'bad' }}"><i class="bi {{ $ca['participation_ok'] ? 'bi-emoji-smile' : 'bi-emoji-frown' }}"></i>{{ $ca['participation'] }}</span></td>
                        <td data-label="Remarks">{!! $ca['remarks'] ? e($ca['remarks']) : '<span class="rs-dash">—</span>' !!}</td>
                    </tr>
                    @if ($groups)
                        <tr class="rv-tlm-row"><td class="rv-sl"></td><td colspan="5" class="rv-wide">
                            <div class="tlm-grps">
                                @foreach ($groups as $g)
                                    <div class="tlm-grp is-{{ $g['list'] }}">
                                        <div class="tlm-grp-head"><i class="bi {{ $tlmIcon[$g['list']] ?? 'bi-box-seam' }}"></i><span>{{ $g['label'] }}</span><span class="tlm-grp-n">{{ count($g['items']) }}</span></div>
                                        <div class="tlm-grp-items">@foreach ($g['items'] as $tn)<span class="tlm-grp-chip">{{ $tn }}</span>@endforeach</div>
                                    </div>
                                @endforeach
                            </div>
                        </td></tr>
                    @endif
                    </tbody>
                @endforeach
            </table>
        </div>
    @else
        <div class="review-line text-danger"><i class="bi bi-exclamation-circle"></i>At least one classroom record is needed.</div>
    @endif
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
