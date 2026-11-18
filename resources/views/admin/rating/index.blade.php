@php use App\Rating\RatingCalculator; use App\Questionnaire\QuestionnaireDefinition as Q; @endphp
<x-layouts.app title="Rating Settings">
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.rating.recalculate') }}" data-confirm="Re-rate every submitted inspection with version {{ $scheme->version }}?">@csrf
            <button class="btn btn-soft-primary"><i class="bi bi-arrow-repeat"></i><span class="d-none d-sm-inline">Recalculate all</span></button></form>
    </x-slot:actions>

    <div class="alert alert-info py-2 text-sm">
        <i class="bi bi-info-circle me-1"></i>
        <b>Score % = Σ(weight × points) ÷ Σ(weight of applicable items) × 100.</b>
        Points run from 0 to 1. Items that are Not Applicable (a hidden or disabled question, an answer of “Not Applicable”, or a metric with nothing to divide by) are left out of the total.
        Saving creates <b>version {{ $scheme->version + 1 }}</b>; ratings already calculated keep their version until they are recalculated.
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">{!! collect($errors->all())->map(fn ($m) => e($m))->implode('<br>') !!}</div>
    @endif

    <form method="POST" action="{{ route('admin.rating.update') }}">
        @csrf
        <div class="row g-3">
            <div class="col-xl-8">
                @foreach ($scheme->criteria->groupBy('section') as $section => $items)
                    <div class="card mb-3">
                        <div class="card-header bg-white d-flex justify-content-between">
                            <span class="fw-semibold">{{ RatingCalculator::SECTION_TITLES[$section] ?? $section }}</span>
                            <span class="text-xs text-muted">{{ $items->count() }} item(s) · total weight {{ rtrim(rtrim(number_format($items->sum('weight'), 2), '0'), '.') }}</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 rating-table">
                                <thead><tr><th>Item</th><th style="width:7rem">Weight</th><th>Points per answer (0 – 1)</th></tr></thead>
                                <tbody>
                                    @foreach ($items as $c)
                                        <tr>
                                            <td><div class="text-sm">{{ $c->label }}</div><div class="text-xs text-muted">{{ $c->code }} · {{ $c->kind === 'metric' ? 'calculated ratio' : 'answer' }}</div></td>
                                            <td><input type="number" name="criteria[{{ $c->id }}][weight]" value="{{ old("criteria.$c->id.weight", rtrim(rtrim(number_format($c->weight, 2, '.', ''), '0'), '.')) }}" min="0" max="100" step="0.5" class="form-control form-control-sm" aria-label="Weight of {{ $c->code }}"></td>
                                            <td>
                                                @if ($c->kind === 'answer')
                                                    <div class="d-flex flex-wrap gap-2">
                                                        @foreach ($c->points as $value => $pts)
                                                            <label class="rating-point"><span>{{ Q::find($c->code)['options'][$value] ?? $value }}</span>
                                                                <input type="number" name="criteria[{{ $c->id }}][points][{{ $value }}]" value="{{ old("criteria.$c->id.points.$value", $pts) }}" min="0" max="1" step="0.05" class="form-control form-control-sm"></label>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <span class="text-xs text-muted">The ratio itself (e.g. 80 % = 0.8 points)</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="col-xl-4">
                <div class="card mb-3 position-sticky" style="top: 5rem">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-palette me-1"></i>Rating bands</div>
                    <div class="card-body">
                        <p class="text-xs text-muted">A score gets the first band whose “from” it reaches. One band must start at 0.</p>
                        @foreach ($scheme->bands as $i => $b)
                            <div class="d-flex gap-2 mb-2 align-items-center">
                                <span class="rating-swatch rating-{{ old("bands.$i.color", $b['color']) }}"></span>
                                <input type="number" name="bands[{{ $i }}][min]" value="{{ old("bands.$i.min", $b['min']) }}" min="0" max="100" step="0.01" class="form-control form-control-sm" style="width:5.5rem" aria-label="From %">
                                <input name="bands[{{ $i }}][label]" value="{{ old("bands.$i.label", $b['label']) }}" maxlength="40" class="form-control form-control-sm" aria-label="Label">
                                <select name="bands[{{ $i }}][color]" class="form-select form-select-sm" style="width:6.5rem" aria-label="Colour">
                                    @foreach ($colors as $k => $l)<option value="{{ $k }}" @selected(old("bands.$i.color", $b['color']) === $k)>{{ $l }}</option>@endforeach
                                </select>
                            </div>
                        @endforeach
                        <div class="form-check mt-3">
                            <input type="checkbox" class="form-check-input" name="recalculate" value="1" id="recalc">
                            <label class="form-check-label text-sm" for="recalc">Also re-rate all submitted inspections with the new version</label>
                        </div>
                        <button class="btn btn-primary w-100 mt-3"><i class="bi bi-save"></i>Save as version {{ $scheme->version + 1 }}</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i>Versions</div>
                    <ul class="list-group list-group-flush">
                        @foreach ($versions as $v)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div><b>Version {{ $v->version }}</b> @if ($v->is_active)<span class="badge-soft badge-soft-success">Active</span>@endif
                                    <div class="text-xs text-muted">{{ $v->activated_at?->format('d M Y, h:i A') }} · {{ $v->creator?->name ?? 'default' }}</div></div>
                                <span class="text-xs text-muted">{{ $rated[$v->id] ?? 0 }} rated</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </form>
</x-layouts.app>
