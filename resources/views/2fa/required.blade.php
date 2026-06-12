<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sicurezza obbligatoria - KMoney</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg, #edf1f8);
            font-family: system-ui, sans-serif;
        }
        .card {
            background: var(--surface, #fff);
            border-radius: 16px;
            box-shadow: 0 4px 32px rgba(10,30,60,.12);
            padding: 40px 36px;
            width: 100%;
            max-width: 440px;
        }
        .icon {
            width: 56px; height: 56px;
            background: #fef3c7;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }
        h1 { font-size: 1.25rem; font-weight: 700; text-align: center; margin: 0 0 8px; }
        p  { color: #555; text-align: center; font-size: .95rem; margin: 0 0 28px; }
        .btn {
            display: block; width: 100%;
            padding: 13px 20px;
            border-radius: 10px;
            font-size: 1rem; font-weight: 600;
            text-align: center;
            cursor: pointer; border: none;
            text-decoration: none;
            margin-bottom: 12px;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-outline { background: transparent; color: #2563eb; border: 2px solid #2563eb; }
        .btn-outline:hover { background: #eff6ff; }
        .logout { display: block; text-align: center; margin-top: 20px; color: #888; font-size: .85rem; }
        .logout a { color: #888; text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">🔐</div>
    <h1>Configura la sicurezza dell'account</h1>
    <p>
        Il circuito KMoney richiede un secondo fattore di autenticazione per proteggere il tuo conto.
        Scegli il metodo che preferisci.
    </p>

    @if (session('info'))
        <div style="background:#fef9c3;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:.9rem;color:#713f12;">
            {{ session('info') }}
        </div>
    @endif

    {{-- Opzione 1: TOTP --}}
    <a href="{{ route('portal.profile.edit') }}#2fa" class="btn btn-primary">
        🔑 Configura codici TOTP (app autenticatore)
    </a>

    {{-- Opzione 2: Passkey --}}
    <a href="{{ route('portal.profile.edit') }}#passkey" class="btn btn-outline">
        🪪 Configura Passkey / impronta digitale
    </a>

    <div class="logout">
        <a href="{{ route('logout') }}"
           onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
            Esci dall'account
        </a>
    </div>

    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none">
        @csrf
    </form>
</div>
</body>
</html>
