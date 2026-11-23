{{-- Coverage period settings: State Admin (state default + district overrides) / District officer with Manage users (own district) --}}
@php use App\Models\CoverageSetting as CS; @endphp
<x-layouts.app title="Coverage Period Settings" :back="route('reports.coverage')">
    @php
        // one form, used for the state default and for a district
        $form = function (?CS $row, string $scope, ?int $districtId, bool $withRadius, string $title, string $hint) {
            return compact('row', 'scope', 'districtId', 'withRadius', 'title', 'hint');
        };
        $forms = [];
        if ($isState) {
            $forms[] = $form($state, 'state', null, true, 'State default', 'Applies to every district that has no setting of its own.');
        } elseif ($district) {
            $forms[] = $form($own ?? $state, 'district', $district->id, false, $district->label,
                $own ? 'This district has its own coverage period.' : 'This district follows the state default. Save to set its own.');
        }
    @endphp

    <div class="row g-4">
        <div class="col-xl-7">
            @foreach ($forms as $fm)
                <section class="card cov-card cov-set mb-4">
                    <div class="cov-card-head"><h3 class="cov-title"><i class="bi bi-sliders"></i>{{ $fm['title'] }}</h3><span class="cov-order">{{ $fm['hint'] }}</span></div>
                    <form method="POST" action="{{ route('reports.coverage.settings.update') }}" class="cov-set-body" data-cov-form>
                        @csrf @method('PUT')
                        <input type="hidden" name="scope" value="{{ $fm['scope'] }}">
                        @if ($fm['districtId'])<input type="hidden" name="district_id" value="{{ $fm['districtId'] }}">@endif

                        <label class="form-label">Coverage period</label>
                        <div class="cov-choice mb-3">
                            @foreach (CS::PERIODS as $k => $l)
                                <input type="radio" class="btn-check" name="period_type" id="pt-{{ $fm['scope'] }}-{{ $k }}" value="{{ $k }}" @checked(old('period_type', $fm['row']->period_type) === $k)>
                                <label for="pt-{{ $fm['scope'] }}-{{ $k }}"><i class="bi {{ ['daily' => 'bi-calendar-day', 'weekly' => 'bi-calendar-week', 'monthly' => 'bi-calendar-month', 'quarterly' => 'bi-calendar3', 'custom' => 'bi-calendar-range'][$k] }}"></i>{{ $l }}</label>
                            @endforeach
                        </div>
                        <div class="row g-2 mb-3 cov-custom-dates" data-cov-custom>
                            <div class="col-6"><label class="form-label text-sm">From</label><input type="date" name="custom_from" value="{{ old('custom_from', $fm['row']->custom_from?->toDateString()) }}" class="form-control @error('custom_from') is-invalid @enderror">
                                @error('custom_from')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-6"><label class="form-label text-sm">To</label><input type="date" name="custom_to" value="{{ old('custom_to', $fm['row']->custom_to?->toDateString()) }}" class="form-control @error('custom_to') is-invalid @enderror">
                                @error('custom_to')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        </div>

                        <label class="form-label">Prescribed inspection cycle <span class="opt">for Inspection Coverage</span></label>
                        <select name="inspection_cycle" class="form-select mb-3">
                            @foreach (CS::CYCLES as $k => $l)<option value="{{ $k }}" @selected(old('inspection_cycle', $fm['row']->inspection_cycle) === $k)>{{ $l }}</option>@endforeach
                        </select>

                        @if ($fm['withRadius'])
                            <label class="form-label">Visit verification radius <span class="opt">metres from the school</span></label>
                            <input type="number" name="verify_radius_m" min="50" max="5000" step="10" value="{{ old('verify_radius_m', $fm['row']->verify_radius_m) }}" class="form-control mb-1 @error('verify_radius_m') is-invalid @enderror">
                            <div class="form-text mb-3">A visit counts for Visit Coverage when the start / submit location or a geo-tagged photo is within this distance of the school.</div>
                        @endif

                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-primary"><i class="bi bi-check2"></i>Save</button>
                            @if ($fm['scope'] === 'district' && $own)
                                <button class="btn btn-light" name="reset" value="1" onclick="return confirm(this.dataset.msg)" data-msg="Use the state default for {{ $fm['title'] }}?"><i class="bi bi-arrow-counterclockwise"></i>Use state default</button>
                            @endif
                        </div>
                        @if ($fm['row']->updater)<div class="text-xs text-secondary mt-2">Last changed by {{ $fm['row']->updater->name }} on {{ $fm['row']->updated_at->format('d M Y, h:i A') }}</div>@endif
                    </form>
                </section>
            @endforeach

            @if ($isState)
                <section class="card cov-card cov-set mb-4">
                    <div class="cov-card-head"><h3 class="cov-title"><i class="bi bi-diagram-3"></i>Set a district's own period</h3><span class="cov-order">Overrides the state default for one district</span></div>
                    <form method="POST" action="{{ route('reports.coverage.settings.update') }}" class="cov-set-body" data-cov-form>
                        @csrf @method('PUT')
                        <input type="hidden" name="scope" value="district">
                        <div class="row g-2">
                            <div class="col-md-4"><label class="form-label text-sm">District</label>
                                <select name="district_id" class="form-select" required><option value="">Select</option>@foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->label }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label text-sm">Coverage period</label>
                                <select name="period_type" class="form-select" data-cov-type>@foreach (CS::PERIODS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label text-sm">Inspection cycle</label>
                                <select name="inspection_cycle" class="form-select">@foreach (CS::CYCLES as $k => $l)<option value="{{ $k }}" @selected($k === $state->inspection_cycle)>{{ $l }}</option>@endforeach</select></div>
                            <div class="col-6 col-md-4 cov-custom-dates" data-cov-custom><label class="form-label text-sm">From</label><input type="date" name="custom_from" class="form-control"></div>
                            <div class="col-6 col-md-4 cov-custom-dates" data-cov-custom><label class="form-label text-sm">To</label><input type="date" name="custom_to" class="form-control"></div>
                        </div>
                        <button class="btn btn-primary mt-3"><i class="bi bi-plus-lg"></i>Save district setting</button>
                    </form>
                </section>
            @endif
        </div>

        <div class="col-xl-5">
            @if ($isState)
                <section class="card cov-card mb-4">
                    <div class="cov-card-head"><h3 class="cov-title"><i class="bi bi-list-ul"></i>District settings</h3><span class="cov-order">{{ $overrides->count() }} of {{ $districts->count() }} districts</span></div>
                    <div class="list-card">
                        @forelse ($overrides as $o)
                            <div class="list-item">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold">{{ $o->district?->label }}</div>
                                    <div class="text-xs text-secondary">{{ $o->periodLabel() }}@if ($o->period_type === 'custom') ({{ $o->custom_from?->format('d M Y') }} – {{ $o->custom_to?->format('d M Y') }})@endif · cycle {{ strtolower($o->cycleLabel()) }}@if ($o->updater) · by {{ $o->updater->name }}@endif</div>
                                </div>
                                <form method="POST" action="{{ route('reports.coverage.settings.update') }}" data-confirm="Use the state default for {{ $o->district?->label }}?">@csrf @method('PUT')
                                    <input type="hidden" name="scope" value="district"><input type="hidden" name="district_id" value="{{ $o->district_id }}"><input type="hidden" name="reset" value="1">
                                    <button class="btn btn-sm btn-light"><i class="bi bi-arrow-counterclockwise"></i>Reset</button>
                                </form>
                            </div>
                        @empty
                            <x-empty-state icon="bi-check2-circle" title="All districts follow the state default" text="Use the form to give a district its own period." />
                        @endforelse
                    </div>
                </section>
            @endif
            <div class="alert alert-light border text-sm">
                <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Two different measures</div>
                <p class="mb-1"><b>Visit Coverage:</b> at least one <b>verified visit</b> to every assigned school in the coverage period (location within the radius of the school).</p>
                <p class="mb-0"><b>Inspection Coverage:</b> a formal inspection <b>submitted</b> within the prescribed inspection cycle.</p>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            // show the from / to dates only for "Custom date range"
            document.querySelectorAll('[data-cov-form]').forEach((form) => {
                const sync = () => {
                    const v = form.querySelector('input[name="period_type"]:checked')?.value ?? form.querySelector('[data-cov-type]')?.value;
                    form.querySelectorAll('[data-cov-custom]').forEach((el) => el.classList.toggle('d-none', v !== 'custom'));
                };
                form.addEventListener('change', sync);
                sync();
            });
        </script>
    @endpush
</x-layouts.app>
