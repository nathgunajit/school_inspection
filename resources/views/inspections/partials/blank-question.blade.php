{{-- Paper form: one question row (No. / Question / Answer). Vars: $q (QuestionnaireDefinition question), $no ("2.3"). --}}
@php
    use App\Questionnaire\QuestionnaireDefinition as QD;
    $optLabels = fn ($q, $vals) => implode(' / ', array_map(fn ($v) => $q['options'][$v] ?? ucfirst($v), (array) $vals));
@endphp
<tr>
    <td class="no">{{ $no }}</td>
    <td class="q">{{ $q['label'] }}@if (! empty($q['required'])) <span class="req">*</span>@endif
        @if ($cond = $q['show_if'] ?? $q['enable_if'] ?? null)
            @php $cq = QD::find($cond[0]); @endphp
            <div class="cond">→ Answer only if “{{ $cq['label'] ?? $cond[0] }}” is {{ $cq ? $optLabels($cq, $cond[1]) : implode(' / ', $cond[1]) }}</div>
        @endif
        @if (! empty($q['hint']))<div class="hint">{{ $q['hint'] }}</div>@endif</td>
    <td class="a">
        @if (in_array($q['type'], ['radio', 'select', 'multi'], true))
            @include('inspections.partials.blank-opts', ['opts' => $q['options']])
            @if ($q['type'] === 'multi')<div class="multi">Tick all that apply</div>@endif
            @if (! empty($q['remarks_if']))<div class="rmk">Remarks ({{ ! empty($q['remarks_optional']) ? 'optional, ' : '' }}if {{ $optLabels($q, $q['remarks_if']) }}):<div class="txt"></div></div>@endif
        @elseif ($q['type'] === 'number')<table class="nb"><tr><td class="nbx"></td></tr></table>
        @else<div class="txt"></div><div class="txt"></div>@endif
    </td>
</tr>
