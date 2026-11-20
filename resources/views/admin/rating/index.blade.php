@php
    use App\Rating\RatingCalculator; use App\Questionnaire\QuestionnaireDefinition as Q;
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $totalWeight = max(0.0001, (float) $scheme->criteria->sum('weight'));
    $icons = ['condition' => 'bi-building-check', 'strength' => 'bi-people', 'absentee' => 'bi-person-x', 'classroom' => 'bi-easel', 'fund' => 'bi-cash-coin', 'governance' => 'bi-diagram-3'];
    $tones = ['condition' => 'primary', 'strength' => 'success', 'absentee' => 'warning', 'classroom' => 'info', 'fund' => 'success', 'governance' => 'primary'];
    $bands = collect($scheme->bands)->map(fn ($b, $i) => ['min' => old("bands.$i.min", $b['min']), 'label' => old("bands.$i.label", $b['label']), 'color' => old("bands.$i.color", $b['color'])])->values();
@endphp
<x-layouts.app title="Rating Settings">
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.rating.recalculate') }}" data-confirm="Re-rate every submitted inspection with version {{ $scheme->version }}?">@csrf
            <button class="btn btn-soft-primary"><i class="bi bi-arrow-repeat"></i><span class="d-none d-sm-inline">Recalculate all</span></button></form>
    </x-slot:actions>

    <div data-rating-settings>
    {{-- Hero --}}
    <section class="rs-hero mb-4">
        <div class="rs-hero-main">
            <span class="rs-hero-icon"><i class="bi bi-award"></i></span>
            <div class="min-w-0">
                <h2 class="rs-hero-title">Rating scheme <span class="rs-version">Active · v{{ $scheme->version }}</span></h2>
                <p class="rs-hero-sub">Set how much each item counts and how many points each answer earns. Saving creates <b>version {{ $scheme->version + 1 }}</b>; ratings already calculated keep their version until they are recalculated.</p>
            </div>
        </div>
        <div class="rs-stats">
            <div><b>{{ $scheme->criteria->count() }}</b><span>Items</span></div>
            <div><b>{{ $num($scheme->criteria->sum('weight')) }}</b><span>Total weight</span></div>
            <div><b>{{ $rated[$scheme->id] ?? 0 }}</b><span>Rated on v{{ $scheme->version }}</span></div>
        </div>
        <details class="rs-formula">
            <summary><i class="bi bi-calculator"></i>How the score is calculated</summary>
            <div class="rs-formula-eq">Score % = <span>Σ(weight × points)</span> ÷ <span>Σ(weight of applicable items)</span> × 100</div>
            <p class="mb-0">Points run from 0 to 1. Items that are Not Applicable (a hidden or disabled question, an answer of “Not Applicable”, or a metric with nothing to divide by) are left out of the total.</p>
        </details>
    </section>

    @if ($errors->any())
        <div class="alert alert-danger">{!! collect($errors->all())->map(fn ($m) => e($m))->implode('<br>') !!}</div>
    @endif

    <form method="POST" action="{{ route('admin.rating.update') }}" id="rs-form">
        @csrf
        <div class="row g-4">
            <div class="col-xl-8">
                @foreach ($scheme->criteria->groupBy('section') as $section => $items)
                    @php $share = round($items->sum('weight') / $totalWeight * 100); $sid = 'rs-sec-'.$loop->index; @endphp
                    <div class="rs-section mb-3">
                        <button type="button" class="rs-section-head" data-bs-toggle="collapse" data-bs-target="#{{ $sid }}" aria-expanded="true" aria-controls="{{ $sid }}">
                            <span class="rs-section-icon tone-{{ $tones[$section] ?? 'primary' }}"><i class="bi {{ $icons[$section] ?? 'bi-list-check' }}"></i></span>
                            <span class="flex-grow-1 min-w-0 text-start">
                                <span class="rs-section-title">{{ RatingCalculator::SECTION_TITLES[$section] ?? $section }}</span>
                                <span class="rs-share"><span class="rs-share-bar"><span style="width: {{ $share }}%"></span></span>Weight share {{ $share }}%</span>
                            </span>
                            <span class="rs-pill">{{ $items->count() }} {{ \Illuminate\Support\Str::plural('item', $items->count()) }}</span>
                            <i class="bi bi-chevron-down rs-chevron"></i>
                        </button>
                        <div class="collapse show" id="{{ $sid }}">
                            @foreach ($items as $c)
                                <div class="rs-item">
                                    <div class="rs-item-info">
                                        <div class="rs-item-label">{{ $c->label }}</div>
                                        <div class="rs-item-meta"><span class="rs-code">{{ $c->code }}</span><span class="rs-kind {{ $c->kind === 'metric' ? 'is-metric' : '' }}"><i class="bi {{ $c->kind === 'metric' ? 'bi-percent' : 'bi-ui-radios' }}"></i>{{ $c->kind === 'metric' ? 'Calculated ratio' : 'Answer' }}</span></div>
                                    </div>
                                    <label class="rs-weight"><span>Weight</span>
                                        <input type="number" name="criteria[{{ $c->id }}][weight]" value="{{ old("criteria.$c->id.weight", $num($c->weight)) }}" min="0" max="100" step="0.5" class="form-control form-control-sm" aria-label="Weight of {{ $c->code }}"></label>
                                    <div class="rs-points">
                                        @if ($c->kind === 'answer')
                                            @foreach ($c->points as $value => $pts)
                                                @php $p = old("criteria.$c->id.points.$value", $pts); @endphp
                                                <label class="rs-chip" style="--p: {{ (float) $p }}"><span>{{ Q::find($c->code)['options'][$value] ?? $value }}</span>
                                                    <input type="number" name="criteria[{{ $c->id }}][points][{{ $value }}]" value="{{ $p }}" min="0" max="1" step="0.05" class="form-control form-control-sm" data-point></label>
                                            @endforeach
                                        @else
                                            <span class="rs-ratio"><i class="bi bi-info-circle"></i>The ratio itself (e.g. 80 % = 0.8 points)</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="col-xl-4">
                <div class="rs-side">
                    <div class="rs-card mb-3">
                        <div class="rs-card-head"><i class="bi bi-palette"></i>Rating bands</div>
                        <div class="rs-band-preview" data-band-preview aria-hidden="true"></div>
                        <div class="rs-band-scale" aria-hidden="true"><span>0%</span><span>50%</span><span>100%</span></div>
                        <p class="rs-help">A score gets the first band whose “from” it reaches. One band must start at 0.</p>
                        @foreach ($bands as $i => $b)
                            <div class="rs-band" data-band>
                                <span class="rating-swatch rs-band-swatch rating-{{ $b['color'] }}" data-swatch></span>
                                <div class="rs-band-from"><input type="number" name="bands[{{ $i }}][min]" value="{{ $b['min'] }}" min="0" max="100" step="0.01" class="form-control form-control-sm" aria-label="From %" data-band-min><span>%</span></div>
                                <input name="bands[{{ $i }}][label]" value="{{ $b['label'] }}" maxlength="40" class="form-control form-control-sm" aria-label="Label" data-band-label>
                                <select name="bands[{{ $i }}][color]" class="form-select form-select-sm rs-band-color" aria-label="Colour" data-band-color>
                                    @foreach ($colors as $k => $l)<option value="{{ $k }}" @selected($b['color'] === $k)>{{ $l }}</option>@endforeach
                                </select>
                            </div>
                        @endforeach
                        <div class="rs-save">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" role="switch" name="recalculate" value="1" id="recalc">
                                <label class="form-check-label text-sm" for="recalc">Also re-rate all submitted inspections with the new version</label>
                            </div>
                            <button class="btn btn-primary rs-save-btn"><i class="bi bi-save"></i>Save as version {{ $scheme->version + 1 }}<span class="rs-dirty" title="Unsaved changes"></span></button>
                        </div>
                    </div>

                    <div class="rs-card">
                        <div class="rs-card-head"><i class="bi bi-clock-history"></i>Versions</div>
                        <ol class="rs-timeline">
                            @foreach ($versions as $v)
                                <li class="{{ $v->is_active ? 'is-active' : '' }}">
                                    <div class="d-flex justify-content-between gap-2">
                                        <b>Version {{ $v->version }} @if ($v->is_active)<span class="badge-soft badge-soft-success ms-1">Active</span>@endif</b>
                                        <span class="rs-rated">{{ $rated[$v->id] ?? 0 }} rated</span>
                                    </div>
                                    <div class="text-xs text-muted">{{ $v->activated_at?->format('d M Y, h:i A') }} · {{ $v->creator?->name ?? 'default' }}</div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </form>
    </div>
</x-layouts.app>
