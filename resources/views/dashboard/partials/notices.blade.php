{{-- 25-11-2026: dashboard notices card (right rail). The State Admin sees the latest sent notices with read counts. --}}
<div class="dash-panel tone-warning">
    <div class="dash-panel-head">
        <h3><i class="bi bi-megaphone"></i>{{ $noticesSent ? 'Notices & orders sent' : 'Notices & orders' }}
            @unless ($noticesSent)<span class="dash-count" title="Unread" data-notice-count @if (! $noticeUnread) hidden @endif>{{ $noticeUnread }}</span>@endunless</h3>
        <a href="{{ route('notices.index', $noticesSent ? ['tab' => 'sent'] : []) }}">View all <i class="bi bi-arrow-right"></i></a>
    </div>
    @forelse ($notices as $n)
        @php $new = ! $noticesSent && ! $n->reads->first()?->read_at; @endphp
        <a href="{{ route('notices.show', $n) }}" class="dash-row" @if ($new) data-notice-unread @endif>
            <span class="notice-icon" style="--nt: {{ $n->typeColor() }}"><i class="bi {{ $n->typeIcon() }}"></i></span>
            <span class="flex-grow-1 min-w-0">
                <span class="d-block fw-semibold text-sm text-truncate text-body">{{ $n->title }}</span>
                <span class="d-block text-xs text-secondary text-truncate">{{ $n->typeLabel() }}@if ($n->ref_no) · No. {{ $n->ref_no }}@endif · {{ $noticesSent ? 'read by '.$n->read_count : $n->creator->name }}@if ($n->isWithdrawn()) · withdrawn @endif</span>
            </span>
            <span class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
                <span class="text-xs text-secondary text-nowrap">{{ \Carbon\Carbon::parse($n->notice_date)->format('d M') }}</span>
                @if ($new)<span class="badge text-bg-warning" data-notice-new>New</span>@endif
            </span>
        </a>
    @empty
        <div class="dash-empty"><i class="bi bi-megaphone"></i>{{ $noticesSent ? 'No notices sent yet.' : 'No notices or orders for you.' }}
            @can('issue_notices')<a href="{{ route('notices.create') }}" class="d-block mt-1">Write a notice</a>@endcan</div>
    @endforelse
</div>
