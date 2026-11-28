{{-- 28-11-2026: school categories a TLM item is offered for on step 4. Vars: $categories (id => label), $selected (ids), $idp (id prefix), optional $error. --}}
@php $selected = array_map('intval', (array) ($selected ?? [])); @endphp
<fieldset class="tlm-cats">
    <legend class="form-label mb-1">School categories <span class="opt">optional</span></legend>
    <div class="form-text mt-0 mb-2">The item is shown on the inspection form only for schools of the ticked categories. Leave all unticked to show it for every school.</div>
    <div class="tlm-cat-grid">
        @foreach ($categories as $cid => $clabel)
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="category_ids[]" value="{{ $cid }}" id="{{ $idp }}-{{ $cid }}" @checked(in_array((int) $cid, $selected, true))>
                <label class="form-check-label text-sm" for="{{ $idp }}-{{ $cid }}">{{ $clabel }}</label>
            </div>
        @endforeach
    </div>
    @if (! empty($error))<div class="text-danger text-xs mt-1">{{ $error }}</div>@endif
</fieldset>
