<x-layouts.app title="Review & Submit" :back="route('inspections.step', [$inspection, 6])">
    @include('inspections.partials.stepper', ['current' => 'review'])

    <div class="row justify-content-center">
        <div class="col-xl-9">
            @if (! $progress['can_submit'])
                <div class="card card-elevated border-0 mb-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="stat-icon tone-danger" style="width:40px;height:40px;border-radius:10px;display:grid;place-items:center"><i class="bi bi-exclamation-octagon"></i></span>
                            <div><h2 class="h6 mb-0">Missing items</h2><div class="text-sm text-secondary">Complete these before you can submit.</div></div>
                        </div>
                        @foreach ($progress['missing'] as $stepNo => $items)
                            <div class="mb-3">
                                <a href="{{ route('inspections.step', [$inspection, $stepNo]) }}" class="fw-semibold d-inline-flex align-items-center gap-1 mb-1">
                                    Step {{ $stepNo }} · {{ $steps[$stepNo]['title'] }} <i class="bi bi-arrow-right-short"></i></a>
                                <ul class="list-unstyled mb-0 ms-1">
                                    @foreach ($items as $m)
                                        <li class="text-sm py-1 d-flex gap-2">
                                            <i class="bi bi-dot text-danger"></i>
                                            @if ($m['code'] && ! str_starts_with($m['code'], 'PHOTO_') && $m['code'] !== 'FUND')
                                                <a href="{{ route('inspections.step', [$inspection, $stepNo]) }}#q-{{ $m['code'] }}" class="text-body">{{ $m['message'] }}</a>
                                            @else
                                                <a href="{{ route('inspections.step', [$inspection, $stepNo]) }}" class="text-body">{{ $m['message'] }}</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-list-check me-2 text-primary"></i>Summary</div>
                <div class="list-card">
                    @foreach ($steps as $n => $s)
                        @php $st = $progress['steps'][$n]; @endphp
                        <a href="{{ route('inspections.step', [$inspection, $n]) }}" class="list-item text-decoration-none">
                            <span class="stat-icon tone-{{ $st['complete'] ? 'success' : 'warning' }}" style="width:38px;height:38px;border-radius:10px;display:grid;place-items:center"><i class="bi {{ $st['complete'] ? 'bi-check-lg' : $s['icon'] }}"></i></span>
                            <div class="flex-grow-1"><div class="fw-semibold">{{ $s['title'] }}</div>
                                <div class="progress-thin progress mt-1" style="max-width:240px"><div class="progress-bar" style="width: {{ $st['pct'] }}%"></div></div></div>
                            <span class="text-sm fw-semibold {{ $st['complete'] ? 'text-success' : 'text-warning' }}">{{ $st['complete'] ? 'Complete' : $st['pct'].'%' }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="card-body border-top">
                    <div class="row text-center g-2">
                        <div class="col-4"><div class="fw-bold fs-5">{{ $inspection->evidence->where('kind', 'photo')->count() }}</div><div class="text-xs text-secondary">Photographs</div></div>
                        <div class="col-4"><div class="fw-bold fs-5">{{ $inspection->evidence->where('kind', 'document')->count() }}</div><div class="text-xs text-secondary">Documents</div></div>
                        <div class="col-4"><div class="fw-bold fs-5">{{ $inspection->remarks->where('type', 'suggestion')->count() }}</div><div class="text-xs text-secondary">Suggestions</div></div>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('inspections.submit', $inspection) }}" id="submit-form" class="card card-elevated">
                @csrf
                <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy">
                <div class="card-body p-4">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm" @disabled(! $progress['can_submit']) required>
                        <label class="form-check-label" for="confirm">I confirm that this inspection was conducted by me at the school and the information is correct. <strong>After submission the report is locked.</strong></label>
                        @error('confirm')<div class="text-danger text-sm">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary btn-lg w-100" id="submit-btn" @disabled(! $progress['can_submit'])><i class="bi bi-send-check"></i>Submit inspection</button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
        document.getElementById('submit-form')?.addEventListener('submit', function (e) {
            const f = this;
            if (f.dataset.go) return;
            e.preventDefault();
            const btn = document.getElementById('submit-btn');
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting…';
            const go = () => { f.dataset.go = 1; f.submit(); };
            if (!navigator.geolocation) return go();
            navigator.geolocation.getCurrentPosition((p) => {
                f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.accuracy.value = Math.round(p.coords.accuracy); go();
            }, go, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
        });
    </script>
    @endpush
</x-layouts.app>
