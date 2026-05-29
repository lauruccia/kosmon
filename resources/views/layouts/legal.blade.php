<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'Informativa legale' }} — KMoney</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; line-height: 1.7; }
        .topnav { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; }
        .topnav-brand { font-weight: 800; font-size: 1.2rem; color: #0f766e; text-decoration: none; }
        .topnav-links a { color: #475569; text-decoration: none; font-size: 14px; margin-left: 20px; }
        .topnav-links a:hover { color: #0f766e; }
        .legal-hero { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 32px 0 24px; }
        .container { max-width: 760px; margin: 0 auto; padding: 0 24px; }
        .breadcrumb { font-size: 13px; color: #94a3b8; margin-bottom: 10px; }
        .breadcrumb a { color: #0f766e; text-decoration: none; }
        .content { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 40px 48px; margin: 32px 0 60px; }
        h1 { font-size: 1.7rem; font-weight: 800; margin: 0 0 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 32px; margin-top: 4px; }
        h2 { font-size: 1.1rem; font-weight: 700; margin: 28px 0 10px; color: #0f766e; }
        p { margin: 0 0 14px; font-size: 14.5px; }
        ul, ol { padding-left: 22px; margin: 0 0 14px; font-size: 14.5px; }
        li { margin-bottom: 6px; }
        a { color: #0f766e; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px; }
        th { text-align: left; padding: 10px 14px; font-size: 12.5px; background: #f1f5f9; }
        td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; }
        .legal-nav { display: flex; gap: 12px; flex-wrap: wrap; padding: 20px 0; border-top: 1px solid #e2e8f0; margin-top: 8px; }
        .legal-nav a { font-size: 13px; color: #0f766e; text-decoration: none; padding: 6px 12px; border-radius: 20px; border: 1px solid #0f766e; }
        .legal-nav a.active, .legal-nav a:hover { background: #0f766e; color: #fff; }
        @media(max-width:600px) { .content { padding: 24px 20px; } }
    </style>
</head>
<body>

<nav class="topnav">
    <a href="{{ route('home') }}" class="topnav-brand">KMoney</a>
    <div class="topnav-links">
        <a href="{{ route('help.index') }}">Assistenza</a>
        <a href="{{ route('login') }}">Accedi</a>
    </div>
</nav>

<div class="legal-hero">
    <div class="container">
        <div class="breadcrumb"><a href="{{ route('home') }}">Home</a> / <a href="{{ route('help.index') }}">Assistenza</a> / {{ $pageTitle ?? 'Legale' }}</div>
        <div class="legal-nav">
            <a href="{{ route('legal.contract') }}" {{ request()->routeIs('legal.contract') ? 'class=active' : '' }}>Contratto di Adesione</a>
            <a href="{{ route('legal.aml-kyc') }}"  {{ request()->routeIs('legal.aml-kyc')  ? 'class=active' : '' }}>Politica AML/KYC</a>
            <a href="{{ route('legal.limits') }}"   {{ request()->routeIs('legal.limits')   ? 'class=active' : '' }}>Limiti Transazionali</a>
            <a href="{{ route('legal.complaints') }}" {{ request()->routeIs('legal.complaints') ? 'class=active' : '' }}>Procedura Reclami</a>
        </div>
    </div>
</div>

<div class="container">
    <div class="content">
        @yield('content')
    </div>
</div>

</body>
</html>
