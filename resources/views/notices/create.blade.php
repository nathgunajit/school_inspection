<x-layouts.app title="New notice" :back="route('notices.index', ['tab' => 'sent'])">
    @php $isAdmin = auth()->user()->isStateAdmin(); @endphp
    <form method="POST" action="{{ route('notices.store') }}" enctype="multipart/form-data" class="row g-4" data-loader-text="Sending…">
        @csrf
        <div class="col-lg-8">
            <div class="card card-elevated">
                <div class="card-header"><i class="bi bi-megaphone me-2 text-primary"></i>Instruction / Notification / Order letter</div>
                <div class="card-body">
                    @if ($errors->any())<div class="alert alert-danger py-2 text-sm">{{ $errors->first() }}</div>@endif
                    <div class="row g-3">
                        <div class="col-sm-4"><label class="form-label" for="n-type">Type</label>
                            <select id="n-type" name="type" class="form-select @error('type') is-invalid @enderror" required>
                                @foreach (\App\Models\NoticeType::options(true) as $k => $l)<option value="{{ $k }}" @selected(old('type', 'instruction') === $k)>{{ $l }}</option>@endforeach
                            </select></div>
                        <div class="col-sm-4"><label class="form-label" for="n-ref">Letter / order no. <span class="opt">optional</span></label>
                            <input id="n-ref" name="ref_no" value="{{ old('ref_no') }}" maxlength="60" class="form-control" placeholder="e.g. SSA/ASM/2026/112"></div>
                        <div class="col-sm-4"><label class="form-label" for="n-date">Date</label>
                            <input id="n-date" type="date" name="notice_date" value="{{ old('notice_date', today()->toDateString()) }}" class="form-control @error('notice_date') is-invalid @enderror" required></div>
                        <div class="col-12"><label class="form-label" for="n-title">Subject</label>
                            <input id="n-title" name="title" value="{{ old('title') }}" maxlength="200" class="form-control @error('title') is-invalid @enderror" placeholder="e.g. Visit 2–3 schools every working day" required></div>
                        <div class="col-12"><label class="form-label" for="n-body">Message</label>
                            <textarea id="n-body" name="body" rows="8" maxlength="10000" class="form-control @error('body') is-invalid @enderror" required placeholder="Write the instruction, notification or order…">{{ old('body') }}</textarea></div>
                        <div class="col-sm-7"><label class="form-label" for="n-file">Attach the letter <span class="opt">optional · PDF, Word or image, up to {{ \App\Support\FileSize::human((int) config('inspection.notice.max_kb')) }}</span></label>
                            <input id="n-file" type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="form-control @error('attachment') is-invalid @enderror">
                            @error('attachment')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-sm-5"><label class="form-label" for="n-exp">Show until <span class="opt">optional</span></label>
                            <input id="n-exp" type="date" name="expires_on" value="{{ old('expires_on') }}" class="form-control @error('expires_on') is-invalid @enderror">
                            @error('expires_on')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        {{-- 25-11-2026: homepage (Dashboard) Latest Notice ticker --}}
                        <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="show_on_home" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" name="show_on_home" value="1" id="n-home" @checked(old('show_on_home', '1') === '1')>
                            <label class="form-check-label fw-semibold" for="n-home"><i class="bi bi-broadcast me-1 text-danger"></i>Show in Latest Notice on the homepage</label></div>
                            <div class="form-text">The newest ticked notice scrolls at the top of the Dashboard of everyone who receives it.</div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-people me-2 text-primary"></i>Send to</div>
                <div class="card-body">
                    <label class="form-label">Officers</label>
                    @foreach ($levels as $l)
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="levels[]" value="{{ $l->value }}" id="lv-{{ $l->value }}" @checked(in_array($l->value, old('levels', ['cluster']), true))>
                            <label class="form-check-label" for="lv-{{ $l->value }}">{{ $l->label() }}{{ $l === \App\Enums\UserLevel::Cluster ? ' (CRCC)' : '' }}</label></div>
                    @endforeach
                    @error('levels')<div class="text-danger text-sm">{{ $message }}</div>@enderror

                    <label class="form-label mt-3" for="n-district">District</label>
                    <select id="n-district" name="district_id" class="form-select" data-notice-district @disabled(! $isAdmin)>
                        @if ($isAdmin)<option value="">Whole state</option>@endif
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected((int) old('district_id', $ownDistrict) === $d->id)>{{ $d->label }}</option>@endforeach
                    </select>
                    @unless ($isAdmin)<input type="hidden" name="district_id" value="{{ $ownDistrict }}">@endunless
                    <label class="form-label mt-3" for="n-block">Block <span class="opt">optional</span></label>
                    <select id="n-block" name="block_id" class="form-select" data-notice-block>
                        <option value="">All blocks</option>
                        @foreach ($blocks as $b)<option value="{{ $b->id }}" data-district="{{ $b->district_id }}" @selected((int) old('block_id') === $b->id)>{{ $b->label }}</option>@endforeach
                    </select>
                    @if ($clusters->isNotEmpty())
                        <label class="form-label mt-3" for="n-cluster">Cluster <span class="opt">optional</span></label>
                        <select id="n-cluster" name="cluster_id" class="form-select" data-notice-cluster>
                            <option value="">All clusters</option>
                            @foreach ($clusters as $c)<option value="{{ $c->id }}" data-block="{{ $c->block_id }}" @selected((int) old('cluster_id') === $c->id)>{{ $c->label }}</option>@endforeach
                        </select>
                    @endif
                    <p class="text-xs text-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Active officers of the ticked levels posted in this area see it in their Notices inbox and on the dashboard, and can acknowledge it. You can follow who has read it.</p>
                </div>
            </div>
            <button class="btn btn-primary w-100 mt-3"><i class="bi bi-send"></i>Send</button>
        </div>
    </form>

    @push('scripts')
    <script>
        (() => { // narrow the block list to the district, and the cluster list to the block
            const d = document.querySelector('[data-notice-district]'), b = document.querySelector('[data-notice-block]'), c = document.querySelector('[data-notice-cluster]');
            const filter = (sel, attr, val) => { if (!sel) return; [...sel.options].forEach(o => { if (!o.value) return; o.hidden = !!val && o.dataset[attr] !== val; if (o.hidden && o.selected) sel.value = ''; }); };
            const run = () => { filter(b, 'district', d.value); if (d.value === '' && b) { [...b.options].forEach(o => { if (o.value) o.hidden = true; }); b.value = ''; } filter(c, 'block', b?.value); };
            d?.addEventListener('change', run); b?.addEventListener('change', run); run();
        })();
    </script>
    @endpush
</x-layouts.app>
