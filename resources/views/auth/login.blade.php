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
        .btn-biometric{width:100%;min-height:60px;border:2px solid var(--line);border-radius:16px;background:#f7fafb;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;font-size:16px;font-weight:700;color:var(--ink);transition:border-color .2s,background .2s}
        .btn-biometric:hover{border-color:#4d7386;background:#eef3f5}.btn-biometric:disabled{opacity:.5;cursor:not-allowed}
        .biometric-msg{margin-top:12px;padding:10px 14px;border-radius:10px;font-size:14px;font-weight:600;display:none}
        .biometric-msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
        .biometric-msg.err{background:var(--rose);color:#7a4250;border:1px solid #fecdd3}
        /* Account chip */
        .account-chip{margin-top:20px;display:flex;align-items:center;gap:12px;padding:14px 16px;border:1px solid var(--line);border-radius:16px;background:#f7fafb;}
        .account-chip-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#4d7386,#718b5c);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .account-chip-btn{font-size:13px;color:#4d7386;background:none;border:1px solid var(--line);border-radius:10px;cursor:pointer;font-weight:600;padding:6px 10px;white-space:nowrap;}
        /* Mobile */
        @media (max-width:900px){
            body{background:var(--navy)}
            .wrap{padding:0;align-items:stretch}
            .card{grid-template-columns:1fr;grid-template-rows:auto 1fr;border-radius:0;box-shadow:none;min-height:100vh}
            .brand{order:2;padding:24px 20px;gap:12px;display:flex;flex-direction:row;align-items:center;flex-wrap:wrap}
            .brand img{width:36px}
            .brand>div{flex:1}
            .brand h1{font-size:18px;margin:0}
            .brand p,.feature{display:none}
            .panel{order:1;padding:32px 20px 24px;background:#fff;border-radius:0}
            .panel h2{font-size:28px}.sub{font-size:15px}
            .cta,.ghost{width:100%}
            .btn-biometric{min-height:64px;font-size:18px}
        }
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

                {{-- ── Vista: nessun account salvato — step 1 email / step 2 auth ─────── --}}
                <div id="view-default" style="display:none;">

                    @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif

                    {{-- Step 1: solo il campo email --}}
                    <div id="step1">
                        <div class="sub" style="margin-top:8px;">Entra con il tuo profilo KMoney o apri un nuovo conto.</div>
                        <div class="field">
                            <label for="email">Email</label>
                            <input id="email" type="email" value="{{ old('email') }}" required autocomplete="username webauthn">
                        </div>
                        <div class="cta-row" style="margin-top:14px;">
                            <button id="btn-continua" class="cta" type="button" style="flex:1;">Continua</button>
                            <a class="ghost" href="{{ route('register') }}">Apri un conto KMoney</a>
                        </div>
                    </div>

                    {{-- Step 2: chip email + passkey + password (nascosto finché non confermata email) --}}
                    <div id="step2" style="display:none;">
                        {{-- Chip email confermata --}}
                        <div class="account-chip">
                            <div class="account-chip-avatar">
                                <span id="step2-initial" style="color:#fff;font-weight:700;font-size:17px;"></span>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div id="step2-email-label" style="font-size:15px;font-weight:700;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                                <div style="font-size:12px;color:var(--muted);margin-top:1px;">Il tuo account</div>
                            </div>
                            <button id="btn-cambia-email" class="account-chip-btn" type="button">Cambia</button>
                        </div>

                        {{-- Passkey --}}
                        <button id="btn-biometric" class="btn-biometric" type="button" style="margin-top:14px;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/>
                                <path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/>
                                <path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/>
                                <path d="M12 12v.01"/>
                            </svg>
                            Accedi con impronta / Passkey
                        </button>
                        <div id="biometric-msg" class="biometric-msg"></div>

                        <div class="divider">oppure con password</div>

                        <form method="post" action="{{ route('login.attempt') }}">
                            @csrf
                            <input type="hidden" id="email-step2" name="email">
                            <div class="field">
                                <label for="password">Password</label>
                                <input id="password" name="password" type="password" required autocomplete="current-password">
                            </div>
                            <div style="text-align:right;margin-top:8px;">
                                <a href="{{ route('password.request') }}" style="font-size:13px;color:#4d7386;text-decoration:none;font-weight:600;">Password dimenticata?</a>
                            </div>
                            <div class="cta-row">
                                <button class="cta" type="submit" style="flex:1;">Accedi al conto</button>
                                <a class="ghost" href="{{ route('register') }}">Apri un conto KMoney</a>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- ── Vista: lista account salvati (2+) ─────────────────────────────── --}}
                <div id="view-accounts" style="display:none;">
                    {{-- Passkey discoverable (nessun account pre-selezionato) --}}
                    <button id="btn-biometric-discover" class="btn-biometric" type="button" style="margin-top:20px;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/>
                            <path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/>
                            <path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/>
                            <path d="M12 12v.01"/>
                        </svg>
                        Accedi con impronta / Passkey
                    </button>
                    <div id="biometric-msg-discover" class="biometric-msg"></div>

                    <div class="divider" style="margin-top:20px;">account su questo dispositivo</div>

                    <div id="saved-accounts-list" style="display:flex;flex-direction:column;gap:8px;margin-top:14px;"></div>
                    <div style="margin-top:10px;text-align:right;">
                        <button id="btn-altro-account" type="button" style="font-size:13px;color:#4d7386;background:none;border:none;cursor:pointer;font-weight:600;padding:0;">+ Usa un altro account</button>
                    </div>
                </div>

                {{-- ── Vista: account selezionato → passkey + password ──────────────── --}}
                <div id="view-selected" style="display:none;">
                    {{-- Chip account selezionato --}}
                    <div class="account-chip">
                        <div class="account-chip-avatar">
                            <span id="sel-initial" style="color:#fff;font-weight:700;font-size:17px;"></span>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div id="sel-email" style="font-size:15px;font-weight:700;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                            <div style="font-size:12px;color:var(--muted);margin-top:1px;">Account selezionato</div>
                        </div>
                        <button id="btn-cambia-account" class="account-chip-btn" type="button">Cambia</button>
                    </div>

                    {{-- Passkey contestuale all'account --}}
                    <button id="btn-biometric-sel" class="btn-biometric" type="button" style="margin-top:14px;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/>
                            <path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/>
                            <path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/>
                            <path d="M12 12v.01"/>
                        </svg>
                        Accedi con impronta / Passkey
                    </button>
                    <div id="biometric-msg-sel" class="biometric-msg"></div>

                    <div class="divider">oppure con password</div>

                    <form method="post" action="{{ route('login.attempt') }}">
                        @csrf
                        <input type="hidden" id="email-sel" name="email">
                        <div class="field"><label for="password-sel">Password</label><input id="password-sel" name="password" type="password" required autocomplete="current-password"></div>
                        <div style="text-align:right;margin-top:8px;">
                            <a href="{{ route('password.request') }}" style="font-size:13px;color:#4d7386;text-decoration:none;font-weight:600;">Password dimenticata?</a>
                        </div>
                        <div class="cta-row">
                            <button class="cta" type="submit" style="flex:1;">Accedi al conto</button>
                        </div>
                    </form>
                    <div style="margin-top:10px;text-align:center;">
                        <a class="ghost" href="{{ route('register') }}" style="font-size:14px;">Apri un nuovo conto KMoney</a>
                    </div>
                </div>

                <p style="margin:12px 0 0;font-size:12px;color:var(--muted);text-align:center;line-height:1.5;">
                    Prima volta con impronta? Accedi con password, poi vai su <strong>Portale&nbsp;→&nbsp;Sicurezza</strong>.
                </p>
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
function escapeHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Gestione viste ─────────────────────────────────────────────────────────────
// 3 viste: 'default', 'accounts', 'selected'
function showView(name) {
    ['default','accounts','selected'].forEach(v => {
        document.getElementById('view-' + v).style.display = (v === name) ? '' : 'none';
    });
}

// ── Account salvati su questo dispositivo ─────────────────────────────────────
const ACCOUNTS_KEY = 'kmoney_saved_accounts';
function getSavedAccounts() {
    try { return JSON.parse(localStorage.getItem(ACCOUNTS_KEY) || '[]'); } catch { return []; }
}
function saveAccount(email) {
    if (!email) return;
    const list = getSavedAccounts().filter(e => e !== email);
    list.unshift(email);
    localStorage.setItem(ACCOUNTS_KEY, JSON.stringify(list.slice(0, 10)));
}
function removeAccount(email) {
    const list = getSavedAccounts().filter(e => e !== email);
    localStorage.setItem(ACCOUNTS_KEY, JSON.stringify(list));
    renderSavedAccounts();
}

// ── Step email (view-default) ─────────────────────────────────────────────────
function confirmEmail(email) {
    email = (email || '').trim();
    if (!email || !email.includes('@')) {
        document.getElementById('email').focus();
        return;
    }
    document.getElementById('step2-initial').textContent = email[0].toUpperCase();
    document.getElementById('step2-email-label').textContent = email;
    document.getElementById('email-step2').value = email;
    document.getElementById('password').value = '';
    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = '';
    clearMsg('biometric-msg');
    setTimeout(() => document.getElementById('password').focus(), 80);
}

document.getElementById('btn-continua').addEventListener('click', () => {
    confirmEmail(document.getElementById('email').value);
});
document.getElementById('email').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); confirmEmail(this.value); }
});
document.getElementById('btn-cambia-email').addEventListener('click', () => {
    document.getElementById('step1').style.display = '';
    document.getElementById('step2').style.display = 'none';
    setTimeout(() => document.getElementById('email').focus(), 40);
});

// ── Seleziona account → vista 'selected' ──────────────────────────────────────
let selectedEmail = null;
function selectAccount(email) {
    selectedEmail = email;
    document.getElementById('sel-initial').textContent = email[0].toUpperCase();
    document.getElementById('sel-email').textContent   = email;
    document.getElementById('email-sel').value         = email;
    document.getElementById('password-sel').value      = '';
    showView('selected');
    setTimeout(() => document.getElementById('password-sel').focus(), 80);
}

// ── Renderizza lista account ───────────────────────────────────────────────────
function renderSavedAccounts() {
    const list = getSavedAccounts();

    // 0 account: mostra il form email manuale
    if (list.length === 0) {
        showView('default');
        // Se c'è un'email da un tentativo precedente (old input), avanza subito allo step 2
        const oldEmail = document.getElementById('email').value.trim();
        if (oldEmail) confirmEmail(oldEmail);
        return;
    }

    // 1 account: vai direttamente alla vista selezionata (senza mostrare la lista)
    if (list.length === 1) {
        selectAccount(list[0]);
        return;
    }

    // 2+ account: mostra la lista
    const listEl = document.getElementById('saved-accounts-list');
    listEl.innerHTML = '';
    list.forEach(email => {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:10px;';
        row.innerHTML = `
            <button type="button" data-action="select" data-email="${escapeHtml(email)}"
                style="flex:1;display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:#f7fafb;cursor:pointer;text-align:left;font-family:inherit;transition:border-color .15s,background .15s;">
                <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#4d7386,#718b5c);display:flex;align-items:center;justify-content:center;flex-shrink:0;pointer-events:none;">
                    <span style="color:#fff;font-weight:700;font-size:15px;pointer-events:none;">${escapeHtml(email[0].toUpperCase())}</span>
                </div>
                <div style="flex:1;min-width:0;pointer-events:none;">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(email)}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:1px;">Tocca per continuare</div>
                </div>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;pointer-events:none;"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <button type="button" data-action="remove" data-email="${escapeHtml(email)}"
                style="flex-shrink:0;background:none;border:1px solid var(--line);border-radius:10px;cursor:pointer;padding:8px 10px;color:var(--muted);font-size:18px;line-height:1;" title="Rimuovi">&times;</button>`;
        listEl.appendChild(row);
    });

    listEl.onclick = function (e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        if (btn.dataset.action === 'select') selectAccount(btn.dataset.email);
        if (btn.dataset.action === 'remove') removeAccount(btn.dataset.email);
    };

    showView('accounts');
}

// ── Navigazione ───────────────────────────────────────────────────────────────
// "Usa un altro account" → vista default con campo email
document.getElementById('btn-altro-account').addEventListener('click', function () {
    selectedEmail = null;
    showView('default');
    document.getElementById('step1').style.display = '';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('email').value = '';
    document.getElementById('email').focus();
});

// "Cambia account" → torna alla lista (o a step1 se c'era solo 1 account)
document.getElementById('btn-cambia-account').addEventListener('click', function () {
    selectedEmail = null;
    const list = getSavedAccounts();
    if (list.length <= 1) {
        // Con 0 o 1 account, torna a step1 del view-default
        showView('default');
        document.getElementById('step1').style.display = '';
        document.getElementById('step2').style.display = 'none';
        document.getElementById('email').value = list[0] || '';
        document.getElementById('email').focus();
    } else {
        renderSavedAccounts();
    }
});

// Salva email al submit dei form password
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function () {
        const e = (
            document.getElementById('email-sel').value ||
            document.getElementById('email-step2').value ||
            ''
        ).trim();
        if (e) saveAccount(e);
    });
});

