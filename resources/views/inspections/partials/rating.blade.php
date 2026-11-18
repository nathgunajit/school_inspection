{{-- Automatic rating (New Module 3): overall score, section bars, strengths, weaknesses, summary --}}
@php
    use App\Rating\RatingCalculator;
    $r = $rating;
    $data = $r ? ['score_percent' => $r->score_percent, 'band' => $r->band, 'sections' => $r->sections] : null;
    $areas = $r ? RatingCalculator::improvementAreas($r->sections) : [];
    $pct = fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
@endphp
<section class="card rp-card mb-4 rating-card" id="rp-rating">
    <div class="rp-card-head"><span class="rp-card-icon tone-orange"><i class="bi bi-star-half"></i></span><h3>School performance rating</h3></div>
    <div class="rp-card-body">
        @if (! $r)
            <p class="text-sm text-muted mb-0">{{ $inspection->questionnaire_version == 2 ? 'The rating is calculated when the inspection is submitted.' : 'Inspections on the old (v1) questionnaire are not rated.' }}</p>
        @elseif (! $r->isRated())
            <p class="text-sm text-muted mb-0">No rated items applied to this inspection.</p>
        @else
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="rating-dial rating-{{ $r->color }}" style="--p: {{ $r->score_percent }}"><span>{{ $pct($r->score_percent) }}<small>%</small></span></div>
                <div>
                    <span class="rating-badge rating-{{ $r->color }}">{{ $r->band }}</span>
                    <div class="text-xs text-muted mt-1">{{ rtrim(rtrim(number_format($r->obtained, 2), '0'), '.') }} of {{ rtrim(rtrim(number_format($r->applicable, 2), '0'), '.') }} weighted points · scheme v{{ $r->scheme?->version }}</div>
                </div>
            </div>

            <div class="rating-sections mb-3">
                @foreach ($r->sections as $key => $s)
                    <div class="rating-row">
                        <div class="d-flex justify-content-between text-sm"><span>{{ $s['title'] }}</span>
                            <b>{{ $s['percent'] === null ? 'N/A' : $pct($s['percent']).' %' }}</b></div>
                        <div class="rating-bar"><span class="rating-{{ $s['color'] ?? 'none' }}" style="width: {{ $s['percent'] ?? 0 }}%"></span></div>
                    </div>
                @endforeach
            </div>

            <p class="text-sm mb-3">{{ RatingCalculator::summary($data, $inspection->school->name) }}</p>

            @if ($r->strengths)
                <div class="fw-semibold text-sm text-success mb-1"><i class="bi bi-hand-thumbs-up me-1"></i>Strengths</div>
                <ul class="rating-list text-sm mb-3">@foreach (array_slice($r->strengths, 0, 8) as $x)<li>{{ $x['label'] }}</li>@endforeach</ul>
            @endif
            @if ($r->weaknesses)
                <div class="fw-semibold text-sm text-danger mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Weaknesses</div>
                <ul class="rating-list text-sm mb-3">@foreach (array_slice($r->weaknesses, 0, 10) as $x)<li>{{ $x['label'] }}</li>@endforeach
                    @if (count($r->weaknesses) > 10)<li class="text-muted">and {{ count($r->weaknesses) - 10 }} more</li>@endif</ul>
            @endif
            @if ($areas)
                <div class="fw-semibold text-sm text-warning-emphasis mb-1"><i class="bi bi-tools me-1"></i>Areas requiring improvement</div>
                <ul class="rating-list text-sm mb-0">@foreach ($areas as $a)<li>{{ $a['title'] }} ({{ $pct($a['percent']) }} %)</li>@endforeach</ul>
            @endif
        @endif
    </div>
</section>
