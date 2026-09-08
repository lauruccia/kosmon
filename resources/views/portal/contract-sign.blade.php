<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firma Contratto di Adesione — KMoney</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; min-height: 100vh; }

        /* Topbar */
        .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .brand  { font-weight: 800; font-size: 1.15rem; color: #0f766e; text-decoration: none; }
        .topbar-user { font-size: 13px; color: #64748b; }
        .topbar-actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-content: flex-end; }
        .topbar-link { font-size: 13px; color: #0f766e; text-decoration: none; }
        .topbar-link:hover { text-decoration: underline; }
        .topbar-logout { background: none; border: none; padding: 0; font: inherit; font-size: 13px; color: #64748b; cursor: pointer; text-decoration: underline; }
        .topbar-logout:hover { color: #b91c1c; }

        .page { max-width: 860px; margin: 32px auto; padding: 0 20px 80px; }

        /* Banners */
        .banner { border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 14px; display: flex; gap: 12px; align-items: flex-start; }
        .banner-required { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .banner-reminder { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .banner-otp-sent  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .banner-info      { background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; }
        .banner-icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }

        /* Main card */
        .card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #0e7490 100%); color: #fff; padding: 24px 32px; }
        .card-header h1 { margin: 0 0 4px; font-size: 1.35rem; font-weight: 800; }
        .card-header p  { margin: 0; font-size: 13px; opacity: .8; }
        .card-body { padding: 28px 32px; }

        /* Steps */
        .steps { display: flex; gap: 0; margin-bottom: 28px; }
        .step { flex: 1; display: flex; flex-direction: column; align-items: center; position: relative; }
        .step:not(:last-child)::after { content:''; position: absolute; top: 14px; left: 50%; width: 100%; height: 2px; background: #e2e8f0; z-index: 0; }
        .step.done::after { background: #0f766e; }
        .step-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; z-index: 1; }
        .step.done .step-dot   { background: #0f766e; color: #fff; }
        .step.active .step-dot { background: #fff; border: 2.5px solid #0f766e; color: #0f766e; }
        .step.pending .step-dot{ background: #f1f5f9; border: 2px solid #e2e8f0; color: #94a3b8; }
        .step-label { font-size: 11.5px; color: #64748b; margin-top: 6px; text-align: center; }

        /* Dati azienda */
        .company-header { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px 24px; margin-bottom: 24px; }
        .company-header h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin: 0 0 16px; }
        .company-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px 24px; }
        .company-field { }
        .company-field .label { font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
        .company-field .value { font-size: 14px; color: #1e293b; font-weight: 600; word-break: break-word; }
        .company-field .value.empty { color: #cbd5e1; font-style: italic; font-weight: 400; }
        .company-field.span2 { grid-column: span 2; }

        /* Contratto */
        .contract-wrapper { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 24px; }
        .contract-toolbar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; }
        .contract-toolbar span { font-size: 13px; color: #64748b; }
        .contract-body { padding: 28px 32px; max-height: 460px; overflow-y: auto; font-size: 14px; line-height: 1.75; }
        .contract-body h2 { font-size: .95rem; font-weight: 700; margin: 22px 0 8px; color: #0f766e; }
        .contract-body p  { margin: 0 0 12px; }
        .contract-body hr { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }
        .contract-body ul, .contract-body ol { padding-left: 20px; }
        .contract-body li { margin-bottom: 6px; }
        .contract-body strong { font-weight: 700; }
        .expand-btn { font-size: 12px; color: #0f766e; border: 1px solid #0f766e; background: none; padding: 4px 10px; border-radius: 6px; cursor: pointer; }

        /* Firma */
        .sign-section { border-top: 1px solid #e2e8f0; padding-top: 24px; }
        .sign-title { font-size: 14px; font-weight: 700; color: #374151; margin: 0 0 6px; }
        .sign-subtitle { font-size: 13px; color: #64748b; margin: 0 0 18px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        input[type="text"] {
            width: 100%; max-width: 260px; padding: 12px 16px; border: 2px solid #cbd5e1; border-radius: 8px;
            font-size: 1.4rem; letter-spacing: .3em; text-align: center; font-weight: 700;
            transition: border-color .2s;
        }
        input[type="text"]:focus { outline: none; border-color: #0f766e; }
        input[type="text"].is-invalid { border-color: #ef4444; }
        .error-msg { color: #dc2626; font-size: 12.5px; margin-top: 5px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all .18s; }
        .btn-primary   { background: #0f766e; color: #fff; }
        .btn-primary:hover { background: #0e6b63; }
        .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .btn-ghost     { background: transparent; color: #94a3b8; border: 1px solid #e2e8f0; font-size: 13px; }
        .btn:disabled  { opacity: .45; cursor: not-allowed; }
        .actions { display: flex; flex-direction: column; gap: 14px; }
        .divider { border: none; border-top: 1px solid #e2e8f0; margin: 24px 0; }
        .postpone-section { text-align: center; }
        .postpone-section p { font-size: 13px; color: #94a3b8; margin: 0 0 10px; }

        @media (max-width: 600px) {
            .card-header, .card-body { padding: 18px 18px; }
            .contract-body { padding: 18px 18px; }
            .company-grid { grid-template-columns: 1fr 1fr; }
            input[type="text"] { max-width: 100%; }
        }
    </style>
</head>
<body>

<nav class="topbar">
    <a href="{{ route('home') }}" class="brand">KMoney</a>
    {{-- 2026-09-08: questa pagina e' un cancello — EnsureContractSigned ci
         rimbalza sopra ogni rotta del portale. Senza queste due uscite chi
         non riceve l'OTP resta chiuso qui dentro: non puo' correggere un
         indirizzo sbagliato (era dietro lo stesso cancello) ne' uscire per
         entrare con un altro account. --}}
    <div class="topbar-actions">
        <span class="topbar-user">{{ auth()->user()->name }}</span>
        <a href="{{ route('portal.email-change') }}" class="topbar-link">Cambia email</a>
        <form method="POST" action="{{ route('logout') }}" style="display:inline;">
            @csrf
            <button type="submit" class="topbar-logout">Esci</button>
        </form>
    </div>
</nav>

<div class="page">

    {{-- Banners --}}
    {{-- 2026-09-03: rifirma dopo una revisione. Va PRIMA degli altri perche'
         cambia il senso della pagina: non e' la prima firma, e la persona
         deve capire subito che le condizioni sono cambiate e quali. --}}
    @if($isResign ?? false)
        <div class="banner banner-reminder">
            <span class="banner-icon">&#128220;</span>
            <div>
                <strong>Le condizioni del contratto sono state aggiornate.</strong>
                Hai firmato la versione {{ $signedVer ?? 1 }}; quella in vigore è la
                {{ $contractVer }}. Per continuare a operare ti chiediamo di leggere il
                testo aggiornato qui sotto e firmarlo.
                <div style="margin-top:6px;font-size:13px;">
                    La tua firma precedente resta valida e archiviata: non viene cancellata.
                </div>
            </div>
        </div>
    @endif

    @if(session('contract_required'))
        <div class="banner banner-required">
            <span class="banner-icon">🔒</span>
            <div><strong>Firma obbligatoria.</strong> Devi firmare il Contratto di Adesione per attivare il tuo conto KMoney.</div>
        </div>
    @elseif(session('contract_reminder'))
        <div class="banner banner-reminder">
            <span class="banner-icon">📋</span>
            <div><strong>Firma in sospeso.</strong> Ti chiediamo di firmare il Contratto di Adesione prima di continuare.</div>
        </div>
    @endif

    @if(session('otp_sent'))
        <div class="banner banner-otp-sent">
            <span class="banner-icon">✉️</span>
            <div>Codice OTP inviato a <strong>{{ session('otp_email') }}</strong> — valido {{ \App\Http\Controllers\ContractController::OTP_MINUTI }} minuti.</div>
        </div>
    @elseif($otpPending)
        <div class="banner banner-info">
            <span class="banner-icon">⏳</span>
            <div>
                Hai già un codice valido, inviato a <strong>{{ $otpEmail }}</strong>@if($otpExpiresAt) e valido fino alle
                <strong>{{ $otpExpiresAt->format('H:i') }}</strong>@endif. Inseriscilo qui sotto.
                <div style="margin-top:6px;font-size:13px;">
                    Non è arrivato? Controlla la posta indesiderata, oppure
                    <a href="{{ route('portal.email-change') }}" style="color:#0369a1;font-weight:600;">correggi l'indirizzo</a>
                    se è sbagliato.
                </div>
            </div>
        </div>
    @endif

    @if($errors->has('general'))
        <div class="banner banner-required">
            <span class="banner-icon">⚠️</span>
            <div>{{ $errors->first('general') }}</div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h1>📜 Contratto di Adesione al Circuito KMoney</h1>
            <p>
                @if($isResign ?? false)
                    Versione {{ $contractVer }} — testo aggiornato, da firmare di nuovo con codice OTP via email
                @else
                    Versione {{ $contractVer }} — firma digitale con codice OTP via email
                @endif
            </p>
        </div>
        <div class="card-body">

            {{-- Step progress --}}
            <div class="steps">
                <div class="step {{ $otpPending ? 'done' : 'active' }}">
                    <div class="step-dot">{{ $otpPending ? '✓' : '1' }}</div>
                    <span class="step-label">Leggi il<br>contratto</span>
                </div>
                <div class="step {{ $otpPending ? 'active' : 'pending' }}">
                    <div class="step-dot">2</div>
                    <span class="step-label">Ricevi<br>OTP email</span>
                </div>
                <div class="step pending">
                    <div class="step-dot">3</div>
                    <span class="step-label">Conferma<br>e firma</span>
                </div>
            </div>

            {{-- Dati azienda personalizzati --}}
            @if($company)
            <div class="company-header">
                <h3>📋 Dati dell'Aderente — compilati automaticamente dalla tua registrazione</h3>
                <div class="company-grid">
                    <div class="company-field span2">
                        <div class="label">Ragione Sociale</div>
                        <div class="value {{ $company->name ? '' : 'empty' }}">{{ $company->name ?: 'Non compilato' }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Settore / Attività</div>
                        <div class="value {{ $company->sector ? '' : 'empty' }}">{{ $company->sector ?: 'Non compilato' }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Rappresentante legale</div>
                        <div class="value">{{ $user->name }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Partita IVA</div>
                        <div class="value {{ $company->vat_number ? '' : 'empty' }}">{{ $company->vat_number ?: '—' }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Codice Fiscale</div>
                        <div class="value {{ $company->fiscal_code ? '' : 'empty' }}">{{ $company->fiscal_code ?: '—' }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Città</div>
                        <div class="value {{ $company->city ? '' : 'empty' }}">{{ $company->city ?: '—' }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Telefono</div>
                        <div class="value {{ $company->phone ? '' : 'empty' }}">{{ $company->phone ?: '—' }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Email</div>
                        <div class="value">{{ $company->email ?: $user->email }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Sito Web</div>
                        <div class="value {{ $company->website ? '' : 'empty' }}">{{ $company->website ?: '—' }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Codice univoco</div>
                        <div class="value" style="font-family:monospace;font-size:13px;">{{ strtoupper(substr($company->uuid ?? 'N/D', 0, 8)) }}</div>
                    </div>
                    <div class="company-field">
                        <div class="label">Data firma</div>
                        <div class="value" style="color:#0f766e;">{{ now()->format('d/m/Y') }}</div>
                    </div>
                </div>
                <div style="margin-top:12px;font-size:12px;color:#94a3b8;">
                    Per aggiornare i dati, <a href="{{ route('onboarding.step1') }}" style="color:#0f766e;">modifica il profilo azienda</a>.
                </div>
            </div>
            @endif

            {{-- Testo contratto --}}
            <div class="contract-wrapper">
                <div class="contract-toolbar">
                    <span>📄 Contratto di Adesione al Circuito KMoney — versione {{ $contractVer }}</span>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <a href="{{ route('legal.contract') }}" target="_blank" style="font-size:12px;color:#0f766e;">Apri in nuova scheda ↗</a>
                        <button class="expand-btn" onclick="toggleExpand(this)">⤢ Espandi</button>
                    </div>
                </div>
                <div class="contract-body" id="contractBody">
                    {!! sanitize_html($contractHtml) !!}
                </div>
            </div>

            <hr class="divider">

            {{-- Sezione firma OTP --}}
            <div class="sign-section">
                <p class="sign-title">✍️ Firma digitale con OTP email</p>

                @if(! $otpPending)
                    <p class="sign-subtitle">
                        Dichiaro di aver letto e accettato integralmente il Contratto di Adesione, comprese le clausole specificamente approvate.<br>
                        Clicca il pulsante per ricevere un codice di conferma su <strong>{{ $otpEmail }}</strong>.
                    </p>
                    <p style="font-size:13px;color:#64748b;margin:-6px 0 16px;">
                        Non è il tuo indirizzo o c'è un errore di battitura?
                        <a href="{{ route('portal.email-change') }}" style="color:#0f766e;font-weight:600;">Cambia email</a>
                        prima di chiedere il codice.
                    </p>
                    <form method="POST" action="{{ route('portal.contract.send-otp') }}">
                        @csrf
                        <div class="actions">
                            <div>
                                <button type="submit" class="btn btn-primary">✉️ Invia codice OTP e firma</button>
                            </div>
                        </div>
                    </form>
                @else
                    <p class="sign-subtitle">
                        Inserisci il codice a 6 cifre ricevuto su <strong>{{ $otpEmail }}</strong>.
                    </p>
                    <form method="POST" action="{{ route('portal.contract.sign') }}" id="signForm">
                        @csrf
                        <div style="margin-bottom:16px;">
                            <label for="otp">Codice OTP</label>
                            <input
                                type="text"
                                id="otp"
                                name="otp"
                                maxlength="6"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                placeholder="000000"
                                class="{{ $errors->has('otp') ? 'is-invalid' : '' }}"
                                value="{{ old('otp') }}"
                                autofocus
                            >
                            @error('otp')
                                <div class="error-msg">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="actions">
                            <div>
                                <button type="submit" class="btn btn-primary" id="signBtn" disabled>
                                    ✅ Conferma e firma il contratto
                                </button>
                            </div>
                            <div style="font-size:13px;color:#94a3b8;line-height:1.7;">
                                Codice non ricevuto? Guarda anche nella posta indesiderata.
                                <form method="POST" action="{{ route('portal.contract.send-otp') }}" style="display:inline;">
                                    @csrf
                                    {{-- Il bottone resta spento per un minuto dall'invio: le
                                         richieste sono limitate a 3 ogni 10 minuti e bruciarle
                                         a vuoto porta a un 429 su una pagina senza uscita. --}}
                                    <button type="submit" id="resendBtn"
                                            data-wait="{{ $otpSentAt ? max(0, \App\Http\Controllers\ContractController::OTP_REINVIO_SECONDI - $otpSentAt->diffInSeconds(now())) : 0 }}"
                                            style="background:none;border:none;color:#0f766e;cursor:pointer;font-size:13px;padding:0;text-decoration:underline;">
                                        Invia di nuovo
                                    </button>
                                </form>
                                <br>
                                L'indirizzo <strong>{{ $otpEmail }}</strong> è sbagliato?
                                <a href="{{ route('portal.email-change') }}" style="color:#0f766e;">Cambialo qui</a>.
                            </div>
                        </div>
                    </form>
                @endif

                {{-- Rimanda (solo utenti esistenti non forzati) --}}
                @if($canPostpone)
                    <hr class="divider">
                    <div class="postpone-section">
                        <p>Preferisci firmare più tardi? Puoi rimandare a dopo (tornerà alla prossima sessione).</p>
                        <form method="POST" action="{{ route('portal.contract.postpone') }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">🕐 Ricordamelo più tardi</button>
                        </form>
                    </div>
                @endif
            </div>

        </div>
    </div>

    {{-- Link legali --}}
    <div style="text-align:center;font-size:13px;color:#94a3b8;">
        <a href="{{ route('legal.aml-kyc') }}" style="color:#0f766e;" target="_blank">Politica AML/KYC</a> &nbsp;·&nbsp;
        <a href="{{ route('legal.limits') }}" style="color:#0f766e;" target="_blank">Limiti Transazionali</a> &nbsp;·&nbsp;
        <a href="{{ route('legal.complaints') }}" style="color:#0f766e;" target="_blank">Procedura Reclami</a>
    </div>
</div>

<script>
// Auto-submit OTP a 6 cifre
const otpInput = document.getElementById('otp');
const signBtn  = document.getElementById('signBtn');
if (otpInput) {
    otpInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
        if (signBtn) signBtn.disabled = this.value.length < 6;
    });
}

// Reinvio OTP: countdown prima di riabilitare il bottone
const resendBtn = document.getElementById('resendBtn');
if (resendBtn) {
    let attesa = parseInt(resendBtn.dataset.wait || '0', 10);
    const etichetta = resendBtn.textContent.trim();
    const tick = () => {
        if (attesa <= 0) {
            resendBtn.disabled = false;
            resendBtn.style.opacity = '1';
            resendBtn.style.cursor = 'pointer';
            resendBtn.textContent = etichetta;
            return;
        }
        resendBtn.disabled = true;
        resendBtn.style.opacity = '.5';
        resendBtn.style.cursor = 'default';
        resendBtn.textContent = etichetta + ' (' + attesa + 's)';
        attesa -= 1;
        setTimeout(tick, 1000);
    };
    tick();
}

// Espandi/comprimi contratto
function toggleExpand(btn) {
    const body = document.getElementById('contractBody');
    if (body.style.maxHeight === 'none') {
        body.style.maxHeight = '460px';
        btn.textContent = '⤢ Espandi';
    } else {
        body.style.maxHeight = 'none';
        btn.textContent = '⤡ Comprimi';
    }
}
</script>
</body>
</html>
