<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login KMoney</title>
    <style>
        :root { --navy:#1d3344; --navy-deep:#11222f; --mist:#eef3f5; --line:#d9e2e8; --sage:#718b5c; --rose:#f8ecef; --ink:#142431; --muted:#557082; }
        *{box-sizing:border-box} body{margin:0;font-family:"Segoe UI",Tahoma,sans-serif;background:linear-gradient(180deg,var(--navy) 0 45%, #f5f8f9 45% 100%);color:var(--ink)}
        .wrap{min-height:100vh;display:grid;place-items:center;padding:36px 16px}.card{width:min(100%,1120px);display:grid;grid-template-columns:minmax(340px,.9fr) minmax(420px,1.1fr);background:#fff;border-radius:34px;overflow:hidden;box-shadow:0 26px 70px rgba(10,27,39,.18)}
        .brand{padding:42px 38px;background:linear-gradient(180deg,var(--navy) 0%,var(--navy-deep) 100%);color:#fff;display:grid;gap:22px}.brand img{width:72px}.brand h1{margin:0;font-size:48px;font-family:Georgia,"Times New Roman",serif}.brand p{margin:0;color:rgba(255,255,255,.76);line-height:1.7}.feature{padding:16px 18px;border-radius:22px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08)}
        .feature strong{display:block;margin-bottom:8px}.panel{padding:42px 38px}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}.panel h2{margin:10px 0 8px;font-size:42px;font-family:Georgia,"Times New Roman",serif}.sub{color:var(--muted);font-size:17px;line-height:1.6}.err{margin:20px 0 0;padding:14px 16px;border-radius:16px;background:var(--rose);color:#7a4250;font-weight:700}
        .field{margin-top:18px}.field label{display:block;margin-bottom:8px;font-weight:700;color:#2f5063}.field input{width:100%;min-height:54px;padding:0 16px;border:1px solid var(--line);border-radius:16px;font-size:17px}
        .cta-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}.cta,.ghost{display:inline-flex;align-items:center;justify-content:center;min-height:54px;padding:0 18px;border-radius:16px;font-weight:800;text-decoration:none}.cta{border:0;background:linear-gradient(135deg,#4d7386,#718b5c);color:#fff;cursor:pointer}.ghost{border:1px solid var(--line);color:var(--ink);background:#f7fafb}
        .demo{margin-top:22px;padding:18px;border-radius:18px;background:#f4f8f5;color:#4f6058;line-height:1.7}
        @media (max-width:900px){.card{grid-template-columns:1fr}.brand,.panel{padding:28px 22px}.brand h1,.panel h2{font-size:36px}.cta,.ghost{width:100%}}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <section class="brand">
                <img src="/assets/brand/kmoney-logo.png" alt="KMoney logo">
                <div>
                    <div class="eyebrow" style="color:rgba(255,255,255,.68);">Circuito KY</div>
                    <h1>Accedi a KMoney</h1>
                    <p>Privati e aziende aprono conti, comprano, vendono e possono distribuire budget ai propri sottoconti nel medesimo ecosistema.</p>
                </div>
                <div class="feature"><strong>Privato</strong>Conto personale, spesa e incasso nel circuito, gestione figli o familiari con conti delegati.</div>
                <div class="feature"><strong>Azienda</strong>Ingresso nel network interno aziende, conto principale, sottoconti per dipendenti e controllo budget.</div>
            </section>
            <section class="panel">
                <div class="eyebrow">Accesso</div>
                <h2>Login</h2>
                <div class="sub">Entra con il tuo profilo KMoney o apri un nuovo conto come privato o azienda.</div>
                @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
                <form method="post" action="{{ route('login.attempt') }}">
                    @csrf
                    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required></div>
                    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
                    <div style="text-align:right;margin-top:8px;">
                        <a href="{{ route('password.request') }}" style="font-size:13px;color:#4d7386;text-decoration:none;font-weight:600;">Password dimenticata?</a>
                    </div>
                    <div class="cta-row">
                        <button class="cta" type="submit">Accedi al conto</button>
                        <a class="ghost" href="{{ route('register') }}">Apri un conto KMoney</a>
                    </div>
                </form>
                <div class="demo">
                    Superadmin demo: <strong>superadmin@kmoney.test</strong> / <strong>secret123</strong><br>
                    Azienda demo: <strong>operatore-panificio-canale@kmoney.test</strong> / <strong>secret123</strong><br>
                    Privato demo: <strong>maria.ferri@kmoney.test</strong> / <strong>secret123</strong>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
