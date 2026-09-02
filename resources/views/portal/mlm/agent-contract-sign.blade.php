<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firma Contratto Agente KNM — KMoney</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; min-height: 100vh; }
        .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .brand  { font-weight: 800; font-size: 1.15rem; color: #0f766e; text-decoration: none; }
        .topbar-user { font-size: 13px; color: #64748b; }
        .page { max-width: 860px; margin: 32px auto; padding: 0 20px 80px; }
        .banner { border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 14px; display: flex; gap: 12px; align-items: flex-start; }
        .banner-otp-sent  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .banner-required { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .banner-icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
        .card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #0e7490 100%); color: #fff; padding: 24px 32px; }
        .card-header h1 { margin: 0 0 4px; font-size: 1.35rem; font-weight: 800; }
        .card-header p  { margin: 0; font-size: 13px; opacity: .8; }
        .card-body { padding: 28px 32px; }
        .contract-wrapper { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 24px; }
        .contract-toolbar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; }
        .contract-toolbar span { font-size: 13px; color: #64748b; }
        .contract-body { padding: 28px 32px; max-height: 460px; overflow-y: auto; font-size: 14px; line-height: 1.75; }
        .contract-body h2 { font-size: 1.05rem; font-weight: 800; margin: 24px 0 8px; color: #0f766e; }
        .contract-body h3 { font-size: .95rem; font-weight: 700; margin: 20px 0 6px; color: #0f766e; }
        .contract-body h4 { font-size: .9rem; font-weight: 700; margin: 18px 0 6px; color: #334155; }
        .contract-body h5 { font-size: .85rem; font-weight: 700; margin: 16px 0 4px; color: #475569; text-transform: uppercase; }
        .contract-body p  { margin: 0 0 12px; }
        .contract-body hr { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }
        .contract-body ul, .contract-body ol { padding-left: 20px; margin: 0 0 12px; }
        .contract-body li { margin-bottom: 6px; }
        .contract-body table { width: 100%; border-collapse: collapse; margin: 8px 0 16px; font-size: 13px; }
        .contract-body table td, .contract-body table th { border: 1px solid #e2e8f0; padding: 6px 10px; }
        .expand-btn { font-size: 12px; color: #0f766e; border: 1px solid #0f766e; background: none; padding: 4px 10px; border-radius: 6px; cursor: pointer; }
        .sign-section { border-top: 1px solid #e2e8f0; padding-top: 24px; }
        .sign-title { font-size: 14px; font-weight: 700; color: #374151; margin: 0 0 6px; }
        .sign-subtitle { font-size: 13px; color: #64748b; margin: 0 0 18px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        input[type="text"] {
            width: 100%; max-width: 260px; padding: 12px 16px; border: 2px solid #cbd5e1; border-radius: 8px;
            font-size: 1.4rem; letter-spacing: .3em; text-align: center; font-weight: 700;
        }
        input[type="text"]:focus { outline: none; border-color: #0f766e; }
        input[type="text"].is-invalid { border-color: #ef4444; }
        .error-msg { color: #dc2626; font-size: 12.5px; margin-top: 5px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
        .btn-primary   { background: #0f766e; color: #fff; }
        .btn:disabled  { opacity: .45; cursor: not-allowed; }
        .actions { display: flex; flex-direction: column; gap: 14px; }
        .divider { border: none; border-top: 1px solid #e2e8f0; margin: 24px 0; }
        /* 2026-08-14 — form dei dati anagrafici mancanti che il contratto stampa.
           Regole scoped: gli input generici sopra sono tarati sul campo OTP. */
        .banner-data { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .banner-saved { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .data-note { font-size: 13px; color: #64748b; line-height: 1.6; margin: 0 0 20px; }
        .data-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px 20px; margin-bottom: 22px; }
        .data-grid .span-2 { grid-column: span 2; }
        .data-form input[type="text"], .data-form input[type="date"], .data-form input[type="tel"] {
            width: 100%; max-width: none; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 13.5px; letter-spacing: normal; text-align: left; font-weight: 500; font-family: inherit;
        }
        .data-form input:focus { outline: none; border-color: #0f766e; }
        .data-form input.is-invalid { border-color: #ef4444; }
        .data-form .upper { text-transform: uppercase; }
        .data-form label { font-size: 12.5px; }
        .locked-note { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 16px 18px; font-size: 13.5px; color: #475569; line-height: 1.6; }
        @media (max-width: 600px) {
            .card-header, .card-body { padding: 18px 18px; }
            .contract-body { padding: 18px 18px; }
            input[type="text"] { max-width: 100%; }
            .data-grid .span-2 { grid-column: span 1; }
        }
    </style>
</head>
<body>

<nav class="topbar">
    <a href="{{ route('home') }}" class="brand">KMoney</a>
    <span class="topbar-user">{{ $user->name }}</span>
</nav>

<div class="page">

    @if($errors->has('general'))
        <div class="banner banner-required">
            <span class="banner-icon">⚠️</span>
            <div>{{ $errors->first('general') }}</div>
        </div>
    @endif

    @if(session('agent_contract_required'))
        <div class="banner banner-required">
            <span class="banner-icon">✍️</span>
            <div>Prima di accedere al resto del portale devi leggere e firmare il contratto di nomina e le Direttive e Procedure Kosmos.</div>
        </div>
    @endif

    @if(session('data_saved'))
        <div class="banner banner-saved">
            <span class="banner-icon">✅</span>
            <div>Dati salvati: il contratto qui sotto è ora compilato con la tua anagrafica. Rileggilo e procedi con la firma.</div>
        </div>
    @endif

    @if(session('otp_sent'))
        <div class="banner banner-otp-sent">
            <span class="banner-icon">✉️</span>
            <div>Codice OTP inviato a <strong>{{ session('otp_email') }}</strong> — valido 15 minuti.</div>
        </div>
    @endif

    @if(! empty($missingFields))
        <div class="banner banner-data">
            <span class="banner-icon">📝</span>
            <div>
                Per firmare devi prima completare i dati che compaiono nel contratto:
                <strong>{{ implode(', ', $missingFields) }}</strong>.
            </div>
        </div>
    @endif

    {{-- 02/09/2026: anche questa pagina fa parte del percorso, e chi ci
         arriva deve vedere che e' l'ultimo passo — non un documento capitato
         li' per caso. --}}
    @include('portal.mlm._passi', ['passo' => 2])

    <div class="card">
        <div class="card-header">
            <h1>📜 Contratto di Nomina ad Agente KNM</h1>
            <p>Versione {{ $contractVer }} — la tua richiesta è stata approvata. Leggi entrambi i documenti e firma per attivare il tuo profilo agente.</p>
        </div>
        <div class="card-body">

            {{-- 2026-08-14: i dati anagrafici sono parte del modulo di adesione
                 (art. 19 D. Lgs. 114/98). Se mancano li chiediamo QUI, prima
                 di qualunque firma: il contratto sotto viene ricompilato con i
                 valori salvati e solo allora compare la sezione OTP. --}}
            @if(! empty($missingFields))
                <div class="sign-section" style="border-top:none;padding-top:0;margin-bottom:8px;">
                    <p class="sign-title">📝 Completa i tuoi dati per il contratto</p>
                    <p class="data-note">
                        Questi dati compaiono nel riquadro <em>“Dati del candidato Incaricato”</em> del contratto
                        qui sotto e vengono congelati nel documento firmato: devono quindi essere corretti e
                        completi prima della firma. Per legge l'incaricato di vendita deve avere almeno 18 anni
                        ed essere residente in Italia (art. 6 delle Condizioni Generali).
                    </p>

                    <form method="POST" action="{{ route('portal.mlm.agent-contract.data') }}" class="data-form">
                        @csrf
                        <div class="data-grid">
                            <div>
                                <label for="fiscal_code">Codice fiscale *</label>
                                <input type="text" id="fiscal_code" name="fiscal_code" required maxlength="16" minlength="16"
                                       class="upper {{ $errors->has('fiscal_code') ? 'is-invalid' : '' }}"
                                       value="{{ old('fiscal_code', $user->fiscal_code) }}" placeholder="RSSMRA85M01H501Z">
                                @error('fiscal_code')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="birth_date">Data di nascita *</label>
                                <input type="date" id="birth_date" name="birth_date" required
                                       class="{{ $errors->has('birth_date') ? 'is-invalid' : '' }}"
                                       value="{{ old('birth_date', $user->birth_date?->toDateString()) }}">
                                @error('birth_date')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="birth_place">Luogo di nascita *</label>
                                <input type="text" id="birth_place" name="birth_place" required maxlength="100"
                                       class="{{ $errors->has('birth_place') ? 'is-invalid' : '' }}"
                                       value="{{ old('birth_place', $user->birth_place) }}" placeholder="Roma">
                                @error('birth_place')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                            <div class="span-2">
                                <label for="residence_address">Indirizzo di residenza *</label>
                                <input type="text" id="residence_address" name="residence_address" required maxlength="190"
                                       class="{{ $errors->has('residence_address') ? 'is-invalid' : '' }}"
                                       value="{{ old('residence_address', $user->residence_address) }}" placeholder="Via Roma, 10">
                                @error('residence_address')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="residence_zip">CAP *</label>
                                <input type="text" id="residence_zip" name="residence_zip" required maxlength="10"
                                       class="{{ $errors->has('residence_zip') ? 'is-invalid' : '' }}"
                                       value="{{ old('residence_zip', $user->residence_zip) }}" placeholder="00100">
                                @error('residence_zip')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="residence_city">Comune di residenza *</label>
                                <input type="text" id="residence_city" name="residence_city" required maxlength="100"
                                       class="{{ $errors->has('residence_city') ? 'is-invalid' : '' }}"
                                       value="{{ old('residence_city', $user->residence_city) }}" placeholder="Roma">
                                @error('residence_city')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="residence_province">Provincia *</label>
                                <input type="text" id="residence_province" name="residence_province" required maxlength="2" minlength="2"
                                       class="upper {{ $errors->has('residence_province') ? 'is-invalid' : '' }}"
                                       value="{{ old('residence_province', $user->residence_province) }}" placeholder="RM">
                                @error('residence_province')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="phone">Telefono</label>
                                <input type="tel" id="phone" name="phone" maxlength="30"
                                       class="{{ $errors->has('phone') ? 'is-invalid' : '' }}"
                                       value="{{ old('phone', $user->phone) }}" placeholder="+39 333 1234567">
                                @error('phone')<div class="error-msg">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">💾 Salva i dati e prosegui</button>
                    </form>
                </div>

                <hr class="divider">
            @endif

            <div class="contract-wrapper">
                <div class="contract-toolbar">
                    <span>📄 Contratto di nomina ad agente — versione {{ $contractVer }}</span>
                    <button class="expand-btn" onclick="toggleExpand(this, 'contractBody')">⤢ Espandi</button>
                </div>
                <div class="contract-body" id="contractBody">
                    {!! sanitize_html($contractHtml) !!}
                </div>
            </div>

            <div class="contract-wrapper">
                <div class="contract-toolbar">
                    <span>📋 Direttive e Procedure Kosmos — versione {{ $directivesVer }}</span>
                    <button class="expand-btn" onclick="toggleExpand(this, 'directivesBody')">⤢ Espandi</button>
                </div>
                <div class="contract-body" id="directivesBody">
                    {!! sanitize_html($directivesHtml) !!}
                </div>
            </div>

            <hr class="divider">

            <div class="sign-section">
                <p class="sign-title">✍️ Firma digitale con OTP email</p>

                @if(! empty($missingFields))
                    {{-- Firma non disponibile finche' il modulo non e' completo:
                         sendOtp()/sign() rifiutano comunque lato server. --}}
                    <div class="locked-note">
                        🔒 La firma si sblocca dopo aver compilato i tuoi dati anagrafici nel riquadro in alto
                        (<strong>{{ implode(', ', $missingFields) }}</strong>). Il contratto verrà ricompilato
                        con i dati salvati e potrai firmarlo subito dopo.
                    </div>
                @elseif(! session('otp_sent'))
                    <p class="sign-subtitle">
                        Dichiaro di aver letto e accettato integralmente il contratto di nomina ad agente KNM e le Direttive e Procedure Kosmos riportate sopra.<br>
                        Clicca il pulsante per ricevere un codice di conferma su <strong>{{ $user->email }}</strong>.
                    </p>
                    <form method="POST" action="{{ route('portal.mlm.agent-contract.send-otp') }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">✉️ Invia codice OTP e firma</button>
                    </form>
                @else
                    <p class="sign-subtitle">
                        Inserisci il codice a 6 cifre ricevuto su <strong>{{ session('otp_email') }}</strong>.
                    </p>
                    <form method="POST" action="{{ route('portal.mlm.agent-contract.sign') }}" id="signForm">
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
                                    ✅ Conferma e firma entrambi i documenti
                                </button>
                            </div>
                            <div style="font-size:13px;color:#94a3b8;">
                                Codice non ricevuto?
                                <form method="POST" action="{{ route('portal.mlm.agent-contract.send-otp') }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" style="background:none;border:none;color:#0f766e;cursor:pointer;font-size:13px;padding:0;text-decoration:underline;">
                                        Invia di nuovo
                                    </button>
                                </form>
                            </div>
                        </div>
                    </form>
                @endif
            </div>

        </div>

        {{-- Rinuncia (01/09/2026). Prima stava SOLO sulla pagina della quota,
             cioe' spariva nel momento esatto in cui l'utente pagava: chi
             aveva saldato — o chi era stato esonerato — si trovava fermo su
             questa pagina senza nessun modo di dire "ho cambiato idea".
             Qui non si muove nessun soldo: se la quota era pagata resta
             pagata, e l'eventuale rimborso lo decide l'amministrazione. --}}
        <div style="text-align:center;margin-top:18px;padding-bottom:10px;">
            <form method="POST" action="{{ route('portal.mlm.agent-code-fee.give-up') }}"
                  onsubmit="return confirm('@if(auth()->user()?->agent_code_fee_paid_at)Rinunci a diventare agente KNM?\n\nHai gia\' saldato la quota per il codice agente: rinunciando NON ti viene restituita in automatico. Se ti spetta un rimborso, lo dispone l\'amministrazione del circuito.\n\nIl tuo conto torna pienamente operativo e potrai ricandidarti quando vorrai.@else Rinunci a diventare agente KNM? Il tuo conto tornera\' pienamente operativo e potrai ricandidarti quando vorrai.@endif');">
                @csrf
                <button type="submit" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:13px;text-decoration:underline;padding:6px;">
                    Non voglio pi&ugrave; diventare agente
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const otpInput = document.getElementById('otp');
const signBtn  = document.getElementById('signBtn');
if (otpInput) {
    otpInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
        if (signBtn) signBtn.disabled = this.value.length < 6;
    });
}
function toggleExpand(btn, bodyId) {
    const body = document.getElementById(bodyId);
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
