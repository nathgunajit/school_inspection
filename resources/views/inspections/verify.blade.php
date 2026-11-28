{{-- 28-11-2026: public, view-only page opened by the QR code on the inspection PDF (InspectionVerifyController).
     No sign-in, no actions, no photographs, no file links. $inspection null = unknown code. --}}
@php
    use App\Questionnaire\QuestionnaireDefinition as QD;
    use App\Questionnaire\InspectionValidator as V;
@endphp
<x-layouts.portal :title="$inspection ? 'Inspection report · '.($inspection->inspection_no ?? 'Draft') : 'QR code not valid'">
    <div class="verify-page mx-auto">
        @if (! $inspection)
            <section class="card verify-card text-center p-4">
                <i class="bi bi-qr-code-scan fs-1 text-danger"></i>
                <h1 class="h5 mt-2">This QR code is not valid</h1>
                <p class="text-secondary mb-0">No inspection report matches this code. Check that the full code was scanned, or ask the office for the report.</p>
            </section>
        @else
            @php
                $s = $inspection->school;
                $tone = match ($inspection->status->value) { 'submitted' => 'ok', 'unlocked' => 'info', 'draft' => 'warn', default => 'off' };
            @endphp
            <section class="card verify-card mb-3">
                <div class="verify-head">
                    <span class="verify-badge {{ $cancelled ? 'is-bad' : 'is-ok' }}"><i class="bi {{ $cancelled ? 'bi-x-octagon-fill' : 'bi-patch-check-fill' }}"></i>{{ $cancelled ? 'Cancelled inspection' : 'Inspection record found' }}</span>
                    <span class="rp-status {{ $tone }}">{{ $inspection->status->label() }}</span>
                </div>
                <h1 class="verify-school">{{ $s?->name }}</h1>
                <div class="verify-meta">
                    <span><i class="bi bi-upc-scan"></i>UDISE <b class="font-monospace">{{ $s?->udise_code }}</b></span>
                    <span><i class="bi bi-geo-alt"></i>{{ collect([$s?->cluster?->label, $s?->block?->label, $s?->district?->label])->filter()->implode(', ') }}</span>
                </div>
                <dl class="verify-facts">
                    <div><dt>Inspection ID</dt><dd class="font-monospace">{{ $inspection->inspection_no ?? 'Draft · not submitted' }}</dd></div>
                    <div><dt>Inspection date</dt><dd>{{ $inspection->inspection_date->format('d M Y') }}</dd></div>
                    @unless ($cancelled)
                        <div><dt>Inspecting officer</dt><dd>{{ $inspection->officer?->name }}<small>{{ $inspection->officer?->designation_label ?? $inspection->officer?->level?->label() }}</small></dd></div>
                        <div><dt>Submitted</dt><dd>{{ $inspection->submitted_at?->format('d M Y, h:i A') ?? 'Not yet' }}</dd></div>
                    @endunless
                </dl>
                @if ($inspection->status->value === 'draft')
                    <div class="verify-note is-warn"><i class="bi bi-exclamation-triangle"></i>This inspection is a <b>draft</b>: it was not submitted yet, so the details below may still change.</div>
                @elseif ($inspection->status->value === 'unlocked')
                    <div class="verify-note is-warn"><i class="bi bi-unlock"></i>This inspection was <b>unlocked for correction</b>; the details below may change until it is submitted again.</div>
                @elseif ($cancelled)
                    <div class="verify-note is-bad"><i class="bi bi-x-octagon"></i>This inspection was <b>cancelled</b>. Its details are not shown.</div>
                @endif
            </section>

            @unless ($cancelled)
                @foreach ($steps as $n => $st)
                    @php
                        $last = $n === count($steps);
                        // the last step (photographs) shows only its questions (the no-internet declaration), never the photos
                        $shown = $last ? collect($questions[$n] ?? [])->filter(fn ($q) => V::visible($q, $answers))->isNotEmpty() : true;
                    @endphp
                    @continue(! $shown)
                    <section class="card rp-card rp-step mb-3">
                        <div class="rp-card-head">
                            <span class="rp-card-icon"><i class="bi {{ $last ? 'bi-pen' : $st['icon'] }}"></i></span>
                            <h3><small>{{ $last ? 'Declaration' : 'Step '.$n }}</small>{{ $last ? 'No internet at the school' : $st['title'] }}</h3>
                        </div>
                        <div class="rp-card-body">
                            @include('inspections.partials.step-answers', ['skipBasic' => true, 'remarks' => $remarks, 'downloads' => false, 'public' => true])
                        </div>
                    </section>
                @endforeach
            @endunless

            <p class="text-xs text-secondary text-center mt-3 mb-0"><i class="bi bi-shield-check me-1"></i>View-only copy from the School Inspection system of Samagra Shiksha Axom · shown {{ now()->format('d M Y, h:i A') }}. Photographs are not shown.</p>
        @endif
    </div>
</x-layouts.portal>
