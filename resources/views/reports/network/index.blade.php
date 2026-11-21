{{-- Modification V11 item 2: internet / network problems reported during inspections (NET_01 = Yes) --}}
<x-layouts.app title="Network Issues at Schools">
    <p class="text-secondary text-sm mb-3">Schools where the inspecting officer reported an internet or network problem. Use the remarks to arrange connectivity and raise the issue.</p>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card label="Reports" :value="$total" icon="bi-wifi-off" tone="warning" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="Schools" :value="$schools" icon="bi-building" /></div>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            @if ($districts->isNotEmpty())
                <div class="col-12 col-md-4"><label class="form-label text-sm" for="nf_d">District</label>
                    <select id="nf_d" name="district_id" class="form-select"><option value="">All districts</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected((int) ($f['district_id'] ?? 0) === $d->id)>{{ $d->name }}</option>@endforeach</select></div>
            @endif
            <div class="col-6 col-md-3"><label class="form-label text-sm" for="nf_from">From</label><input id="nf_from" type="date" name="from" value="{{ $f['from'] ?? '' }}" class="form-control"></div>
            <div class="col-6 col-md-3"><label class="form-label text-sm" for="nf_to">To</label><input id="nf_to" type="date" name="to" value="{{ $f['to'] ?? '' }}" class="form-control"></div>
            <div class="col-12 col-md d-flex gap-2">
                <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i>Apply</button>
                <a href="{{ url()->current() }}" class="btn btn-light" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Date</th><th>School</th><th>Block / District</th><th>Officer</th><th>Problem reported</th><th></th></tr></thead>
                <tbody>
                @forelse ($rows as $i)
                    <tr>
                        <td class="text-sm text-nowrap">{{ $i->inspection_date->format('d M Y') }}</td>
                        <td><div class="fw-semibold">{{ $i->school->name }}</div><div class="text-xs text-secondary font-monospace">UDISE {{ $i->school->udise_code }}</div></td>
                        <td class="text-sm">{{ $i->block?->name }}<div class="text-xs text-secondary">{{ $i->district?->name }}</div></td>
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
</x-layouts.app>
