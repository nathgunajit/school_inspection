@php
    use App\Questionnaire\QuestionnaireDefinition as QD;
    use App\Questionnaire\InspectionValidator as V;
    $s = $inspection->school;
    $fmtDist = fn ($d) => $d === null ? '' : ' ('.($d < 1000 ? $d.' m' : round($d / 1000, 2).' km').' from school)';
    $photos = $inspection->evidence->where('kind', 'photo')->values();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Inspection Report {{ $inspection->inspection_no }}</title>
<style>
    @page { margin: 22mm 14mm 18mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1e293b; }
    header { position: fixed; top: -16mm; left: 0; right: 0; height: 12mm; border-bottom: 2px solid #4338ca; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; font-size: 7.5pt; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 2mm; }
    .pagenum:before { content: counter(page); }
    h1 { font-size: 15pt; margin: 0 0 2mm; color: #0f172a; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; padding: 1.6mm 2.5mm; background: #eef2ff; color: #3730a3; border-left: 3px solid #4338ca; }
    table { width: 100%; border-collapse: collapse; }
    .kv td { padding: 1.4mm 2mm; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .kv td.k { width: 30%; color: #64748b; }
    .ans td { padding: 1.6mm 2mm; border-bottom: 1px solid #eef0f5; vertical-align: top; }
    .ans td.no { width: 6%; color: #94a3b8; }
    .ans td.v { width: 34%; font-weight: bold; }
    .rem { color: #b91c1c; font-size: 8.5pt; font-weight: normal; }
    .grid th { background: #f1f5f9; text-align: left; font-size: 8pt; padding: 1.5mm 2mm; border: 1px solid #e2e8f0; }
    .grid td { padding: 1.5mm 2mm; border: 1px solid #e2e8f0; }
    .r { text-align: right; }
    .badge { display: inline-block; padding: 0.6mm 2mm; border-radius: 2mm; background: #dcfce7; color: #166534; font-size: 8pt; font-weight: bold; }
    .photos td { width: 50%; padding: 2mm; vertical-align: top; }
    .photo img { width: 100%; max-height: 62mm; border: 1px solid #e2e8f0; }
    .cap { font-size: 7.5pt; color: #475569; margin-top: 1mm; }
    .muted { color: #64748b; }
</style>
</head>
<body>
<header>
    <table><tr>
        <td style="font-weight:bold;color:#3730a3;font-size:11pt">School Inspection Report</td>
        <td class="r muted" style="font-size:8pt">{{ $inspection->inspection_no ?? 'DRAFT' }} · {{ $inspection->status->label() }}</td>
    </tr></table>
</header>
<footer>
    <table><tr>
        <td>Generated {{ now()->format('d M Y, h:i A') }} · School Inspection Management System</td>
        <td class="r">Page <span class="pagenum"></span></td>
    </tr></table>
</footer>

<h1>{{ $s->name }}</h1>
<div class="muted">UDISE {{ $s->udise_code }} · {{ $s->cluster?->name }}, {{ $s->block?->name }}, {{ $s->district?->name }}</div>

<h2>1. Basic Information</h2>
<table class="kv">
    <tr><td class="k">Inspection ID</td><td><strong>{{ $inspection->inspection_no ?? '—' }}</strong> <span class="badge">{{ $inspection->status->label() }}</span></td></tr>
    <tr><td class="k">Inspecting officer</td><td>{{ $inspection->officer->name }}, {{ $inspection->officer->designation?->label }}</td></tr>
    <tr><td class="k">Date &amp; time of inspection</td><td>{{ $inspection->started_at->format('d M Y, h:i A') }} (submitted {{ $inspection->submitted_at?->format('d M Y, h:i A') ?? '—' }})</td></tr>
    <tr><td class="k">School management / category</td><td>{{ $s->management?->label ?? '—' }} / {{ $s->category?->label ?? '—' }}</td></tr>
    <tr><td class="k">Device location (start)</td><td>{{ $inspection->start_lat ? number_format($inspection->start_lat, 6).', '.number_format($inspection->start_lng, 6).$fmtDist($startDistance) : 'Not available' }}</td></tr>
    <tr><td class="k">Device location (submit)</td><td>{{ $inspection->submit_lat ? number_format($inspection->submit_lat, 6).', '.number_format($inspection->submit_lng, 6).$fmtDist($submitDistance) : 'Not available' }}</td></tr>
    <tr><td class="k">Person met</td><td>{{ $inspection->person_met_name }} {{ $inspection->person_met_designation ? '('.$inspection->person_met_designation.')' : '' }}</td></tr>
</table>

@foreach ($steps as $n => $st)
    @continue($n === count($steps))
    <h2>{{ $n + 1 }}. {{ $st['title'] }}</h2>
    @php $qs = collect($questions[$n])->reject(fn ($q) => str_starts_with($q['code'], 'BAS_'))->filter(fn ($q) => V::visible($q, $answers))->values(); @endphp
    @if ($qs->isNotEmpty())
        <table class="ans">
            @foreach ($qs as $i => $q)
                <tr>
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ $q['label'] }}@if (! empty($remarks[$q['code']]))<div class="rem">Remarks: {{ $remarks[$q['code']] }}</div>@endif</td>
                    <td class="v">{{ QD::optionLabel($q['code'], $answers[$q['code']] ?? null) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($n === 3)
        @if ($inspection->absentTeachers->isNotEmpty())
            <table class="grid"><tr><th>Teacher's name</th><th>Teacher's type</th><th>Reason for absence</th><th>Remarks</th></tr>
                @foreach ($inspection->absentTeachers as $t)<tr><td>{{ $t->teacher_name }}</td><td>{{ $t->typeLabel() }}</td><td>{{ $t->reasonLabel() }}</td><td>{{ $t->remarks }}</td></tr>@endforeach
            </table>
        @else <p class="muted">No absent teachers recorded.</p> @endif
    @endif
    @if ($n === 4)
        @if ($inspection->classroomObservations->isNotEmpty())
            <table class="grid"><tr><th>Teacher</th><th>Class</th><th>Subject</th><th>Use of TLM</th><th>Participation</th><th>Remarks</th></tr>
                @foreach ($inspection->classroomObservations as $c)<tr><td>{{ $c->teacher_name }}</td><td>{{ \App\Models\InspectionClassroomObservation::CLASSES[$c->class] ?? $c->class }}</td><td>{{ $c->subject }}</td><td>{{ $c->tlm_used ? 'Yes' : 'No' }}</td><td>{{ $c->participationLabel() }}</td><td>{{ $c->remarks }}</td></tr>@endforeach
            </table>
        @else <p class="muted">No classroom records.</p> @endif
    @endif
    @if ($n === 5)
        @forelse ($inspection->fundPositions as $f)
            <p style="margin:2mm 0 1mm"><strong>{{ $f->financial_year }}</strong> <span class="muted">(amounts in Rs lakh)</span></p>
            <table class="grid"><tr><th>Head</th><th class="r">Received</th><th class="r">Expenditure</th><th class="r">Balance</th></tr>
                @if ($f->isLegacy())
                    <tr><td>{{ $f->category?->label ?? 'Fund' }}</td><td class="r">{{ number_format((float) $f->amount_received, 2) }}</td><td class="r">{{ number_format((float) $f->amount_utilised, 2) }}</td><td class="r"><strong>{{ number_format($f->balance(), 2) }}</strong></td></tr>
                @else
                    @foreach (\App\Models\InspectionFundPosition::HEADS as $key => [$label, $rc, $ec])
                        <tr><td>{{ $label }}</td><td class="r">{{ number_format((float) $f->$rc, 2) }}</td><td class="r">{{ number_format((float) $f->$ec, 2) }}</td><td class="r"><strong>{{ number_format($f->balance($key), 2) }}</strong></td></tr>
                    @endforeach
                @endif
            </table>
        @empty
            <p class="muted">No fund details entered.</p>
        @endforelse
    @endif
    @if ($n === 6)
        @php $sug = $inspection->remarks->where('type', 'suggestion'); @endphp
        <p style="margin:3mm 0 1mm"><strong>Suggestions</strong></p>
        @if ($sug->isNotEmpty())
            <ol style="margin:0 0 2mm 5mm;padding:0">@foreach ($sug as $sg)<li style="margin-bottom:1mm">{{ $sg->body }}</li>@endforeach</ol>
        @else
            <p class="muted">No suggestions recorded.</p>
        @endif
    @endif
@endforeach

@if ($photos->isNotEmpty())
    <h2>{{ count($steps) + 1 }}. Photographs of School ({{ $photos->count() }})</h2>
    <table class="photos">
        @foreach ($photos->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $p)
                    <td class="photo">
                        @if (! empty($images[$p->id]))<img src="{{ $images[$p->id] }}" alt="">@endif
                        <div class="cap"><strong>{{ $p->category?->label }}</strong>@if ($p->source === 'gallery') · <em>From gallery</em>@endif @if ($p->question_code) · {{ $p->question_code }}@endif @if ($p->caption) · {{ $p->caption }}@endif<br>
                            {{ $p->taken_at?->format('d M Y, h:i:s A') }} ·
                            @if ($p->hasLocation()) {{ number_format($p->latitude, 6) }}, {{ number_format($p->longitude, 6) }}{{ $fmtDist($inspection->distanceFromSchool($p->latitude, $p->longitude)) }}
                            @else <strong>No location</strong>{{ $p->no_location_reason ? ': '.$p->no_location_reason : '' }} @endif<br>
                            <span class="muted">SHA-256 {{ substr($p->sha256, 0, 16) }}…</span></div>
                    </td>
                @endforeach
                @if ($pair->count() === 1)<td></td>@endif
            </tr>
        @endforeach
    </table>
@endif

<table style="margin-top:14mm"><tr>
    <td style="width:60%"></td>
    <td style="border-top:1px solid #94a3b8;text-align:center;padding-top:2mm">{{ $inspection->officer->name }}<br><span class="muted">{{ $inspection->officer->designation?->label }}</span></td>
</tr></table>
</body>
</html>
