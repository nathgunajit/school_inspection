{{-- Rating Report PDF (23-11-2026, RatingReportController::exportPdf via App\Support\MasterPdf::view, A4 portrait).
     DomPDF has no flexbox: every block is a table; bars are a percentage-width div inside a track.
     Vars: $title, $filters, $chips, $summary, $areas, $areaLevel, $best, $lowest, $rows, $sortLabel, $logos, $generatedAt. --}}
@php
    $s = $summary;
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    $hex = ['green' => '#1e9b4b', 'blue' => '#1565c0', 'yellow' => '#d4a500', 'orange' => '#f39223', 'red' => '#d93025', 'none' => '#94a3b8'];
    $soft = ['green' => '#e6f5ec', 'blue' => '#e7f0fb', 'yellow' => '#fbf3d6', 'orange' => '#fdeedd', 'red' => '#fbe4e2', 'none' => '#eef1f5'];
    $c = fn ($color) => $hex[$color ?? 'none'] ?? $hex['none'];
    $bg = fn ($color) => $soft[$color ?? 'none'] ?? $soft['none'];
    $ink = fn ($color) => ($color ?? 'none') === 'yellow' ? '#3d3200' : '#ffffff';
    $pill = fn ($score, $band, $color) => '<span class="pill" style="background:'.$c($color).';color:'.$ink($color).'">'.e($fmt($score)).'%'.($band ? ' · '.e($band) : '').'</span>';
    $maxBand = max(1, ...(array_column($s['bands'], 'count') ?: [0]));
    $levelLabel = ['district' => 'District', 'block' => 'Block', 'cluster' => 'Cluster'][$areaLevel] ?? null;
    $bandList = collect($s['bands'])->whereNotNull('min')->values();
    $improved = $rows->filter(fn ($r) => ($r['change'] ?? 0) > 0)->count();
    $declined = $rows->filter(fn ($r) => ($r['change'] ?? 0) < 0)->count();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 32mm 11mm 17mm 11mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #1f2937; }
    table { width: 100%; border-collapse: collapse; }
    header { position: fixed; top: -27mm; left: 0; right: 0; height: 25mm; }
    header td { vertical-align: middle; }
    .hd-logo { width: 22mm; text-align: center; }
    .hd-logo img { height: 15mm; }
    .hd-logo.ssa img { height: auto; width: 25mm; }
    .hd-center { text-align: center; }
    .hd-govt { font-size: 9pt; font-weight: bold; letter-spacing: 1.2pt; }
    .hd-ssa { font-size: 7.5pt; color: #1b5e20; font-weight: bold; margin-top: .3mm; }
    .hd-title { font-size: 14pt; font-weight: bold; color: #0d47a1; letter-spacing: 1.6pt; margin-top: 1mm; }
    .hd-rule { margin-top: 1.4mm; height: 1.2mm; background: #1565c0; border-bottom: .8mm solid #1e9b4b; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; font-size: 7pt; color: #475569; border-top: .6px solid #cbd5e1; padding-top: 1.4mm; }

    .meta { margin-bottom: 3mm; font-size: 7.5pt; color: #334155; }
    .meta span { display: inline-block; background: #f1f5f9; border: .5px solid #d8dee8; border-radius: 1.2mm; padding: .3mm 1.6mm; margin: 0 1mm .8mm 0; }

    /* KPI cards */
    .kpis { border-collapse: separate; border-spacing: 2mm 0; margin: 0 -2mm 4mm; width: auto; }
    .kpi { width: 20%; border: .6px solid #dbe3ee; border-radius: 2.2mm; padding: 2.6mm 3mm; background: #fff; vertical-align: top; }
    .kpi .k-label { font-size: 6.8pt; color: #64748b; text-transform: uppercase; letter-spacing: .6pt; font-weight: bold; }
    .kpi .k-value { font-size: 17pt; font-weight: bold; color: #0f172a; margin-top: 1mm; }
    .kpi .k-hint { font-size: 6.8pt; color: #64748b; margin-top: .6mm; }
    .kpi.accent { border-top: 1.2mm solid #1565c0; }
    .kpi.green { border-top: 1.2mm solid #1e9b4b; }
    .kpi.orange { border-top: 1.2mm solid #f39223; }
    .kpi.hero { color: #fff; border: 0; }
    .kpi.hero .k-label, .kpi.hero .k-hint { color: rgba(255,255,255,.88); }
    .kpi.hero .k-value { color: inherit; }

    /* cards */
    .card { border: .6px solid #dbe3ee; border-radius: 2.2mm; margin-bottom: 4mm; page-break-inside: avoid; }
    .card-h { background: #f4f7fb; border-bottom: .6px solid #dbe3ee; padding: 2mm 3mm; font-size: 9pt; font-weight: bold; color: #0d47a1; border-radius: 2.2mm 2.2mm 0 0; }
    .card-h small { font-weight: normal; color: #64748b; font-size: 7pt; margin-left: 2mm; }
    .card-b { padding: 2.4mm 3mm; }
    .two { border-collapse: separate; border-spacing: 2mm 0; margin: 0 -2mm; width: auto; }
    .two > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }

    /* bars */
    .bars td { padding: 1.1mm 0; vertical-align: middle; }
    .sw { display: inline-block; width: 2.6mm; height: 2.6mm; border-radius: .6mm; margin-right: 1.2mm; }
    .b-name { width: 34%; font-weight: bold; }
    .b-name small { display: block; font-weight: normal; color: #64748b; font-size: 6.5pt; }
    .track { background: #edf1f6; height: 3.4mm; border-radius: 1.7mm; }
    .fill { height: 3.4mm; border-radius: 1.7mm; }
    .b-num { width: 19%; text-align: right; font-weight: bold; white-space: nowrap; }
    .b-num small { color: #64748b; font-weight: normal; }
    .na { color: #94a3b8; font-style: italic; }

    .pill { display: inline-block; padding: .5mm 1.8mm; border-radius: 3mm; font-weight: bold; font-size: 7pt; white-space: nowrap; }
    .rank td { padding: 1.4mm 0; border-bottom: .5px solid #e6ebf2; vertical-align: middle; }
    .rank tr:last-child td { border-bottom: 0; }
    .rank .no { width: 6mm; color: #94a3b8; font-weight: bold; }
    .rank .nm { font-weight: bold; }
    .rank .sub { color: #64748b; font-size: 6.6pt; }
    .rank .sc { text-align: right; width: 30mm; }

    /* data tables */
    .list th { background: #1565c0; color: #fff; font-size: 7pt; text-align: left; padding: 1.5mm 1.6mm; }
    .list th.r, .list td.r { text-align: right; }
    .list td { padding: 1.3mm 1.6mm; border-bottom: .5px solid #e1e7ef; vertical-align: middle; }
    .list tbody tr:nth-child(even) td { background: #f7f9fc; }
    .list thead { display: table-header-group; }
    .list .sub { color: #64748b; font-size: 6.6pt; }
    .chip { display: inline-block; min-width: 4mm; text-align: center; padding: .3mm 1mm; border-radius: 1mm; font-size: 6.6pt; font-weight: bold; }
    .up { color: #1e9b4b; font-weight: bold; }
    .down { color: #d93025; font-weight: bold; }
    .flat { color: #64748b; }
    .sec-title { font-size: 10pt; font-weight: bold; color: #0d47a1; margin: 2mm 0 1.6mm; }
    .sec-title small { font-weight: normal; color: #64748b; font-size: 7pt; margin-left: 2mm; }
    .empty { border: .6px dashed #cbd5e1; border-radius: 2.2mm; padding: 10mm; text-align: center; color: #64748b; font-size: 9pt; }
</style>
</head>
<body>
<header>
    <table><tr>
        <td class="hd-logo">@if (! empty($logos['assam']))<img src="{{ $logos['assam'] }}" alt="">@endif</td>
        <td class="hd-center">
            <div class="hd-govt">GOVERNMENT OF ASSAM</div>
            <div class="hd-ssa">Samagra Shiksha Abhiyan, Assam</div>
            <div class="hd-title">{{ mb_strtoupper($title) }}</div>
        </td>
        <td class="hd-logo ssa">@if (! empty($logos['ssa']))<img src="{{ $logos['ssa'] }}" alt="">@endif</td>
    </tr></table>
    <div class="hd-rule"></div>
</header>
<footer>Generated {{ $generatedAt->format('d-m-Y H:i') }} · School Inspection Management System · Ratings are calculated at submit from the active rating scheme</footer>

@include('inspections.partials.pdf-watermark', ['logo' => $logos['ssa'] ?? null, 'title' => mb_strtoupper($title), 'top' => 70])

<div class="meta">@foreach ($chips as $chip)<span>{{ $chip }}</span>@endforeach</div>

{{-- ============ KPI cards ============ --}}
<table class="kpis"><tr>
    <td class="kpi accent"><div class="k-label">Rated inspections</div><div class="k-value">{{ number_format($s['rated']) }}</div><div class="k-hint">submitted and rated</div></td>
    <td class="kpi green"><div class="k-label">Schools rated</div><div class="k-value">{{ number_format($s['schools']) }}</div><div class="k-hint">different schools</div></td>
    <td class="kpi hero" style="background: {{ $c($s['avg_band']['color']) }}; color: {{ $ink($s['avg_band']['color']) }}">
        <div class="k-label">Average score</div><div class="k-value">{{ $s['avg'] === null ? '—' : $fmt($s['avg']).'%' }}</div>
        <div class="k-hint">{{ $s['avg_band']['band'] ?? 'Not rated' }}</div></td>
    <td class="kpi orange"><div class="k-label">Score range</div><div class="k-value" style="font-size: 13pt">{{ $s['rated'] ? $fmt($s['low']).' – '.$fmt($s['high']).'%' : '—' }}</div><div class="k-hint">lowest – highest</div></td>
    <td class="kpi accent"><div class="k-label">Change</div><div class="k-value" style="font-size: 13pt"><span class="up">▲ {{ $improved }}</span> <span class="down">▼ {{ $declined }}</span></div><div class="k-hint">schools improved / declined</div></td>
</tr></table>

@if ($s['rated'] === 0)
    <div class="empty">No submitted inspection has a rating for these dates and filters.</div>
@else
{{-- ============ Bands and sections ============ --}}
<table class="two"><tr>
    <td>
        <div class="card">
            <div class="card-h">Rating bands<small>{{ number_format($s['rated']) }} rated inspection(s)</small></div>
            <div class="card-b"><table class="bars">
                @foreach ($s['bands'] as $b)
                    @php $share = $s['rated'] ? round($b['count'] * 100 / $s['rated']) : 0; @endphp
                    <tr>
                        <td class="b-name"><span class="sw" style="background: {{ $c($b['color']) }}"></span>{{ $b['label'] }}@if ($b['min'] !== null)<small>{{ $b['min'] }}% and above</small>@endif</td>
                        <td><div class="track"><div class="fill" style="width: {{ $b['count'] ? max(2, round($b['count'] * 100 / $maxBand)) : 0 }}%; background: {{ $c($b['color']) }}"></div></div></td>
                        <td class="b-num">{{ number_format($b['count']) }} <small>{{ $share }}%</small></td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    </td>
    <td>
        <div class="card">
            <div class="card-h">Average by section<small>coloured by rating band</small></div>
            <div class="card-b"><table class="bars">
                @foreach ($s['sections'] as $sec)
                    <tr>
                        <td class="b-name">{{ $sec['title'] }}<small>{{ $sec['count'] }} inspection(s)</small></td>
                        <td>@if ($sec['avg'] === null)<span class="na">Not applicable</span>@else<div class="track"><div class="fill" style="width: {{ max(2, min(100, $sec['avg'])) }}%; background: {{ $c($sec['color'] ?? null) }}"></div></div>@endif</td>
                        <td class="b-num">{{ $sec['avg'] === null ? 'N/A' : $fmt($sec['avg']).'%' }}</td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    </td>
</tr></table>

{{-- ============ Best and lowest ============ --}}
<table class="two"><tr>
    @foreach (['Best rated schools' => [$best, '#1e9b4b'], 'Lowest rated schools' => [$lowest, '#d93025']] as $head => [$list, $tone])
        <td>
            <div class="card">
                <div class="card-h" style="color: {{ $tone }}">{{ $head }}</div>
                <div class="card-b"><table class="rank">
                    @foreach ($list as $i => $r)
                        <tr>
                            <td class="no">{{ $i + 1 }}</td>
                            <td><div class="nm">{{ $r['school']->name }}</div><div class="sub">{{ $r['school']->udise_code }} · {{ $r['inspection']->cluster?->label }} · {{ $r['inspection']->inspection_date->format('d M Y') }}</div></td>
                            <td class="sc">{!! $pill($r['score'], $r['band'], $r['color']) !!}</td>
                        </tr>
                    @endforeach
                </table></div>
            </div>
        </td>
    @endforeach
</tr></table>

{{-- ============ Area-wise ============ --}}
@if ($areas)
    <div class="sec-title">{{ $levelLabel }}-wise rating<small>highest average first</small></div>
    <table class="list" style="margin-bottom: 4mm">
        <thead><tr><th style="width: 5%">Sl.</th><th>{{ $levelLabel }}</th><th class="r">Rated</th><th class="r">Schools</th><th style="width: 26%">Average</th>
            @foreach ($bandList as $b)<th class="r"><span class="sw" style="background: {{ $c($b['color']) }}; margin: 0"></span> {{ $b['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($areas as $i => $a)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td><b>{{ $a['name'] }}</b></td>
                <td class="r">{{ number_format($a['rated']) }}</td>
                <td class="r">{{ number_format($a['schools']) }}</td>
                <td><table><tr><td style="padding: 0; border: 0; background: none"><div class="track"><div class="fill" style="width: {{ max(2, min(100, $a['avg'])) }}%; background: {{ $c($a['color']) }}"></div></div></td>
                    <td style="padding: 0 0 0 1.5mm; border: 0; background: none; width: 13mm; text-align: right"><b>{{ $fmt($a['avg']) }}%</b></td></tr></table></td>
                @foreach ($bandList as $b)
                    @php $n = $a['bands'][$b['label']] ?? 0; @endphp
                    <td class="r">@if ($n)<span class="chip" style="background: {{ $bg($b['color']) }}; color: {{ $c($b['color']) === '#d4a500' ? '#7a5f00' : $c($b['color']) }}">{{ $n }}</span>@else<span class="flat">·</span>@endif</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

{{-- ============ School-wise ============ --}}
<div class="sec-title">School-wise rating<small>{{ number_format($rows->count()) }} school(s) · latest rating in the period against the one before · {{ $sortLabel }}</small></div>
@foreach ($rows->chunk(300) as $part)
<table class="list">
    <thead><tr><th style="width: 5%">Sl.</th><th>School</th><th>Area</th><th>Inspected</th><th>Rating</th><th>Previous</th><th class="r">Change</th></tr></thead>
    <tbody>
    @foreach ($part as $i => $r)
        <tr>
            <td>{{ $loop->parent->index * 300 + $loop->iteration }}</td>
            <td><b>{{ $r['school']->name }}</b><div class="sub">UDISE {{ $r['school']->udise_code }}@if ($r['school']->category) · {{ $r['school']->category->label }}@endif</div></td>
            <td>{{ $r['inspection']->cluster?->label }}<div class="sub">{{ $r['inspection']->block?->label }} · {{ $r['inspection']->district?->label }}</div></td>
            <td style="white-space: nowrap">{{ $r['inspection']->inspection_date->format('d M Y') }}<div class="sub">{{ $r['inspection']->officer?->name }}</div></td>
            <td>{!! $pill($r['score'], $r['band'], $r['color']) !!}</td>
            <td>@if ($r['previous']){!! $pill($r['previous']['score'], null, $r['previous']['color']) !!}<div class="sub">{{ \Illuminate\Support\Carbon::parse($r['previous']['date'])->format('d M Y') }}</div>@else<span class="flat">First rating</span>@endif</td>
            <td class="r" style="white-space: nowrap">@if ($r['change'] === null)<span class="flat">—</span>@elseif ($r['change'] > 0)<span class="up">▲ {{ $fmt($r['change']) }} pts</span>@elseif ($r['change'] < 0)<span class="down">▼ {{ $fmt(abs($r['change'])) }} pts</span>@else<span class="flat">0 pts</span>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endforeach
@endif
</body>
</html>
