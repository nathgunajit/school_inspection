@php
    use App\Questionnaire\QuestionnaireDefinition as QD;
    use App\Models\InspectionFundPosition as FP;
    $s = $inspection->school;
    $photos = $inspection->evidence->where('kind', 'photo')->values();
    $docs = $inspection->evidence->where('kind', 'document');
    $suggestions = $inspection->remarks->where('type', 'suggestion')->pluck('body')->filter()->values();
    $fmtDist = fn ($d) => $d === null ? null : ($d < 1000 ? $d.' m' : round($d / 1000, 2).' km');
    $mandatory = config('inspection.mandatory_photos');
    $catCounts = $photos->groupBy(fn ($p) => $p->category?->code)->map->count();
    $requiredDone = collect($mandatory)->filter(fn ($min, $cat) => ($catCounts[$cat] ?? 0) >= $min)->count();
    $totS = QD::totalStudents($answers);
    $totT = QD::totalTeachers($answers);
    $presT = $answers['TSI_09'] ?? null;
    $funds = $inspection->fundPositions;
    $fundBal = $funds->sum(fn ($f) => $f->balance());
    $statusTone = match ($inspection->status->value) { 'submitted' => 'ok', 'unlocked' => 'info', 'draft' => 'warn', default => 'off' };
    $counts = [
        3 => $inspection->absentTeachers->count().' absent',
        4 => $inspection->classroomObservations->count().' observed',
        5 => $funds->count().' year(s)',
        6 => $suggestions->count().' suggestion(s)',
        7 => $photos->count().' photo(s)',
    ];
    $showSteps = collect($steps)->filter(fn ($st, $n) => $n !== count($steps) || $photos->isNotEmpty());
