{{-- List PDF (23-11-2026): App\Support\MasterPdf. Vars: $title, $sections (title, headings, rows), $records, $filters, $logos, $generatedAt.
     A single table may also be passed as $headings + $rows. --}}
@php
    $sections ??= [['title' => null, 'headings' => $headings ?? [], 'rows' => $rows ?? []]];
    $records ??= count(end($sections)['rows'] ?? []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 34mm 12mm 18mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #1f2937; }
    table { width: 100%; border-collapse: collapse; }
    header { position: fixed; top: -29mm; left: 0; right: 0; height: 26mm; }
    header td { vertical-align: middle; }
    .hd-logo { width: 22mm; text-align: center; }
    .hd-logo img { height: 16mm; }
    .hd-logo.ssa img { height: auto; width: 26mm; }
    .hd-center { text-align: center; }
    .hd-govt { font-size: 9pt; font-weight: bold; letter-spacing: 1.2pt; }
    .hd-ssa { font-size: 7.5pt; color: #1b5e20; font-weight: bold; margin-top: .3mm; }
    .hd-title { font-size: 13pt; font-weight: bold; color: #0d47a1; letter-spacing: 1.4pt; margin-top: 1mm; }
    .hd-rule { margin-top: 1.4mm; border-top: 1.4px solid #1565c0; }
    footer { position: fixed; bottom: -13mm; left: 0; right: 0; height: 9mm; font-size: 7pt; color: #475569; border-top: .6px solid #cbd5e1; padding-top: 1.4mm; }
    .meta { margin-bottom: 2.5mm; font-size: 7.5pt; color: #334155; }
    .meta span { display: inline-block; background: #f1f5f9; border: .5px solid #d8dee8; border-radius: 1.2mm; padding: .3mm 1.6mm; margin: 0 1mm .8mm 0; }
    .meta b { color: #0d47a1; }
    .sec { font-size: 9pt; font-weight: bold; color: #0d47a1; margin: 4mm 0 1.4mm; }
    .list th { background: #1565c0; color: #fff; font-size: 7.5pt; text-align: left; padding: 1.4mm 1.6mm; border: .5px solid #1565c0; }
    .list td { padding: 1mm 1.6mm; border-bottom: .5px solid #d5dce6; }
    .list thead { display: table-header-group; }
    .empty { padding: 6mm; text-align: center; color: #64748b; }
</style>
</head>
<body>
<header>
    <table><tr>
        <td class="hd-logo">@if (! empty($logos['assam']))<img src="{{ $logos['assam'] }}" alt="">@endif</td>
        <td class="hd-center">
            <div class="hd-govt">GOVERNMENT OF ASSAM</div>
            <div class="hd-ssa">Samagra Shiksha Axom</div>
            <div class="hd-title">{{ mb_strtoupper($title) }}</div>
        </td>
        <td class="hd-logo ssa">@if (! empty($logos['ssa']))<img src="{{ $logos['ssa'] }}" alt="">@endif</td>
    </tr></table>
    <div class="hd-rule"></div>
</header>
<footer>Generated {{ $generatedAt->format('d-m-Y H:i') }} · School Inspection Management System</footer>

@include('inspections.partials.pdf-watermark', ['logo' => $logos['ssa'] ?? null, 'title' => mb_strtoupper($title), 'top' => 40])

<div class="meta">
    <span>Records <b>{{ number_format($records) }}</b></span>
    @forelse ($filters as $f)<span>{{ $f }}</span>@empty<span>All records</span>@endforelse
</div>
@foreach ($sections as $sec)
@if (! empty($sec['title']))<div class="sec">{{ $sec['title'] }}</div>@endif
{{-- several tables of 300 rows: DomPDF keeps a cell map per table, and one table of 4,600 rows runs out of memory --}}
@forelse (array_chunk($sec['rows'], 300) as $part)
<table class="list">
    <thead><tr>@foreach ($sec['headings'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($part as $row)
        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
    @endforeach
    </tbody>
</table>
@empty
<table class="list">
    <thead><tr>@foreach ($sec['headings'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody><tr><td class="empty" colspan="{{ count($sec['headings']) }}">No records match the filters.</td></tr></tbody>
</table>
@endforelse
@endforeach
</body>
</html>
