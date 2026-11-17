@php
    $meta = $steps[$step];
    $photos = $inspection->evidence->map->toClientArray()->values();
    $mandatory = config('inspection.mandatory_photos');
    $catLabels = $photoCategories->pluck('label', 'code');
    $teacherRows = $inspection->absentTeachers->map(fn ($t) => [
        'id' => $t->id, 'teacher_name' => $t->teacher_name, 'status' => $t->statusLabel(), 'remarks' => $t->remarks,
        'delete_url' => route('inspections.teachers.destroy', [$inspection, $t]),
    ])->values();
    $fundRows = $inspection->fundPositions->map(fn ($f) => [
        'id' => $f->id, 'financial_year' => $f->financial_year, 'category' => $f->category?->label,
        'received' => number_format((float) $f->amount_received, 2), 'utilised' => number_format((float) $f->amount_utilised, 2),
        'balance' => number_format($f->balance(), 2), 'delete_url' => route('inspections.funds.destroy', [$inspection, $f]),
    ])->values();
    $clientConfig = [
        'step' => $step,
        'saveUrl' => route('inspections.answers', $inspection),
        'progressUrl' => route('inspections.progress', $inspection),
        'evidenceUrl' => route('evidence.store', $inspection),
        'questions' => (object) collect($questions)->keyBy('code')->all(),
        'answers' => (object) $answers,
        'evidence' => $photos,
        'teachers' => $teacherRows,
        'funds' => $fundRows,
        'mandatory' => $mandatory,
        'maxPhotos' => config('inspection.evidence.max_photos'),
        'maxSide' => config('inspection.evidence.photo_max_side_px'),
        'stamp' => ['udise' => $inspection->school->udise_code, 'school' => $inspection->school->name, 'officer' => $inspection->officer->name],
    ];
