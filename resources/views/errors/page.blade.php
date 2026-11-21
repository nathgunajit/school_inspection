{{-- V12 item 8: friendly error pages (no dead-end "Not Found"). Self-contained: no Vite build or database needed. --}}
@php
    $signedIn = rescue(fn () => auth()->check(), false, false);
    $home = $signedIn ? rescue(fn () => route('dashboard'), url('/'), false) : rescue(fn () => route('login'), url('/'), false);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:1.25rem;font:400 15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1e293b;background:linear-gradient(160deg,#eef4fc 0%,#f8fafc 55%,#eefaf2 100%)}
        .box{width:100%;max-width:460px;background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;box-shadow:0 24px 48px -28px rgba(15,23,42,.35);padding:2rem 1.75rem;text-align:center}
        .logos{display:flex;justify-content:center;gap:.75rem;margin-bottom:1.1rem}.logos img{height:44px}
        .code{display:inline-block;padding:.2rem .7rem;border-radius:999px;background:{{ $tint }}1a;color:{{ $tint }};font-weight:600;font-size:.78rem;letter-spacing:.04em}
        h1{font-size:1.35rem;margin:.7rem 0 .4rem}p{margin:0 0 1.4rem;color:#475569}
        .actions{display:flex;flex-wrap:wrap;gap:.6rem;justify-content:center}
        a.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:.55rem 1.2rem;border-radius:.7rem;font-weight:600;text-decoration:none;font-size:.9rem}
        .btn-primary{background:#1565c0;color:#fff}.btn-primary:hover{background:#0d47a1}.btn-light{background:#f1f5f9;color:#334155}.btn-light:hover{background:#e2e8f0}
    </style>
</head>
<body>
    <main class="box" role="main">
        <div class="logos">
            <img src="{{ asset('logo/ASSAM GOVT LOGO.png') }}" alt="">
            <img src="{{ asset('logo/SSA LOGO.png') }}" alt="">
        </div>
        <span class="code">{{ $code }}</span>
        <h1>{{ $title }}</h1>
        <p>{{ $text }}</p>
        <div class="actions">
            <a class="btn btn-primary" href="{{ $home }}">{{ $signedIn ? 'Go to Dashboard' : 'Sign in' }}</a>
            <a class="btn btn-light" href="javascript:history.back()">Go back</a>
        </div>
    </main>
</body>
</html>
