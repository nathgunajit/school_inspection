{{-- 27-11-2026: the Coverage Settings sentence, for every report user. $how = CoverageReportService::howCounted() --}}
<p class="cov-how" role="note">
    <i class="bi bi-info-circle"></i>
    <span><b class="cov-how-title">How this report is counted:</b> It shows <b>{{ $how['period_name'] }} ({{ $how['period_dates'] }})</b> and counts the visits made then.
        A school counts as inspected when an inspection is submitted in the <b>{{ $how['cycle_name'] }}</b> cycle, now <b>{{ $how['cycle_dates'] }}</b>.
        A visit counts when the officer is within <b>{{ $how['radius'] }} m</b> of the school.</span>
</p>
