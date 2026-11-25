{{-- 25-11-2026: "Latest notice" strip at the top of the dashboard: only the newest live notice (or the pinned Latest
     Notification). Static since 25-11-2026 (no scrolling): the title (one line, "…" when long, full text in the tooltip)
     and the date pill, both on the same centre line. --}}
<section class="news-ticker" aria-label="Latest notice">
    <div class="news-ticker-label">
        <span class="news-ticker-badge" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></span>
        <span class="news-ticker-label-text">Latest Notice</span>
    </div>
    @foreach ($ticker as $n)
        @php $new = ! $noticesSent && ! $n->reads->first()?->read_at; @endphp
        <a href="{{ route('notices.show', $n) }}" class="news-ticker-item" title="{{ $n->title }}" @if ($new) data-notice-unread @endif>
            <span class="news-ticker-title">{{ $n->title }}</span>
            <span class="news-ticker-date"><i class="bi bi-calendar3" aria-hidden="true"></i>{{ \Carbon\Carbon::parse($n->notice_date)->format('d M Y') }}</span>
        </a>
    @endforeach
    <a href="{{ route('notices.index', $noticesSent ? ['tab' => 'sent'] : []) }}" class="news-ticker-all" title="View all notices"><span class="d-none d-sm-inline">View all</span><i class="bi bi-arrow-right"></i></a>
</section>
