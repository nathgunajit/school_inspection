{{-- New Module 3: compact score + band pill. Shows "—" when the inspection has no rating. --}}
@props(['rating' => null])
@if ($rating && $rating->score_percent !== null)
    <span {{ $attributes->merge(['class' => 'rating-pill rating-'.$rating->color]) }} title="{{ $rating->band }}">{{ rtrim(rtrim(number_format($rating->score_percent, 1), '0'), '.') }}% · {{ $rating->band }}</span>
@else
    <span {{ $attributes->merge(['class' => 'text-muted text-xs']) }}>—</span>
@endif
