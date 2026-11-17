@php
    use App\Models\InspectionAbsentTeacher as AT;
    use App\Models\InspectionClassroomObservation as CO;
    use App\Models\InspectionFundPosition as FP;

    $meta = $steps[$step];
    $lastStep = count($steps);
    $mandatory = config('inspection.mandatory_photos');
    $catLabels = $photoCategories->pluck('label', 'code');
    $warnings = $progress['warnings'][$step] ?? [];
    $clientConfig = [
        'step' => $step,
        'saveUrl' => route('inspections.answers', $inspection),
        'progressUrl' => route('inspections.progress', $inspection),
        'evidenceUrl' => route('evidence.store', $inspection),
        'loginUrl' => route('login'),
        'questions' => (object) collect($questions)->keyBy('code')->all(),
        'answers' => (object) $answers,
        'evidence' => $inspection->evidence->map->toClientArray()->values(),
        'teachers' => $teacherRows,
        'classrooms' => $classroomRows,
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
                    <span class="step-icon"><i class="bi {{ $meta['icon'] }}"></i></span>
                    <div><div class="text-xs text-secondary fw-normal">Step {{ $step }} of {{ $lastStep }}</div>{{ $meta['title'] }}</div>
                </div>
                <div class="card-body pt-2">

                    <div id="step-warnings">
                        @foreach ($warnings as $w)
                            <div class="alert alert-warning text-sm py-2 mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>{{ $w }}</div>
                        @endforeach
                    </div>

                    {{-- ============ STEP 1: info card + condition questions ============ --}}
                    @if ($step === 1)
                        <div class="info-card my-3">
                            <div><span>Officer</span><strong>{{ $inspection->officer->name }}</strong><small>{{ $inspection->officer->designation?->label ?? $inspection->officer->level->label() }}</small></div>
                            <div><span>School</span><strong>{{ $inspection->school->name }}</strong><small class="font-monospace">UDISE {{ $inspection->school->udise_code }}</small></div>
                            <div><span>Date &amp; time</span><strong>{{ $inspection->started_at->format('d M Y, h:i A') }}</strong><small>Recorded by server</small></div>
                            <div><span>Location</span>
                                @if ($inspection->start_lat)
                                    <strong>{{ number_format($inspection->start_lat, 5) }}, {{ number_format($inspection->start_lng, 5) }}</strong>
                                    <small>@if (($d = $inspection->distanceFromSchool($inspection->start_lat, $inspection->start_lng)) !== null){{ $d < 1000 ? $d.' m' : round($d / 1000, 1).' km' }} from school @else {{ $inspection->school->block?->name }} @endif</small>
                                @else <strong class="text-warning">Not captured</strong><small>{{ $inspection->school->block?->name }}</small> @endif
                            </div>
                        </div>
                    @endif

                    @if ($step === 2)
                        <div class="row">
                            @foreach ($questions as $i => $q)
                                <div class="col-sm-6">@include('inspections.partials.question', ['q' => $q, 'n' => $i + 1])</div>
                            @endforeach
                        </div>
                    @else
                        @foreach ($questions as $i => $q)
                            @include('inspections.partials.question', ['q' => $q, 'n' => $step === 1 ? ($i < 2 ? '•' : $i - 1) : $i + 1])
                        @endforeach
                    @endif

                    {{-- ============ STEP 3: Teachers absentee (repeating) ============ --}}
                    @if ($step === 3)
                        <p class="text-sm text-secondary mt-3">Add each teacher absent on the day of inspection. Leave empty if all teachers were present.</p>
                        <div id="teacher-list" class="row-list mb-3"></div>
                        <form id="teacher-form" class="row-form" data-url="{{ route('inspections.teachers.store', $inspection) }}">
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label text-sm">Teacher's name</label><input name="teacher_name" class="form-control" maxlength="120" required></div>
                                <div class="col-md-6"><label class="form-label text-sm">Teacher's type</label>
                                    <select name="teacher_type" class="form-select" required><option value="">Select</option>@foreach (AT::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-md-6"><label class="form-label text-sm">Reason for absence</label>
                                    <select name="reason" class="form-select" required><option value="">Select</option>@foreach (AT::REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-md-6"><label class="form-label text-sm">Remarks</label><input name="remarks" class="form-control" maxlength="500"></div>
                            </div>
                            <button class="btn btn-soft-primary mt-2"><i class="bi bi-plus-lg"></i>Add teacher</button>
                        </form>
                    @endif

                    {{-- ============ STEP 4: Classroom performance (repeating) ============ --}}
                    @if ($step === 4)
                        <p class="text-sm text-secondary mt-3">Add at least one classroom observed. You can add more than one record.</p>
                        <div id="classroom-list" class="row-list mb-3"></div>
                        <form id="classroom-form" class="row-form" data-url="{{ route('inspections.classrooms.store', $inspection) }}">
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label text-sm">Name of the teacher</label><input name="teacher_name" class="form-control" maxlength="120" required></div>
                                <div class="col-6 col-md-3"><label class="form-label text-sm">Class</label>
                                    <select name="class" class="form-select" required><option value="">Select</option>@foreach (CO::CLASSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-6 col-md-3"><label class="form-label text-sm">Subject</label><input name="subject" class="form-control" maxlength="100" required></div>
                                <div class="col-md-6"><label class="form-label text-sm d-block">Use of TLM by teacher</label>
                                    <div class="choice-group"><input type="radio" name="tlm_used" id="tlm_yes" value="yes" required><label for="tlm_yes" class="tone-good">Yes</label>
                                        <input type="radio" name="tlm_used" id="tlm_no" value="no"><label for="tlm_no" class="tone-bad">No</label></div></div>
                                <div class="col-md-6"><label class="form-label text-sm d-block">Participation of students</label>
                                    <div class="choice-group">@foreach (CO::PARTICIPATION as $k => $l)<input type="radio" name="participation" id="part_{{ $k }}" value="{{ $k }}" required><label for="part_{{ $k }}" class="{{ $k === 'satisfactory' ? 'tone-good' : 'tone-bad' }}">{{ $l }}</label>@endforeach</div></div>
                                <div class="col-12"><label class="form-label text-sm">Remarks</label><input name="remarks" class="form-control" maxlength="500"></div>
                            </div>
                            <button class="btn btn-soft-primary mt-2"><i class="bi bi-plus-lg"></i>Add classroom record</button>
                        </form>
                    @endif

                    {{-- ============ STEP 5: Fund received by year ============ --}}
                    @if ($step === 5)
                        <p class="text-sm text-secondary mt-3">Optional. Select the year first, then enter the amounts <strong>in lakh</strong>. Saving the same year again updates it.</p>
                        <div id="fund-list" class="row-list mb-3"></div>
                        <form id="fund-form" class="row-form" data-url="{{ route('inspections.funds.store', $inspection) }}">
                            <div class="row g-2 mb-2">
                                <div class="col-md-4"><label class="form-label text-sm">Year of fund received</label>
                                    <select name="financial_year" class="form-select" required><option value="">Select year</option>@foreach ($financialYears as $fy)<option value="{{ $fy }}">{{ $fy }}</option>@endforeach</select></div>
                            </div>
                            <fieldset id="fund-amounts" disabled>
                                @foreach ([['Fund received & expenditure (SSA)', [['asg_received', 'Annual School Grant received'], ['mdm_received', 'MDM fund received'], ['asg_expenditure', 'Annual School Grant expenditure'], ['mdm_expenditure', 'MDM fund expenditure']]],
                                           ['Fund received & expenditure (Government)', [['govt_received', 'Fund received'], ['govt_expenditure', 'Expenditure of fund']]],
                                           ['Fund received & expenditure (Other source)', [['other_received', 'Fund received'], ['other_expenditure', 'Expenditure of fund']]]] as [$title, $fields])
                                    <div class="fund-group">
                                        <div class="fund-group-title">{{ $title }}</div>
                                        <div class="row g-2">
                                            @foreach ($fields as [$name, $label])
                                                <div class="col-6 col-md-3"><label class="form-label text-xs">{{ $label }}</label>
                                                    <div class="input-group input-group-sm"><input name="{{ $name }}" type="number" min="0" step="0.01" inputmode="decimal" class="form-control" placeholder="0.00"><span class="input-group-text">lakh</span></div></div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                                <button class="btn btn-soft-primary mt-2"><i class="bi bi-check2"></i>Save year</button>
                            </fieldset>
                        </form>
                    @endif

                    {{-- ============ STEP 6: suggestions after governance questions ============ --}}
                    @if ($step === 6)
                        <div class="section-title mt-4">Suggestions</div>
                        <div id="suggestions" data-url="{{ route('inspections.suggestions', $inspection) }}">
                            @php $sug = $inspection->remarks->where('type', 'suggestion')->pluck('body')->values(); @endphp
                            @for ($i = 0; $i < config('inspection.max_suggestions'); $i++)
                                <div class="input-group mb-2"><span class="input-group-text bg-white text-secondary text-sm">Suggestion-{{ $i + 1 }}</span>
                                    <input class="form-control" maxlength="500" value="{{ $sug[$i] ?? '' }}" data-suggestion aria-label="Suggestion {{ $i + 1 }}"></div>
                            @endfor
                        </div>
                    @endif

                    {{-- ============ STEP 7: Photographs ============ --}}
                    @if ($step === $lastStep)
                        <div class="alert alert-light border text-xs mt-3 mb-3 d-flex gap-2"><i class="bi bi-aspect-ratio"></i>
                            <span><strong>Photo standard:</strong> JPEG, longest side 1600 px, up to 1 MB, resized automatically. Each photo is stamped with UDISE, school, date, time and location. Avoid close-ups of children.</span></div>
                        <div class="photo-slots">
                            @foreach ($mandatory as $cat => $min)
                                <div class="photo-slot" data-req-cat="{{ $cat }}" data-min="{{ $min }}">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <div class="fw-semibold text-sm"><i class="bi bi-circle me-1"></i>Upload Photograph of {{ $catLabels[$cat] ?? $cat }} <span class="text-danger">*</span></div>
                                        <div class="d-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-soft-primary" data-camera data-category="{{ $cat }}" aria-label="Take photo"><i class="bi bi-camera"></i><span class="d-none d-sm-inline">Camera</span></button>
                                            <button type="button" class="btn btn-sm btn-light" data-gallery data-category="{{ $cat }}" aria-label="Upload from gallery"><i class="bi bi-images"></i><span class="d-none d-sm-inline">Gallery</span></button>
                                        </div>
                                    </div>
                                    <div class="photo-grid" data-photo-grid data-category="{{ $cat }}"></div>
                                </div>
                            @endforeach
                        </div>

                        <div class="section-title mt-4">Additional photographs <span class="text-secondary text-xs text-lowercase fw-normal">(optional · up to {{ config('inspection.evidence.max_photos') }} in total)</span></div>
                        <div class="photo-grid mb-2" data-photo-grid data-extra>
                            <button type="button" class="photo-add" data-camera data-category-picker><i class="bi bi-camera"></i>Camera</button>
                            <button type="button" class="photo-add" data-gallery data-category="other"><i class="bi bi-images"></i>Gallery</button>
                        </div>

                        <div class="section-title mt-4">Supporting documents <span class="text-secondary text-xs text-lowercase fw-normal">(optional · PDF/JPG/PNG · max 5 MB · up to {{ config('inspection.evidence.max_documents') }})</span></div>
                        <div class="list-card border rounded-3 mb-2" id="doc-list"></div>
                        <label class="btn btn-soft-primary btn-sm"><i class="bi bi-paperclip"></i>Attach document
                            <input type="file" id="doc-input" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="d-none"></label>
                    @endif

                    <input type="file" id="gallery-input" accept="image/*" class="d-none">
                </div>
            </div>

            <div class="action-bar">
                @if ($step > 1)
                    <a href="{{ route('inspections.step', [$inspection, $step - 1]) }}" class="btn btn-light"><i class="bi bi-arrow-left"></i><span class="d-none d-sm-inline">Back</span></a>
                @endif
                <div class="flex-grow-1 d-flex align-items-center justify-content-center text-xs text-secondary">
                    <span id="step-missing">{{ count($progress['missing'][$step] ?? []) ? count($progress['missing'][$step]).' item(s) pending in this step' : 'Step complete' }}</span>
                </div>
                @if ($step < $lastStep)
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
