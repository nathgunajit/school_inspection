<x-layouts.app :title="$school->name" :back="route('schools.index')">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card card-elevated overflow-hidden">
                <div class="bg-grad text-white p-4">
                    <div class="d-flex align-items-start gap-3">
                        <span class="d-grid rounded-4 bg-white bg-opacity-25" style="width:52px;height:52px;place-items:center"><i class="bi bi-building fs-4"></i></span>
                        <div class="min-w-0 flex-grow-1">
                            <h2 class="h4 text-white mb-1">{{ $school->name }}</h2>
                            <div class="font-monospace text-white-50">UDISE {{ $school->udise_code }}</div>
                        </div>
                        {{-- V6 Module 9: the school's current rating, with the change since the rating before it --}}
                        @if (config('inspection.rating.display') && ($current = $school->latestRating())) {{-- V9: hidden unless RATING_DISPLAY=true --}}
                            @php
                                $previous = $history->first(fn ($h) => $h->id !== $school->latestRatedInspection->id && $h->rating?->score_percent !== null
                                    && $h->inspection_date->lte($school->latestRatedInspection->inspection_date));
                                $change = $previous ? round($current->score_percent - $previous->rating->score_percent, 1) : null;
                            @endphp
                            <div class="school-rating-box text-end flex-shrink-0">
                                <div class="text-white-50 text-xs">Rating</div>
                                <div class="school-rating-score">{{ rtrim(rtrim(number_format($current->score_percent, 1), '0'), '.') }}%</div>
                                <span class="school-rating-band"><span class="rating-swatch rating-{{ $current->color }}"></span>{{ $current->band }}</span>
                                <div class="text-white-50 text-xs mt-1">
                                    {{ $school->latestRatedInspection->inspection_date->format('d M Y') }}
                                    @if ($change !== null)
                                        · <span class="text-white" title="Change since the rating of {{ $previous->inspection_date->format('d M Y') }}"><i class="bi {{ $change >= 0 ? 'bi-arrow-up' : 'bi-arrow-down' }}"></i>{{ rtrim(rtrim(number_format(abs($change), 1), '0'), '.') }} pts</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <dl class="kv mb-0">
                        <dt>District</dt><dd>{{ $school->district?->name }}</dd>
                        <dt>Block</dt><dd>{{ $school->block?->name }}</dd>
                        <dt>Cluster</dt><dd>{{ $school->cluster?->name }}</dd>
                        <dt>Management</dt><dd>{{ $school->management?->label ?? '—' }}</dd>
                        <dt>Category</dt><dd>{{ $school->category?->label ?? '—' }}</dd>
                        @if ($school->school_type_label || $school->area_type_label)
                            <dt>School type</dt><dd>{{ collect([$school->school_type_label, $school->area_type_label])->filter()->join(' · ') }}</dd>
                        @endif
                        @if ($school->medium_label)<dt>Medium</dt><dd>{{ $school->medium_label }}</dd>@endif
                        @if ($school->streams_label)<dt>HS streams</dt><dd>{{ $school->streams_label }}</dd>@endif
                        @if ($school->ac)<dt>Assembly (AC)</dt><dd>{{ $school->ac->label }}</dd>@endif
                        @if ($school->pc)<dt>Lok Sabha (PC)</dt><dd>{{ $school->pc->label }}</dd>@endif
                        <dt>Address</dt><dd>{{ $school->address ?: '—' }}@if ($school->pincode) <span class="text-secondary">· PIN {{ $school->pincode }}</span>@endif</dd>
                        <dt>Coordinates</dt><dd>{{ $school->hasCoordinates() ? number_format($school->latitude, 5).', '.number_format($school->longitude, 5) : 'Not available' }}</dd>
                        <dt>Status</dt><dd><span class="badge-soft badge-soft-{{ $school->isActive() ? 'success' : 'secondary' }}">{{ ucfirst($school->status) }}</span></dd>
                    </dl>
                </div>
            </div>

            {{-- V6 Module 8: where the school is, how far it is from me, and the road route --}}
            @if ($mapConfig)
                <div class="card mt-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-signpost-split me-2 text-primary"></i>Location &amp; route</span>
                        <button type="button" class="btn btn-sm btn-soft-primary" data-locate-me><i class="bi bi-crosshair"></i>My location</button>
                    </div>
                    <div id="schoolMap" class="si-map school-map" data-config='@json($mapConfig)' role="region" aria-label="Map of {{ $school->name }}"></div>
                    <div class="card-body pt-2" id="schoolDir"></div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card card-elevated mb-4">
                <div class="card-body p-4">
                    @php $si = $start['inspection'] ?? null; $sType = $start['type'] ?? null; @endphp
                    @if ($sType === \App\Services\InspectionStartGuard::OWN_DRAFT)
                        <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-pencil-square text-warning fs-5"></i>
                            <strong>{{ $si->inspection_date->isToday() ? 'You have a draft for today' : 'You have a draft from '.$si->inspection_date->format('d M Y') }}</strong></div>
                        @unless ($si->inspection_date->isToday())<p class="text-secondary text-sm mb-2">Finish this draft before starting a new inspection of this school.</p>@endunless
                        <div class="progress-thin progress mb-3"><div class="progress-bar" style="width: {{ $si->completion_pct }}%"></div></div>
                        <a href="{{ route('inspections.step', [$si, 1]) }}" class="btn btn-primary btn-lg w-100"><i class="bi bi-arrow-right-circle"></i>Continue inspection</a>
                    @elseif ($sType === \App\Services\InspectionStartGuard::OWN_TODAY)
                        <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-check-circle-fill text-success fs-5"></i><strong>Inspected by you today</strong></div>
                        <a href="{{ route('inspections.show', $si) }}" class="btn btn-soft-primary w-100"><i class="bi bi-file-earmark-text"></i>View report {{ $si->inspection_no }}</a>
                    @elseif ($sType === \App\Services\InspectionStartGuard::OTHER_DRAFT)
                        <div class="start-block is-draft">
                            <div class="sb-head"><i class="bi bi-hourglass-split"></i><strong>Inspection in progress by another officer</strong></div>
                            <div class="sb-officer"><span class="avatar">{{ $si->officer?->initials() }}</span>
                                <div class="min-w-0"><div class="fw-semibold text-truncate">{{ $si->officer?->name }}</div>
                                    <div class="text-xs text-secondary text-truncate">{{ $si->officer?->designation_label ?? $si->officer?->level?->label() }}</div></div></div>
                            <div class="sb-meta"><i class="bi bi-calendar3"></i>Draft started {{ $si->started_at->format('d M Y, h:i A') }}</div>
                            <p class="sb-note">You cannot start another inspection of this school while this draft is open.</p>
                        </div>
                    @elseif ($sType === \App\Services\InspectionStartGuard::OTHER_MONTH)
                        <div class="start-block is-done">
                            <div class="sb-head"><i class="bi bi-patch-check-fill"></i><strong>Already inspected this month</strong></div>
                            <div class="sb-officer"><span class="avatar">{{ $si->officer?->initials() }}</span>
                                <div class="min-w-0"><div class="fw-semibold text-truncate">{{ $si->officer?->name }}</div>
                                    <div class="text-xs text-secondary text-truncate">{{ $si->officer?->designation_label ?? $si->officer?->level?->label() }}</div></div></div>
                            <div class="sb-meta"><i class="bi bi-calendar-check"></i>Inspected on {{ $si->inspection_date->format('d M Y') }}@if ($si->inspection_no) · <span class="font-monospace">{{ $si->inspection_no }}</span>@endif</div>
                            <p class="sb-note">It can be inspected again from <strong>{{ \App\Services\InspectionStartGuard::reopensOn()->format('d M Y') }}</strong>.</p>
                            @can('view', $si)<a href="{{ route('inspections.show', $si) }}" class="btn btn-soft-primary w-100"><i class="bi bi-file-earmark-text"></i>View report</a>@endcan
                        </div>
                    @elseif (! $school->isActive())
                        <div class="alert alert-secondary border-0 mb-0"><i class="bi bi-slash-circle me-1"></i>This school is closed and cannot be inspected.</div>
                    @else
                        <h3 class="h6 mb-1">Ready to inspect?</h3>
                        <p class="text-secondary text-sm">The date and time are recorded from the server. Your location is captured when you start, so allow location access when asked.</p>
                        <form method="POST" action="{{ route('inspections.start', $school) }}" id="start-form">
                            @csrf
                            <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy">
                            <button class="btn btn-primary btn-lg w-100" id="start-btn"><i class="bi bi-play-circle"></i>Start inspection</button>
                            <div class="text-xs text-secondary text-center mt-2" id="loc-status"><i class="bi bi-geo-alt"></i> Location will be captured on start</div>
                        </form>
                    @endif
                    @if ($school->isActive())
                        {{-- Modification V11 item 2: paper form for a school without internet --}}
                        <a href="{{ route('schools.blank-form', $school) }}" class="btn btn-light w-100 mt-2" data-no-loader data-no-busy>
                            <i class="bi bi-file-earmark-arrow-down"></i>No internet? Download the blank form (PDF)</a>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Inspection history</div>
                <div class="list-card">
                    @forelse ($history as $h)
                        @php $canView = auth()->user()->can('view', $h); @endphp
                        <a @if ($canView) href="{{ route('inspections.show', $h) }}" @endif class="list-item text-decoration-none">
                            <div class="flex-grow-1">
                                <div class="fw-semibold text-sm">{{ $h->inspection_date->format('d M Y') }}</div>
                                <div class="text-xs text-secondary">{{ $h->officer->name }} · {{ $h->inspection_no }}</div>
                            </div>
                            @php $prev = $history->slice($loop->index + 1)->first(fn ($x) => $x->rating?->score_percent !== null); $diff = $h->rating?->score_percent !== null && $prev ? $h->rating->score_percent - $prev->rating->score_percent : null; @endphp
                            @if (config('inspection.rating.display') && $h->rating?->score_percent !== null)
                                <span class="text-end"><x-rating-badge :rating="$h->rating" />
                                    @if ($diff !== null)<span class="d-block text-xs {{ $diff >= 0 ? 'text-success' : 'text-danger' }}" title="Change since the previous rated inspection"><i class="bi {{ $diff >= 0 ? 'bi-arrow-up' : 'bi-arrow-down' }}"></i>{{ rtrim(rtrim(number_format(abs($diff), 1), '0'), '.') }} pts</span>@endif</span>
                            @else
                                <x-status-badge :status="$h->status" />
                            @endif
                        </a>
                    @empty
                        <x-empty-state icon="bi-calendar-x" title="No previous inspections" />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    @if ($mapConfig) @vite(['resources/js/school-map.js']) @endif
    <script>
        document.getElementById('start-form')?.addEventListener('submit', function (e) {
            const f = this;
            if (f.dataset.located) return;
            e.preventDefault();
            const btn = document.getElementById('start-btn'), st = document.getElementById('loc-status');
            window.SI_BUSY ? window.SI_BUSY.setBusy(btn, 'Getting location…') : (btn.disabled = true);
            const go = () => { f.dataset.located = 1; const t = btn.querySelector('.btn-busy-text'); if (t) t.textContent = 'Starting inspection…'; f.submit(); };
            if (!navigator.geolocation) { st.textContent = 'Location not supported on this device'; return go(); }
            navigator.geolocation.getCurrentPosition((p) => {
                f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.accuracy.value = Math.round(p.coords.accuracy);
                go();
            }, () => { st.textContent = 'Location unavailable – starting without it'; go(); }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
        });
    </script>
    @endpush
</x-layouts.app>
