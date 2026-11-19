<x-layouts.app title="Map View">
    <x-slot:actions>
        <button type="button" class="btn btn-soft-primary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#mapFilters" aria-controls="mapFilters"><i class="bi bi-funnel"></i>Filters</button>
    </x-slot:actions>

    {{-- Stat cards: filled from the JSON of the current map level --}}
    <div class="row g-2 g-md-3 mb-3 map-stats">
        <div class="col-6 col-md-4 col-xl"><x-stat-card label="Registered officers" value="—" icon="bi-person-badge" tone="primary" data-stat="officers" /></div>
        <div class="col-6 col-md-4 col-xl"><x-stat-card label="Total schools" value="—" icon="bi-building" tone="info" data-stat="schools" /></div>
        <div class="col-6 col-md-4 col-xl"><x-stat-card label="Inspected schools" value="—" icon="bi-check2-circle" tone="success" data-stat="inspected" /></div>
        <div class="col-6 col-md-4 col-xl"><x-stat-card label="Pending inspections" value="—" icon="bi-hourglass-split" tone="danger" data-stat="pending" /></div>
        <div class="col-12 col-md-4 col-xl"><x-stat-card label="Inspection coverage" value="—" icon="bi-pie-chart" tone="accent" data-stat="coverage" /></div>
    </div>

    <div class="row g-3">
        {{-- Filters: a side panel on desktop, an offcanvas on phones --}}
        <div class="col-lg-3">
            <div class="offcanvas-lg offcanvas-start" tabindex="-1" id="mapFilters" aria-labelledby="mapFiltersLabel">
                <div class="offcanvas-header">
                    <h5 class="offcanvas-title" id="mapFiltersLabel">Map filters</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#mapFilters" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body">
                    <form class="card w-100 map-filters" id="mapFilterForm" onsubmit="return false">
                        <div class="card-body d-grid gap-2">
                            <div class="fw-semibold"><i class="bi bi-funnel me-1"></i>Filters</div>
                            <div><label class="form-label text-xs mb-1" for="fDistrict">District</label><select id="fDistrict" class="form-select form-select-sm" data-level="district"><option value="">All districts</option></select></div>
                            <div><label class="form-label text-xs mb-1" for="fBlock">Block</label><select id="fBlock" class="form-select form-select-sm" data-level="block" disabled><option value="">All blocks</option></select></div>
                            <div><label class="form-label text-xs mb-1" for="fCluster">Cluster</label><select id="fCluster" class="form-select form-select-sm" data-level="cluster" disabled><option value="">All clusters</option></select></div>
                            <div class="position-relative"><label class="form-label text-xs mb-1" for="fSchool">School</label>
                                <input id="fSchool" class="form-control form-control-sm" placeholder="UDISE code or name (3+ letters)" autocomplete="off">
                                <div class="list-group map-search-results shadow-sm d-none" id="fSchoolResults"></div>
                            </div>
                            <div><label class="form-label text-xs mb-1" for="fOfficer">Inspection officer</label>
                                <select id="fOfficer" class="form-select form-select-sm"><option value="">All officers</option>
                                    @foreach ($officers as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }} · {{ $o['level'] }}</option>@endforeach
                                </select></div>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label text-xs mb-1" for="fFrom">From</label><input type="date" id="fFrom" class="form-control form-control-sm" value="{{ now()->startOfMonth()->toDateString() }}"></div>
                                <div class="col-6"><label class="form-label text-xs mb-1" for="fTo">To</label><input type="date" id="fTo" class="form-control form-control-sm" value="{{ now()->toDateString() }}"></div>
                            </div>
                            <button type="button" class="btn btn-light btn-sm" id="fReset"><i class="bi bi-arrow-counterclockwise"></i>Reset</button>

                            <div class="map-legend mt-2">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                    <span class="fw-semibold text-xs text-uppercase text-muted">School markers</span>
                                    {{-- V6 Module 9: colour the school pins by inspection status or by rating band --}}
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Colour schools by">
                                        <input type="radio" class="btn-check" name="colourBy" id="colourStatus" value="status" checked>
                                        <label class="btn btn-outline-primary" for="colourStatus">Status</label>
                                        <input type="radio" class="btn-check" name="colourBy" id="colourRating" value="rating">
                                        <label class="btn btn-outline-primary" for="colourRating">Rating</label>
                                    </div>
                                </div>
                                <div data-legend="status">
                                    <div><span class="dot st-inspected"></span>Inspected in the period</div>
                                    <div><span class="dot st-in_progress"></span>Inspection in progress</div>
                                    <div><span class="dot st-pending"></span>Not yet inspected</div>
                                </div>
                                <div data-legend="rating" class="d-none">
                                    @foreach ($bands as $b)<div><span class="dot rt-{{ $b['color'] }}"></span>{{ $b['label'] }} ({{ $b['min'] }} %+)</div>@endforeach
                                    <div><span class="dot rt-none"></span>Not rated yet</div>
                                </div>
                                <div class="fw-semibold text-xs text-uppercase text-muted mt-2 mb-1">Area coverage</div>
                                <div><span class="dot cov-high"></span>75 % or more</div>
                                <div><span class="dot cov-mid"></span>40 – 74 %</div>
                                <div><span class="dot cov-low"></span>Below 40 %</div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="card map-card">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <nav aria-label="Map level" class="flex-grow-1 min-w-0"><ol class="breadcrumb mb-0 map-crumbs" id="mapCrumbs"></ol></nav>
                        <span class="text-xs text-muted" id="mapNoGps"></span>
                    </div>
                    {{-- Modification V8 item 2: colour key on top of the map, with the live counts of the current level --}}
                    <div class="map-keybar" aria-label="Map colour key">
                        <div class="map-keybar-items" data-legend="status">
                            <span class="key st-inspected"><span class="dot st-inspected"></span>Inspected <b data-key="inspected">—</b></span>
                            <span class="key st-in_progress"><span class="dot st-in_progress"></span>In progress <b data-key="in_progress">—</b></span>
                            <span class="key st-pending"><span class="dot st-pending"></span>Not inspected <b data-key="pending">—</b></span>
                        </div>
                        <div class="map-keybar-items d-none" data-legend="rating">
                            @foreach ($bands as $b)<span class="key"><span class="dot rt-{{ $b['color'] }}"></span>{{ $b['label'] }}</span>@endforeach
                            <span class="key"><span class="dot rt-none"></span>Not rated</span>
                        </div>
                        <span class="map-keybar-period text-xs text-secondary" id="mapKeyPeriod"></span>
                    </div>
                    <div id="map" class="si-map" role="region" aria-label="Inspection coverage map"
                         data-config='@json($config)' data-start='@json($start)'></div>
                    <div class="map-loading d-none" id="mapLoading"><span class="spinner-border spinner-border-sm"></span> Loading…</div>
                </div>
            </div>

            {{-- District-, block-, cluster- or school-wise table of the current level --}}
            <div class="card mt-3">
                <div class="card-header bg-white fw-semibold" id="mapTableTitle">Areas</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 map-table">
                        <thead id="mapTableHead"></thead>
                        <tbody id="mapTableBody"><tr><td class="text-muted p-3">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite(['resources/js/map.js'])
    @endpush
</x-layouts.app>
