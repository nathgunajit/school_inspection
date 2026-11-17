<x-layouts.app title="Find School">
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <form method="GET" class="card card-elevated mb-4">
                <div class="card-body p-3 p-md-4">
                    <label class="form-label fw-semibold" for="q">Search by UDISE Code or school name</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-primary"></i></span>
                        <input id="q" name="q" value="{{ $q }}" class="form-control" placeholder="e.g. 18010101001" autocomplete="off" autofocus inputmode="search">
                        <button class="btn btn-primary px-4">Search</button>
                    </div>
                    @if ($districts->isNotEmpty() || $blocks->isNotEmpty() || $clusters->isNotEmpty())
                        <div class="row g-2 mt-2">
                            @if ($districts->isNotEmpty())
                                <div class="col-sm-4"><select name="district_id" class="form-select" data-autosubmit aria-label="District"><option value="">All districts</option>
                                    @foreach ($districts as $d)<option value="{{ $d->id }}" @selected(request('district_id') == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
                            @endif
                            @if ($blocks->isNotEmpty())
                                <div class="col-sm-4"><select name="block_id" class="form-select" data-autosubmit aria-label="Block"><option value="">All blocks</option>
                                    @foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(request('block_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                            @endif
                            @if ($clusters->isNotEmpty())
                                <div class="col-sm-4"><select name="cluster_id" class="form-select" data-autosubmit aria-label="Cluster"><option value="">All clusters</option>
                                    @foreach ($clusters as $c)<option value="{{ $c->id }}" @selected(request('cluster_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                            @endif
                        </div>
                    @endif
                </div>
            </form>

            @if ($schools === null)
                <x-empty-state icon="bi-building" title="Find a school to inspect" text="Only schools inside your jurisdiction are shown. Enter the 11-digit UDISE Code for a direct match." />
            @elseif ($schools->isEmpty())
                <div class="card"><x-empty-state icon="bi-search" title="School not found" text="No school in your jurisdiction matches your search. If the school is missing, please contact the State Administrator." /></div>
            @else
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="text-secondary text-sm">{{ $schools->total() }} school{{ $schools->total() === 1 ? '' : 's' }} found</div>
                </div>
                <div class="card">
                    <div class="list-card">
                        @foreach ($schools as $s)
                            <a href="{{ route('schools.show', $s) }}" class="list-item text-decoration-none {{ $s->isActive() ? '' : 'opacity-75' }}">
                                <span class="stat-icon tone-primary" style="width:44px;height:44px;border-radius:12px;display:grid;place-items:center"><i class="bi bi-building"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $s->name }}</div>
                                    <div class="text-xs text-secondary text-truncate"><span class="font-monospace">{{ $s->udise_code }}</span> · {{ $s->cluster?->name }}, {{ $s->block?->name }}</div>
                                </div>
                                @unless ($s->isActive())<span class="badge-soft badge-soft-secondary">Closed</span>@endunless
                                <i class="bi bi-chevron-right text-secondary"></i>
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="mt-3">{{ $schools->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