@endphp
<x-layouts.app :title="$inspection->inspection_no ?: 'Inspection draft'" :back="url()->previous() !== url()->current() ? url()->previous() : route('inspections.mine')">
    <x-slot:actions>
        <a href="{{ route('inspections.pdf', $inspection) }}" target="_blank" class="btn btn-soft-primary d-none d-sm-inline-flex"><i class="bi bi-file-earmark-pdf"></i>PDF</a>
    </x-slot:actions>

    {{-- ============ Report hero (Modification V4 item 1) ============ --}}
    <section class="rp-hero">
        <div class="rp-hero-top">
            <span class="rp-status {{ $statusTone }}"><i class="bi {{ $statusTone === 'ok' ? 'bi-patch-check-fill' : 'bi-pencil-square' }}"></i>{{ $inspection->status->label() }}</span>
            <span class="rp-no">{{ $inspection->inspection_no ?? 'Draft · not submitted' }}</span>
        </div>
        <h2 class="rp-school">{{ $s->name }}</h2>
        <div class="rp-place"><span class="font-monospace">UDISE {{ $s->udise_code }}</span><span><i class="bi bi-geo-alt"></i>{{ collect([$s->cluster?->name, $s->block?->name, $s->district?->name])->filter()->implode(', ') }}</span></div>
        <div class="rp-tags">
            @if ($s->category)<span>{{ $s->category->label }}</span>@endif
            @if ($s->management)<span>{{ $s->management->label }}</span>@endif
            @if ($inspection->questionnaire_version < QD::VERSION)<span>Questionnaire v{{ $inspection->questionnaire_version }} (legacy)</span>@endif
        </div>
        <div class="rp-stats">
            <div><i class="bi bi-calendar-event"></i><span>Inspection date</span><b>{{ $inspection->inspection_date->format('d M Y') }}</b></div>
            <div><i class="bi bi-person-badge"></i><span>Inspected by</span><b class="text-truncate">{{ $inspection->officer->name }}</b></div>
            <div><i class="bi bi-camera"></i><span>Photographs</span><b>{{ $photos->count() }} <small>· {{ $requiredDone }}/{{ count($mandatory) }} required</small></b></div>
            <div><i class="bi bi-send-check"></i><span>Submitted</span><b>{{ $inspection->submitted_at?->format('d M Y, h:i A') ?? 'Not yet' }}</b></div>
        </div>
        <div class="rp-actions">
            <a href="{{ route('inspections.pdf', $inspection) }}" target="_blank" class="btn btn-light"><i class="bi bi-file-earmark-pdf"></i>Download PDF</a>
            <button type="button" class="btn btn-light d-none d-md-inline-flex" onclick="window.print()"><i class="bi bi-printer"></i>Print</button>
            @if ($inspection->officer_id === auth()->id() && $inspection->isEditable())
                <a href="{{ route('inspections.step', [$inspection, 1]) }}" class="btn btn-warning"><i class="bi bi-pencil-square"></i>Continue editing</a>
            @endif
        </div>
    </section>

    @if ($inspection->unlock_reason)
        <div class="rp-notice"><i class="bi bi-unlock"></i><div><strong>Unlocked for correction</strong> on {{ $inspection->unlocked_at?->format('d M Y') }} by {{ $inspection->unlocker?->name }}<div class="text-sm">{{ $inspection->unlock_reason }}</div></div></div>
    @endif
    @can('unlock', $inspection)
        <form method="POST" action="{{ route('inspections.unlock', $inspection) }}" class="rp-unlock" data-confirm="Unlock this inspection for correction by the officer?">
            @csrf
            <i class="bi bi-shield-lock"></i>
            <input name="reason" class="form-control @error('reason') is-invalid @enderror" placeholder="Reason for unlocking (required)" required minlength="5" maxlength="500" aria-label="Reason for unlocking">
            <button class="btn btn-soft-danger"><i class="bi bi-unlock"></i>Unlock for correction</button>
        </form>
    @endcan

    <div class="row g-4">
        <div class="col-xl-8 min-w-0">
            {{-- Details --}}
            <section class="card rp-card mb-4">
                <div class="rp-card-head"><span class="rp-card-icon"><i class="bi bi-info-circle"></i></span><h3>Inspection details</h3></div>
                <div class="rp-details">
                    <div><span>Inspecting officer</span><b>{{ $inspection->officer->name }}</b><small>{{ $inspection->officer->designation_label ?? $inspection->officer->level->label() }}</small></div>
                    <div><span>Person met at the school</span><b>{{ $inspection->person_met_name ?: '—' }}</b><small>{{ $inspection->person_met_designation }}</small></div>
                    <div><span>Started</span><b>{{ $inspection->started_at->format('d M Y, h:i A') }}</b>
                        <small>@if ($inspection->start_lat){{ number_format($inspection->start_lat, 5) }}, {{ number_format($inspection->start_lng, 5) }}@if ($startDistance !== null) <em class="rp-dist {{ $startDistance > 500 ? 'far' : '' }}">{{ $fmtDist($startDistance) }} from school</em>@endif @else No location @endif</small></div>
                    <div><span>Submitted</span><b>{{ $inspection->submitted_at?->format('d M Y, h:i A') ?? '—' }}</b>
                        <small>@if ($inspection->submitted_at)@if ($inspection->submit_lat){{ number_format($inspection->submit_lat, 5) }}, {{ number_format($inspection->submit_lng, 5) }}@if ($submitDistance !== null) <em class="rp-dist {{ $submitDistance > 500 ? 'far' : '' }}">{{ $fmtDist($submitDistance) }} from school</em>@endif @else No location @endif @endif</small></div>
                </div>
            </section>

            {{-- Jump to a section --}}
            <nav class="rp-nav" aria-label="Report sections">
                @foreach ($showSteps as $n => $st)
                    <a href="#rp-step-{{ $n }}"><span>{{ $n }}</span>{{ $st['tab'] ?? $st['short'] }}</a>
                @endforeach
            </nav>

            @foreach ($showSteps as $n => $st)
                <section class="card rp-card mb-4" id="rp-step-{{ $n }}">
                    <div class="rp-card-head">
                        <span class="rp-card-icon"><i class="bi {{ $st['icon'] }}"></i></span>
                        <h3><small>Step {{ $n }}</small>{{ $st['title'] }}</h3>
                        @isset($counts[$n])<span class="rp-count">{{ $counts[$n] }}</span>@endisset
                    </div>
                    <div class="rp-card-body">
                        @include('inspections.partials.step-answers', ['skipBasic' => true, 'remarks' => $remarks, 'downloads' => true])
                    </div>
                </section>
            @endforeach
        </div>

        {{-- ============ Summary aside ============ --}}
        <div class="col-xl-4">
            <aside class="rp-aside">
                @if ($inspection->status === \App\Enums\InspectionStatus::Submitted || $rating)
                    @include('inspections.partials.rating')
                @endif
                <section class="card rp-card mb-4">
                    <div class="rp-card-head"><span class="rp-card-icon tone-green"><i class="bi bi-clipboard-data"></i></span><h3>At a glance</h3></div>
                    <div class="rp-glance">
                        <div><span>Total students</span><b>{{ $totS === null ? '—' : number_format($totS) }}</b><small>Present {{ $answers['TSI_01'] ?? '—' }} · Absent {{ $answers['TSI_02'] ?? '—' }}</small></div>
                        <div><span>Total teachers</span><b>{{ $totT === null ? '—' : number_format($totT) }}</b><small>Present {{ $presT ?? '—' }}@if ($totT !== null && $presT !== null && $presT !== '') · Absent {{ $totT - (int) $presT }}@endif</small></div>
                        <div><span>Absent teachers recorded</span><b>{{ $inspection->absentTeachers->count() }}</b></div>
                        <div><span>Classrooms observed</span><b>{{ $inspection->classroomObservations->count() }}</b></div>
                        <div class="wide"><span>Fund balance ({{ $funds->count() }} year(s))</span><b class="{{ $fundBal < 0 ? 'text-danger' : 'text-success' }}">₹ {{ FP::lakh($fundBal) }} L</b></div>
                        <div class="wide"><span>Required photographs</span>
                            <div class="rp-req">
                                @foreach ($mandatory as $cat => $min)
                                    @php $ok = ($catCounts[$cat] ?? 0) >= $min; @endphp
                                    <span class="{{ $ok ? 'ok' : 'bad' }}"><i class="bi {{ $ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }}"></i>{{ $photoCategories->firstWhere('code', $cat)?->label ?? $cat }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>
                @if ($docs->isNotEmpty())
                    <section class="card rp-card mb-4">
                        <div class="rp-card-head"><span class="rp-card-icon tone-orange"><i class="bi bi-paperclip"></i></span><h3>Documents</h3><span class="rp-count">{{ $docs->count() }}</span></div>
                        <div class="rp-docs">
                            @foreach ($docs as $d)
                                <a href="{{ route('evidence.show', [$d, 'download' => 1]) }}"><i class="bi bi-file-earmark-arrow-down"></i><span class="text-truncate">{{ $d->original_name }}</span></a>
                            @endforeach
                        </div>
                    </section>
                @endif
                <a href="{{ route('inspections.pdf', $inspection) }}" target="_blank" class="btn btn-primary w-100 rp-pdf-btn"><i class="bi bi-file-earmark-pdf"></i>Download official PDF report</a>
            </aside>
        </div>
    </div>

    @include('inspections.partials.photo-viewer')

    @push('scripts')
        @vite('resources/js/review.js')
    @endpush
</x-layouts.app>
