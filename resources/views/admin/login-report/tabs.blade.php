{{-- Modification V8 item 7: Login Monitoring has two views, the sign-in log and the daily report with the current status --}}
<ul class="nav nav-pills report-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link {{ $active === 'log' ? 'active' : '' }}" @if ($active === 'log') aria-current="page" @endif href="{{ route('admin.login-report.index') }}"><i class="bi bi-clock-history"></i>Sign-in log</a></li>
    <li class="nav-item"><a class="nav-link {{ $active === 'daily' ? 'active' : '' }}" @if ($active === 'daily') aria-current="page" @endif href="{{ route('admin.login-report.daily') }}"><i class="bi bi-calendar-day"></i>Daily report</a></li>
</ul>
