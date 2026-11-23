{{-- Paper form: one calculated box from QuestionnaireDefinition::CALC_BOXES ($cb). Shaded, to add up and write. --}}
<div class="gap"></div>
@if (! empty($cb['cells']))
    <table class="cells keep">
        <tr class="cells-head"><td colspan="{{ count($cb['cells']) }}">{{ $cb['label'] }} · {{ $cb['formula'] }}</td></tr>
        <tr>@foreach ($cb['cells'] as [$label, $formula])<td><div class="cl">{{ $label }}</div><div class="cf">{{ $formula }}</div><span class="num"></span></td>@endforeach</tr>
    </table>
@else
    <table class="calc keep {{ ! empty($cb['highlight']) ? 'hi' : '' }}"><tr>
        <td><div class="cl">{{ $cb['label'] }}</div><div class="cf">= {{ $cb['formula'] }}</div></td>
        <td class="a"><span class="num"></span></td>
    </tr></table>
@endif
<div class="gap"></div>
