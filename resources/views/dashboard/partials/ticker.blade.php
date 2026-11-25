{{-- 25-11-2026: "Latest notices" news ticker at the top of the dashboard (pure CSS marquee; pauses on hover / focus;
     no motion with prefers-reduced-motion). The list is drawn twice so the -50% loop is seamless; the copy is aria-hidden. --}}
@php $duration = max(25, $ticker->count() * 7); @endphp
<section class="news-ticker" aria-label="Latest notices">
    <div class="news-ticker-label"><span class="news-ticker-dot" aria-hidden="true"></span><i class="bi bi-broadcast"></i><span>Latest<span class="d-none d-md-inline"> Notices</span></span></div>
    <div class="news-ticker-viewport">
        <div class="news-ticker-track" style="--ticker-duration: {{ $duration }}s">
            @foreach ([false, true] as $copy)
                <ul class="news-ticker-list" @if ($copy) aria-hidden="true" @endif>
                    @foreach ($ticker as $n)
                        @php $new = ! $noticesSent && ! $n->reads->first()?->read_at; @endphp
                        <li><a href="{{ route('notices.show', $n) }}" @if ($copy) tabindex="-1" @endif>
                            <span class="news-ticker-type is-{{ $n->type }}"><i class="bi {{ $n->typeIcon() }}"></i>{{ $n->typeLabel() }}</span>
                            @if ($new)<span class="news-ticker-new">NEW</span>@endif
                            <b>{{ $n->title }}</b>
                            <span class="news-ticker-meta">@if ($n->ref_no)No. {{ $n->ref_no }} · @endif{{ $n->creator?->name }} · {{ \Carbon\Carbon::parse($n->notice_date)->format('d M') }}</span>
                        </a></li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    </div>
    <a href="{{ route('notices.index', $noticesSent ? ['tab' => 'sent'] : []) }}" class="news-ticker-all" title="View all notices"><span class="d-none d-sm-inline">View all</span><i class="bi bi-arrow-right"></i></a>
</section>
