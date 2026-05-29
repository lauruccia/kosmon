<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — KMoney</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: var(--bg, #edf1f8); font-family: system-ui, sans-serif; color: var(--ink, #0d1c30); margin: 0; }
        .legal-wrap { max-width: 760px; margin: 0 auto; padding: 40px 24px 80px; }
        .legal-back { display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--ink-soft,#4a637d);text-decoration:none;margin-bottom:28px; }
        .legal-back:hover { color:var(--primary,#0f52c4); }
        h1 { font-size: 28px; font-weight: 800; margin: 0 0 6px; }
        .legal-meta { font-size: 13px; color: var(--ink-muted, #7a95aa); margin-bottom: 36px; }
        h2 { font-size: 17px; font-weight: 700; margin: 32px 0 10px; }
        p, li { font-size: 15px; line-height: 1.7; color: var(--ink-soft, #4a637d); }
        ul { padding-left: 20px; }
        a { color: var(--primary, #0f52c4); }
    </style>
</head>
<body>
    <div class="legal-wrap">
        <a class="legal-back" href="{{ auth()->check() ? route('portal.dashboard') : route('login') }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
            Torna
        </a>
        @yield('content')
    </div>
</body>
</html>
