<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
        /* WebAuthn */
        .divider{display:flex;align-items:center;gap:12px;margin:24px 0 0;color:var(--muted);font-size:13px}.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--line)}
        .btn-biometric{width:100%;min-height:54px;border:2px solid var(--line);border-radius:16px;background:#f7fafb;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;font-size:16px;font-weight:700;color:var(--ink);transition:border-color .2s,background .2s}
        .btn-biometric:hover{border-color:#4d7386;background:#eef3f5}.btn-biometric:disabled{opacity:.5;cursor:not-allowed}
        .biometric-msg{margin-top:12px;padding:10px 14px;border-radius:10px;font-size:14px;font-weight:600;display:none}
        .biometric-msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
        .biometric-msg.err{background:var(--rose);color:#7a4250;border:1px solid #fecdd3}
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
                    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"></div>
                    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
                    <div style="text-align:right;margin-top:8px;">
                        <a href="{{ route('password.request') }}" style="font-size:13px;color:#4d7386;text-decoration:none;font-weight:600;">Password dimenticata?</a>
                    </div>
                    <div class="cta-row">
                        <button class="cta" type="submit">Accedi al conto</button>
                        <a class="ghost" href="{{ route('register') }}">Apri un conto KMoney</a>
                    </div>
                </form>

                {{-- ── Accesso con impronta (WebAuthn / Passkey) ──────────────────── --}}
                <div class="divider">oppure</div>

                <button id="btn-biometric" class="btn-biometric" type="button">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/>
                        <path d="M3.5 12c0-1.4.3-2.7.8-3.9"/>
                        <path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/>
                        <path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/>
                        <path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/>
                        <path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/>
                        <path d="M12 12v.01"/>
                    </svg>
                    Accedi con impronta
                </button>
                <div id="biometric-msg" class="biometric-msg"></div>

                <div class="demo">
                    Superadmin demo: <strong>superadmin@kmoney.test</strong> / <strong>secret123</strong><br>
                    Azienda demo: <strong>operatore-panificio-canale@kmoney.test</strong> / <strong>secret123</strong><br>
                    Privato demo: <strong>maria.ferri@kmoney.test</strong> / <strong>secret123</strong>
                </div>
            </section>
        </div>
    </div>

<script>
// ── Utilities base64url ────────────────────────────────────────────────────────
function b64urlToBuffer(b64url) {
    const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
    const padded = b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=');
    return Uint8Array.from(atob(padded), c => c.charCodeAt(0)).buffer;
}

function bufferToB64url(buf) {
    const bytes = new Uint8Array(buf);
    let bin = '';
    bytes.forEach(b => bin += String.fromCharCode(b));
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

// ── Messaggio UI ───────────────────────────────────────────────────────────────
function showMsg(text, type) {
    const el = document.getElementById('biometric-msg');
    el.textContent = text;
    el.className = 'biometric-msg ' + type;
    el.style.display = 'block';
}

function clearMsg() {
    const el = document.getElementById('biometric-msg');
    el.style.display = 'none';
}

// ── Login con impronta ─────────────────────────────────────────────────────────
document.getElementById('btn-biometric').addEventListener('click', async () => {
    clearMsg();

    const email = document.getElementById('email').value.trim();
    if (!email) {
        showMsg('Inserisci prima la tua email nel campo sopra.', 'err');
        document.getElementById('email').focus();
        return;
    }

    const btn = document.getElementById('btn-biometric');
    btn.disabled = true;
    btn.textContent = 'In attesa del dispositivo…';

    try {
        // 1. Ottieni le opzioni di sfida dal server
        const optRes = await fetch('{{ route("webauthn.login.options") }}', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ email }),
        });

        const optData = await optRes.json();
        if (!optRes.ok) {
            showMsg(optData.error || 'Errore nel recupero delle opzioni.', 'err');
            return;
        }

        // 2. Decodifica i campi binari
        optData.challenge = b64urlToBuffer(optData.challenge);
        if (optData.allowCredentials) {
            optData.allowCredentials = optData.allowCredentials.map(c => ({
                ...c, id: b64urlToBuffer(c.id),
            }));
        }

        // 3. Avvia il prompt biometrico del browser
        const assertion = await navigator.credentials.get({ publicKey: optData });

        // 4. Codifica la risposta per inviarla al server
        const payload = {
            id:    assertion.id,
            rawId: bufferToB64url(assertion.rawId),
            type:  assertion.type,
            response: {
                clientDataJSON:    bufferToB64url(assertion.response.clientDataJSON),
                authenticatorData: bufferToB64url(assertion.response.authenticatorData),
                signature:         bufferToB64url(assertion.response.signature),
                userHandle: assertion.response.userHandle
                    ? bufferToB64url(assertion.response.userHandle)
                    : null,
            },
        };

        // 5. Invia al server per la verifica
        const verRes = await fetch('{{ route("webauthn.login.verify") }}', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(payload),
        });

        const verData = await verRes.json();
        if (!verRes.ok) {
            showMsg(verData.error || 'Autenticazione fallita.', 'err');
            return;
        }

        showMsg('Accesso riuscito! Reindirizzamento…', 'ok');
        window.location.href = verData.redirect;

    } catch (err) {
        if (err.name === 'NotAllowedError') {
            showMsg('Autenticazione annullata o non riuscita.', 'err');
        } else if (err.name === 'NotSupportedError') {
            showMsg('Il tuo dispositivo non supporta l\'autenticazione biometrica.', 'err');
        } else {
            showMsg('Errore: ' + err.message, 'err');
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/><path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/><path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/><path d="M12 12v.01"/></svg> Accedi con impronta`;
    }
});
</script>
</body>
</html>
