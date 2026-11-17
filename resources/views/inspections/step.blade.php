@php
    use App\Models\InspectionAbsentTeacher as AT;
    use App\Models\InspectionClassroomObservation as CO;
    use App\Models\InspectionFundPosition as FP;

    $meta = $steps[$step];
    $lastStep = count($steps);
    $mandatory = config('inspection.mandatory_photos');
    $catLabels = $photoCategories->pluck('label', 'code');
    $warnings = $progress['warnings'][$step] ?? [];

    // Presentation only: short guidance per step and visual group headings (question codes are unchanged)
    $intro = [
        1 => 'Record who you met and check the school building and facilities.',
        2 => 'Enter today\'s numbers. Use − / + or type the figure.',
        3 => 'Add every teacher absent today. Leave empty if all were present.',
        4 => 'Observe at least one class and record how it was taught.',
        5 => 'Optional. Choose a year, then enter amounts in lakh.',
        6 => 'Record how the school is run, then add up to three suggestions.',
        7 => 'Take or upload the four required photographs.',
    ];
    $groups = [
        'BAS_PERSON_NAME' => ['Person met', 'bi-person-badge'],
        'CND_01' => ['Building & basic facilities', 'bi-building'],
        'CND_08' => ['Electricity & classrooms', 'bi-lightning-charge'],
        'CND_17' => ['Digital learning & labs', 'bi-pc-display'],
        'TSI_01' => ['Students', 'bi-backpack'],
        'TSI_04' => ['Teachers & tutors', 'bi-person-workspace'],
        'TSI_10' => ['Trained / untrained teachers', 'bi-mortarboard'],
        'GOVR_01' => ['Teaching & SMC', 'bi-people'],
        'GOVR_05' => ['Assembly & entitlements', 'bi-book'],
        'GOVR_10' => ['Cleanliness, MDM & health', 'bi-heart-pulse'],
        'GOVR_18' => ['Safety & sports', 'bi-shield-check'],
        'GOVR_20' => ['Records & administration', 'bi-folder2-open'],
    ];
    $groupHead = fn ($code) => isset($groups[$code])
        ? '<div class="q-group"><span class="q-group-icon"><i class="bi '.$groups[$code][1].'"></i></span><span>'.e($groups[$code][0]).'</span></div>'
        : '';
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
        'missing' => $progress['missing'],
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
            <div class="card wizard-card" data-enhance-selects>
                <div class="wizard-head">
                    <span class="step-icon"><i class="bi {{ $meta['icon'] }}"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="wizard-kicker">Step {{ $step }} of {{ $lastStep }}</div>
                        <h2 class="wizard-title">{{ $meta['title'] }}</h2>
                        <p class="wizard-intro">{{ $intro[$step] ?? '' }}</p>
                    </div>
                </div>
                <div class="card-body wizard-body">

                    <div id="step-warnings">
                        @foreach ($warnings as $w)
                            <div class="alert alert-warning text-sm py-2 mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>{{ $w }}</div>
                        @endforeach
                    </div>

                    {{-- ============ STEP 1: info card + condition questions ============ --}}
                    @if ($step === 1)
                        <div class="info-card mt-2 mb-1">
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
                        <div class="row g-0 num-grid">
                            @foreach ($questions as $i => $q)
                                @if ($groupHead($q['code']))<div class="col-12">{!! $groupHead($q['code']) !!}</div>@endif
                                <div class="col-md-6">@include('inspections.partials.question', ['q' => $q, 'n' => $i + 1])</div>
                            @endforeach
                        </div>
                    @else
                        @foreach ($questions as $i => $q)
                            {!! $groupHead($q['code']) !!}
                            @include('inspections.partials.question', ['q' => $q, 'n' => $i + 1])
                        @endforeach
                    @endif

                    {{-- ============ STEP 3: Teachers absentee (repeating) ============ --}}
                    @if ($step === 3)
                        <div class="rs-head">
                            <div class="q-group"><span class="q-group-icon"><i class="bi bi-person-x"></i></span><span>Absent teachers</span></div>
                            <span class="rs-count" data-count="teachers">0</span>
                        </div>
                        <div id="teacher-list" class="rs-list"></div>
                        <form id="teacher-form" class="row-form" data-url="{{ route('inspections.teachers.store', $inspection) }}" novalidate>
                            <div class="row-form-title"><span class="rf-icon"><i class="bi bi-person-plus"></i></span>
                                <span data-form-title data-add="Add an absent teacher" data-edit="Edit absent teacher">Add an absent teacher</span></div>
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label text-sm" for="t_name">Teacher's name <span class="req">*</span></label><input id="t_name" name="teacher_name" class="form-control" maxlength="120" placeholder="Full name" autocomplete="off" required></div>
                                <div class="col-md-6"><label class="form-label text-sm" for="t_type">Teacher's type <span class="req">*</span></label>
                                    <select id="t_type" name="teacher_type" class="form-select" required><option value="">Select</option>@foreach (AT::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-md-6"><label class="form-label text-sm" for="t_reason">Reason for absence <span class="req">*</span></label>
                                    <select id="t_reason" name="reason" class="form-select" required><option value="">Select</option>@foreach (AT::REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-md-6"><label class="form-label text-sm" for="t_remarks">Remarks</label><input id="t_remarks" name="remarks" class="form-control" maxlength="500" placeholder="Optional"></div>
                            </div>
                            <div class="form-error d-none" data-form-error></div>
                            <div class="row-form-actions">
                                <button type="button" class="btn btn-light d-none" data-cancel-edit><i class="bi bi-x-lg"></i>Cancel</button>
                                <button class="btn btn-primary row-form-btn" data-add-label="Add teacher" data-edit-label="Update teacher"><i class="bi bi-plus-lg"></i><span>Add teacher</span></button>
                            </div>
                        </form>
                    @endif

                    {{-- ============ STEP 4: Classroom performance (repeating) ============ --}}
                    @if ($step === 4)
                        <div class="rs-head">
                            <div class="q-group"><span class="q-group-icon"><i class="bi bi-easel"></i></span><span>Classrooms observed</span></div>
                            <span class="rs-count" data-count="classrooms">0</span>
                        </div>
                        <div id="classroom-list" class="rs-list"></div>
                        <form id="classroom-form" class="row-form" data-url="{{ route('inspections.classrooms.store', $inspection) }}" novalidate>
                            <div class="row-form-title"><span class="rf-icon"><i class="bi bi-journal-plus"></i></span>
                                <span data-form-title data-add="Add a classroom observation" data-edit="Edit classroom observation">Add a classroom observation</span></div>
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label text-sm" for="c_name">Name of the teacher <span class="req">*</span></label><input id="c_name" name="teacher_name" class="form-control" maxlength="120" placeholder="Full name" autocomplete="off" required></div>
                                <div class="col-6 col-md-3"><label class="form-label text-sm" for="c_class">Class <span class="req">*</span></label>
                                    <select id="c_class" name="class" class="form-select" required><option value="">Select</option>@foreach (CO::CLASSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-6 col-md-3"><label class="form-label text-sm" for="c_subject">Subject <span class="req">*</span></label><input id="c_subject" name="subject" class="form-control" maxlength="100" placeholder="e.g. Mathematics" required></div>
                                <div class="col-md-6"><label class="form-label text-sm d-block">Use of TLM by teacher <span class="req">*</span></label>
                                    <div class="choice-group seg" data-field="tlm_used"><input type="radio" name="tlm_used" id="tlm_yes" value="yes" required><label for="tlm_yes" class="tone-good">Yes</label>
                                        <input type="radio" name="tlm_used" id="tlm_no" value="no"><label for="tlm_no" class="tone-bad">No</label></div></div>
                                <div class="col-md-6"><label class="form-label text-sm d-block">Participation of students <span class="req">*</span></label>
                                    <div class="choice-group seg" data-field="participation">@foreach (CO::PARTICIPATION as $k => $l)<input type="radio" name="participation" id="part_{{ $k }}" value="{{ $k }}" required><label for="part_{{ $k }}" class="{{ $k === 'satisfactory' ? 'tone-good' : 'tone-bad' }}">{{ $l }}</label>@endforeach</div></div>
                                <div class="col-12"><label class="form-label text-sm" for="c_remarks">Remarks</label><input id="c_remarks" name="remarks" class="form-control" maxlength="500" placeholder="Optional"></div>
                            </div>
                            <div class="form-error d-none" data-form-error></div>
                            <div class="row-form-actions">
                                <button type="button" class="btn btn-light d-none" data-cancel-edit><i class="bi bi-x-lg"></i>Cancel</button>
                                <button class="btn btn-primary row-form-btn" data-add-label="Add classroom record" data-edit-label="Update record"><i class="bi bi-plus-lg"></i><span>Add classroom record</span></button>
                            </div>
                        </form>
                    @endif

                    {{-- ============ STEP 5: Fund received by year ============ --}}
                    @if ($step === 5)
                        <form id="fund-form" class="fund-form" data-url="{{ route('inspections.funds.store', $inspection) }}" novalidate>
                            <div class="fund-step"><span class="fund-step-no">1</span><span>Choose the financial year</span></div>
                            <div class="fy-chips" role="radiogroup" aria-label="Financial year" data-field="financial_year">
                                @foreach ($financialYears as $fy)
                                    <input type="radio" name="financial_year" id="fy_{{ $fy }}" value="{{ $fy }}">
                                    <label for="fy_{{ $fy }}" data-fy="{{ $fy }}"><span>{{ str_replace('FY-', '', $fy) }}</span><i class="bi bi-check-circle-fill" title="Saved"></i></label>
                                @endforeach
                            </div>

                            <div class="fund-step"><span class="fund-step-no">2</span><span>Enter the amounts <small>in lakh · e.g. 1.25 = ₹1,25,000 · leave blank if nil</small></span></div>
                            <div id="fund-empty-hint" class="fund-hint"><i class="bi bi-hand-index-thumb"></i>Select a year above to enter its amounts.</div>
                            <fieldset id="fund-amounts" class="d-none" disabled>
                                <div class="fund-table">
                                    <div class="fund-row fund-row-head" aria-hidden="true"><span>Fund head</span><span>Received</span><span>Expenditure</span><span>Balance</span></div>
                                    @foreach (FP::SOURCES as $src => [$srcLabel, $srcIcon, $srcHeads])
                                        <div class="fund-src src-{{ $src }}"><span class="fund-src-icon"><i class="bi {{ $srcIcon }}"></i></span>{{ $srcLabel }}</div>
                                        @foreach ($srcHeads as $key)
                                            @php [$headLabel, $rc, $ec] = FP::HEADS[$key]; @endphp
                                            <div class="fund-row src-{{ $src }}" data-head="{{ $key }}">
                                                <span class="fund-head">{{ FP::SHORT[$key] }}</span>
                                                @foreach ([[$rc, 'Received'], [$ec, 'Expenditure']] as [$col, $what])
                                                    <div class="fund-cell">
                                                        <label class="fund-cell-lbl" for="f_{{ $col }}">{{ $what }}</label>
                                                        <div class="fund-input"><input id="f_{{ $col }}" name="{{ $col }}" type="number" min="0" max="99999" step="0.01" inputmode="decimal" placeholder="0.00"
                                                            class="form-control" data-label="{{ $headLabel }} {{ strtolower($what) }}"><span>L</span></div>
                                                    </div>
                                                @endforeach
                                                <span class="fund-bal"><span class="fund-cell-lbl">Balance</span><span data-bal>0.00</span></span>
                                            </div>
                                        @endforeach
                                    @endforeach
                                    <div class="fund-row fund-row-total">
                                        <span class="fund-head">Total (lakh)</span>
                                        <span><span class="fund-cell-lbl">Received</span><span data-total="r">0.00</span></span>
                                        <span><span class="fund-cell-lbl">Expenditure</span><span data-total="e">0.00</span></span>
                                        <span><span class="fund-cell-lbl">Balance</span><span data-total="b">0.00</span></span>
                                    </div>
                                </div>
                                <div id="fund-warn" class="fund-warn d-none"><i class="bi bi-exclamation-triangle"></i><span></span></div>
                                <div class="form-error d-none" data-form-error></div>
                                <div class="fund-actions">
                                    <button type="button" class="btn btn-light" data-fund-clear><i class="bi bi-eraser"></i>Clear</button>
                                    <button class="btn btn-primary" id="fund-save"><i class="bi bi-check2"></i><span>Save year</span></button>
                                </div>
                            </fieldset>
                        </form>

                        <div class="rs-head mt-4">
                            <div class="q-group"><span class="q-group-icon"><i class="bi bi-cash-coin"></i></span><span>Saved years</span></div>
                            <span class="rs-count" data-count="funds">0</span>
                        </div>
                        <div id="fund-list" class="rs-list"></div>
                    @endif

                    {{-- ============ STEP 6: suggestions after governance questions ============ --}}
                    @if ($step === 6)
                        <div class="q-group"><span class="q-group-icon"><i class="bi bi-lightbulb"></i></span><span>Suggestions <span class="text-secondary fw-normal text-lowercase">(optional · up to {{ config('inspection.max_suggestions') }})</span></span></div>
                        <div id="suggestions" data-url="{{ route('inspections.suggestions', $inspection) }}">
                            @php $sug = $inspection->remarks->where('type', 'suggestion')->pluck('body')->values(); @endphp
                            @for ($i = 0; $i < config('inspection.max_suggestions'); $i++)
                                <div class="sug-row"><span class="sug-no">{{ $i + 1 }}</span>
                                    <input class="form-control" maxlength="500" value="{{ $sug[$i] ?? '' }}" placeholder="Suggestion {{ $i + 1 }}" data-suggestion aria-label="Suggestion {{ $i + 1 }}"></div>
                            @endfor
                        </div>
                    @endif

                    {{-- ============ STEP 7: Photographs ============ --}}
                    @if ($step === $lastStep)
                        <div class="photo-note"><i class="bi bi-info-circle"></i><span>Photos are resized automatically (JPEG, up to 1600 px, 1 MB) and stamped with UDISE, school, date, time and location. Avoid close-ups of children.</span></div>
                        <div class="photo-slots">
                            @foreach ($mandatory as $cat => $min)
                                <div class="photo-slot" data-req-cat="{{ $cat }}" data-min="{{ $min }}">
                                    <div class="photo-slot-head">
                                        <div class="photo-slot-title"><i class="bi bi-circle me-1"></i><span>{{ $catLabels[$cat] ?? $cat }} <span class="text-danger">*</span><small>Required · at least {{ $min }}</small></span></div>
                                        <div class="photo-slot-actions">
                                            <button type="button" class="btn btn-sm btn-soft-primary" data-camera data-category="{{ $cat }}" aria-label="Take photo"><i class="bi bi-camera"></i><span>Camera</span></button>
                                            <button type="button" class="btn btn-sm btn-light" data-gallery data-category="{{ $cat }}" aria-label="Upload from gallery"><i class="bi bi-images"></i><span>Gallery</span></button>
                                        </div>
                                    </div>
                                    <div class="photo-grid" data-photo-grid data-category="{{ $cat }}"></div>
                                </div>
                            @endforeach
                        </div>

                        <div class="q-group"><span class="q-group-icon"><i class="bi bi-images"></i></span><span>Additional photographs <span class="text-secondary fw-normal text-lowercase">(optional · up to {{ config('inspection.evidence.max_photos') }} in total)</span></span></div>
                        <div class="photo-grid mb-2" data-photo-grid data-extra>
                            <button type="button" class="photo-add" data-camera data-category-picker><i class="bi bi-camera"></i>Camera</button>
                            <button type="button" class="photo-add" data-gallery data-category="other"><i class="bi bi-images"></i>Gallery</button>
                        </div>

                        <div class="q-group"><span class="q-group-icon"><i class="bi bi-paperclip"></i></span><span>Supporting documents <span class="text-secondary fw-normal">(optional · PDF/JPG/PNG · max 5 MB · up to {{ config('inspection.evidence.max_documents') }})</span></span></div>
                        <div class="list-card border rounded-3 mb-2" id="doc-list"></div>
                        <label class="btn btn-soft-primary btn-sm"><i class="bi bi-paperclip"></i>Attach document
                            <input type="file" id="doc-input" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="d-none"></label>
                    @endif

                    <input type="file" id="gallery-input" accept="image/*" class="d-none">
                </div>
            </div>

            <div class="action-bar">
                @if ($step > 1)
                    <a href="{{ route('inspections.step', [$inspection, $step - 1]) }}" class="btn btn-light action-back"><i class="bi bi-arrow-left"></i><span class="d-none d-sm-inline">Back</span></a>
                @endif
                <div class="action-status">
                    @php $left = count($progress['missing'][$step] ?? []); @endphp
                    <span id="step-missing" class="{{ $left ? '' : 'is-done' }}">{{ $left ? $left.' left in this step' : 'Step complete' }}</span>
                </div>
                @if ($step < $lastStep)
                    <a href="{{ route('inspections.step', [$inspection, $step + 1]) }}" class="btn btn-primary action-next" data-next>Next<i class="bi bi-arrow-right"></i></a>
                @else
                    <a href="{{ route('inspections.review', $inspection) }}" class="btn btn-primary action-next" data-next><i class="bi bi-flag"></i>Review</a>
                @endif
            </div>

            @if ($inspection->status->value === 'draft')
                <div class="text-center mt-3">
                    <button class="btn btn-link btn-sm text-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-trash"></i>Cancel this draft</button>
                </div>
            @endif
        </div>
    </div>

    {{-- Unanswered items when leaving a step --}}
    <div class="modal fade" id="missingModal" tabindex="-1" aria-labelledby="missing-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm-wide"><div class="modal-content missing-modal">
            <div class="modal-body">
                <div class="missing-icon"><i class="bi bi-list-check"></i></div>
                <h5 class="modal-title text-center mb-1" id="missing-title"><span id="missing-count">0</span> item(s) still to fill</h5>
                <p class="text-center text-secondary text-sm mb-2">You can continue and come back later. All items are needed before you submit.</p>
                <ul class="missing-list" id="missing-list"></ul>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light flex-fill" data-missing-continue>Continue anyway</button>
                <button type="button" class="btn btn-primary flex-fill" data-missing-show><i class="bi bi-search"></i>Show me</button>
            </div>
        </div></div>
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
