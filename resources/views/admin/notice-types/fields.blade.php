{{-- 25-11-2026: name, icon, colour and order of a notice type (Add card and Edit window) --}}
<div class="row g-3">
    <div class="col-12"><label class="form-label" for="nt-name-{{ $idp }}">Name</label>
        <input id="nt-name-{{ $idp }}" name="name" value="{{ $val('name', $type?->name) }}" maxlength="60" class="form-control {{ $err->has('name') ? 'is-invalid' : '' }}" placeholder="e.g. Circular" required>
        @if ($err->has('name'))<div class="invalid-feedback">{{ $err->first('name') }}</div>@endif</div>
    <div class="col-12"><label class="form-label" for="nt-icon-{{ $idp }}">Icon</label>
        <select id="nt-icon-{{ $idp }}" name="icon" class="form-select {{ $err->has('icon') ? 'is-invalid' : '' }}" required>
            @foreach (\App\Models\NoticeType::ICONS as $k => $l)<option value="{{ $k }}" @selected($val('icon', $type?->icon ?? 'bi-megaphone') === $k)>{{ $l }}</option>@endforeach
        </select></div>
    <div class="col-6"><label class="form-label" for="nt-color-{{ $idp }}">Colour</label>
        <input id="nt-color-{{ $idp }}" type="color" name="color" value="{{ $val('color', $type?->color ?? '#1565c0') }}" class="form-control form-control-color w-100 {{ $err->has('color') ? 'is-invalid' : '' }}" required></div>
    <div class="col-6"><label class="form-label" for="nt-sort-{{ $idp }}">Order</label>
        <input id="nt-sort-{{ $idp }}" type="number" name="sort_order" min="0" max="9999" value="{{ $val('sort_order', $type?->sort_order ?? 100) }}" class="form-control"></div>
</div>
