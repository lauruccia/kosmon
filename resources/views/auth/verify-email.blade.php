<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifica email — KMoney</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg,#edf1f8);font-family:system-ui,sans-serif; }
        .card { background:var(--surface,#fff);border-radius:16px;box-shadow:0 4px 32px rgba(10,30,60,.10);padding:40px 36px;max-width:420px;width:100%; }
        .icon { width:56px;height:56px;background:#e6effc;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px; }
        h1 { font-size:20px;font-weight:700;text-align:center;color:var(--ink,#0d1c30);margin:0 0 8px; }
        p { font-size:14px;color:var(--ink-soft,#4a637d);text-align:center;line-height:1.6;margin:0 0 24px; }
        .btn { display:block;width:100%;padding:13px;background:var(--primary,#0f52c4);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;box-sizing:border-box; }
        .btn:hover { background:var(--primary-strong,#0b3f9a); }
        .logout { display:block;text-align:center;margin-top:16px;font-size:13px;color:var(--ink-muted,#7a95aa);background:none;border:none;cursor:pointer;width:100%; }
        .alert { border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:18px; }
        .alert-success { background:var(--success-soft,#d1fae5);color:var(--success,#065f46);border:1px solid #a7f3d0; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#0f52c4" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
        </svg>
    </div>

    <h1>Verifica la tua email</h1>
    <p>
        Ti abbiamo inviato un link di verifica a <strong>{{ auth()->user()->email }}</strong>.<br>
        Clicca il link nell'email per attivare il tuo account KMoney.
    </p>

    @if (session('portal_success'))
        <div class="alert alert-success">{{ session('portal_success') }}</div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn">Invia di nuovo il link</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="logout">Accedi con un altro account</button>
    </form>
</div>
</body>
</html>
