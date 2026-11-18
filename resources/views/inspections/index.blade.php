@php $area = $mode === 'area'; $u = auth()->user(); @endphp
<x-layouts.app :title="$area ? 'Inspections in My Area' : 'My Inspections'">
    <x-slot:actions>
        @can('export_excel')
            <a href="{{ route('inspections.export', [$mode] + request()->query()) }}" class="btn btn-soft-primary d-none d-sm-inline-flex"><i class="bi bi-file-earmark-excel"></i>Export</a>
        @endcan
    </x-slot:actions>

    <div class="row g-3 mb-3">
        @if ($area)
            <div class="col-4"><x-stat-card label="Schools in jurisdiction" :value="number_format($counts['schools'])" icon="bi-building" tone="primary" /></div>
            <div class="col-4"><x-stat-card label="Schools inspected" :value="number_format($counts['inspected'])" icon="bi-check2-circle" tone="accent" /></div>
            <div class="col-4"><x-stat-card label="Inspections submitted" :value="number_format($counts['submitted'])" icon="bi-send-check" tone="success" /></div>
        @else
            <div class="col-4"><x-stat-card label="Drafts" :value="$counts['draft']" icon="bi-pencil-square" tone="warning" :href="route('inspections.mine', ['status' => 'draft'])" /></div>
            <div class="col-4"><x-stat-card label="Submitted" :value="$counts['submitted']" icon="bi-send-check" tone="success" :href="route('inspections.mine', ['status' => 'submitted'])" /></div>
            <div class="col-4"><x-stat-card label="This month" :value="$counts['month']" icon="bi-calendar-check" tone="primary" /></div>
        @endif
    </div>

    <form method="GET" class="filter-bar mb-3">
        <div class="row g-2">
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">From</label><input type="date" name="from" value="{{ request('from', $area ? ($period[0] ?? '') : '') }}" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">To</label><input type="date" name="to" value="{{ request('to', $area ? ($period[1] ?? '') : '') }}" class="form-control"></div>
            @if ($districts->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">District</label><select name="district_id" class="form-select" data-autosubmit><option value="">All</option>@foreach ($districts as $d)<option value="{{ $d->id }}" @selected(request('district_id') == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
            @endif
            @if ($blocks->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">Block</label><select name="block_id" class="form-select" data-autosubmit><option value="">All</option>@foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(request('block_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
            @endif
            @if ($clusters->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">Cluster</label><select name="cluster_id" class="form-select" data-autosubmit><option value="">All</option>@foreach ($clusters as $c)<option value="{{ $c->id }}" @selected(request('cluster_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            @endif
            @if ($area && $officers->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">Officer</label><select name="officer_id" class="form-select" data-autosubmit><option value="">All</option>@foreach ($officers as $o)<option value="{{ $o->id }}" @selected(request('officer_id') == $o->id)>{{ $o->name }}</option>@endforeach</select></div>
            @endif
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">School</label><input name="school" value="{{ request('school') }}" class="form-control" placeholder="UDISE / name"></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">Status</label>
                <select name="status" class="form-select" data-autosubmit><option value="">All</option>
                    @foreach (($area ? ['submitted' => 'Submitted', 'unlocked' => 'Unlocked'] : ['draft' => 'Draft / Unlocked', 'submitted' => 'Submitted', 'cancelled' => 'Cancelled']) as $k => $l)
                        <option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-12 col-md d-flex align-items-end gap-2">
                <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i>Apply</button>
                <a href="{{ url()->current() }}" class="btn btn-light" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>
    @can('export_excel')
        <a href="{{ route('inspections.export', [$mode] + request()->query()) }}" class="btn btn-soft-primary w-100 mb-3 d-sm-none"><i class="bi bi-file-earmark-excel"></i>Export to Excel</a>
    @endcan

    <div class="card">
        <div class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0">
                <thead><tr><th>Inspection</th><th>School</th>@if ($area)<th>Officer</th>@endif<th>Date</th><th>Status</th><th>Rating</th><th></th></tr></thead>
                <tbody>
                @forelse ($inspections as $i)
                    <tr>
                        <td class="font-monospace text-sm">{{ $i->inspection_no ?? '—' }}</td>
                        <td><div class="fw-semibold">{{ $i->school->name }}</div><div class="text-xs text-secondary font-monospace">{{ $i->school->udise_code }}</div></td>
                        @if ($area)<td class="text-sm">{{ $i->officer->name }}</td>@endif
                        <td class="text-sm">{{ $i->inspection_date->format('d M Y') }}</td>
                        <td><x-status-badge :status="$i->status" />
                            @if ($i->isEditable() && ! $area)<div class="progress-thin progress mt-1" style="width:80px"><div class="progress-bar" style="width: {{ $i->completion_pct }}%"></div></div>@endif</td>
                        <td><x-rating-badge :rating="$i->rating" /></td>
                        <td class="text-end text-nowrap">
                            @if (! $area && $i->isEditable())
                                <a href="{{ route('inspections.step', [$i, 1]) }}" class="btn btn-sm btn-primary">Continue</a>
                            @elseif ($i->status->value !== 'cancelled')
                                <a href="{{ route('inspections.show', $i) }}" class="btn btn-sm btn-soft-primary">View</a>
                                <a href="{{ route('inspections.pdf', $i) }}" target="_blank" class="btn btn-sm btn-light btn-icon" aria-label="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-empty-state icon="bi-clipboard" title="No inspections found" text="Try changing the filters." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="list-card d-md-none">
            @forelse ($inspections as $i)
                <a href="{{ ! $area && $i->isEditable() ? route('inspections.step', [$i, 1]) : route('inspections.show', $i) }}" class="list-item text-decoration-none">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ $i->school->name }}</div>
                        <div class="text-xs text-secondary text-truncate">{{ $i->inspection_no ?? 'Draft' }} · {{ $i->inspection_date->format('d M Y') }}@if ($area) · {{ $i->officer->name }}@endif</div>
                        @if ($i->isEditable() && ! $area)<div class="progress-thin progress mt-1"><div class="progress-bar" style="width: {{ $i->completion_pct }}%"></div></div>@endif
                        @if ($i->rating)<div class="mt-1"><x-rating-badge :rating="$i->rating" /></div>@endif
                    </div>
                    <x-status-badge :status="$i->status" />
                </a>
            @empty
                <x-empty-state icon="bi-clipboard" title="No inspections found" />
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $inspections->links() }}</div>
</x-layouts.app>
