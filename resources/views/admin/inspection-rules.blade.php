{{-- 26-11-2026: Admin Settings → Inspection Rules (State Admin): when a school is blocked for a new inspection --}}
@php use App\Models\InspectionStartSetting as R; @endphp
<x-layouts.app title="Inspection Rules">
    <div class="row g-4">
        <div class="col-xl-7">
            <section class="card cov-card cov-set mb-4">
                <div class="cov-card-head"><h3 class="cov-title"><i class="bi bi-shield-lock"></i>When can a school be inspected again?</h3><span class="cov-order">Applies to the whole state</span></div>
                <form method="POST" action="{{ route('admin.inspection-rules.update') }}" class="cov-set-body" data-rules-form>
                    @csrf @method('PUT')

                    <label class="form-label">Block after a submitted inspection <span class="opt">officers of the same level</span></label>
                    <div class="cov-choice mb-2">
                        @foreach (R::WINDOWS as $k => $l)
                            <input type="radio" class="btn-check" name="repeat_window" id="rw-{{ $k }}" value="{{ $k }}" @checked(old('repeat_window', $setting->repeat_window) === $k)>
                            <label for="rw-{{ $k }}"><i class="bi {{ ['month' => 'bi-calendar-month', 'week' => 'bi-calendar-week', 'day' => 'bi-calendar-day', 'none' => 'bi-slash-circle'][$k] }}"></i>{{ $l }}</label>
                        @endforeach
                    </div>
                    @error('repeat_window')<div class="text-danger text-sm mb-2">{{ $message }}</div>@enderror
                    <p class="cov-field-help mt-2">When an officer submits an inspection of a school, other officers <b>of the same level</b> cannot start a new one there until this period ends. <b>None</b> = no block after a submission. An open draft by another officer of the same level <b>always</b> blocks the school until it is submitted or cancelled.</p>

                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="cross-level" name="cross_level" value="1" @checked(old('cross_level', $setting->cross_level))>
                        <label class="form-check-label fw-semibold" for="cross-level">Officers of different levels (Cluster, Block, District) may inspect the same school, even on the same day</label>
                    </div>
                    <p class="cov-field-help mt-1">Switched off: an officer of another level who inspects the school today blocks it for the rest of the day. On other days different levels are not blocked.</p>

                    <div class="cov-preview mb-3" data-rules-preview aria-live="polite"></div>

                    <button class="btn btn-primary"><i class="bi bi-check2"></i>Save</button>
                    @if ($setting->updater)<div class="text-xs text-secondary mt-2">Last changed by {{ $setting->updater->name }} on {{ $setting->updated_at->format('d M Y, h:i A') }}</div>@endif
                </form>
            </section>
        </div>
        <div class="col-xl-5">
            <div class="alert alert-light border text-sm">
                <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Examples (today {{ today()->format('d M Y') }})</div>
                <ul class="mb-2 ps-3">
                    @foreach (R::WINDOWS as $k => $l)
                        @continue($k === 'none')
                        <li><b>{{ $l }}</b>: a school submitted today by a Cluster officer is blocked for other Cluster officers ({{ $examples[$k] }}).</li>
                    @endforeach
                    <li><b>None</b>: another Cluster officer may start at once (not while a draft is open).</li>
                </ul>
                <p class="mb-1">An officer can always reopen <b>their own</b> draft, and inspects a school at most once a day.</p>
                <p class="mb-0">These rules also decide which schools can go on an inspection plan and on Today's Schools.</p>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            // live "With these settings" summary
            (() => {
                const form = document.querySelector('[data-rules-form]');
                const box = form.querySelector('[data-rules-preview]');
                const repeat = {
                    month: 'other officers of the same level may inspect it again from the 1st of next month',
                    week: 'other officers of the same level may inspect it again from the next Monday',
                    day: 'other officers of the same level may inspect it again from the next day',
                    none: 'another officer of the same level may inspect it again at once',
                };
                const sync = () => {
                    const w = form.querySelector('input[name="repeat_window"]:checked')?.value ?? 'month';
                    const cross = form.querySelector('#cross-level').checked;
                    box.innerHTML = '<b>With these settings</b>After an inspection of a school is submitted, ' + repeat[w] + '. '
                        + (cross ? 'Officers of different levels may inspect the same school, <b>even on the same day</b>.'
                                 : 'Officers of different levels may <b>not</b> inspect the same school on the same day.');
                };
                form.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endpush
</x-layouts.app>
