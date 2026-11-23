@php
    use App\Questionnaire\QuestionnaireDefinition as QD;
    use App\Questionnaire\InspectionValidator as V;
    $s = $inspection->school;
    $fmtDist = fn ($d) => $d === null ? '' : ' ('.($d < 1000 ? $d.' m' : round($d / 1000, 2).' km').' from school)';
    $photos = $inspection->evidence->where('kind', 'photo')->values();
    // answer tone dot: green / amber / red, from the same mapping as the web report
    $dot = fn ($v) => match (QD::tone(is_array($v) ? ($v[0] ?? null) : $v)) { 'tone-good' => 'g', 'tone-mid' => 'm', 'tone-bad' => 'b', default => '' };
    $sec = 0; // running section number
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Inspection Report {{ $inspection->inspection_no }}</title>
<style>
    /* Clean official style: white page, one blue accent, soft tints. DomPDF: tables + simple CSS only. */
    @page { margin: 40mm 14mm 26mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1f2937; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }

    /* Header (every page) */
    header { position: fixed; top: -35mm; left: 0; right: 0; height: 31mm; }
    header table td { vertical-align: middle; }
    .hd-logo { width: 24mm; text-align: center; }
    .hd-logo img { height: 18mm; }
    .hd-logo.ssa img { height: auto; width: 29mm; }
    .hd-center { text-align: center; padding: 0 2mm; }
    .hd-govt { font-size: 9.5pt; font-weight: bold; color: #1f2937; letter-spacing: 1.2pt; }
    .hd-ssa { font-size: 8pt; color: #1b5e20; font-weight: bold; margin-top: .4mm; letter-spacing: .3pt; }
    .hd-title { font-size: 14pt; font-weight: bold; color: #0d47a1; letter-spacing: 1.6pt; margin-top: 1.2mm; }
    .hd-sub { font-size: 7.5pt; color: #64748b; margin-top: .3mm; letter-spacing: .4pt; }
    .hd-rule-b { height: 0; margin-top: 1.6mm; border-top: 1.4px solid #1565c0; }
    .hd-ref { margin-top: 1.2mm; text-align: right; }
    .ref { display: inline-block; font-size: 7pt; color: #334155; background: #f1f5f9; border: .5px solid #d8dee8; border-radius: 1.4mm; padding: .3mm 1.8mm; margin-left: 1mm; }
    .ref b { color: #0d47a1; }

    /* Footer (every page); the background watermark is partials/pdf-watermark */
    footer { position: fixed; bottom: -20mm; left: 0; right: 0; height: 16mm; font-size: 7.5pt; color: #475569; border-top: .6px solid #cbd5e1; padding-top: 1.6mm; }
    footer strong { color: #1f2937; }
    footer .conf { margin-top: 1.2mm; text-align: center; font-size: 7.5pt; font-weight: bold; color: #b91c1c; letter-spacing: 1.4pt; }

    /* School card */
    .sc { border: .6px solid #c9d8ee; border-left: 1.4mm solid #1565c0; border-radius: 1.6mm; margin-bottom: 1mm; }
    .sc-head { padding: 3mm 4mm 2.6mm; background: #f5f8fd; }
    .sc-label { font-size: 6.8pt; letter-spacing: 1.6pt; color: #1565c0; font-weight: bold; }
    .sc-name { font-size: 14.5pt; font-weight: bold; color: #0f172a; line-height: 1.2; margin-top: .6mm; }
    .sc-chip { display: inline-block; padding: .3mm 2mm; margin: 1.4mm 1.2mm 0 0; border-radius: 2mm; background: #fff; border: .5px solid #cbd5e1; color: #334155; font-size: 7.5pt; }
    .sc-chip.st { border-color: #86c79b; background: #ecf8f0; color: #166534; font-weight: bold; }
    .sc-grid td { width: 25%; padding: 2.2mm 4mm; vertical-align: top; border-top: .6px solid #e2e8f0; border-right: .6px solid #e2e8f0; }
    .sc-grid td.c4 { border-right: 0; }
    .lbl { font-size: 6.6pt; text-transform: uppercase; letter-spacing: .8pt; color: #64748b; font-weight: bold; }
    .val { font-size: 10pt; font-weight: bold; color: #0f172a; margin-top: .6mm; }
    .mono { font-family: DejaVu Sans Mono, monospace; letter-spacing: .5pt; }

    /* Section heading: numbered badge + title + hairline */
    .h { margin: 6.5mm 0 2.2mm; page-break-after: avoid; }
    .h td { vertical-align: middle; }
    .h td { padding: 0; }
    .h .n { width: 6.5mm; height: 6.5mm; background: #1565c0; color: #fff; text-align: center; vertical-align: middle; font-size: 8.5pt; font-weight: bold; }
    .h .t { font-size: 11pt; font-weight: bold; color: #0d47a1; padding-left: 2.5mm; }
    .h .c { text-align: right; font-size: 7.5pt; color: #64748b; }
    .h-rule { border-top: .6px solid #dbe3ef; margin: -1mm 0 2mm; }

    /* Key / value and question tables: zebra rows, no vertical borders */
    .kv td { padding: 1.8mm 2.4mm; border-bottom: .6px solid #e5e9f0; vertical-align: top; }
    .kv tr:nth-child(even) td { background: #f8fafc; }
    .kv td.k { width: 21%; color: #64748b; }
    .kv td.v { width: 29%; font-weight: bold; color: #0f172a; }
    .qa td { padding: 1.8mm 2.4mm; border-bottom: .6px solid #e5e9f0; vertical-align: top; }
    .qa tr.z td { background: rgba(15, 23, 42, .03); } /* see-through, so the background logo shows */
    .qa td.no { width: 6%; color: #94a3b8; }
    .qa td.v { width: 34%; font-weight: bold; color: #0f172a; }
    .rem { color: #64748b; font-size: 7.8pt; font-style: italic; margin-top: .6mm; }
    .d { font-size: 8pt; padding-right: 1.2mm; color: #cbd5e1; }
    .d.g { color: #1e9b4b; } .d.m { color: #f39223; } .d.b { color: #d93025; }

    /* Data tables: dark-blue head, zebra rows, Sl. column */
    .dt { margin-top: 1mm; table-layout: fixed; }
    .dt td { word-wrap: break-word; }
    .dt th { background: #0d47a1; color: #fff; text-align: left; font-size: 7.4pt; font-weight: bold; padding: 1.8mm 2.2mm; letter-spacing: .3pt; }
    .dt td { padding: 1.8mm 2.2mm; border-bottom: .6px solid #e5e9f0; vertical-align: top; }
    .dt tr:nth-child(even) td { background: #f8fafc; }
    .dt td.sl, .dt th.sl { width: 7%; text-align: center; color: #64748b; }
    .dt th.sl { color: #fff; }
    .dt tr.tot td { background: #eaf2fc; font-weight: bold; color: #0d47a1; border-bottom: 0; }
    .r, .dt th.r, .dt td.r { text-align: right; }
    .keep { page-break-inside: avoid; }
    /* classroom rows carry a second line (TLM items), so their zebra is set per record */
    .dt.cls tr td { background: #fff; }
    .dt.cls tr.z td { background: #f8fafc; }
    .dt.cls tr.main td { border-bottom: 0; }
    .dt.cls tr.sub td { padding-top: 0; font-size: 7.6pt; color: #475569; }
    .dt.cls tr.sub b.fln { color: #0d47a1; } .dt.cls tr.sub b.ecce { color: #9a4a00; }
    /* FLN (blue) / ECCE (orange) TLM tags */
    .tag { display: inline-block; padding: .1mm 1.3mm; border-radius: 1.5mm; font-size: 6.6pt; font-weight: bold; margin-left: .8mm; }
    .tag.fln { background: #e3eefb; color: #0d47a1; } .tag.ecce { background: #fff1de; color: #9a4a00; }
    .dt.cls tr.sub .tag { margin-left: 0; }
    /* V10: absent teachers on one line */
    .dt.abs td { vertical-align: middle; }
    .dt td.nw { white-space: nowrap; overflow: hidden; }
    .dt.abs .pill { padding: .2mm 1.4mm; font-size: 7pt; }
    .pill { display: inline-block; padding: .2mm 1.8mm; border-radius: 2mm; font-size: 7.4pt; font-weight: bold; }
    .pill.ok { background: #e3f4e9; color: #166534; } .pill.bad { background: #fde8e7; color: #b42318; } .pill.warn { background: #fef1df; color: #9a4a00; }
    .small { font-size: 7.4pt; color: #475569; margin-top: .6mm; }
    .fy { margin: 2.4mm 0 .6mm; font-weight: bold; color: #0f172a; }
    .fy span { font-weight: normal; color: #64748b; font-size: 7.6pt; }

    /* Step 2 figure boxes */
    /* 23-11-2026: the four boxes are the cells themselves (equal size in one row), with fixed rows so the numbers line up */
    .figs { table-layout: fixed; border-collapse: separate; border-spacing: 2.2mm 0; margin-left: -2.2mm; width: 186.4mm; } /* 182 mm content + the two outer gaps, so the box edges line up with the rows below */
    .figs td.fig { width: 25%; height: 21mm; padding: 2mm 2.6mm; vertical-align: top; border: .8px solid #c9d6ea; border-radius: 1.6mm; background: #f5f8fd; }
    .fig .lbl { height: 4mm; white-space: nowrap; overflow: hidden; }
    .fig .big { height: 8.5mm; font-size: 14pt; font-weight: bold; color: #0d47a1; line-height: 8.5mm; margin: .4mm 0 0; }
    .fig .small { height: 4mm; white-space: nowrap; overflow: hidden; margin-top: .2mm; }
    .figs td.fig.hi { border: 1.4px solid #1565c0; background: #e8f0fc; }
    .figs td.fig.hi.warn { border-color: #f39223; background: #fff4e6; } .fig.hi.warn .big { color: #b45309; }

    /* Photos */
    .photos td { width: 50%; padding: 1.6mm; vertical-align: top; }
    .frame { border: .6px solid #dbe3ef; border-radius: 1.6mm; padding: 1.6mm; background: #fff; }
    .frame img { width: 100%; max-height: 60mm; }
    .cap { font-size: 7.4pt; color: #334155; margin-top: 1.2mm; line-height: 1.4; }
    .cap b { color: #0f172a; }
    .muted { color: #64748b; }
    .note { padding: 2mm 3mm; background: #f8fafc; border: .6px dashed #cbd5e1; border-radius: 1.4mm; color: #475569; }

    /* Signature */
    .sig { margin-top: 16mm; page-break-inside: avoid; }
    .sig td { vertical-align: bottom; }
    .sig td.box { width: 42%; border-top: .8px solid #64748b; text-align: center; padding-top: 2mm; font-size: 8.5pt; }
    .sig .role { font-size: 7.2pt; text-transform: uppercase; letter-spacing: .8pt; color: #64748b; margin-bottom: .6mm; }
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
    <div class="hd-rule-b"></div>
    <div class="hd-ref">
        <span class="ref">ID <b>{{ $inspection->inspection_no ?? 'DRAFT' }}</b></span><span class="ref">{{ $inspection->status->label() }}</span><span class="ref">UDISE <b class="mono">{{ $s->udise_code }}</b></span>
    </div>
</header>

{{-- Background on every page (23-11-2026): faint SSA logo, title and SSA, ASSAM --}}
@include('inspections.partials.pdf-watermark', ['logo' => $logos['ssa'] ?? null, 'title' => 'SCHOOL INSPECTION REPORT', 'top' => 82])

{{-- Footer, repeated on every page; "Page X of Y" is stamped by ReportController::pdf() --}}
<footer>
    <div>Generated by: <strong>School Inspection &amp; Monitoring System</strong></div>
    <div>Generated Date &amp; Time: <strong>{{ ($generatedAt ?? now())->format('d-m-Y H:i') }}</strong></div>
    <div class="conf">CONFIDENTIAL · FOR OFFICIAL USE ONLY</div>
</footer>

{{-- School identity card --}}
<div class="sc">
    <div class="sc-head">
        <div class="sc-label">SCHOOL</div>
        <div class="sc-name">{{ $s->name }}</div>
        <div>
            <span class="sc-chip">{{ $s->management?->label ?? 'Management —' }}</span>
            <span class="sc-chip">{{ $s->category?->label ?? 'Category —' }}</span>
            {{-- 23-11-2026: limited school data; school type and rural / urban are not printed, the medium is --}}
            @if ($s->medium_label)<span class="sc-chip">Medium: {{ $s->medium_label }}</span>@endif
            <span class="sc-chip st">{{ $inspection->status->label() }}</span>
        </div>
    </div>
    <table class="sc-grid"><tr>
        <td class="c1"><div class="lbl">UDISE Code</div><div class="val mono">{{ $s->udise_code }}</div></td>
        <td class="c2"><div class="lbl">District</div><div class="val">{{ $s->district?->label ?? '—' }}</div></td>
        <td class="c3"><div class="lbl">Block</div><div class="val">{{ $s->block?->label ?? '—' }}</div></td>
        <td class="c4"><div class="lbl">Cluster</div><div class="val">{{ $s->cluster?->label ?? '—' }}</div></td>
    </tr></table>
</div>

@php $sec++; @endphp
<table class="h"><tr><td class="n">{{ $sec }}</td><td class="t">Basic Information</td></tr></table><div class="h-rule"></div>
@php
    // Modification V8: the device location at start and submit is no longer printed (still stored, shown on the web report)
    $basic = [
        ['Inspection ID', $inspection->inspection_no ?? '—', 'Status', $inspection->status->label()],
        ['Inspecting officer', $inspection->officer->name, 'Designation', $inspection->officer->designation_label ?? '—'],
        ['Inspection started', $inspection->started_at->format('d M Y, h:i A'), 'Submitted', $inspection->submitted_at?->format('d M Y, h:i A') ?? '—'],
    ];
    if ($inspection->person_met_name) { // V9: no longer asked; printed only for older inspections that have it
        $basic[] = ['Person met', $inspection->person_met_name, 'Their designation', $inspection->person_met_designation ?: '—'];
    }
@endphp
<table class="kv">
    @foreach ($basic as [$k1, $v1, $k2, $v2])
        <tr><td class="k">{{ $k1 }}</td><td class="v">{{ $v1 }}</td><td class="k">{{ $k2 }}</td><td class="v">{{ $v2 }}</td></tr>
    @endforeach
</table>

@foreach ($steps as $n => $st)
    @continue($n === count($steps))
    @php
        $sec++;
        $qs = collect($questions[$n])->reject(fn ($q) => str_starts_with($q['code'], 'BAS_') || $q['code'] === 'ABS_00')
            // the male / female split (TSI_10..13); an inspection with only the V10 totals (TSI_14 / TSI_15, rolled back) prints those
            ->filter(fn ($q) => $n === 2 && in_array($q['code'], array_merge(QD::TEACHER_BREAKDOWN_CODES, QD::TOTALS_ONLY_CODES), true)
                ? in_array($q['code'], QD::shownBreakdownCodes($answers), true) : V::visible($q, $answers))->values();
        $count = match ($n) {
            3 => $inspection->absentTeachers->count().' absent', 4 => $inspection->classroomObservations->count().' observed',
            5 => $inspection->fundPositions->count().' year(s)', default => '',
        };
    @endphp
    @php
        $lead = 3; // question rows kept on the same page as the heading
        $smallTable = ($n === 3 && $inspection->absentTeachers->count() <= 10) || ($n === 4 && $inspection->classroomObservations->count() <= 6);
        $open = true; // the keep block below is still open
    @endphp
    <div class="keep">
    <table class="h"><tr><td class="n">{{ $sec }}</td><td class="t">{{ $st['title'] }}</td><td class="c">{{ $count }}</td></tr></table><div class="h-rule"></div>

    @if ($n === 2)
        {{-- figures first: three boxes with the automatic totals --}}
        @php $totS = QD::totalStudents($answers); $totT = QD::totalTeachers($answers); $bd = QD::teacherBreakdown($answers); $fa = QD::withDefaults($answers); @endphp
        {{-- 23-11-2026: four boxes; trained / untrained with the male / female totals, then Teachers absent today (highlighted) --}}
        @php $absT = V::absentLimit($answers); @endphp
        <table class="figs"><tr>
            <td class="fig"><div class="lbl">Total students</div><div class="big">{{ $totS ?? '—' }}</div><div class="small">Present {{ $fa['TSI_01'] ?? '—' }} · Absent {{ $fa['TSI_02'] ?? '—' }}</div></td>
            <td class="fig hi"><div class="lbl">Total teachers</div><div class="big">{{ $totT ?? '—' }}</div><div class="small">Present {{ $fa['TSI_09'] ?? '—' }}</div></td>
            <td class="fig"><div class="lbl">Trained / Untrained</div><div class="big">{{ $bd ? $bd['trained'].' / '.$bd['untrained'] : '—' }}</div><div class="small">@if ($bd && $bd['split'])Male {{ $bd['male'] }} · Female {{ $bd['female'] }}@else of {{ $bd['sum'] ?? '—' }} counted @endif</div></td>
            <td class="fig hi {{ $absT ? 'warn' : '' }}"><div class="lbl">Absent today</div><div class="big">{{ $absT ?? '—' }}</div><div class="small">Total teachers − Present</div></td>
        </tr></table>
        @if ($qs->isNotEmpty())
            <table class="qa" style="margin-top:2.4mm">
                @foreach ($qs as $i => $q)
                    @if ($i === $lead)</table></div>@php $open = false; @endphp<table class="qa">@endif
                    <tr class="{{ $i % 2 ? 'z' : '' }}"><td class="no">{{ $i + 1 }}</td><td>{{ $q['label'] }}</td><td class="v">{{ $fa[$q['code']] ?? '—' }}</td></tr>
                @endforeach
            </table>
        @endif
    @elseif ($qs->isNotEmpty())
        <table class="qa">
            @foreach ($qs as $i => $q)
                @php $a = $answers[$q['code']] ?? null; $tone = $dot($a); @endphp
                @if ($i === $lead)</table></div>@php $open = false; @endphp<table class="qa">@endif
                <tr class="{{ $i % 2 ? 'z' : '' }}">
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ $q['label'] }}@if (! empty($remarks[$q['code']]))<div class="rem">Remarks: {{ $remarks[$q['code']] }}</div>@endif</td>
                    <td class="v">@if ($tone)<span class="d {{ $tone }}">●</span>@endif{{ QD::optionLabel($q['code'], $a) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($open && in_array($n, [3, 4], true) && ! $smallTable)</div>@php $open = false; @endphp @endif
    @if ($n === 3)
        @if ($inspection->absentTeachers->isNotEmpty())
            {{-- Modification V10: one line per absent teacher (type and reason do not wrap) --}}
            <table class="dt abs"><thead><tr><th class="sl">Sl.</th><th style="width:30%">Name of the teacher</th><th style="width:18%">Type</th><th style="width:24%">Reason for absence</th><th>Remarks</th></tr></thead>
                @foreach ($inspection->absentTeachers as $i => $t)
                    <tr><td class="sl">{{ $i + 1 }}</td><td><b>{{ $t->teacher_name }}</b></td><td class="nw">{{ $t->typeLabel() }}</td>
                        <td class="nw"><span class="pill {{ $t->reason === 'UL' ? 'bad' : 'warn' }}">{{ $t->reasonLabel() }}</span></td><td>{{ $t->remarks ?: '—' }}</td></tr>
                @endforeach
            </table>
        @else <div class="note">{{ V::allTeachersPresent($answers) || V::absentLimit($answers) === 0 ? 'All teachers present (no absent teacher).' : 'No absent teachers recorded.' }}</div> @endif
    @endif
    @if ($n === 4)
        @if ($inspection->classroomObservations->isNotEmpty())
            <table class="dt cls"><thead><tr><th class="sl">Sl.</th><th style="width:13%">Class</th><th style="width:29%">Subject &amp; teacher</th><th style="width:14%">Use of TLM</th><th style="width:18%">Participation</th><th style="width:19%">Remarks</th></tr></thead>
                @foreach ($inspection->classroomObservations as $i => $c)
                    @php $groups = $c->tlmGroups(); $z = $i % 2 ? 'z' : ''; @endphp
                    <tr class="{{ $z }} {{ $groups ? 'main' : '' }}"><td class="sl">{{ $i + 1 }}</td>
                        <td><b>{{ \App\Models\InspectionClassroomObservation::CLASSES[$c->class] ?? $c->class }}</b></td>
                        <td><b>{{ $c->subject }}</b><div class="small">{{ $c->teacher_name }}</div></td>
                        <td><span class="pill {{ $c->tlm_used ? 'ok' : 'bad' }}">{{ $c->tlm_used ? 'Yes' : 'No' }}</span>
                            @foreach ($groups as $g)<span class="tag {{ $g['list'] }}">{{ $g['short'] }} {{ count($g['items']) }}</span>@endforeach</td>
                        <td><span class="pill {{ $c->participation === 'satisfactory' ? 'ok' : 'bad' }}">{{ $c->participationLabel() }}</span></td>
                        <td>{{ $c->remarks ?: '—' }}</td></tr>
                    @foreach ($groups as $g)
                        <tr class="sub {{ $z }} {{ $loop->last ? '' : 'main' }}"><td></td><td colspan="5"><span class="tag {{ $g['list'] }}">{{ $g['short'] }}</span> <b class="{{ $g['list'] }}">{{ $g['label'] }}:</b> {{ implode(' · ', $g['items']) }}</td></tr>
                    @endforeach
                @endforeach
            </table>
        @else <div class="note">No classroom records.</div> @endif
    @endif
    @if ($n === 5)
        @php
            $FP = \App\Models\InspectionFundPosition::class;
            $fundRows = $inspection->fundPositions->sortByDesc('financial_year')->values();
            $rec = fn ($f) => $f->isLegacy() ? (float) $f->amount_received : $f->totalReceived();
            $exp = fn ($f) => $f->isLegacy() ? (float) $f->amount_utilised : $f->totalExpenditure();
        @endphp
        @forelse ($fundRows as $fi => $f)
            <div class="{{ $fi ? 'keep' : '' }}"><div class="fy">{{ str_replace('FY-', 'FY ', $f->financial_year) }} <span>· amounts in Rs lakh</span></div>
            <table class="dt"><thead><tr><th style="width:43%">Fund head</th><th class="r" style="width:19%">Received</th><th class="r" style="width:19%">Expenditure</th><th class="r" style="width:19%">Balance</th></tr></thead>
                @if ($f->isLegacy())
                    <tr><td>{{ $f->category?->label ?? 'Fund' }}</td><td class="r">{{ $FP::lakh((float) $f->amount_received) }}</td><td class="r">{{ $FP::lakh((float) $f->amount_utilised) }}</td><td class="r"><b>{{ $FP::lakh($f->balance()) }}</b></td></tr>
                @else
                    @foreach ($FP::HEADS as $key => [$label, $rc, $ec])
                        <tr><td>{{ $label }}</td><td class="r">{{ $FP::lakh((float) $f->$rc) }}</td><td class="r">{{ $FP::lakh((float) $f->$ec) }}</td><td class="r"><b>{{ $FP::lakh($f->balance($key)) }}</b></td></tr>
                    @endforeach
                    <tr class="tot"><td>Total</td><td class="r">{{ $FP::lakh($rec($f)) }}</td><td class="r">{{ $FP::lakh($exp($f)) }}</td><td class="r">{{ $FP::lakh($f->balance()) }}</td></tr>
                @endif
            </table></div>
        @empty
            <div class="note">No fund details entered.</div>
        @endforelse
        @if ($open)</div>@php $open = false; @endphp @endif
        @if ($fundRows->count() > 1)
            @php $gr = $fundRows->sum($rec); $ge = $fundRows->sum($exp); @endphp
            <div class="keep"><div class="fy">Year-wise summary <span>· Rs lakh</span></div>
            <table class="dt"><thead><tr><th style="width:43%">Financial year</th><th class="r" style="width:19%">Received</th><th class="r" style="width:19%">Expenditure</th><th class="r" style="width:19%">Balance</th></tr></thead>
                @foreach ($fundRows as $f)
                    <tr><td>{{ str_replace('FY-', 'FY ', $f->financial_year) }}</td><td class="r">{{ $FP::lakh($rec($f)) }}</td><td class="r">{{ $FP::lakh($exp($f)) }}</td><td class="r">{{ $FP::lakh($f->balance()) }}</td></tr>
                @endforeach
                <tr class="tot"><td>All years</td><td class="r">{{ $FP::lakh($gr) }}</td><td class="r">{{ $FP::lakh($ge) }}</td><td class="r">{{ $FP::lakh($gr - $ge) }}</td></tr>
            </table></div>
        @endif
    @endif
    @if ($n === 6)
        @php $sug = $inspection->remarks->where('type', 'suggestion')->values(); @endphp
        <div class="fy">Suggestions</div>
        @if ($sug->isNotEmpty())
            <table class="dt"><thead><tr><th class="sl">Sl.</th><th style="width:93%">Suggestion</th></tr></thead>
                @foreach ($sug as $i => $sg)<tr><td class="sl">{{ $i + 1 }}</td><td>{{ $sg->body }}</td></tr>@endforeach
            </table>
        @else
            <div class="note">No suggestions recorded.</div>
        @endif
    @endif
    @if ($open)</div>@endif
@endforeach

@if ($photos->isNotEmpty())
    @php $sec++; @endphp
    <div class="keep">
    <table class="h"><tr><td class="n">{{ $sec }}</td><td class="t">Photographs of School</td><td class="c">{{ $photos->count() }} photo(s)</td></tr></table><div class="h-rule"></div>
    @foreach ($photos->chunk(2)->values() as $ri => $pair)
        @if ($ri === 1)</div>@endif
        <table class="photos keep"><tr>
            @foreach ($pair as $p)
                <td><div class="frame">
                    @if (! empty($images[$p->id]))<img src="{{ $images[$p->id] }}" alt="">@endif
                    <div class="cap"><b>{{ $p->category?->label }}</b>@if ($p->source === 'gallery') · <em>From gallery</em>@endif @if ($p->question_code) · {{ $p->question_code }}@endif @if ($p->caption) · {{ $p->caption }}@endif<br>
                        {{ $p->taken_at?->format('d M Y, h:i:s A') }} ·
                        @if ($p->hasLocation()) {{ number_format($p->latitude, 6) }}, {{ number_format($p->longitude, 6) }}{{ $fmtDist($inspection->distanceFromSchool($p->latitude, $p->longitude)) }}
                        @else <b>No location</b>{{ $p->no_location_reason ? ': '.$p->no_location_reason : '' }} @endif<br>
                        <span class="muted">SHA-256 {{ substr($p->sha256, 0, 16) }}…</span></div>
                </div></td>
            @endforeach
            @if ($pair->count() === 1)<td></td>@endif
        </tr></table>
    @endforeach
    @if ($photos->count() <= 2)</div>@endif
@endif

{{-- Modification V9: the automatic rating is no longer printed --}}

<table class="sig"><tr>
    <td style="width:58%" class="muted">Date: {{ ($inspection->submitted_at ?? $generatedAt ?? now())->format('d M Y') }}</td>
    <td class="box"><div class="role">Signature of Inspecting Officer</div><b>{{ $inspection->officer->name }}</b><br><span class="muted">{{ $inspection->officer->designation_label }}</span></td>
</tr></table>
</body>
</html>
