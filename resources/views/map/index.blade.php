<x-layouts.app title="Map View">
    <x-slot:actions>
        <button type="button" class="btn btn-soft-primary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#mapFilters" aria-controls="mapFilters"><i class="bi bi-funnel"></i>Filters</button>
    </x-slot:actions>

    {{-- 26-11-2026: map and filters only (no count tiles, no table); Full screen covers this block --}}
    <div class="row g-3 map-layout" id="mapLayout">
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
                                    {{-- 28-11-2026: Internet (connectivity survey) for every map user; Rating only when shown (V9 / V13) --}}
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Colour schools by">
                                        <input type="radio" class="btn-check" name="colourBy" id="colourStatus" value="status" @checked($colour === 'status')>
                                        <label class="btn btn-outline-primary" for="colourStatus">Status</label>
                                        @if ($showRating)
                                        <input type="radio" class="btn-check" name="colourBy" id="colourRating" value="rating" @checked($colour === 'rating')>
                                        <label class="btn btn-outline-primary" for="colourRating">Rating</label>
                                        @endif
                                        <input type="radio" class="btn-check" name="colourBy" id="colourNet" value="net" @checked($colour === 'net')>
                                        <label class="btn btn-outline-primary" for="colourNet" title="Internet connectivity at the schools"><i class="bi bi-wifi"></i>Internet</label>
                                    </div>
                                </div>
                                <div data-legend="status" class="{{ $colour === 'status' ? '' : 'd-none' }}">
                                    <div><span class="dot st-inspected"></span>Inspected in the period</div>
                                    <div><span class="dot st-in_progress"></span>Inspection in progress</div>
                                    <div><span class="dot st-pending"></span>Not yet inspected</div>
                                </div>
                                <div data-legend="rating" class="{{ $colour === 'rating' ? '' : 'd-none' }}">
                                    @foreach ($bands as $b)<div><span class="dot rt-{{ $b['color'] }}"></span>{{ $b['label'] }} ({{ $b['min'] }} %+)</div>@endforeach
                                    <div><span class="dot rt-none"></span>Not rated yet</div>
                                </div>
                                {{-- 28-11-2026: internet connectivity (latest NET_01 answer of the school's inspections in the period) --}}
                                <div data-legend="net" class="{{ $colour === 'net' ? '' : 'd-none' }}">
                                    <div><span class="dot net-no"></span>No internet at the school</div>
                                    <div><span class="dot net-ok"></span>Internet available</div>
                                    <div><span class="dot net-none"></span>Not surveyed in the period</div>
                                    <div class="text-xs text-muted mt-1">From the latest inspection that answered the internet question.</div>
                                </div>
                                {{-- 23-11-2026: District / Block offices (Location Master office location), with distances in the school popups.
                                     V14: shown only when the view has an office with a location; an office without one is never displayed. --}}
                                <div class="d-none" data-office-panel>
                                    <div class="d-flex align-items-center justify-content-between gap-2 mt-2 mb-1">
                                        <span class="fw-semibold text-xs text-uppercase text-muted">Offices</span>
                                        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="fOffices" checked>
                                            <label class="form-check-label text-xs" for="fOffices">Show</label></div>
                                    </div>
                                    <div><span class="office-dot office-district"><i class="bi bi-bank2"></i></span>District HQ office</div>
                                    <div><span class="office-dot office-block"><i class="bi bi-house-door-fill"></i></span>Block office</div>
                                    <div class="text-xs text-muted" id="officeNote"></div>
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
            <div class="card map-card map-card-full" id="mapCard">{{-- the dashboard's Map view button links to /map#mapCard --}}
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <nav aria-label="Map level" class="flex-grow-1 min-w-0"><ol class="breadcrumb mb-0 map-crumbs" id="mapCrumbs"></ol></nav>
                        <span class="text-xs text-muted" id="mapNoGps"></span>
                        <button type="button" class="btn btn-sm btn-soft-primary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#mapFilters" aria-controls="mapFilters"><i class="bi bi-funnel"></i>Filters</button>
                        <button type="button" class="btn btn-sm btn-light" data-map-full aria-pressed="false" title="Show the map on the full screen"><i class="bi bi-arrows-fullscreen"></i><span>Full screen</span></button>
                    </div>
                    {{-- 26-11-2026: what is selected (area, school, officer, period, colour), each with × to clear --}}
                    <div class="map-chips" id="mapChips" aria-live="polite" aria-label="Selected filters"></div>
                    {{-- Modification V8 item 2: colour key on top of the map, with the live counts of the current level --}}
                    <div class="map-keybar" aria-label="Map colour key">
                        <div class="map-keybar-items {{ $colour === 'status' ? '' : 'd-none' }}" data-legend="status">
                            <span class="key st-inspected"><span class="dot st-inspected"></span>Inspected <b data-key="inspected">—</b></span>
                            <span class="key st-in_progress"><span class="dot st-in_progress"></span>In progress <b data-key="in_progress">—</b></span>
                            <span class="key st-pending"><span class="dot st-pending"></span>Not inspected <b data-key="pending">—</b></span>
                        </div>
                        <div class="map-keybar-items {{ $colour === 'rating' ? '' : 'd-none' }}" data-legend="rating">
                            @foreach ($bands as $b)<span class="key"><span class="dot rt-{{ $b['color'] }}"></span>{{ $b['label'] }}</span>@endforeach
                            <span class="key"><span class="dot rt-none"></span>Not rated</span>
                        </div>
                        {{-- 28-11-2026: internet connectivity, with the live counts of the current level --}}
                        <div class="map-keybar-items {{ $colour === 'net' ? '' : 'd-none' }}" data-legend="net">
                            <span class="key"><span class="dot net-no"></span>No internet <b data-key="net_no">—</b></span>
                            <span class="key"><span class="dot net-ok"></span>Internet OK <b data-key="net_ok">—</b></span>
                            <span class="key"><span class="dot net-none"></span>Not surveyed <b data-key="net_unknown">—</b></span>
                        </div>
                        <span class="key d-none" data-office-key><span class="office-dot office-district"><i class="bi bi-bank2"></i></span>District HQ <span class="office-dot office-block ms-1"><i class="bi bi-house-door-fill"></i></span>Block office</span>
                        <span class="map-keybar-period text-xs text-secondary" id="mapKeyPeriod"></span>
                    </div>
                    <div id="map" class="si-map" role="region" tabindex="-1" aria-label="Inspection coverage map"
                         data-config='@json($config)' data-start='@json($start)'></div>
                    <div class="map-loading d-none" id="mapLoading"><span class="spinner-border spinner-border-sm"></span> Loading…</div>
                </div>
            </div>

            {{-- District-, block-, cluster- or school-wise table of the current level (26-11-2026: below the map; hidden in Full screen) --}}
            <div class="card mt-3 map-list">
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
