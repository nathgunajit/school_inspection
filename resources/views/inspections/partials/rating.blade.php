{{-- Automatic rating (New Module 3), redesigned in Modification V7: score hero with the change since the
     previous rating, band scale, section tiles, and strengths / weaknesses / improvement cards. --}}
@php
    use App\Rating\RatingCalculator;
    $r = $rating;
    $prev = $previousRating ?? null;
    $data = $r ? ['score_percent' => $r->score_percent, 'band' => $r->band, 'sections' => $r->sections] : null;
    $areas = $r ? RatingCalculator::improvementAreas($r->sections) : [];
    $pct = fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
    $pts = fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
    // Band scale: lowest band first; each segment spans [min, next min)
    $bands = collect($r?->scheme?->bands ?? [])->sortBy('min')->values();
    $scale = $bands->map(fn ($b, $i) => $b + ['to' => $bands[$i + 1]['min'] ?? 100])->all();
    $change = ($r && $prev && $r->isRated()) ? round($r->score_percent - $prev->score_percent, 1) : null;
    $LIMIT = 5;
@endphp
<section class="card rp-card mb-4 rating-card" id="rp-rating">
    <div class="rp-card-head"><span class="rp-card-icon tone-orange"><i class="bi bi-star-half"></i></span><h3>School performance rating</h3></div>
    <div class="rp-card-body">
        @if (! $r)
            <div class="rt-empty"><i class="bi bi-hourglass-split"></i>{{ $inspection->questionnaire_version == 2 ? 'The rating is calculated when the inspection is submitted.' : 'Inspections on the old (v1) questionnaire are not rated.' }}</div>
        @elseif (! $r->isRated())
            <div class="rt-empty"><i class="bi bi-dash-circle"></i>No rated items applied to this inspection.</div>
        @else
            {{-- Hero: score dial, band, points and change since the previous rating --}}
            <div class="rt-hero rating-{{ $r->color }}">
                <div class="rating-dial rating-{{ $r->color }} rt-dial" style="--p: {{ $r->score_percent }}" role="img" aria-label="Score {{ $pct($r->score_percent) }} percent, {{ $r->band }}">
                    <span>{{ $pct($r->score_percent) }}<small>%</small></span>
                </div>
                <div class="rt-hero-text">
                    <div class="rt-kicker">Overall rating</div>
                    <span class="rating-badge rating-{{ $r->color }}"><i class="bi bi-award-fill me-1"></i>{{ $r->band }}</span>
                    <div class="rt-points">{{ $pts($r->obtained) }} of {{ $pts($r->applicable) }} weighted points</div>
                    @if ($change !== null)
                        <div class="rt-change {{ $change > 0 ? 'is-up' : ($change < 0 ? 'is-down' : 'is-same') }}"
                             title="Previous rating {{ $pct($prev->score_percent) }} % ({{ $prev->band }})@if ($prev->inspection) on {{ $prev->inspection->inspection_date->format('d M Y') }}@endif">
                            <i class="bi {{ $change > 0 ? 'bi-arrow-up-right' : ($change < 0 ? 'bi-arrow-down-right' : 'bi-arrow-right') }}"></i>
                            {{ $change > 0 ? '+' : ($change < 0 ? '−' : '') }}{{ $pct(abs($change)) }} pts
                            <span>since last inspection{{ $prev->inspection ? ' ('.$prev->inspection->inspection_date->format('d M Y').')' : '' }}</span>
                        </div>
                    @else
                        <div class="rt-change is-first"><i class="bi bi-flag"></i>First rated inspection of this school</div>
                    @endif
                </div>
            </div>

            {{-- Band scale with a marker at the score --}}
            @if (count($scale))
                <div class="rt-scale" aria-label="Rating bands: {{ collect($scale)->map(fn ($b) => $b['label'].' from '.$b['min'].' %')->implode(', ') }}">
                    <div class="rt-scale-bar">
                        @foreach ($scale as $b)
                            <span class="rating-swatch rating-{{ $b['color'] }} {{ $b['label'] === $r->band ? 'is-current' : '' }}" style="width: {{ max(0, $b['to'] - $b['min']) }}%" title="{{ $b['label'] }}: {{ $b['min'] }}–{{ $b['to'] }} %"></span>
                        @endforeach
                        <i class="rt-marker" style="left: {{ min(100, max(0, $r->score_percent)) }}%" aria-hidden="true"></i>
                    </div>
                    <div class="rt-scale-labels">
                        @foreach ($scale as $b)
                            <span style="width: {{ max(0, $b['to'] - $b['min']) }}%" class="{{ $b['label'] === $r->band ? 'is-current' : '' }}">{{ $b['min'] }}</span>
                        @endforeach
                    </div>
                    <div class="rt-scale-legend">
                        @foreach (array_reverse($scale) as $b)
                            <span class="{{ $b['label'] === $r->band ? 'is-current' : '' }}"><i class="rating-swatch rating-{{ $b['color'] }}"></i>{{ $b['label'] }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="rt-summary"><i class="bi bi-chat-square-quote"></i>{{ RatingCalculator::summary($data, $inspection->school->name) }}</p>

            {{-- Section tiles --}}
            <div class="rt-head">Section scores</div>
            <div class="rt-sections">
                @foreach ($r->sections as $key => $s)
                    <div class="rt-section {{ $s['percent'] === null ? 'is-na' : '' }}" title="{{ $s['title'] }}: {{ $s['percent'] === null ? 'not applicable' : $pct($s['percent']).' % · '.($s['band'] ?? '') }}">
                        <div class="rt-section-top">
                            <span class="rt-section-title">{{ $s['title'] }}</span>
                            <b>{{ $s['percent'] === null ? 'N/A' : $pct($s['percent']).' %' }}</b>
                        </div>
                        <div class="rating-bar"><span class="rating-{{ $s['color'] ?? 'none' }}" style="width: {{ $s['percent'] ?? 0 }}%"></span></div>
                        @if ($s['percent'] !== null && ! empty($s['band']))<div class="rt-section-band">{{ $s['band'] }}</div>@endif
                    </div>
                @endforeach
            </div>

            {{-- Strengths, weaknesses and improvement areas --}}
            @php
                $groups = array_filter([
                    ['key' => 'str', 'title' => 'Strengths', 'icon' => 'bi-hand-thumbs-up-fill', 'item' => 'bi-check-circle-fill', 'tone' => 'good', 'items' => array_column($r->strengths ?? [], 'label')],
                    ['key' => 'weak', 'title' => 'Weaknesses', 'icon' => 'bi-exclamation-triangle-fill', 'item' => 'bi-x-circle-fill', 'tone' => 'bad', 'items' => array_column($r->weaknesses ?? [], 'label')],
                    ['key' => 'imp', 'title' => 'Areas requiring improvement', 'icon' => 'bi-tools', 'item' => 'bi-arrow-up-circle-fill', 'tone' => 'warn', 'items' => array_map(fn ($a) => $a['title'].' ('.$pct($a['percent']).' %)', $areas)],
                ], fn ($g) => count($g['items']));
            @endphp
            @foreach ($groups as $g)
                <div class="rt-group rt-{{ $g['tone'] }}">
                    <div class="rt-group-head"><i class="bi {{ $g['icon'] }}"></i>{{ $g['title'] }}<span class="rt-count">{{ count($g['items']) }}</span></div>
                    <ul class="rt-items">
                        @foreach ($g['items'] as $i => $label)
                            <li class="{{ $i >= $LIMIT ? 'collapse rt-more-'.$g['key'] : '' }}"><i class="bi {{ $g['item'] }}"></i><span>{{ $label }}</span></li>
                        @endforeach
                    </ul>
                    @if (count($g['items']) > $LIMIT)
                        <button type="button" class="rt-toggle" data-bs-toggle="collapse" data-bs-target="#rp-rating .rt-more-{{ $g['key'] }}" aria-expanded="false"
                                onclick="this.querySelector('span').textContent = this.getAttribute('aria-expanded') === 'false' ? 'Show fewer' : 'Show all {{ count($g['items']) }}'">
                            <span>Show all {{ count($g['items']) }}</span><i class="bi bi-chevron-down"></i>
                        </button>
                    @endif
                </div>
            @endforeach

            <div class="rt-foot">Calculated automatically from the questionnaire · scheme v{{ $r->scheme?->version }}</div>
        @endif
    </div>
</section>
