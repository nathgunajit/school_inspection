{{-- 25-11-2026, one user per browser (session-guard.js): who this page belongs to; a guest page says so --}}
@if ($pageUser = auth()->user())
    <meta name="si-user" content="{{ \App\Support\PageUser::token($pageUser) }}">
    {{-- base64, so the officer's own name is never page text (own-name rule, 25-11-2026) --}}
    <meta name="si-user-name" content="{{ base64_encode($pageUser->name) }}">
@else
    <meta name="si-guest" content="1">
@endif
<meta name="si-login-url" content="{{ route('login') }}">
