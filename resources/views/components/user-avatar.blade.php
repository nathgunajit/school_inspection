{{-- Employee photo if uploaded, otherwise initials --}}
@props(['user', 'size' => null])
@if ($user->photo_path)
    <img src="{{ route('admin.users.photo', $user) }}" alt="Photo of {{ $user->name }}" loading="lazy"
         {{ $attributes->merge(['class' => 'avatar avatar-img'.($size ? ' avatar-'.$size : '')]) }}>
@else
    <span {{ $attributes->merge(['class' => 'avatar'.($size ? ' avatar-'.$size : '')]) }}>{{ $user->initials() }}</span>
@endif
