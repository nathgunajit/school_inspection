<x-layouts.app title="Find School">
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <form method="GET" class="find-hero" id="find-form" role="search" data-live-url="{{ route('schools.index') }}">
                <div class="find-hero-top">
                    <span class="find-hero-icon"><i class="bi bi-building-check"></i></span>
                    <div class="min-w-0">
                        <h2 class="find-hero-title">Find a school</h2>
                        <p class="find-hero-sub">Search by school name or UDISE Code. Results update as you type. <a href="{{ route('schools.mine') }}" class="find-hero-link">See all my schools <i class="bi bi-arrow-right"></i></a></p>
                    </div>
                </div>
                <div class="find-box">
                    <label class="visually-hidden" for="q">School name or UDISE Code</label>
                    <div class="find-input">
                        <i class="bi bi-search"></i>
                        <input id="q" name="q" value="{{ $q }}" type="search" class="form-control" placeholder="School name or 11-digit UDISE Code"
                               autocomplete="off" autofocus inputmode="search" maxlength="100" aria-describedby="find-tips">
                        <span class="find-actions">
                            <span class="spinner-border find-spin d-none" id="find-spin" role="status" aria-label="Searching"></span>
                            <button type="button" class="find-clear {{ $q === '' ? 'd-none' : '' }}" id="find-clear" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                            <button class="btn btn-primary find-go"><span class="d-none d-sm-inline">Search</span><i class="bi bi-arrow-right d-sm-none"></i></button>
                        </span>
                    </div>
                    <div class="find-tips" id="find-tips">
                        <span><i class="bi bi-check2-circle"></i>Type 2+ letters of the name</span>
                        <span><i class="bi bi-check2-circle"></i>Or the first digits of the UDISE Code</span>
                        <span><i class="bi bi-check2-circle"></i>Full 11-digit code + Enter opens the school</span>
                    </div>
                </div>
                @if ($districts->isNotEmpty() || $blocks->isNotEmpty() || $clusters->isNotEmpty())
                    <div class="find-filters">
                        @if ($districts->isNotEmpty())
                            <select name="district_id" class="form-select" data-find-filter aria-label="District"><option value="">All districts</option>
                                @foreach ($districts as $d)<option value="{{ $d->id }}" @selected(request('district_id') == $d->id)>{{ $d->label }}</option>@endforeach</select>
                        @endif
                        @if ($blocks->isNotEmpty())
                            <select name="block_id" class="form-select" data-find-filter aria-label="Block"><option value="">All blocks</option>
                                @foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(request('block_id') == $b->id)>{{ $b->label }}</option>@endforeach</select>
                        @endif
                        @if ($clusters->isNotEmpty())
                            <select name="cluster_id" class="form-select" data-find-filter aria-label="Cluster"><option value="">All clusters</option>
                                @foreach ($clusters as $c)<option value="{{ $c->id }}" @selected(request('cluster_id') == $c->id)>{{ $c->label }}</option>@endforeach</select>
                        @endif
                    </div>
                @endif
            </form>

            <div id="find-results" aria-live="polite">
                @include('schools._results')
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        (function () {
            const form = document.getElementById('find-form');
            const input = document.getElementById('q');
            const out = document.getElementById('find-results');
            const spin = document.getElementById('find-spin');
            const clear = document.getElementById('find-clear');
            let timer, ctrl, last = null;

            const params = () => {
                const p = new URLSearchParams(new FormData(form));
                for (const [k, v] of [...p.entries()]) if (!v) p.delete(k);
                return p;
            };
            const skeleton = () => { out.innerHTML = '<div class="school-list">' + '<div class="find-skeleton"></div>'.repeat(4) + '</div>'; };

            async function search() {
                const p = params();
                const q = (p.get('q') || '').trim();
                const hasFilter = [...p.keys()].some((k) => k !== 'q');
                // wait for 2 letters (or 3 digits) unless a filter is chosen
                if (q && q.length < (/^\d+$/.test(q) ? 3 : 2) && !hasFilter) return;
                const qs = p.toString();
                if (qs === last) return;
                last = qs;
                ctrl?.abort();
                ctrl = new AbortController();
                spin.classList.remove('d-none');
                const slow = setTimeout(skeleton, 250);
                try {
                    const r = await fetch(form.dataset.liveUrl + (qs ? '?' + qs : ''), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, signal: ctrl.signal, credentials: 'same-origin',
                    });
                    if (r.status === 401 || r.status === 419) { location.reload(); return; }
                    if (!r.ok) throw new Error(r.status);
                    const d = await r.json();
                    out.innerHTML = d.html;
                    history.replaceState(null, '', form.dataset.liveUrl + (qs ? '?' + qs : ''));
                } catch (e) {
                    if (e.name !== 'AbortError') { last = null; window.toast('Search failed. Please check your connection and try again.', 'danger'); }
                } finally {
                    clearTimeout(slow);
                    spin.classList.add('d-none');
                }
            }

            input.addEventListener('input', () => {
                clear.classList.toggle('d-none', !input.value);
                clearTimeout(timer);
                timer = setTimeout(search, 300);
            });
            form.querySelectorAll('[data-find-filter]').forEach((s) => s.addEventListener('change', () => {
                // district / block change the lists below them: reload the page to refresh those lists
                if (s.name === 'district_id' || s.name === 'block_id') {
                    if (s.name === 'district_id') form.querySelector('[name="block_id"]')?.remove();
                    form.querySelector('[name="cluster_id"]')?.remove();
                    form.submit();
                    return;
                }
                search();
            }));
            clear.addEventListener('click', () => { input.value = ''; clear.classList.add('d-none'); input.focus(); search(); });
            // Enter = normal search (an exact 11-digit UDISE Code opens the school directly)
            form.addEventListener('submit', () => { ctrl?.abort(); });
        })();
    </script>
    @endpush
</x-layouts.app>