@endphp
<x-layouts.app :title="'Inspection · '.$meta['short']" :back="route('inspections.mine')">
    @include('inspections.partials.stepper', ['current' => $step])

    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="card card-elevated">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="stat-icon tone-primary" style="width:36px;height:36px;border-radius:10px;display:grid;place-items:center"><i class="bi {{ $meta['icon'] }}"></i></span>
                    <div><div class="text-xs text-secondary fw-normal">Step {{ $step }} of {{ count($steps) }}</div>{{ $meta['title'] }}</div>
                </div>
                <div class="card-body pt-2">

                    {{-- ============ STEP 1: Basic information (auto-filled) ============ --}}
                    @if ($step === 1)
                        <div class="row g-3 my-1">
                            <div class="col-md-6">
                                <div class="p-3 rounded-3 bg-light h-100">
                                    <div class="section-title mb-2">Inspection officer</div>
                                    <dl class="kv mb-0">
                                        <dt>Name</dt><dd>{{ $inspection->officer->name }}</dd>
                                        <dt>Designation</dt><dd>{{ $inspection->officer->designation?->label ?? $inspection->officer->level->label() }}</dd>
                                        <dt>Date &amp; time</dt><dd>{{ $inspection->started_at->format('d M Y, h:i A') }} <i class="bi bi-lock-fill text-secondary text-xs" title="Recorded by server"></i></dd>
                                        <dt>Device location</dt><dd>
                                            @if ($inspection->start_lat)
                                                {{ number_format($inspection->start_lat, 5) }}, {{ number_format($inspection->start_lng, 5) }}
                                                @if ($d = $inspection->distanceFromSchool($inspection->start_lat, $inspection->start_lng))<div class="text-xs text-secondary">{{ $d < 1000 ? $d.' m' : round($d / 1000, 1).' km' }} from school</div>@endif
                                            @else <span class="text-warning">Not captured</span> @endif
                                        </dd>
                                    </dl>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 rounded-3 bg-light h-100">
                                    <div class="section-title mb-2">School</div>
                                    <dl class="kv mb-0">
                                        <dt>School</dt><dd>{{ $inspection->school->name }}</dd>
                                        <dt>UDISE Code</dt><dd class="font-monospace">{{ $inspection->school->udise_code }}</dd>
                                        <dt>District / Block</dt><dd>{{ $inspection->school->district?->name }} / {{ $inspection->school->block?->name }}</dd>
                                        <dt>Cluster</dt><dd>{{ $inspection->school->cluster?->name }}</dd>
                                        <dt>Management</dt><dd>{{ $inspection->school->management?->label ?? '—' }}</dd>
                                        <dt>Category</dt><dd>{{ $inspection->school->category?->label ?? '—' }}</dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                        <div class="section-title mt-4 mb-0">Person met at the school</div>
                    @endif

                    {{-- ============ STEP 4 helper text ============ --}}
                    @if ($step === 4)
                        <div class="alert alert-light border text-sm mt-3 mb-1"><i class="bi bi-calculator me-1"></i>Teachers present + absent must equal working teachers. Add details for each absent teacher below.</div>
                    @endif

                    {{-- ============ Questions for this step ============ --}}
                    @if ($step === 4)
                        <div class="row">
                            @foreach ($questions as $i => $q)
                                <div class="{{ $q['type'] === 'number' ? 'col-sm-6' : 'col-12' }}">
                                    @include('inspections.partials.question', ['q' => $q, 'n' => $i + 1])
                                </div>
                            @endforeach
                        </div>
                        <div id="att-check" class="alert d-none text-sm py-2"></div>
                    @elseif ($step !== 6)
                        @foreach ($questions as $i => $q)
                            @include('inspections.partials.question', ['q' => $q, 'n' => $step === 3 ? chr(97 + $i).'.' : $i + 1])
                        @endforeach
                    @endif

                    {{-- ============ STEP 4: Absent teachers ============ --}}
                    @if ($step === 4)
                        <div class="mt-3 p-3 rounded-3 border">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold"><i class="bi bi-person-x me-1 text-danger"></i>Absent teachers <span class="badge text-bg-light" id="teacher-count">{{ $inspection->absentTeachers->count() }}</span></div>
                            </div>
                            <div id="teacher-list" class="list-card mb-3"></div>
                            <form id="teacher-form" class="row g-2 align-items-end" data-url="{{ route('inspections.teachers.store', $inspection) }}">
                                <div class="col-md-4"><label class="form-label text-sm">Teacher name</label><input name="teacher_name" class="form-control" maxlength="120" required></div>
                                <div class="col-md-3"><label class="form-label text-sm">Status</label>
                                    <select name="status" class="form-select" required>
                                        @foreach (\App\Models\InspectionAbsentTeacher::STATUSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                                    </select></div>
                                <div class="col-md-3"><label class="form-label text-sm">Remarks</label><input name="remarks" class="form-control" maxlength="500"></div>
                                <div class="col-md-2 d-grid"><button class="btn btn-soft-primary"><i class="bi bi-plus-lg"></i>Add</button></div>
                            </form>
                        </div>
                    @endif

                    {{-- ============ STEP 5: Fund position ============ --}}
                    @if ($step === 5)
                        <div class="mt-3 p-3 rounded-3 border">
                            <div class="fw-semibold mb-1"><i class="bi bi-cash-coin me-1 text-success"></i>Fund position <span class="text-secondary fw-normal text-sm">(optional)</span></div>
                            <p class="text-xs text-secondary mb-2">If you record a fund, take one photograph of the cash book page.</p>
                            <div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>Year</th><th>Fund</th><th class="text-end">Received</th><th class="text-end">Utilised</th><th class="text-end">Balance</th><th></th></tr></thead><tbody id="fund-list"></tbody></table></div>
                            <form id="fund-form" class="row g-2 align-items-end" data-url="{{ route('inspections.funds.store', $inspection) }}">
                                @php $fy = now()->month >= 4 ? now()->year : now()->year - 1; @endphp
                                <div class="col-6 col-md-2"><label class="form-label text-sm">Financial year</label><input name="financial_year" value="{{ $fy }}-{{ $fy + 1 }}" class="form-control" pattern="\d{4}-\d{4}" required></div>
                                <div class="col-6 col-md-3"><label class="form-label text-sm">Fund category</label>
                                    <select name="fund_category_id" class="form-select" required>@foreach ($fundCategories as $fc)<option value="{{ $fc->id }}">{{ $fc->label }}</option>@endforeach</select></div>
                                <div class="col-6 col-md-2"><label class="form-label text-sm">Received (₹)</label><input name="amount_received" type="number" min="0" step="0.01" class="form-control" required></div>
                                <div class="col-6 col-md-2"><label class="form-label text-sm">Utilised (₹)</label><input name="amount_utilised" type="number" min="0" step="0.01" class="form-control" required></div>
                                <div class="col-12 col-md-3 d-grid"><button class="btn btn-soft-primary"><i class="bi bi-plus-lg"></i>Add fund</button></div>
                            </form>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <span class="text-sm fw-semibold"><i class="bi bi-journal-text me-1"></i>Cash book page</span>
                                <button type="button" class="btn btn-sm btn-soft-primary" data-camera data-category="cash_book" data-caption="Cash book page"><i class="bi bi-camera"></i>Take photo</button>
                            </div>
                            <div class="photo-grid mt-2" data-photo-grid data-category="cash_book"></div>
                        </div>
                    @endif

                    {{-- ============ STEP 6: Photographs, documents, suggestions ============ --}}
                    @if ($step === 6)
                        <div class="section-title mt-3">Mandatory photographs</div>
                        <div class="d-flex flex-wrap gap-2 mb-3" id="mandatory-chips">
                            @foreach ($mandatory as $cat => $min)
                                <span class="req-chip" data-req-cat="{{ $cat }}" data-min="{{ $min }}"><i class="bi bi-circle"></i>{{ $catLabels[$cat] ?? $cat }}</span>
                            @endforeach
                        </div>
                        <div class="photo-grid mb-2" data-photo-grid data-all>
                            <button type="button" class="photo-add" data-camera data-category-picker><i class="bi bi-camera"></i>Take photo</button>
                        </div>
                        <p class="text-xs text-secondary"><i class="bi bi-info-circle me-1"></i>Photos can only be taken with the in-app camera. Avoid close-ups that identify individual children. Up to {{ config('inspection.evidence.max_photos') }} photos in total.</p>

                        <div class="section-title mt-4">Supporting documents <span class="text-secondary text-xs text-lowercase fw-normal">(optional · PDF/JPG/PNG · max 5 MB · up to {{ config('inspection.evidence.max_documents') }})</span></div>
                        <div class="list-card border rounded-3 mb-2" id="doc-list"></div>
                        <label class="btn btn-soft-primary"><i class="bi bi-paperclip"></i>Attach document
                            <input type="file" id="doc-input" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="d-none"></label>

                        <div class="section-title mt-4">Suggestions <span class="text-secondary text-xs text-lowercase fw-normal">(up to {{ config('inspection.max_suggestions') }})</span></div>
                        <div id="suggestions" data-url="{{ route('inspections.suggestions', $inspection) }}">
                            @php $sug = $inspection->remarks->where('type', 'suggestion')->pluck('body')->values(); @endphp
                            @for ($i = 0; $i < config('inspection.max_suggestions'); $i++)
                                <div class="input-group mb-2"><span class="input-group-text bg-white text-secondary">{{ $i + 1 }}</span>
                                    <input class="form-control" maxlength="500" value="{{ $sug[$i] ?? '' }}" data-suggestion aria-label="Suggestion {{ $i + 1 }}"></div>
                            @endfor
                        </div>
                        @foreach ($questions as $i => $q)
                            @include('inspections.partials.question', ['q' => $q, 'n' => ''])
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="action-bar">
                @if ($step > 1)
                    <a href="{{ route('inspections.step', [$inspection, $step - 1]) }}" class="btn btn-light"><i class="bi bi-arrow-left"></i><span class="d-none d-sm-inline">Back</span></a>
                @endif
                <div class="flex-grow-1 d-flex align-items-center justify-content-center text-xs text-secondary">
                    <span id="step-missing">{{ count($progress['missing'][$step] ?? []) ? count($progress['missing'][$step]).' item(s) pending in this step' : 'Step complete' }}</span>
                </div>
                @if ($step < count($steps))
                    <a href="{{ route('inspections.step', [$inspection, $step + 1]) }}" class="btn btn-primary" data-next>Next<i class="bi bi-arrow-right"></i></a>
                @else
                    <a href="{{ route('inspections.review', $inspection) }}" class="btn btn-primary" data-next><i class="bi bi-flag"></i>Review</a>
                @endif
            </div>

            @if ($inspection->status->value === 'draft')
                <div class="text-center mt-3">
                    <button class="btn btn-link btn-sm text-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-trash"></i>Cancel this draft</button>
                </div>
            @endif
        </div>
    </div>

    {{-- Cancel draft modal --}}
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><form method="POST" action="{{ route('inspections.cancel', $inspection) }}" class="modal-content">
            @csrf
            <div class="modal-header border-0"><h5 class="modal-title">Cancel draft?</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body pt-0"><label class="form-label" for="cancel_reason">Reason</label><input id="cancel_reason" name="reason" class="form-control" required maxlength="255"></div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep draft</button><button class="btn btn-danger">Cancel draft</button></div>
        </form></div>
    </div>

    @include('inspections.partials.camera', ['photoCategories' => $photoCategories])

    @push('scripts')
        <script>window.SI = {!! json_encode($clientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!};</script>
        @vite(['resources/js/wizard.js', 'resources/js/camera.js'])
    @endpush
</x-layouts.app>
