{{-- Paper form: tick boxes with their labels as table cells (DomPDF keeps a cell box and its label on one line; inline spans drift).
     Vars: $opts (labels), optional $vertical (one option per line). Short lists (Yes / No) sit side by side. --}}
@php
    $opts = array_values((array) $opts);
    // side by side only when they surely fit the answer column (Yes / No); longer lists one per line
    $vertical ??= count($opts) > 3 || mb_strlen(implode('', $opts)) > 14;
@endphp
<table class="chk">
    @if ($vertical)
        @foreach ($opts as $label)<tr><td class="bxc"></td><td class="ol">{{ $label }}</td></tr>@endforeach
    @else
        <tr>@foreach ($opts as $label)<td class="bxc"></td><td class="ol">{{ $label }}</td>@endforeach<td></td></tr>
    @endif
</table>
