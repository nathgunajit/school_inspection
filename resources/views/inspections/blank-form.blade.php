{{-- Modification V11 item 2: printable blank inspection form for a school with no internet.
     The officer fills it on paper and enters it in the app later. Vars: $school, $questions, $steps, $logos, $photoLabels.
     23-11-2026: it follows the questionnaire as the app shows it. Questions, options, conditions, group headings (QD::GROUPS),
     section notes (QD::PAPER_INTROS / STEP_INTROS) and the calculated boxes (QD::CALC_BOXES) all come from QuestionnaireDefinition,
     so a change to the questionnaire changes this form too. Only the row tables (sections 3, 4, 5) are laid out here.
     Print layout: every box is a table cell (partials blank-opts / blank-calc), never an inline span, because DomPDF
     misplaces inline boxes against their label. One fixed-column table per section keeps the columns aligned.
     Text is at least 8 pt and dark, so it survives an office / mono printer. --}}
@php
    use App\Models\InspectionAbsentTeacher as AT;
    use App\Models\InspectionClassroomObservation as CO;
    use App\Models\InspectionFundPosition as FP;
    use App\Questionnaire\QuestionnaireDefinition as QD;
    $sec = 0;
    $note = fn ($n) => QD::PAPER_INTROS[$n] ?? QD::STEP_INTROS[$n] ?? '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Inspection form (blank) · {{ $school->udise_code }}</title>