// Inizializza
renderSavedAccounts();

// ── Shared: ottieni opzioni + verifica assertion ───────────────────────────────
async function getLoginOptions(email) {
    const res = await fetch('{{ route("webauthn.login.options") }}', {
        method:  'POST',
        headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify(email ? { email } : {}),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Errore opzioni');
    data.challenge = b64urlToBuffer(data.challenge);
    if (data.allowCredentials) data.allowCredentials = data.allowCredentials.map(c => ({ ...c, id: b64urlToBuffer(c.id) }));
    return data;
}
async function verifyAssertion(assertion) {
    const payload = {
        id: assertion.id, rawId: bufferToB64url(assertion.rawId), type: assertion.type,
        response: {
            clientDataJSON:    bufferToB64url(assertion.response.clientDataJSON),
            authenticatorData: bufferToB64url(assertion.response.authenticatorData),
            signature:         bufferToB64url(assertion.response.signature),
            userHandle: assertion.response.userHandle ? bufferToB64url(assertion.response.userHandle) : null,
        },
    };
    const res = await fetch('{{ route("webauthn.login.verify") }}', {
        method:  'POST',
        headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Autenticazione fallita');
    return data;
}

// ── Messaggio UI ───────────────────────────────────────────────────────────────
function showMsg(elId, text, type) {
    const el = document.getElementById(elId);
    el.textContent = text;
    el.className = 'biometric-msg ' + type;
    el.style.display = 'block';
}
function clearMsg(elId) {
    const el = document.getElementById(elId);
    if (el) el.style.display = 'none';
}

// ── Conditional UI (Passkey Autofill) ─────────────────────────────────────────
let conditionalAbortController = null;
async function startConditionalPasskey() {
    if (!window.PublicKeyCredential || !PublicKeyCredential.isConditionalMediationAvailable) return;
    const supported = await PublicKeyCredential.isConditionalMediationAvailable();
    if (!supported) return;
    try {
        conditionalAbortController = new AbortController();
        const optData = await getLoginOptions(null);
        const assertion = await navigator.credentials.get({ publicKey: optData, mediation: 'conditional', signal: conditionalAbortController.signal });
        const verData = await verifyAssertion(assertion);
        showMsg('biometric-msg', 'Accesso riuscito! Reindirizzamento…', 'ok');
        window.location.href = verData.redirect;
    } catch (err) {
        if (err.name !== 'AbortError') console.warn('Conditional passkey error:', err.message);
    }
}
startConditionalPasskey();

// ── Helper: esegui login biometrico ───────────────────────────────────────────
const FINGERPRINT_SVG = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/><path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/><path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/><path d="M12 12v.01"/></svg>`;

async function doBiometricLogin(btnId, msgId, email) {
    clearMsg(msgId);
    if (conditionalAbortController) { conditionalAbortController.abort(); conditionalAbortController = null; }
    const btn = document.getElementById(btnId);
    btn.disabled = true; btn.textContent = 'In attesa del dispositivo…';
    try {
        const optData   = await getLoginOptions(email || null);
        const assertion = await navigator.credentials.get({ publicKey: optData });
        const verData   = await verifyAssertion(assertion);
        showMsg(msgId, 'Accesso riuscito! Reindirizzamento…', 'ok');
        if (email) saveAccount(email);
        window.location.href = verData.redirect;
    } catch (err) {
        if      (err.name === 'NotAllowedError')   showMsg(msgId, 'Autenticazione annullata o non riuscita.', 'err');
        else if (err.name === 'NotSupportedError') showMsg(msgId, "Il tuo dispositivo non supporta l'autenticazione biometrica.", 'err');
        else if (err.message?.includes('impronta'))showMsg(msgId, 'Questo account non ha una passkey registrata. Usa la password.', 'err');
        else                                        showMsg(msgId, 'Errore: ' + err.message, 'err');
        startConditionalPasskey();
    } finally {
        btn.disabled = false;
        btn.innerHTML = FINGERPRINT_SVG + ' Accedi con impronta / Passkey';
    }
}

// Passkey step2 (view-default con email confermata)
document.getElementById('btn-biometric').addEventListener('click', () => {
    const email = document.getElementById('email-step2').value.trim() || null;
    doBiometricLogin('btn-biometric', 'biometric-msg', email);
});

// Passkey vista lista account (discoverable)
document.getElementById('btn-biometric-discover').addEventListener('click', () => {
    doBiometricLogin('btn-biometric-discover', 'biometric-msg-discover', null);
});

// Passkey vista account selezionato
document.getElementById('btn-biometric-sel').addEventListener('click', () => {
    doBiometricLogin('btn-biometric-sel', 'biometric-msg-sel', selectedEmail);
});

// ── Toggle visibilità password ────────────────────────────────────────────────
(function () {
    const eyeShow = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
    const eyeHide = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
    document.querySelectorAll('input[type=password]').forEach(function (input) {
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.style.paddingRight = '42px';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Mostra/nascondi password');
        btn.style.cssText = 'position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:4px;display:flex;align-items:center;color:var(--muted);';
        btn.innerHTML = eyeShow;
        btn.addEventListener('click', function () {
            var visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.innerHTML = visible ? eyeShow : eyeHide;
        });
        wrap.appendChild(btn);
    });
})();
</script>
@include('partials.password-toggle')
</body>
</html>
