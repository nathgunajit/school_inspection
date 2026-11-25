<x-layouts.app title="Notices & Orders">
    @if ($canIssue)
        <x-slot:actions>
            <a href="{{ route('notices.create') }}" class="btn btn-primary"><i class="bi bi-send"></i><span class="d-none d-sm-inline">New notice</span></a>
        </x-slot:actions>
    @endif

    <div data-filter-region id="notices-region">
        @unless (auth()->user()->isStateAdmin())
            @if ($canIssue)
                <ul class="nav nav-pills gap-2 mb-3">
                    <li class="nav-item"><a class="nav-link {{ $tab === 'inbox' ? 'active' : 'bg-white border' }}" href="{{ route('notices.index') }}"><i class="bi bi-inbox me-1"></i>Inbox @if ($unread)<span class="badge text-bg-warning ms-1">{{ $unread }}</span>@endif</a></li>
                    <li class="nav-item"><a class="nav-link {{ $tab === 'sent' ? 'active' : 'bg-white border' }}" href="{{ route('notices.index', ['tab' => 'sent']) }}"><i class="bi bi-send me-1"></i>Sent</a></li>
                </ul>
            @endif
        @endunless

        <form method="GET" class="filter-bar mb-3 row g-2" data-filter>
            @if ($tab === 'sent')<input type="hidden" name="tab" value="sent">@endif
            <div class="col-12 col-md"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search by title or letter number" aria-label="Search"></div>
            <div class="col-6 col-md-3"><select name="type" class="form-select" aria-label="Type" data-autosubmit>
                <option value="">All types</option>
                @foreach (\App\Models\NoticeType::options(false) as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach
            </select></div>
            @if ($tab === 'inbox')
                <div class="col-6 col-md-auto d-flex align-items-center"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="unread" value="1" id="n-unread" @checked(request('unread')) data-autosubmit><label class="form-check-label" for="n-unread">Unread only</label></div></div>
            @endif
            <div class="col-12 col-md-auto"><x-filter-buttons :reset="route('notices.index', $tab === 'sent' ? ['tab' => 'sent'] : [])" /></div>
        </form>

        <div class="card" data-filter-results>
            @if ($items->isEmpty())
                <x-empty-state icon="bi-megaphone" :title="$tab === 'sent' ? 'No notices sent yet' : 'No notices for you'" :text="$tab === 'sent' ? 'Use “New notice” to send an instruction, notification or order.' : 'Instructions, notifications and orders from your higher officers will appear here.'" />
            @else
                <div class="list-group list-group-flush notice-list">
                    @foreach ($items as $n)
                        @php $unreadRow = $tab === 'inbox' && ! $n->reads->first()?->read_at; @endphp
                        <a href="{{ route('notices.show', $n) }}" class="list-group-item list-group-item-action d-flex gap-3 align-items-start {{ $unreadRow ? 'is-unread' : '' }} {{ $n->isWithdrawn() ? 'text-secondary' : '' }}">
                            <span class="notice-icon" style="--nt: {{ $n->typeColor() }}"><i class="bi {{ $n->typeIcon() }}"></i></span>
                            <span class="flex-grow-1 min-w-0">
                                <span class="d-flex flex-wrap gap-2 align-items-center">
                                    <span class="notice-type" style="--nt: {{ $n->typeColor() }}">{{ $n->typeLabel() }}</span>
                                    @if ($n->ref_no)<span class="text-xs text-secondary">No. {{ $n->ref_no }}</span>@endif
                                    @if ($unreadRow)<span class="badge text-bg-warning">New</span>@endif
                                    @if ($n->isWithdrawn())<span class="badge text-bg-secondary">Withdrawn</span>@elseif ($n->isExpired())<span class="badge text-bg-light">Expired</span>@endif
                                    @if ($n->attachment_path)<i class="bi bi-paperclip text-secondary" title="Has an attachment"></i>@endif
                                </span>
                                <span class="d-block fw-semibold text-body text-truncate mt-1">{{ $n->title }}</span>
                                <span class="d-block text-xs text-secondary text-truncate">
                                    @if ($tab === 'sent'){{ $n->audienceLabel() }} · read {{ $n->read_count }}, acknowledged {{ $n->ack_count }}@else From {{ $n->creator->name }} · {{ $n->creator->designation_label ?? $n->creator->level->label() }}@endif
                                </span>
                            </span>
                            <span class="text-xs text-secondary text-nowrap">{{ \Carbon\Carbon::parse($n->notice_date)->format('d M Y') }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="mt-3">{{ $items->links() }}</div>
    </div>
</x-layouts.app>
