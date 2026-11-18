@php
    use App\Questionnaire\QuestionnaireDefinition as QD;
    $s = $inspection->school;
    $photos = $inspection->evidence->where('kind', 'photo');
    $docs = $inspection->evidence->where('kind', 'document');
    $fmtDist = fn ($d) => $d === null ? null : ($d < 1000 ? $d.' m' : round($d / 1000, 2).' km');
@endphp
<x-layouts.app :title="$inspection->inspection_no ?: 'Inspection draft'" :back="url()->previous() !== url()->current() ? url()->previous() : route('inspections.mine')">
    <x-slot:actions>
        <a href="{{ route('inspections.pdf', $inspection) }}" target="_blank" class="btn btn-soft-primary d-none d-sm-inline-flex"><i class="bi bi-file-earmark-pdf"></i>PDF</a>
    </x-slot:actions>

    <div class="card card-elevated mb-4 overflow-hidden">
        <div class="bg-grad text-white p-4">
            <div class="d-flex flex-wrap gap-3 align-items-start justify-content-between">
                <div class="min-w-0">
                    <div class="text-white-50 text-sm font-monospace">{{ $inspection->inspection_no ?? 'Draft' }}</div>
                    <h2 class="h4 text-white mb-1">{{ $s->name }}</h2>
                    <div class="text-white-50 text-sm">UDISE {{ $s->udise_code }} · {{ $s->cluster?->name }}, {{ $s->block?->name }}, {{ $s->district?->name }}</div>
                </div>
                <span class="badge bg-white text-primary fs-6">{{ $inspection->status->label() }}</span>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <dl class="kv mb-0">
                        <dt>Inspecting officer</dt><dd>{{ $inspection->officer->name }}<div class="text-xs text-secondary fw-normal">{{ $inspection->officer->designation?->label }}</div></dd>
                        <dt>Person met</dt><dd>{{ $inspection->person_met_name ?: '—' }}<div class="text-xs text-secondary fw-normal">{{ $inspection->person_met_designation }}</div></dd>
                        <dt>Inspection date</dt><dd>{{ $inspection->inspection_date->format('d M Y') }}</dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="kv mb-0">
                        <dt>Started</dt><dd>{{ $inspection->started_at->format('d M Y, h:i A') }}
                            <div class="text-xs text-secondary fw-normal">@if ($inspection->start_lat){{ number_format($inspection->start_lat, 5) }}, {{ number_format($inspection->start_lng, 5) }} @if ($startDistance !== null)· <strong>{{ $fmtDist($startDistance) }}</strong> from school @endif @else No location @endif</div></dd>
                        <dt>Submitted</dt><dd>{{ $inspection->submitted_at?->format('d M Y, h:i A') ?? '—' }}
                            @if ($inspection->submitted_at)<div class="text-xs text-secondary fw-normal">@if ($inspection->submit_lat){{ number_format($inspection->submit_lat, 5) }}, {{ number_format($inspection->submit_lng, 5) }} @if ($submitDistance !== null)· <strong>{{ $fmtDist($submitDistance) }}</strong> from school @endif @else No location @endif</div>@endif</dd>
                        @if ($inspection->unlock_reason)
                            <dt>Unlocked</dt><dd>{{ $inspection->unlocked_at?->format('d M Y') }} by {{ $inspection->unlocker?->name }}<div class="text-xs text-secondary fw-normal">{{ $inspection->unlock_reason }}</div></dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
        @if ($inspection->officer_id === auth()->id() && $inspection->isEditable())
            <div class="card-footer bg-warning-subtle d-flex align-items-center gap-2 flex-wrap">
                <i class="bi bi-pencil-square"></i><span class="flex-grow-1 text-sm">This inspection is open for editing.</span>
                <a href="{{ route('inspections.step', [$inspection, 1]) }}" class="btn btn-sm btn-primary">Continue editing</a>
            </div>
        @endif
        @can('unlock', $inspection)
            <div class="card-footer bg-white">
                <form method="POST" action="{{ route('inspections.unlock', $inspection) }}" class="row g-2 align-items-center" data-confirm="Unlock this inspection for correction by the officer?">
                    @csrf
                    <div class="col-md"><input name="reason" class="form-control form-control-sm @error('reason') is-invalid @enderror" placeholder="Reason for unlocking (required)" required minlength="5" maxlength="500"></div>
                    <div class="col-md-auto"><button class="btn btn-sm btn-soft-danger w-100"><i class="bi bi-unlock"></i>Unlock for correction</button></div>
                </form>
            </div>
        @endcan
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            @if ($inspection->questionnaire_version < QD::VERSION)
                <div class="alert alert-secondary text-sm"><i class="bi bi-archive me-1"></i>This inspection used <strong>Questionnaire v{{ $inspection->questionnaire_version }} (legacy)</strong>. Answers to questions that no longer exist are kept in the database and in the Excel export.</div>
            @endif
            @foreach ($steps as $n => $st)
                @continue($n === count($steps))
                <div class="card mb-4">
                    <div class="card-header"><i class="bi {{ $st['icon'] }} me-2 text-primary"></i>{{ $st['title'] }}</div>
                    <div class="card-body py-2">
                        @if ($n === 3)
                            @forelse ($inspection->absentTeachers as $t)
                                <div class="answer-row"><div class="a-q">{{ $t->teacher_name }}<div class="text-xs text-secondary">{{ $t->typeLabel() }}@if ($t->remarks) · {{ $t->remarks }}@endif</div></div><div class="a-v">{{ $t->reasonLabel() }}</div></div>
                            @empty
                                <div class="text-sm text-secondary py-2">No absent teachers recorded.</div>
                            @endforelse
                        @endif
                        @if ($n === 4)
                            @forelse ($inspection->classroomObservations as $c)
                                <div class="answer-row"><div class="a-q"><strong>{{ \App\Models\InspectionClassroomObservation::CLASSES[$c->class] ?? $c->class }} · {{ $c->subject }}</strong><div class="text-xs text-secondary">{{ $c->teacher_name }}@if ($c->remarks) · {{ $c->remarks }}@endif</div></div>
                                    <div class="a-v text-sm">TLM: {{ $c->tlm_used ? 'Yes' : 'No' }}<div class="fw-normal text-xs">{{ $c->participationLabel() }}</div></div></div>
                            @empty
                                <div class="text-sm text-secondary py-2">No classroom records.</div>
                            @endforelse
                        @endif
                        @if ($n === 5)
                            <div class="pb-2">@include('inspections.partials.fund-summary', ['funds' => $inspection->fundPositions])</div>
                        @endif
                        @foreach ($questions[$n] as $q)
                            @continue(str_starts_with($q['code'], 'BAS_'))
                            @continue(! \App\Questionnaire\InspectionValidator::visible($q, $answers))
                            <div class="answer-row">
                                <div class="a-q">{{ $q['label'] }}
                                    @if (! empty($remarks[$q['code']]))<div class="text-xs text-danger mt-1"><i class="bi bi-chat-left-text me-1"></i>{{ $remarks[$q['code']] }}</div>@endif
                                </div>
                                <div class="a-v">{{ QD::optionLabel($q['code'], $answers[$q['code']] ?? null) }}</div>
                            </div>
                        @endforeach

                        @if ($n === 2)
                            @php $totS = QD::totalStudents($answers); $totT = QD::totalTeachers($answers); $presT = $answers['TSI_09'] ?? null; @endphp
                            <div class="fig-totals mb-2">
                                <div class="fig-total"><i class="bi bi-backpack"></i><span>Total students <small>Present + Absent</small></span><b>{{ $totS === null ? '—' : number_format($totS) }}</b></div>
                                <div class="fig-total"><i class="bi bi-person-workspace"></i><span>Total teachers <small>Regular + Contractual + SSA + Tutors</small></span><b>{{ $totT === null ? '—' : number_format($totT) }}</b></div>
                                @if ($totT !== null && $presT !== null && $presT !== '')
                                    @php $absT = $totT - (int) $presT; @endphp
                                    <div class="fig-total {{ $absT < 0 ? 'is-bad' : '' }}"><i class="bi bi-person-x"></i><span>Teachers absent <small>Total − Present ({{ (int) $presT }})</small></span><b>{{ $absT < 0 ? '−'.abs($absT) : $absT }}</b></div>
                                @endif
                            </div>
                        @endif

                        @if ($n === 6)
                            @php $sug = $inspection->remarks->where('type', 'suggestion'); @endphp
                            @if ($sug->isNotEmpty())
                                <div class="section-title mt-3">Suggestions</div>
                                <ol class="mb-0 ps-3">@foreach ($sug as $sg)<li class="py-1">{{ $sg->body }}</li>@endforeach</ol>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="col-xl-4">
            <div class="card mb-4" style="position:sticky;top:80px">
                <div class="card-header"><i class="bi bi-images me-2 text-primary"></i>Photographs <span class="badge text-bg-light">{{ $photos->count() }}</span></div>
                <div class="card-body">
                    <div class="photo-grid">
                        @foreach ($photos as $p)
                            <a href="{{ route('evidence.show', $p) }}" target="_blank" rel="noopener" class="photo-tile" title="{{ $p->caption }}">
                                <img src="{{ route('evidence.show', $p) }}" alt="{{ $p->category?->label }}" loading="lazy">
                                @if ($p->source === 'gallery')<span class="badge text-bg-info tile-status">Gallery</span>@endif
                                @unless ($p->hasLocation())<span class="badge text-bg-dark tile-status" @if ($p->source === 'gallery') style="top:28px" @endif>No location</span>@endunless
                                <div class="tile-cap">{{ $p->category?->label }}@if ($p->question_code) · {{ $p->question_code }}@endif</div>
                            </a>
                        @endforeach
                    </div>
                    @if ($photos->isNotEmpty())
                        <div class="mt-3 small text-secondary">
                            @foreach ($photos as $p)
                                <div class="d-flex justify-content-between border-bottom py-1 gap-2">
                                    <span class="text-truncate">{{ $p->category?->label }}</span>
                                    <span class="text-nowrap">{{ $p->taken_at?->format('h:i A') }} · {{ $p->hasLocation() ? (($d = $inspection->distanceFromSchool($p->latitude, $p->longitude)) !== null ? $fmtDist($d) : number_format($p->latitude, 4).','.number_format($p->longitude, 4)) : 'No location' }}</span>
                                    <a href="{{ route('evidence.show', [$p, 'download' => 1]) }}" title="Download original" aria-label="Download"><i class="bi bi-download"></i></a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                @if ($docs->isNotEmpty())
                    <div class="card-body border-top">
                        <div class="section-title">Documents</div>
                        @foreach ($docs as $d)
                            <a href="{{ route('evidence.show', [$d, 'download' => 1]) }}" class="d-flex align-items-center gap-2 py-1 text-sm"><i class="bi bi-paperclip"></i>{{ $d->original_name }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
