<x-layouts.app :title="$school->name" :back="route('schools.index')">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card card-elevated overflow-hidden">
                <div class="bg-grad text-white p-4">
                    <div class="d-flex align-items-start gap-3">
                        <span class="d-grid rounded-4 bg-white bg-opacity-25" style="width:52px;height:52px;place-items:center"><i class="bi bi-building fs-4"></i></span>
                        <div class="min-w-0">
                            <h2 class="h4 text-white mb-1">{{ $school->name }}</h2>
                            <div class="font-monospace text-white-50">UDISE {{ $school->udise_code }}</div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="kv mb-0">
                        <dt>District</dt><dd>{{ $school->district?->name }}</dd>
                        <dt>Block</dt><dd>{{ $school->block?->name }}</dd>
                        <dt>Cluster</dt><dd>{{ $school->cluster?->name }}</dd>
                        <dt>Management</dt><dd>{{ $school->management?->label ?? '—' }}</dd>
                        <dt>Category</dt><dd>{{ $school->category?->label ?? '—' }}</dd>
                        <dt>Address</dt><dd>{{ $school->address ?: '—' }}</dd>
                        <dt>Coordinates</dt><dd>{{ $school->hasCoordinates() ? number_format($school->latitude, 5).', '.number_format($school->longitude, 5) : 'Not available' }}</dd>
                        <dt>Status</dt><dd><span class="badge-soft badge-soft-{{ $school->isActive() ? 'success' : 'secondary' }}">{{ ucfirst($school->status) }}</span></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card card-elevated mb-4">
                <div class="card-body p-4">
                    @if ($today && $today->isEditable())
                        <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-pencil-square text-warning fs-5"></i><strong>You have a draft for today</strong></div>
                        <div class="progress-thin progress mb-3"><div class="progress-bar" style="width: {{ $today->completion_pct }}%"></div></div>
                        <a href="{{ route('inspections.step', [$today, 1]) }}" class="btn btn-primary btn-lg w-100"><i class="bi bi-arrow-right-circle"></i>Continue inspection</a>
                    @elseif ($today && $today->status->value === 'submitted')
                        <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-check-circle-fill text-success fs-5"></i><strong>Inspected by you today</strong></div>
                        <a href="{{ route('inspections.show', $today) }}" class="btn btn-soft-primary w-100"><i class="bi bi-file-earmark-text"></i>View report {{ $today->inspection_no }}</a>
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
                            <x-status-badge :status="$h->status" />
                        </a>
                    @empty
                        <x-empty-state icon="bi-calendar-x" title="No previous inspections" />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.getElementById('start-form')?.addEventListener('submit', function (e) {
            const f = this;
            if (f.dataset.located) return;
            e.preventDefault();
            const btn = document.getElementById('start-btn'), st = document.getElementById('loc-status');
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Getting location…';
            const go = () => { f.dataset.located = 1; f.submit(); };
            if (!navigator.geolocation) { st.textContent = 'Location not supported on this device'; return go(); }
            navigator.geolocation.getCurrentPosition((p) => {
                f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.accuracy.value = Math.round(p.coords.accuracy);
                go();
            }, () => { st.textContent = 'Location unavailable – starting without it'; go(); }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
        });
    </script>
    @endpush
</x-layouts.app>
