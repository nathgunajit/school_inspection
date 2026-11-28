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
                        <p class="cov-field-help">The dates the report counts visits for when it opens. <b>Monthly</b> = this month's visits. Anyone can still pick another period on the report.</p>
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
                        <p class="cov-field-help">How often every school must have a <b>submitted</b> inspection. <b>Quarterly</b> = once every 3 months (Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec); half-yearly and yearly follow the financial year (from 1 April).</p>

                        @if ($fm['withRadius'])
                            @php $anyDist = (bool) old('verify_any', (int) $fm['row']->verify_radius_m === 0); @endphp
                            <label class="form-label">Visit verification radius <span class="opt">metres from the school</span></label>
                            <div class="form-check form-switch mb-2">
                                <input type="hidden" name="verify_any" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="verify_any" value="1" id="verify-any" data-cov-any @checked($anyDist)>
                                <label class="form-check-label" for="verify-any"><b>Any distance</b>: count every visit, wherever the officer was</label>
                            </div>
                            <input type="number" name="verify_radius_m" min="50" max="5000" step="10" value="{{ old('verify_radius_m', $fm['row']->verify_radius_m ?: config('inspection.coverage.verify_radius_m', 500)) }}" class="form-control mb-1 @error('verify_radius_m') is-invalid @enderror" @disabled($anyDist)>
                            @error('verify_radius_m')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text mb-3">How close the officer must be to the school for a visit to count: the start or submit location, or a photo location, must be within this distance. 500 m suits most schools; raise it where GPS is weak. With <b>Any distance</b> every started inspection counts, even without a location. It applies to every district.</div>
                        @endif

                        <div class="cov-preview mb-3" data-cov-preview data-radius="{{ (int) $state->verify_radius_m }}" aria-live="polite"></div>

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
                        <p class="cov-field-help mt-2 mb-0">The district's officers then see the report with this period and cycle; every other district keeps the state default. The radius always comes from the state default.</p>
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
                <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Period and cycle: what is the difference?</div>
                <p class="mb-1"><b>Coverage period</b> → <b>Visit coverage</b>: was every school <b>visited</b> at least once in these dates? Any started inspection counts, if the officer was within the radius of the school (or at any distance, when <b>Any distance</b> is on).</p>
                <p class="mb-1"><b>Inspection cycle</b> → <b>Inspection coverage</b>: does every school have a <b>submitted</b> inspection in this cycle? Drafts do not count.</p>
                <p class="mb-2"><i>Example: period Monthly, cycle Quarterly. In November the report shows which schools were visited in November, and which schools have a submitted inspection between 1 October and 31 December.</i></p>
                <div class="fw-semibold mb-1"><i class="bi bi-diagram-3 me-1"></i>District settings</div>
                <p class="mb-0">A district setting replaces the state default for that district only. <b>Use state default</b> / <b>Reset</b> brings the district back to the state default.</p>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            // show the from / to dates only for "Custom date range"; 26-11-2026: "With these settings" sentence
            // (the dates come from the server, CoverageReportController::preview())
            const PREVIEW = @json($preview);
            const fmt = (v) => v ? new Date(v + 'T00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '…';
            document.querySelectorAll('[data-cov-form]').forEach((form) => {
                const sync = () => {
                    const v = form.querySelector('input[name="period_type"]:checked')?.value ?? form.querySelector('[data-cov-type]')?.value;
                    form.querySelectorAll('[data-cov-custom]').forEach((el) => el.classList.toggle('d-none', v !== 'custom'));
                    const box = form.querySelector('[data-cov-preview]');
                    if (!box) return;
                    const cycle = form.querySelector('[name="inspection_cycle"]');
                    const anyBox = form.querySelector('[data-cov-any]');
                    const radiusBox = form.querySelector('input[name="verify_radius_m"]');
                    if (anyBox && radiusBox) radiusBox.disabled = anyBox.checked;
                    const radius = anyBox ? (anyBox.checked ? '0' : radiusBox?.value) : box.dataset.radius;
                    const radiusText = radius === '0' ? 'A visit counts at <b>any distance</b> from the school.'
                        : 'A visit counts when the officer is within <b>' + String(radius ?? '').replace(/[&<>"]/g, '') + ' m</b> of the school.';
                    const period = v === 'custom'
                        ? fmt(form.querySelector('[name="custom_from"]').value) + ' – ' + fmt(form.querySelector('[name="custom_to"]').value)
                        : PREVIEW.periods[v];
                    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
                    box.innerHTML = '<b>With these settings</b>The report opens on <b>' + esc(period) + '</b> and counts the visits made then. '
                        + 'A school counts as inspected when an inspection is submitted in the <b>' + esc(cycle.options[cycle.selectedIndex].text.toLowerCase())
                        + '</b> cycle, now <b>' + esc(PREVIEW.cycles[cycle.value]) + '</b>. ' + radiusText;
                };
                form.addEventListener('input', sync);
                form.addEventListener('change', sync);
                sync();
            });
        </script>
    @endpush
</x-layouts.app>
