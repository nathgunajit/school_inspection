{{-- 28-11-2026: no-internet declaration (NET_01 = Yes, NET_02). Pre-filled; printed, signed by the Block / District officer,
     scanned and attached to the inspection as a PDF. Vars: $inspection, $school, $officer, $remark, $logos.
     DomPDF: table layout only, text at least 8 pt and dark (print-safe). --}}
@php
    $date = \Illuminate\Support\Carbon::parse($inspection->inspection_date)->format('d-m-Y');
    $level = $officer?->level?->label() ?? ($inspection->officer_level ? ucfirst($inspection->officer_level) : '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>No-internet declaration · {{ $school?->udise_code }}</title>
<style>
    @page { margin: 14mm 15mm 16mm 15mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111827; line-height: 1.45; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .lg { width: 24mm; text-align: center; vertical-align: middle; } .lg img { height: 17mm; } .lg.ssa img { height: auto; width: 26mm; }
    .hc { text-align: center; vertical-align: middle; }
    .g1 { font-size: 10pt; font-weight: bold; letter-spacing: 1pt; }
    .g2 { font-size: 9pt; color: #14532d; font-weight: bold; }
    .g3 { font-size: 13.5pt; font-weight: bold; color: #0d47a1; letter-spacing: 1pt; margin-top: 1.2mm; }
    .rule { border-top: 1.4px solid #0d47a1; margin: 2mm 0 4mm; }
    .det td { border: .8px solid #475569; padding: 1.8mm 2.4mm; }
    .lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .4pt; color: #1f2937; font-weight: bold; }
    .val { font-size: 10pt; font-weight: bold; margin-top: .4mm; }
    h3 { font-size: 11pt; color: #0d47a1; margin: 5mm 0 1.6mm; }
    .txt { border: .8px solid #475569; padding: 3mm 3.4mm; }
    .txt p { margin: 0 0 2mm; }
    .remark { min-height: 12mm; }
    .sig td { width: 50%; padding: 0 2mm; }
    .sig .box { border: .8px solid #475569; padding: 2.6mm 3mm; height: 52mm; }
    .sig .h { font-weight: bold; color: #0d47a1; margin-bottom: 2mm; }
    .sig .space { height: 16mm; border-bottom: .8px solid #334155; margin-bottom: 1.6mm; }
    .sig .row { padding: 1.2mm 0; border-bottom: .6px dotted #475569; font-size: 9pt; }
    .note { margin-top: 5mm; font-size: 8.5pt; color: #1f2937; border-top: .8px solid #475569; padding-top: 1.8mm; }
</style>
</head>
<body>
@include('inspections.partials.pdf-watermark', ['logo' => $logos['ssa'] ?? null, 'title' => 'NO-INTERNET DECLARATION', 'top' => 70])

<table><tr>
    <td class="lg">@if ($logos['assam'] ?? null)<img src="{{ $logos['assam'] }}" alt="">@endif</td>
    <td class="hc"><div class="g1">GOVERNMENT OF ASSAM</div><div class="g2">Samagra Shiksha Axom</div>
        <div class="g3">DECLARATION · NO INTERNET / NETWORK AT THE SCHOOL</div></td>
    <td class="lg ssa">@if ($logos['ssa'] ?? null)<img src="{{ $logos['ssa'] }}" alt="">@endif</td>
</tr></table>
<div class="rule"></div>

<table class="det">
    <tr><td colspan="2"><div class="lbl">School</div><div class="val">{{ $school?->name }}</div></td>
        <td><div class="lbl">UDISE code</div><div class="val">{{ $school?->udise_code }}</div></td></tr>
    <tr><td><div class="lbl">District</div><div class="val">{{ $school?->district?->label ?? '—' }}</div></td>
        <td><div class="lbl">Block</div><div class="val">{{ $school?->block?->label ?? '—' }}</div></td>
        <td><div class="lbl">Cluster</div><div class="val">{{ $school?->cluster?->label ?? '—' }}</div></td></tr>
    <tr><td><div class="lbl">Inspection date</div><div class="val">{{ $date }}</div></td>
        <td colspan="2"><div class="lbl">Inspection no.</div><div class="val">{{ $inspection->inspection_no ?: 'Draft (number given at submission)' }}</div></td></tr>
    <tr><td colspan="2"><div class="lbl">Inspecting officer</div><div class="val">{{ $officer?->name }}@if ($officer?->designation_label) · {{ $officer->designation_label }}@endif</div></td>
        <td><div class="lbl">Level</div><div class="val">{{ $level ?: '—' }}</div></td></tr>
</table>

<h3>Declaration</h3>
<div class="txt">
    <p>I, <b>{{ $officer?->name }}</b>@if ($officer?->designation_label), {{ $officer->designation_label }}@endif, declare that during my inspection of
        <b>{{ $school?->name }}</b> (UDISE {{ $school?->udise_code }}) on <b>{{ $date }}</b> there was <b>no internet / mobile network</b> at the school,
        so the inspection could not be entered in the School Inspection app at the school in the usual way.</p>
    <p>The details recorded for this inspection are true to the best of my knowledge.</p>
    <div class="lbl" style="margin-top:3mm">Problem observed</div>
    <div class="remark">{{ $remark ?: '' }}</div>
</div>

<h3>Signatures</h3>
<table class="sig"><tr>
    <td><div class="box">
        <div class="h">Inspecting officer</div>
        <div class="space"></div>
        <div class="row">Name: {{ $officer?->name }}</div>
        <div class="row">Designation: {{ $officer?->designation_label }}</div>
        <div class="row">Date:</div>
    </div></td>
    <td><div class="box">
        <div class="h">Verified by the Block / District officer</div>
        <div class="space"></div>
        <div class="row">Name:</div>
        <div class="row">Designation:</div>
        <div class="row">Office seal and date:</div>
    </div></td>
</tr></table>

<div class="note">After signing, scan this page as a PDF and attach it to the inspection in the app (step 1, "Signed declaration (PDF)"). The inspection cannot be submitted without it.
    · Printed {{ now()->format('d-m-Y h:i A') }}</div>
</body>
</html>
