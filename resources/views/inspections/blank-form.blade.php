{{-- Modification V11 item 2: printable blank inspection form for a school with no internet.
     The officer fills it on paper and enters it in the app later. Vars: $school, $questions, $steps, $logos, $photoLabels. --}}
@php
    use App\Models\InspectionAbsentTeacher as AT;
    use App\Models\InspectionClassroomObservation as CO;
    use App\Models\InspectionFundPosition as FP;
    use App\Questionnaire\QuestionnaireDefinition as QD;
    $box = '<span class="bx"></span>';
    $sec = 0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Inspection form (blank) · {{ $school->udise_code }}</title>
<style>
    @page { margin: 32mm 13mm 20mm 13mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.6pt; color: #1f2937; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    header { position: fixed; top: -27mm; left: 0; right: 0; height: 24mm; }
    header td { vertical-align: middle; }
    .lg { width: 22mm; text-align: center; } .lg img { height: 16mm; } .lg.ssa img { height: auto; width: 26mm; }
    .hc { text-align: center; }
    .g1 { font-size: 9pt; font-weight: bold; letter-spacing: 1pt; }
    .g2 { font-size: 7.6pt; color: #1b5e20; font-weight: bold; }
    .g3 { font-size: 12.5pt; font-weight: bold; color: #0d47a1; letter-spacing: 1.2pt; margin-top: 1mm; }
    .g4 { font-size: 7pt; color: #64748b; }
    .rule { border-top: 1.2px solid #1565c0; margin-top: 1.4mm; }
    footer { position: fixed; bottom: -14mm; left: 0; right: 0; height: 10mm; font-size: 7pt; color: #64748b; border-top: .5px solid #cbd5e1; padding-top: 1.4mm; }
    .sc td { border: .6px solid #c9d8ee; padding: 1.6mm 2.4mm; vertical-align: top; }
    .lbl { font-size: 6.4pt; text-transform: uppercase; letter-spacing: .6pt; color: #64748b; font-weight: bold; }
    .val { font-size: 9.4pt; font-weight: bold; color: #0f172a; margin-top: .4mm; }
    .line { border-bottom: .6px solid #94a3b8; height: 5mm; }
    .h { margin: 5mm 0 1.6mm; page-break-after: avoid; }
    .h td { padding: 0; vertical-align: middle; }
    .h .n { width: 6mm; height: 6mm; background: #1565c0; color: #fff; text-align: center; font-weight: bold; }
    .h .t { padding-left: 2.2mm; font-size: 10.5pt; font-weight: bold; color: #0d47a1; }
    .qa td { border-bottom: .5px solid #e2e8f0; padding: 1.5mm 2mm; vertical-align: top; }
    .qa td.no { width: 6%; color: #94a3b8; }
    .qa td.q { width: 48%; }
    .qa td.a { width: 46%; }
    .grp td { background: #eef4fc; color: #0d47a1; font-weight: bold; font-size: 7.6pt; padding: 1.4mm 2mm; vertical-align: middle; }
    .bx { display: inline-block; width: 3mm; height: 3mm; border: .7px solid #334155; margin-right: 1.4mm; vertical-align: -.7mm; }
    .opt { display: inline-block; margin-right: 3.5mm; white-space: nowrap; line-height: 5mm; }
    .opt-v { display: block; white-space: nowrap; line-height: 4.6mm; }
    .num { display: inline-block; width: 18mm; height: 5mm; border: .7px solid #334155; }
    .txt { border-bottom: .6px solid #94a3b8; height: 5mm; }
    .req { color: #d93025; }
    .note { font-size: 7.2pt; color: #475569; margin: .8mm 0 1.4mm; }
    /* Data tables: no page-break-inside on the whole table (DomPDF stretches the header row when it does not fit); thead repeats instead */
    .dt { table-layout: fixed; margin-top: .6mm; }
    .dt th { background: #0d47a1; color: #fff; text-align: left; font-size: 7pt; padding: 1.6mm 1.8mm; white-space: nowrap; overflow: hidden; }
    .dt th.c, .dt td.c { text-align: center; }
    .dt td { border: .5px solid #cbd5e1; height: 8mm; padding: 1mm 1.8mm; font-size: 7.2pt; color: #475569; vertical-align: middle; }
    .dt td.sl { text-align: center; color: #94a3b8; }
    .dt tr { page-break-inside: avoid; }
    /* Class Room Performance: two rows per record (details, then remarks) */
    .cls td { height: 12mm; }
    .cls tr.rm td { height: 6.5mm; font-size: 6.8pt; color: #64748b; }
    .cls tr.rm td b { color: #475569; }
    .cls tr.z td { background: #f8fafc; }
    .cls .wl { border-bottom: .6px solid #94a3b8; height: 4.2mm; margin-top: .3mm; font-size: 6.6pt; font-weight: bold; }
    .cls .wl.fln { color: #0d47a1; } .cls .wl.ecce { color: #9a4a00; }
    .newpage { page-break-before: always; }
    .chip { display: inline-block; padding: .2mm 1.4mm; margin: .6mm .8mm 0 0; border-radius: 1.2mm; background: #f1f5f9; border: .5px solid #d8dee8; font-size: 6.6pt; color: #334155; }
    .chip b { color: #0d47a1; }
    .keep { page-break-inside: avoid; }
    .legend { font-size: 6.8pt; color: #475569; margin-top: 1.2mm; }
    .sig { margin-top: 6mm; page-break-inside: avoid; }
    .sig td { padding-top: 9mm; width: 50%; }
    .sig .ln { border-top: .6px solid #334155; padding-top: 1mm; font-size: 7.4pt; color: #475569; width: 70mm; }
</style>
</head>
<body>
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
    <span style="float:right">Fields marked <span class="req">*</span> are required in the app</span></footer>

<table class="sc">
    <tr><td colspan="2"><div class="lbl">School</div><div class="val">{{ $school->name }}</div></td>
        <td><div class="lbl">UDISE code</div><div class="val">{{ $school->udise_code }}</div></td>
        <td><div class="lbl">Cluster</div><div class="val">{{ $school->cluster?->name ?? '—' }}</div></td></tr>
    <tr><td><div class="lbl">District</div><div class="val">{{ $school->district?->name ?? '—' }}</div></td>
        <td><div class="lbl">Block</div><div class="val">{{ $school->block?->name ?? '—' }}</div></td>
        <td><div class="lbl">Date of inspection</div><div class="line"></div></td>
        <td><div class="lbl">Time</div><div class="line"></div></td></tr>
    <tr><td colspan="2"><div class="lbl">Inspecting officer</div><div class="line"></div></td>
        <td colspan="2"><div class="lbl">Designation</div><div class="line"></div></td></tr>
</table>

@foreach ($steps as $n => $meta)
    @php $sec++; $qs = $questions[$n] ?? []; @endphp
    {{-- Class Room Performance always starts a page, so its table is never split --}}
    <table class="h {{ $n === 4 ? 'newpage' : '' }}"><tr><td class="n">{{ $sec }}</td><td class="t">{{ $meta['title'] }}</td></tr></table>

    @if ($n === 3)
        <div class="note">Absent teachers = Total teachers − Teachers present (step 2). Write one row per absent teacher; tick the type and write the reason code.</div>
        <table class="dt">
            <thead><tr><th class="c" style="width:6%">Sl.</th><th style="width:30%">Name of the teacher</th><th style="width:20%">Type</th><th style="width:14%">Reason (code)</th><th style="width:30%">Remarks</th></tr></thead>
            <tbody>
            @for ($r = 1; $r <= 6; $r++)
                <tr><td class="sl">{{ $r }}</td><td></td>
                    <td>@foreach (AT::TYPES as $label)<span class="opt-v">{!! $box !!}{{ str_replace(' Teacher', '', $label) }}</span>@endforeach</td>
                    <td></td><td></td></tr>
            @endfor
            </tbody>
        </table>
        <div class="legend">Reason codes:
            @foreach (AT::REASONS as $code => $label)<span class="chip"><b>{{ $code }}</b> {{ trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $label)) }}</span>@endforeach</div>
    @elseif ($n === 4)
        <div class="note">Record at least one classroom. Tick the boxes; if TLM was used, write the FLN and / or ECCE TLM items.</div>
        <table class="dt cls">
            <thead><tr><th class="c" style="width:6%">Sl.</th><th style="width:22%">Name of the teacher</th><th class="c" style="width:8%">Class</th><th style="width:14%">Subject</th>
                <th style="width:12%">Use of TLM</th><th style="width:20%">TLM items used</th><th style="width:18%">Participation</th></tr></thead>
            <tbody>
            @for ($r = 1; $r <= 5; $r++)
                <tr class="{{ $r % 2 ? '' : 'z' }}"><td class="sl" rowspan="2">{{ $r }}</td><td></td><td></td><td></td>
                    <td><span class="opt-v">{!! $box !!}Yes</span><span class="opt-v">{!! $box !!}No</span></td>
                    <td><div class="wl fln">FLN:</div><div class="wl ecce">ECCE:</div></td>
                    <td>@foreach (CO::PARTICIPATION as $label)<span class="opt-v">{!! $box !!}{{ $label }}</span>@endforeach</td></tr>
                <tr class="rm {{ $r % 2 ? '' : 'z' }}"><td colspan="6"><b>Remarks:</b></td></tr>
            @endfor
            </tbody>
        </table>
        <div class="legend">Classes: {{ implode(', ', array_keys(CO::CLASSES)) }} · FLN and ECCE TLM item names are listed in the app (step 4, “Which TLM was used?”).</div>
    @elseif ($n === 5)
        <div class="note">Optional. Amounts in lakh (e.g. 1.25 = ₹1,25,000). One block per financial year.</div>
        @for ($y = 1; $y <= 2; $y++)
            <table class="dt" style="margin-bottom:2.4mm">
                <thead><tr><th style="width:34%">Financial year: ____________</th><th class="c" style="width:22%">Received (₹ L)</th><th class="c" style="width:22%">Expenditure (₹ L)</th><th class="c" style="width:22%">Balance (₹ L)</th></tr></thead>
                <tbody>@foreach (FP::HEADS as $k => $h)<tr><td style="color:#1f2937">{{ FP::SHORT[$k] }}</td><td></td><td></td><td></td></tr>@endforeach</tbody>
            </table>
        @endfor
    @elseif ($n === count($steps))
        <div class="note">Photos are taken in the app (camera or gallery). Required:</div>
        <table class="qa">@foreach ($photoLabels as $label)<tr><td>{!! $box !!}{{ $label }}</td></tr>@endforeach</table>
    @endif

    @if ($qs)
        <table class="qa">
            @foreach ($qs as $i => $q)
                @if ($n === 2 && $q['code'] === 'TSI_04')<tr class="grp"><td colspan="2">Total teachers = Regular + Contractual + SSA + Provincialised + Newly accorded</td><td class="a"><span class="num"></span></td></tr>@endif
                <tr class="keep">
                    <td class="no">{{ $i + 1 }}</td>
                    <td class="q">{{ $q['label'] }} @if (! empty($q['required']))<span class="req">*</span>@endif
                        @if ($cond = $q['show_if'] ?? $q['enable_if'] ?? null)<div class="note">Only if “{{ QD::find($cond[0])['label'] ?? $cond[0] }}” is {{ implode(' / ', array_map('ucfirst', $cond[1])) }}</div>@endif
                        @if (! empty($q['remarks_if']))<div class="note">Remarks needed if {{ implode(' / ', $q['remarks_if']) }}</div>@endif</td>
                    <td class="a">
                        @if (in_array($q['type'], ['radio', 'select', 'multi'], true))
                            @foreach ($q['options'] as $label)<span class="opt">{!! $box !!}{{ $label }}</span>@endforeach
                            @if (! empty($q['remarks_if']))<div class="txt"></div>@endif
                        @elseif ($q['type'] === 'number')<span class="num"></span>
                        @else<div class="txt"></div><div class="txt"></div>@endif
                    </td>
                </tr>
                @if ($n === 2 && $q['code'] === 'TSI_09')<tr class="grp"><td colspan="2">Teachers absent today = Total − Present · details in section 3</td><td class="a"><span class="num"></span></td></tr>@endif
            @endforeach
        </table>
    @endif

    @if ($n === 6)
        <div class="note" style="margin-top:2mm"><b>Suggestions</b> (optional, up to {{ config('inspection.max_suggestions') }})</div>
        @for ($k = 1; $k <= config('inspection.max_suggestions'); $k++)<div class="txt"></div>@endfor
    @endif
@endforeach

<table class="sig"><tr>
    <td><div class="ln">Signature of the inspecting officer</div></td>
    <td><div class="ln">Signature of the Head of the school</div></td>
</tr></table>
</body>
</html>
