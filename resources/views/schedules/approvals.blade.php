<x-layouts.app title="Plans Waiting Approval" :back="route('schedules.index')">
    @forelse ($plans as $p)
        <a href="{{ route('schedules.show', $p) }}" class="card mb-2 text-decoration-none">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-dark">{{ $p->officer->name }} <span class="text-muted text-sm">· {{ $p->officer->level->label() }}</span></div>
                    <div class="text-xs text-muted">{{ $p->schedule_date->format('D, d M Y') }} · {{ $p->items->count() }} school(s) · ~{{ number_format($p->total_distance_km, 1) }} km · submitted {{ $p->submitted_at?->diffForHumans() }}</div>
                    <div class="text-xs text-body mt-1 text-truncate">{{ $p->items->map(fn ($i) => $i->sequence.'. '.$i->school->name)->implode(' → ') }}</div>
                </div>
                <span class="btn btn-sm btn-soft-primary">Review</span>
            </div>
        </a>
    @empty
        <x-empty-state icon="bi-check2-all" title="No plans waiting for approval" />
    @endforelse
    {{ $plans->links() }}
</x-layouts.app>
