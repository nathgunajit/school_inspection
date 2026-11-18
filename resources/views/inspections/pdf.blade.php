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
    /* Light, clean official style (Modification V4): white page, neutral greys, one blue accent */
    @page { margin: 40mm 14mm 26mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; }
    header { position: fixed; top: -34mm; left: 0; right: 0; height: 30mm; }
    header table td { vertical-align: middle; }
    .hd-logo { width: 22mm; text-align: center; }
    .hd-logo img { height: 19mm; }
    .hd-logo.ssa img { height: auto; width: 30mm; }
    .hd-center { text-align: center; padding: 0 2mm; }
    .hd-govt { font-size: 10pt; font-weight: bold; color: #1f2937; letter-spacing: .4pt; }
    .hd-ssa { font-size: 8.5pt; color: #2e7d32; margin-top: .4mm; }
    .hd-title { font-size: 13.5pt; font-weight: bold; color: #1565c0; letter-spacing: 1pt; margin-top: 1mm; }
    .hd-sub { font-size: 7.5pt; color: #6b7280; margin-top: .3mm; }
    .hd-rule { height: 0; margin-top: 1.5mm; border-top: 1px solid #90b4e0; }
    .hd-ref { font-size: 7pt; color: #9ca3af; text-align: right; margin-top: .8mm; }
    .watermark { position: fixed; top: 92mm; left: -12mm; width: 206mm; text-align: center; transform: rotate(-35deg);
        color: #1565c0; opacity: .05; font-weight: bold; line-height: 1.25; }
    .watermark .w1 { font-size: 34pt; letter-spacing: 2pt; }
    .watermark .w2 { font-size: 26pt; letter-spacing: 4pt; }
    footer { position: fixed; bottom: -20mm; left: 0; right: 0; height: 16mm; font-size: 7.5pt; color: #6b7280; border-top: 1px solid #d1d5db; padding-top: 1.6mm; }
    footer strong { color: #374151; }
    footer .conf { margin-top: 1.4mm; text-align: center; font-size: 7.5pt; font-weight: bold; color: #b91c1c; letter-spacing: .6pt; }
    h2 { font-size: 10.5pt; margin: 6mm 0 2mm; padding: 0 0 1.2mm; color: #1565c0; border-bottom: 1px solid #cfe0f5; }
    table { width: 100%; border-collapse: collapse; }
    .ans td { padding: 1.5mm 2mm; border-bottom: 1px solid #f0f2f5; vertical-align: top; }
    .ans td.no { width: 6%; color: #9ca3af; }
    .ans td.v { width: 34%; font-weight: bold; color: #111827; }
    .rem { color: #b91c1c; font-size: 8.5pt; font-weight: normal; }
    .grid th { background: #f8fafc; color: #4b5563; text-align: left; font-size: 8pt; font-weight: bold; padding: 1.5mm 2mm; border: 1px solid #e5e7eb; }
    .grid td { padding: 1.5mm 2mm; border: 1px solid #e5e7eb; }
    .r { text-align: right; }
    .photos td { width: 50%; padding: 2mm; vertical-align: top; }
    .photo img { width: 100%; max-height: 62mm; border: 1px solid #e5e7eb; }
    .cap { font-size: 7.5pt; color: #4b5563; margin-top: 1mm; }
    .muted { color: #6b7280; }
    /* School card */
    .sc { border: 1px solid #e5e7eb; border-left: .9mm solid #1565c0; margin-bottom: 2mm; }
    .sc-head { padding: 3mm 4mm 2.4mm; border-bottom: 1px solid #eef0f3; }
    .sc-label { font-size: 6.5pt; letter-spacing: 1.5pt; color: #9ca3af; font-weight: bold; }
    .sc-name { font-size: 14pt; font-weight: bold; color: #111827; line-height: 1.2; margin-top: .6mm; }
    .sc-meta { margin-top: 1.6mm; }
    .sc-chip { display: inline-block; padding: .4mm 2mm; margin-right: 1.2mm; border-radius: 2mm; border: 1px solid #d1d5db; color: #4b5563; font-size: 7.5pt; }
    .sc-chip.st { border-color: #a7d7b5; color: #2e7d32; }
    .sc-grid td { width: 25%; padding: 2.2mm 4mm; vertical-align: top; border-right: 1px solid #f0f2f5; }
    .sc-grid td.c4 { border-right: 0; }
    .sc-grid .l { font-size: 6.8pt; text-transform: uppercase; letter-spacing: .8pt; color: #9ca3af; }
    .sc-grid .v { font-size: 10pt; font-weight: bold; color: #1f2937; margin-top: .6mm; }
    .mono { font-family: DejaVu Sans Mono, monospace; letter-spacing: .6pt; }
    /* Basic information: two label/value pairs per row */
    .bi td { padding: 1.7mm 2.4mm; border-bottom: 1px solid #f0f2f5; vertical-align: top; }
    .bi td.k { width: 18%; color: #6b7280; font-size: 8pt; }
    .bi td.v { width: 32%; font-weight: bold; color: #111827; }
</style>
</head>
<body>
{{-- Official header, repeated on every page --}}
<header>
    <table><tr>
        <td class="hd-logo">@if (! empty($logos['assam']))<img src="{{ $logos['assam'] }}" alt="Government of Assam">@endif</td>
        <td class="hd-center">
            <div class="hd-govt">GOVERNMENT OF ASSAM</div>
            <div class="hd-ssa">Samagra Shiksha Abhiyan, Assam</div>
            <div class="hd-title">SCHOOL INSPECTION REPORT</div>
            <div class="hd-sub">School Monitoring and Assessment System</div>
        </td>
        <td class="hd-logo ssa">@if (! empty($logos['ssa']))<img src="{{ $logos['ssa'] }}" alt="Samagra Shiksha">@endif</td>
    </tr></table>
    <div class="hd-rule"></div>
    <div class="hd-ref">{{ $inspection->inspection_no ?? 'DRAFT' }} · {{ $inspection->status->label() }} · UDISE {{ $inspection->school->udise_code }}</div>
</header>

{{-- Watermark, repeated on every page --}}
<div class="watermark"><div class="w1">SCHOOL INSPECTION REPORT</div><div class="w2">SSA, ASSAM</div></div>

{{-- Footer, repeated on every page; "Page X of Y" is stamped by ReportController::pdf() --}}
<footer>
    <div>Generated by: <strong>School Inspection &amp; Monitoring System</strong></div>
    <div>Generated Date &amp; Time: <strong>{{ ($generatedAt ?? now())->format('d-m-Y H:i') }}</strong></div>
    <div class="conf">CONFIDENTIAL – FOR OFFICIAL USE ONLY</div>
</footer>

{{-- School identity card: name, UDISE code and location (district / block / cluster) --}}
<div class="sc">
    <div class="sc-head">
        <div class="sc-label">SCHOOL</div>
        <div class="sc-name">{{ $s->name }}</div>
        <div class="sc-meta">
            <span class="sc-chip">{{ $s->management?->label ?? 'Management —' }}</span>
            <span class="sc-chip">{{ $s->category?->label ?? 'Category —' }}</span>
            <span class="sc-chip st">{{ $inspection->status->label() }}</span>
        </div>
    </div>
    <table class="sc-grid"><tr>
        <td class="c1"><div class="l">UDISE Code</div><div class="v mono">{{ $s->udise_code }}</div></td>
        <td class="c2"><div class="l">District</div><div class="v">{{ $s->district?->name ?? '—' }}</div></td>
        <td class="c3"><div class="l">Block</div><div class="v">{{ $s->block?->name ?? '—' }}</div></td>
        <td class="c4"><div class="l">Cluster</div><div class="v">{{ $s->cluster?->name ?? '—' }}</div></td>
    </tr></table>
</div>

<h2>1. Basic Information</h2>
@php
    $loc = fn ($lat, $lng, $d) => $lat ? number_format($lat, 6).', '.number_format($lng, 6).$fmtDist($d) : 'Not available';
    $basic = [
        ['Inspection ID', $inspection->inspection_no ?? '—', 'Status', $inspection->status->label()],
        ['Inspecting officer', $inspection->officer->name, 'Designation', $inspection->officer->designation?->label ?? '—'],
        ['Inspection started', $inspection->started_at->format('d M Y, h:i A'), 'Submitted', $inspection->submitted_at?->format('d M Y, h:i A') ?? '—'],
        ['Person met', $inspection->person_met_name ?: '—', 'Their designation', $inspection->person_met_designation ?: '—'],
    ];
@endphp
<table class="bi">
    @foreach ($basic as [$k1, $v1, $k2, $v2])
        <tr><td class="k">{{ $k1 }}</td><td class="v">{{ $v1 }}</td><td class="k">{{ $k2 }}</td><td class="v">{{ $v2 }}</td></tr>
    @endforeach
    <tr><td class="k">Device location (start)</td><td class="v" colspan="3">{{ $loc($inspection->start_lat, $inspection->start_lng, $startDistance) }}</td></tr>
    <tr><td class="k">Device location (submit)</td><td class="v" colspan="3">{{ $loc($inspection->submit_lat, $inspection->submit_lng, $submitDistance) }}</td></tr>
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

    @if ($n === 2)
        @php $totS = QD::totalStudents($answers); $totT = QD::totalTeachers($answers); $presT = $answers['TSI_09'] ?? null; @endphp
        <table class="grid"><tr><th>Total students (Present + Absent)</th><th>Total teachers (Regular + Contractual + SSA + Tutors)</th><th>Teachers absent (Total − Present)</th></tr>
            <tr><td class="r"><strong>{{ $totS ?? '—' }}</strong></td><td class="r"><strong>{{ $totT ?? '—' }}</strong></td>
                <td class="r"><strong>{{ ($totT !== null && $presT !== null && $presT !== '') ? $totT - (int) $presT : '—' }}</strong></td></tr>
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
        @php
            $FP = \App\Models\InspectionFundPosition::class;
            $fundRows = $inspection->fundPositions->sortByDesc('financial_year')->values();
            $rec = fn ($f) => $f->isLegacy() ? (float) $f->amount_received : $f->totalReceived();
            $exp = fn ($f) => $f->isLegacy() ? (float) $f->amount_utilised : $f->totalExpenditure();
        @endphp
        @forelse ($fundRows as $f)
            <p style="margin:2mm 0 1mm"><strong>{{ str_replace('FY-', 'FY ', $f->financial_year) }}</strong> <span class="muted">(amounts in Rs lakh)</span></p>
            <table class="grid"><tr><th>Head</th><th class="r">Received</th><th class="r">Expenditure</th><th class="r">Balance</th></tr>
                @if ($f->isLegacy())
                    <tr><td>{{ $f->category?->label ?? 'Fund' }}</td><td class="r">{{ $FP::lakh((float) $f->amount_received) }}</td><td class="r">{{ $FP::lakh((float) $f->amount_utilised) }}</td><td class="r"><strong>{{ $FP::lakh($f->balance()) }}</strong></td></tr>
                @else
                    @foreach ($FP::HEADS as $key => [$label, $rc, $ec])
                        <tr><td>{{ $label }}</td><td class="r">{{ $FP::lakh((float) $f->$rc) }}</td><td class="r">{{ $FP::lakh((float) $f->$ec) }}</td><td class="r"><strong>{{ $FP::lakh($f->balance($key)) }}</strong></td></tr>
                    @endforeach
                    <tr><td><strong>Total</strong></td><td class="r"><strong>{{ $FP::lakh($rec($f)) }}</strong></td><td class="r"><strong>{{ $FP::lakh($exp($f)) }}</strong></td><td class="r"><strong>{{ $FP::lakh($f->balance()) }}</strong></td></tr>
                @endif
            </table>
        @empty
            <p class="muted">No fund details entered.</p>
        @endforelse
        @if ($fundRows->count() > 1)
            @php $gr = $fundRows->sum($rec); $ge = $fundRows->sum($exp); @endphp
            <p style="margin:2mm 0 1mm"><strong>Year-wise summary</strong> <span class="muted">(Rs lakh)</span></p>
            <table class="grid"><tr><th>Financial year</th><th class="r">Received</th><th class="r">Expenditure</th><th class="r">Balance</th></tr>
                @foreach ($fundRows as $f)
                    <tr><td>{{ str_replace('FY-', 'FY ', $f->financial_year) }}</td><td class="r">{{ $FP::lakh($rec($f)) }}</td><td class="r">{{ $FP::lakh($exp($f)) }}</td><td class="r">{{ $FP::lakh($f->balance()) }}</td></tr>
                @endforeach
                <tr><td><strong>All years</strong></td><td class="r"><strong>{{ $FP::lakh($gr) }}</strong></td><td class="r"><strong>{{ $FP::lakh($ge) }}</strong></td><td class="r"><strong>{{ $FP::lakh($gr - $ge) }}</strong></td></tr>
            </table>
        @endif
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
