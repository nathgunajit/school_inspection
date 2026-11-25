{{-- Employee photo if uploaded; otherwise the male / female default image (Modification V13), else initials --}}
@props(['user', 'size' => null])
@if ($user->photo_path)
    <img src="{{ route('admin.users.photo', ['user' => $user, 'v' => substr(md5($user->photo_path), 0, 8)]) }}" alt="Photo of {{ $user->name }}" loading="lazy" decoding="async"
         {{ $attributes->merge(['class' => 'avatar avatar-img'.($size ? ' avatar-'.$size : '')]) }}>
@elseif ($default = $user->defaultPhotoUrl())
    <img src="{{ $default }}" alt="{{ $user->name }} (no photo uploaded)" loading="lazy" decoding="async"
         {{ $attributes->merge(['class' => 'avatar avatar-img avatar-default'.($size ? ' avatar-'.$size : '')]) }}>
@else
    <span {{ $attributes->merge(['class' => 'avatar'.($size ? ' avatar-'.$size : '')]) }}>{{ $user->initials() }}</span>
@endif
