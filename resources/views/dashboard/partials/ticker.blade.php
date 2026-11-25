{{-- 25-11-2026: "Latest notices" news ticker at the top of the dashboard: only the newest live notice, crawling slowly
     (pure CSS marquee, 40 s loop; pauses on hover / focus; still with prefers-reduced-motion). The notice repeats 3 times in
     each half of the doubled track so the strip stays full; only the very first copy is read out / focusable. --}}
<section class="news-ticker" aria-label="Latest notice">
    <div class="news-ticker-label"><span class="news-ticker-dot" aria-hidden="true"></span><i class="bi bi-broadcast"></i><span>Latest<span class="d-none d-md-inline"> Notice</span></span></div>
    <div class="news-ticker-viewport">
        <div class="news-ticker-track" style="--ticker-duration: 40s">
            @foreach ([false, true] as $copy)
                <ul class="news-ticker-list" @if ($copy) aria-hidden="true" @endif>
                    @for ($r = 0; $r < 3; $r++)
                        @foreach ($ticker as $n)
                            @php $new = ! $noticesSent && ! $n->reads->first()?->read_at; $repeat = $copy || $r > 0; @endphp
                            <li @if ($repeat) class="is-repeat" aria-hidden="true" @endif><a href="{{ route('notices.show', $n) }}" @if ($repeat) tabindex="-1" @endif>
                                <span class="news-ticker-type is-{{ $n->type }}"><i class="bi {{ $n->typeIcon() }}"></i>{{ $n->typeLabel() }}</span>
                                @if ($new)<span class="news-ticker-new">NEW</span>@endif
                                <b>{{ $n->title }}</b>
                                <span class="news-ticker-meta">@if ($n->ref_no)No. {{ $n->ref_no }} · @endif{{ $n->creator?->name }} · {{ \Carbon\Carbon::parse($n->notice_date)->format('d M') }}</span>
                            </a></li>
                        @endforeach
                    @endfor
                </ul>
            @endforeach
        </div>
    </div>
    <a href="{{ route('notices.index', $noticesSent ? ['tab' => 'sent'] : []) }}" class="news-ticker-all" title="View all notices"><span class="d-none d-sm-inline">View all</span><i class="bi bi-arrow-right"></i></a>
</section>
