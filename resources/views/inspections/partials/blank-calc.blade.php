{{-- Paper form: one calculated box from QuestionnaireDefinition::CALC_BOXES ($cb), as rows of the section table
     (columns No. / Question / Answer), so its number box lines up with the question boxes. Shaded, to add up and write. --}}
@if (! empty($cb['cells']))
    <tr class="calc-cells"><td colspan="3">
        <table class="cells">
            <tr><td class="ch" colspan="{{ count($cb['cells']) }}">{{ $cb['label'] }} · {{ $cb['formula'] }}</td></tr>
            <tr>@foreach ($cb['cells'] as [$label, $formula])<td class="cc"><div class="cl">{{ $label }}</div><div class="cf">{{ $formula }}</div>
                <table class="nb"><tr><td class="nbx"></td></tr></table></td>@endforeach</tr>
        </table>
    </td></tr>
@else
    <tr class="calc {{ ! empty($cb['highlight']) ? 'hi' : '' }}">
        <td colspan="2"><div class="cl">{{ $cb['label'] }}</div><div class="cf">= {{ $cb['formula'] }}</div></td>
        <td class="a"><table class="nb"><tr><td class="nbx"></td></tr></table></td>
    </tr>
@endif
