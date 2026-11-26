{{-- V15 (26-11-2026): every in-app alert of the signed-in user (next-day schools, reminders, list changes) --}}
<x-layouts.app title="Alerts">
    <x-slot:actions>
        @if (auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('alerts.read-all') }}">@csrf<button class="btn btn-light"><i class="bi bi-check2-all"></i><span class="d-none d-sm-inline">Mark all as read</span></button></form>
        @endif
    </x-slot:actions>
    @if ($alerts->isEmpty())
        <x-empty-state icon="bi-bell" title="No alerts yet" text="The next working day's schools, reminders for pending schools and changes to your list appear here." />
    @else
        <div class="dash-panel">
            @foreach ($alerts as $a)
                <a href="{{ route('alerts.open', $a->id) }}" class="alert-row {{ $a->read_at ? '' : 'is-unread' }}">
                    <span class="alert-ico"><i class="bi {{ $a->data['icon'] ?? 'bi-bell' }}"></i></span>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block fw-semibold text-body">{{ $a->data['title'] ?? 'Alert' }}</span>
                        @if (! empty($a->data['body']))<span class="d-block text-sm text-secondary">{{ $a->data['body'] }}</span>@endif
                        <span class="d-block text-xs text-secondary">{{ $a->created_at->format('d M Y, h:i A') }}</span>
                    </span>
                    @unless ($a->read_at)<span class="badge rounded-pill text-bg-primary">New</span>@endunless
                </a>
            @endforeach
        </div>
        <div class="mt-3">{{ $alerts->links() }}</div>
    @endif
</x-layouts.app>
