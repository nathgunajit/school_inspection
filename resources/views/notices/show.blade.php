<x-layouts.app :title="$notice->typeLabel()" :back="route('notices.index', $manage && ! $read ? ['tab' => 'sent'] : [])">
    <div class="row g-4">
        <div class="{{ $manage ? 'col-lg-7' : 'col-lg-9' }}">
            <div class="card card-elevated notice-letter">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <span class="notice-type is-{{ $notice->type }}"><i class="bi {{ $notice->typeIcon() }} me-1"></i>{{ $notice->typeLabel() }}</span>
                        @if ($notice->ref_no)<span class="text-sm text-secondary">No. {{ $notice->ref_no }}</span>@endif
                        <span class="text-sm text-secondary ms-auto">Dated {{ \Carbon\Carbon::parse($notice->notice_date)->format('d M Y') }}</span>
                    </div>
                    @if ($notice->isWithdrawn())<div class="alert alert-secondary py-2 text-sm"><i class="bi bi-slash-circle me-1"></i>Withdrawn on {{ $notice->withdrawn_at?->format('d M Y, h:i A') }}.</div>@endif
                    <h2 class="h5 mb-3">{{ $notice->title }}</h2>
                    <div class="notice-body">{!! nl2br(e($notice->body)) !!}</div>
                    @if ($notice->attachment_path)
                        <div class="notice-attach mt-4">
                            <i class="bi bi-paperclip"></i>
                            <span class="flex-grow-1 min-w-0 text-truncate">{{ $notice->attachment_name }}</span>
                            <a href="{{ route('notices.attachment', $notice) }}" target="_blank" rel="noopener" class="btn btn-sm btn-light" data-no-loader><i class="bi bi-eye"></i>Open</a>
                            <a href="{{ route('notices.attachment', [$notice, 'download' => 1]) }}" class="btn btn-sm btn-light" data-no-loader><i class="bi bi-download"></i>Download</a>
                        </div>
                    @endif
                    <div class="text-sm text-secondary mt-4 border-top pt-3">
                        From <b class="text-body">{{ $notice->creator->name }}</b>, {{ $notice->creator->designation_label ?? $notice->creator->level->label() }}
                        <div class="text-xs">To: {{ $notice->audienceLabel() }}@if ($notice->expires_on) · shown until {{ \Carbon\Carbon::parse($notice->expires_on)->format('d M Y') }}@endif</div>
                    </div>
                </div>
                @if ($read && ! $notice->isWithdrawn())
                    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                        @if ($read->acknowledged_at)
                            <span class="text-success text-sm"><i class="bi bi-check2-circle me-1"></i>You acknowledged this on {{ $read->acknowledged_at->format('d M Y, h:i A') }}.</span>
                        @else
                            <span class="text-sm text-secondary flex-grow-1">Please confirm that you have read and will follow it.</span>
                            <form method="POST" action="{{ route('notices.acknowledge', $notice) }}">@csrf<button class="btn btn-success"><i class="bi bi-check2-circle"></i>Acknowledge</button></form>
                        @endif
                    </div>
                @endif
            </div>
            @if ($manage && ! $notice->isWithdrawn())
                <form method="POST" action="{{ route('notices.withdraw', $notice) }}" class="mt-3" data-confirm="Withdraw this {{ strtolower($notice->typeLabel()) }}? Officers will no longer see it in their inbox.">
                    @csrf<button class="btn btn-outline-danger"><i class="bi bi-slash-circle"></i>Withdraw</button>
                </form>
            @endif
        </div>

        @if ($manage)
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><i class="bi bi-eye me-2 text-primary"></i>Who has read it</div>
                    <div class="card-body pb-2">
                        <div class="row g-2 text-center mb-2">
                            <div class="col-4"><div class="notice-stat"><b>{{ $report['total'] }}</b><span>Officers</span></div></div>
                            <div class="col-4"><div class="notice-stat is-read"><b>{{ $report['read'] }}</b><span>Read</span></div></div>
                            <div class="col-4"><div class="notice-stat is-ack"><b>{{ $report['ack'] }}</b><span>Acknowledged</span></div></div>
                        </div>
                    </div>
                    @if ($report['total'] === 0)
                        <div class="dash-empty"><i class="bi bi-people"></i>No active officer matches this audience yet.</div>
                    @else
                        <ul class="list-group list-group-flush notice-readers">
                            @foreach ($report['rows'] as ['user' => $u, 'read' => $r])
                                <li class="list-group-item d-flex gap-2 align-items-center">
                                    <span class="flex-grow-1 min-w-0"><span class="d-block text-sm fw-semibold text-truncate">{{ $u->name }}</span>
                                        <span class="d-block text-xs text-secondary text-truncate">{{ $u->level->label() }} · {{ $u->currentJurisdiction?->label() }}</span></span>
                                    @if ($r?->acknowledged_at)<span class="badge text-bg-success" title="{{ $r->acknowledged_at->format('d M Y, h:i A') }}">Acknowledged</span>
                                    @elseif ($r?->read_at)<span class="badge text-bg-primary" title="{{ $r->read_at->format('d M Y, h:i A') }}">Read</span>
                                    @else<span class="badge text-bg-light">Not read</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
