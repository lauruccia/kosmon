{{--
    Guscio minimo per le pagine che devono restare raggiungibili da chi e'
    fermo a un cancello: email non verificata, onboarding a meta', contratto
    non firmato. Il layout del portale non va bene qui — la sua barra laterale
    e' fatta di link che per questa persona rimbalzano tutti indietro, e
    presuppone un conto attivo che ancora non c'e'.

    Nato l'08/09/2026 insieme allo sblocco del cambio email.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'KMoney' }} — KMoney</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; min-height: 100vh; }

        .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .brand  { font-weight: 800; font-size: 1.15rem; color: #0f766e; text-decoration: none; }
        .topbar-actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-content: flex-end; }
        .topbar-user { font-size: 13px; color: #64748b; }
        .topbar-logout { background: none; border: none; padding: 0; font: inherit; font-size: 13px; color: #64748b; cursor: pointer; text-decoration: underline; }
        .topbar-logout:hover { color: #b91c1c; }

        .shell { padding: 32px 0 80px; }

        .eyebrow { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: 6px; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin: 0 0 8px; color: #0f172a; }
        .subtle { color: #64748b; font-size: 14px; margin: 0; }

        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; }
        .card-pad { padding: 24px; }

        .alert { border-radius: 10px; padding: 14px 16px; font-size: 14px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .alert.success { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .alert.info    { background: #f0f9ff; border-color: #bae6fd; color: #0369a1; }

        .form-group { display: block; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 15px; font-family: inherit; background: #fff; color: #1e293b; }
        .form-control:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
        .form-control.is-invalid { border-color: #dc2626; }
        .invalid-feedback { color: #dc2626; font-size: 13px; margin-top: 6px; }

        .cta { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: #0f766e; color: #fff; border: none; border-radius: 8px; padding: 12px 22px; font-size: 15px; font-weight: 700; font-family: inherit; cursor: pointer; text-decoration: none; }
        .cta:hover { background: #115e59; }
        .cta.secondary { background: #fff; color: #475569; border: 1.5px solid #cbd5e1; }
        .cta.secondary:hover { background: #f8fafc; }

        .gated-note { max-width: 520px; margin: 0 auto 20px; padding: 0 16px; }
    </style>
</head>
<body>

<nav class="topbar">
    <a href="{{ route('home') }}" class="brand">KMoney</a>
    <div class="topbar-actions">
        <span class="topbar-user">{{ ($currentUser ?? auth()->user())?->name }}</span>
        <form method="POST" action="{{ route('logout') }}" style="display:inline;">
            @csrf
            <button type="submit" class="topbar-logout">Esci</button>
        </form>
    </div>
</nav>

<div class="shell">
    @if(! empty($gatedReturnUrl))
        <div class="gated-note">
            <div class="alert info">
                Stai correggendo il tuo indirizzo prima di completare l'accesso.
                <a href="{{ $gatedReturnUrl }}" style="color:#0369a1;font-weight:600;">Torna indietro</a>
                quando hai finito.
            </div>
        </div>
    @endif

    @yield('content')
</div>

</body>
</html>
