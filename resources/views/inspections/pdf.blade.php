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
    @continue($n === 1 || $n === 6)
    <h2>{{ $n }}. {{ $st['title'] }}</h2>
    <table class="ans">
        @foreach ($questions[$n] as $i => $q)
            @continue(! V::visible($q, $answers))
            <tr>
                <td class="no">{{ $n === 3 ? chr(97 + $i) : $i + 1 }}</td>
                <td>{{ $q['label'] }}@if (! empty($remarks[$q['code']]))<div class="rem">Remarks: {{ $remarks[$q['code']] }}</div>@endif</td>
                <td class="v">{{ QD::optionLabel($q['code'], $answers[$q['code']] ?? null) }}</td>
            </tr>
        @endforeach
    </table>

    @if ($n === 4 && $inspection->absentTeachers->isNotEmpty())
        <p style="margin:3mm 0 1mm"><strong>Absent teachers</strong></p>
        <table class="grid"><tr><th>Name</th><th>Status</th><th>Remarks</th></tr>
            @foreach ($inspection->absentTeachers as $t)<tr><td>{{ $t->teacher_name }}</td><td>{{ $t->statusLabel() }}</td><td>{{ $t->remarks }}</td></tr>@endforeach
        </table>
    @endif
    @if ($n === 5 && $inspection->fundPositions->isNotEmpty())
        <p style="margin:3mm 0 1mm"><strong>Fund position</strong></p>
        <table class="grid"><tr><th>Year</th><th>Fund</th><th class="r">Received (Rs)</th><th class="r">Utilised (Rs)</th><th class="r">Balance (Rs)</th></tr>
            @foreach ($inspection->fundPositions as $f)<tr><td>{{ $f->financial_year }}</td><td>{{ $f->category?->label }}</td><td class="r">{{ number_format((float) $f->amount_received, 2) }}</td><td class="r">{{ number_format((float) $f->amount_utilised, 2) }}</td><td class="r"><strong>{{ number_format($f->balance(), 2) }}</strong></td></tr>@endforeach
        </table>
    @endif
@endforeach

<h2>6. Suggestions &amp; Remarks</h2>
@php $sug = $inspection->remarks->where('type', 'suggestion'); @endphp
@if ($sug->isNotEmpty())
    <ol style="margin:0 0 2mm 5mm;padding:0">@foreach ($sug as $sg)<li style="margin-bottom:1mm">{{ $sg->body }}</li>@endforeach</ol>
@else
    <p class="muted">No suggestions recorded.</p>
@endif
<p><strong>General remarks:</strong> {{ $answers['FIN_GENERAL_REMARKS'] ?? '—' }}</p>

@if ($photos->isNotEmpty())
    <h2>Photographic Evidence ({{ $photos->count() }})</h2>
    <table class="photos">
        @foreach ($photos->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $p)
                    <td class="photo">
                        @if (! empty($images[$p->id]))<img src="{{ $images[$p->id] }}" alt="">@endif
                        <div class="cap"><strong>{{ $p->category?->label }}</strong>@if ($p->question_code) · {{ $p->question_code }}@endif @if ($p->caption) · {{ $p->caption }}@endif<br>
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