<style>
    @page { margin: 32mm 13mm 20mm 13mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111827; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: middle; }
    header { position: fixed; top: -27mm; left: 0; right: 0; height: 24mm; }
    .lg { width: 22mm; text-align: center; } .lg img { height: 16mm; } .lg.ssa img { height: auto; width: 26mm; }
    .hc { text-align: center; }
    .g1 { font-size: 9.5pt; font-weight: bold; letter-spacing: 1pt; color: #111827; }
    .g2 { font-size: 8.5pt; color: #14532d; font-weight: bold; }
    .g3 { font-size: 13pt; font-weight: bold; color: #0d47a1; letter-spacing: 1.2pt; margin-top: 1mm; }
    .g4 { font-size: 8pt; color: #1f2937; }
    .rule { border-top: 1.4px solid #0d47a1; margin-top: 1.4mm; }
    footer { position: fixed; bottom: -14mm; left: 0; right: 0; height: 10mm; font-size: 8pt; color: #1f2937; border-top: .8px solid #475569; padding-top: 1.4mm; }
    .pg:after { content: counter(page); }

    /* School details */
    .sc td { border: .8px solid #475569; padding: 1.6mm 2.4mm; vertical-align: top; }
    .lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .4pt; color: #1f2937; font-weight: bold; }
    .val { font-size: 10pt; font-weight: bold; color: #111827; margin-top: .5mm; }
    .line { border-bottom: .8px solid #334155; height: 6mm; }

    /* How to fill */
    .how { margin-top: 3mm; border: 1px solid #334155; }
    .how td { padding: 1.6mm 2.2mm; font-size: 8.5pt; color: #111827; }
    .how td.t { font-weight: bold; width: 22mm; background: #e5edf8; color: #0d47a1; }
    .how .chk { display: inline-table; }

    /* Section heading */
    .h { margin: 5.5mm 0 1mm; page-break-after: avoid; }
    .h td { padding: 0; }
    .h .n { width: 8mm; height: 8mm; background: #0d47a1; color: #fff; text-align: center; font-weight: bold; font-size: 11pt; }
    .h .t { padding-left: 2.6mm; font-size: 12pt; font-weight: bold; color: #0d47a1; }
    .note { font-size: 8.5pt; color: #1f2937; margin: 1mm 0 1.8mm; page-break-after: avoid; }

    /* Questions: one table per section, fixed columns No. / Question / Answer */
    .qa { table-layout: fixed; }
    .qa > tr > td, .qa > tbody > tr > td { border-bottom: .6px solid #94a3b8; padding: 2mm 2mm; }
    .qa tr { page-break-inside: avoid; }
    .qa td.no { width: 9%; font-weight: bold; color: #1f2937; font-size: 9pt; vertical-align: top; }
    .qa td.q { width: 51%; }
    .qa td.a { width: 40%; }
    .qa tr.cols td { height: 0; padding: 0; border: 0; }
    .qa tr.blk > td { padding: 0; border: 0; }
    .qa td.q { vertical-align: top; }
    .qa td.a { vertical-align: top; }
    .qa .cond { font-size: 8pt; color: #1f2937; font-style: italic; margin-top: .6mm; }
    .qa .hint { font-size: 8pt; color: #1f2937; margin-top: .6mm; }
    .qa tr.grp td { background: #0d47a1; color: #fff; font-weight: bold; font-size: 9pt; padding: 1.6mm 2mm; border-bottom: 0; }
    .req { color: #c62828; font-weight: bold; }

    /* Tick box + label as cells */
    .chk { width: auto; border-collapse: separate; border-spacing: 0 .8mm; }
    .chk td { padding: 0; border: 0; }
    .chk td.bxc { width: 4mm; height: 4mm; border: 1px solid #111827; background: #fff; }
    .chk td.ol { padding: 0 4.5mm 0 1.6mm; font-size: 9pt; color: #111827; white-space: nowrap; }
    .multi { font-size: 8pt; font-style: italic; color: #1f2937; margin-top: .6mm; }
    /* Number box */
    .nb { width: auto; }
    .nb td.nbx { width: 26mm; height: 7mm; border: 1px solid #111827; background: #fff; padding: 0; }
    /* Writing lines */
    .txt { border-bottom: .8px solid #334155; height: 6.5mm; }
    .rmk { font-size: 8pt; color: #1f2937; margin-top: 1.2mm; }
    .rmk .txt { height: 5.5mm; }

    /* Calculated boxes (CALC_BOXES), rows of the section table */
    .qa tr.calc td { background: #e5edf8; border-top: 1px solid #1e3a8a; border-bottom: 1px solid #1e3a8a; padding: 2mm; }
    .qa tr.calc td:first-child { border-left: 1px solid #1e3a8a; }
    .qa tr.calc td:last-child { border-right: 1px solid #1e3a8a; }
    .qa tr.calc.hi td { border-top-width: 2px; border-bottom-width: 2px; background: #d6e4f7; }
    .qa tr.calc.hi td:first-child { border-left-width: 2px; } .qa tr.calc.hi td:last-child { border-right-width: 2px; }
    .cl { font-weight: bold; color: #0d47a1; font-size: 9.5pt; }
    .cf { font-size: 8pt; color: #1f2937; }
    .qa tr.calc-cells > td { padding: 2mm 0; border-bottom: 0; }
    .cells { table-layout: fixed; }
    .cells td { border: 1px solid #1e3a8a; }
    .cells td.ch { background: #0d47a1; color: #fff; font-weight: bold; font-size: 9pt; padding: 1.4mm 2mm; }
    .cells td.cc { background: #e5edf8; padding: 1.8mm 2mm; vertical-align: top; }
    .cells .cf { margin-bottom: 1.2mm; }

    /* Row tables (sections 3, 4, 5): thead repeats on a new page; no page-break-inside on the whole table (DomPDF stretches the header) */
    .dt { table-layout: fixed; margin-top: .6mm; }
    .dt th { background: #0d47a1; color: #fff; text-align: left; font-size: 8.5pt; padding: 1.6mm 1.8mm; border: 1px solid #0d47a1; }
    .dt th.c, .dt td.c { text-align: center; }
    .dt td { border: .8px solid #475569; height: 9mm; padding: 1mm 1.8mm; font-size: 8.5pt; color: #111827; }
    .dt td.sl { text-align: center; font-weight: bold; color: #1f2937; }
    .dt tr { page-break-inside: avoid; }
    .dt .chk td.ol { font-size: 8.5pt; padding-right: 1mm; }
    .cls td { height: 13mm; }
    .cls tr.rm td { height: 7mm; font-size: 8pt; }
    .cls .wl { border-bottom: .8px solid #334155; height: 5mm; font-size: 8pt; font-weight: bold; color: #0d47a1; }
    .cls .wl.ecce { color: #9a3412; }
    /* Nested boxes keep their own look inside styled rows and tables (DomPDF applies the outer td rules to nested cells too) */
    .dt .chk td, .qa .chk td, .cells .chk td { border: 0; height: auto; padding: 0; background: none; }
    .dt .chk td.bxc, .qa .chk td.bxc, .qa tr.calc .chk td.bxc { width: 4mm; height: 4mm; border: 1px solid #111827; background: #fff; }
    .dt .chk td.ol, .qa .chk td.ol { padding: 0 4.5mm 0 1.6mm; }
    .qa .nb td.nbx, .qa tr.calc .nb td.nbx, .qa tr.calc.hi .nb td.nbx, .cells .nb td.nbx { width: 26mm; height: 7mm; padding: 0; border: 1px solid #111827; background: #fff; }
    .qa tr.calc.hi .nb td.nbx { border-width: 1.6px; }
    .newpage { page-break-before: always; }
    .legend { font-size: 8pt; color: #1f2937; margin-top: 1.6mm; line-height: 1.6; }
    .legend b { color: #0d47a1; }
    .sig { margin-top: 10mm; page-break-inside: avoid; }
    .sig td { padding-top: 12mm; width: 50%; }
    .sig .ln { border-top: .8px solid #111827; padding-top: 1mm; font-size: 8.5pt; color: #111827; width: 72mm; }
</style>
</head>
<body>
{{-- Background on every page (23-11-2026): faint SSA logo, title and SSA, ASSAM --}}
@include('inspections.partials.pdf-watermark', ['logo' => $logos['ssa'] ?? null, 'title' => 'SCHOOL INSPECTION FORM', 'top' => 92])
<header>
    <table><tr>
        <td class="lg">@if ($logos['assam'])<img src="{{ $logos['assam'] }}" alt="">@endif</td>
        <td class="hc"><div class="g1">GOVERNMENT OF ASSAM</div><div class="g2">Samagra Shiksha, Assam</div>
            <div class="g3">SCHOOL INSPECTION FORM</div><div class="g4">Paper copy for use without internet · enter it in the app when the network is back</div></td>
        <td class="lg ssa">@if ($logos['ssa'])<img src="{{ $logos['ssa'] }}" alt="">@endif</td>
    </tr></table>
    <div class="rule"></div>
</header>
<footer>School {{ $school->name }} · UDISE {{ $school->udise_code }} · printed {{ now()->format('d M Y, h:i A') }}
    <span style="float:right">Page <span class="pg"></span> · <span class="req">*</span> required in the app</span></footer>

<table class="sc">
    <tr><td colspan="2"><div class="lbl">School</div><div class="val">{{ $school->name }}</div></td>
        <td><div class="lbl">UDISE code</div><div class="val">{{ $school->udise_code }}</div></td>
        <td><div class="lbl">Cluster</div><div class="val">{{ $school->cluster?->label ?? '—' }}</div></td></tr>
    <tr><td><div class="lbl">District</div><div class="val">{{ $school->district?->label ?? '—' }}</div></td>
        <td><div class="lbl">Block</div><div class="val">{{ $school->block?->label ?? '—' }}</div></td>
        <td><div class="lbl">Date of inspection</div><div class="line"></div></td>
        <td><div class="lbl">Time</div><div class="line"></div></td></tr>
    <tr><td colspan="2"><div class="lbl">Inspecting officer</div><div class="line"></div></td>
        <td colspan="2"><div class="lbl">Designation</div><div class="line"></div></td></tr>
</table>

<table class="how"><tr>
    <td class="t">How to fill</td>
    <td>Tick one box per question (✓) · write numbers in the boxes · shaded rows are totals: add up and write ·
        write remarks on the line under the question · <span class="req">*</span> must be answered</td>
</tr></table>

@foreach ($steps as $n => $meta)
    @php $sec++; $qs = $questions[$n] ?? []; @endphp
    {{-- Class Room Performance always starts a page, so its table is never split --}}
    <table class="h {{ $n === 4 ? 'newpage' : '' }}"><tr><td class="n">{{ $sec }}</td><td class="t">{{ $meta['title'] }}</td></tr></table>
    @if ($note($n))<div class="note">{{ $note($n) }}</div>@endif

    @if ($n === 3)
        <table class="dt">
            <thead><tr><th class="c" style="width:7%">Sl.</th><th style="width:31%">Name of the teacher</th><th style="width:20%">Type</th><th style="width:14%">Reason (code)</th><th style="width:28%">Remarks</th></tr></thead>
            <tbody>
            @for ($r = 1; $r <= 6; $r++)
                <tr><td class="sl">{{ $r }}</td><td></td>
                    <td>@include('inspections.partials.blank-opts', ['opts' => array_map(fn ($l) => str_replace(' Teacher', '', $l), AT::TYPES), 'vertical' => true])</td>
                    <td></td><td></td></tr>
            @endfor
            </tbody>
        </table>
        <div class="legend"><b>Reason codes:</b>
            @foreach (AT::REASONS as $code => $label)<b>{{ $code }}</b> {{ trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $label)) }}@if (! $loop->last) · @endif @endforeach</div>
    @elseif ($n === 4)
        <table class="dt cls">
            <thead><tr><th class="c" style="width:6%">Sl.</th><th style="width:21%">Name of the teacher</th><th class="c" style="width:8%">Class</th><th style="width:14%">Subject</th>
                <th style="width:12%">Use of TLM</th><th style="width:20%">TLM items used</th><th style="width:19%">Participation</th></tr></thead>
            <tbody>
            @for ($r = 1; $r <= 5; $r++)
                <tr><td class="sl" rowspan="2">{{ $r }}</td><td></td><td></td><td></td>
                    <td>@include('inspections.partials.blank-opts', ['opts' => ['Yes', 'No'], 'vertical' => true])</td>
                    <td><div class="wl">FLN:</div><div class="wl ecce">ECCE:</div></td>
                    <td>@include('inspections.partials.blank-opts', ['opts' => CO::PARTICIPATION, 'vertical' => true])</td></tr>
                <tr class="rm"><td colspan="6"><b>Remarks:</b></td></tr>
            @endfor
            </tbody>
        </table>
        <div class="legend"><b>Classes:</b> {{ implode(', ', array_keys(CO::CLASSES)) }} · FLN and ECCE TLM item names are listed in the app (step 4, “Which TLM was used?”).</div>
    @elseif ($n === 5)
        @for ($y = 1; $y <= 2; $y++)
            <table class="dt" style="margin-bottom:3mm">
                <thead><tr><th style="width:34%">Financial year: ____________</th><th class="c" style="width:22%">Received (₹ L)</th><th class="c" style="width:22%">Expenditure (₹ L)</th><th class="c" style="width:22%">Balance (₹ L)</th></tr></thead>
                <tbody>@foreach (FP::HEADS as $k => $h)<tr><td>{{ FP::SHORT[$k] }}</td><td></td><td></td><td></td></tr>@endforeach</tbody>
            </table>
        @endfor
    @elseif ($n === count($steps))
        @include('inspections.partials.blank-opts', ['opts' => $photoLabels, 'vertical' => true])
    @endif

    @if ($qs)
        {{-- the first row fixes the column widths (DomPDF ignores <colgroup>) --}}
        <table class="qa">
            <tr class="cols"><td class="no"></td><td class="q"></td><td class="a"></td></tr>
            @foreach ($qs as $i => $q)
                @php $lead = isset(QD::GROUPS[$q['code']]) || QD::calcBoxes('before', $q['code']); @endphp
                {{-- a group heading (and a box placed before the question) is kept on the page of its first question: one nested block --}}
                @if ($lead)<tr class="blk"><td colspan="3"><table class="qa">
                    <tr class="cols"><td class="no"></td><td class="q"></td><td class="a"></td></tr>@endif
                @if (isset(QD::GROUPS[$q['code']]))<tr class="grp"><td colspan="3">{{ QD::GROUPS[$q['code']][0] }}</td></tr>@endif
                @foreach (QD::calcBoxes('before', $q['code']) as $cb)@include('inspections.partials.blank-calc', ['cb' => $cb])@endforeach
                @include('inspections.partials.blank-question', ['q' => $q, 'no' => $sec.'.'.($i + 1)])
                @if ($lead)</table></td></tr>@endif
                {{-- calculated boxes placed after this question --}}
                @foreach (QD::calcBoxes('after', $q['code']) as $cb)@include('inspections.partials.blank-calc', ['cb' => $cb])@endforeach
            @endforeach
        </table>
    @endif
    @if ($n === 6)
        <div class="note" style="margin-top:3mm"><b>Suggestions</b> (optional, up to {{ config('inspection.max_suggestions') }})</div>
        @for ($k = 1; $k <= config('inspection.max_suggestions'); $k++)<div class="txt"></div>@endfor
    @endif
@endforeach

<table class="sig"><tr>
    <td><div class="ln">Signature of the inspecting officer</div></td>
    <td><div class="ln">Signature of the Head of the school</div></td>
</tr></table>
</body>
</html>
