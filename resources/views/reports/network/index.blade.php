{{-- Modification V11 item 2: internet / network problems reported during inspections (NET_01 = Yes) --}}
<x-layouts.app title="Network Issues at Schools">
    {{-- 25-11-2026: the filtered list as PDF; the region follows the filters --}}
    <x-slot:actions>
        @can('export_excel')<span id="nw-actions" class="d-contents" data-filter-region>
            <a href="{{ route('reports.network.export-pdf', request()->except('page')) }}" class="btn btn-soft-danger d-none d-sm-inline-flex" data-no-loader title="Download the list as PDF"><i class="bi bi-file-earmark-pdf"></i>PDF</a>
        </span>@endcan
    </x-slot:actions>

    <p class="text-secondary text-sm mb-3">Schools where the inspecting officer reported an internet or network problem. Use the remarks to arrange connectivity and raise the issue.</p>

    <div id="nw-stats" class="row g-3 mb-3" data-filter-region>
        <div class="col-6 col-md-3"><x-stat-card label="Reports" :value="$total" icon="bi-wifi-off" tone="warning" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="Schools" :value="$schools" icon="bi-building" /></div>
    </div>

    <form method="GET" id="nw-form" class="filter-bar mb-3" data-filter data-filter-region data-loader-text="Loading network issues…">
        <div class="row g-2 align-items-end">
            @if ($districts->isNotEmpty())
                <div class="col-12 col-md-4"><label class="form-label text-sm" for="nf_d">District</label>
                    <select id="nf_d" name="district_id" class="form-select"><option value="">All districts</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected((int) ($f['district_id'] ?? 0) === $d->id)>{{ $d->label }}</option>@endforeach</select></div>
            @endif
            <div class="col-6 col-md-3"><label class="form-label text-sm" for="nf_from">From</label><input id="nf_from" type="date" name="from" value="{{ $f['from'] ?? '' }}" class="form-control"></div>
            <div class="col-6 col-md-3"><label class="form-label text-sm" for="nf_to">To</label><input id="nf_to" type="date" name="to" value="{{ $f['to'] ?? '' }}" class="form-control"></div>
            <div class="col-12 col-md-auto"><x-filter-buttons /></div>
        </div>
    </form>

    <div id="nw-results" data-filter-region data-filter-results>
    @can('export_excel')
        <div class="d-flex gap-2 mb-3 d-sm-none">
            <a href="{{ route('reports.network.export-pdf', request()->except('page')) }}" class="btn btn-soft-danger flex-fill" data-no-loader><i class="bi bi-file-earmark-pdf"></i>PDF</a>
        </div>
    @endcan

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Date</th><th>School</th><th>Block / District</th><th>Officer</th><th>Problem reported</th><th></th></tr></thead>
                <tbody>
                @forelse ($rows as $i)
                    <tr>
                        <td class="text-sm text-nowrap">{{ $i->inspection_date->format('d M Y') }}</td>
                        <td><div class="fw-semibold">{{ $i->school->name }}</div><div class="text-xs text-secondary font-monospace">UDISE {{ $i->school->udise_code }}</div></td>
                        <td class="text-sm">{{ $i->block?->label }}<div class="text-xs text-secondary">{{ $i->district?->label }}</div></td>
                        <td class="text-sm">{{ $i->officer->name }}</td>
                        <td class="text-sm" style="min-width: 220px">{!! filled($remarks[$i->id] ?? null) ? e($remarks[$i->id]) : '<span class="text-secondary">No remark</span>' !!}</td>
                        <td class="text-end text-nowrap">
                            @if ($i->status->value === 'draft')<x-status-badge :status="$i->status" />
                            @else<a href="{{ route('inspections.show', $i) }}" class="btn btn-sm btn-soft-primary">View</a>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="bi-wifi" title="No network issues reported" text="Officers report them on step 1 of the inspection." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
    </div>
</x-layouts.app>
