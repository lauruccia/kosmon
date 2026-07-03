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
        .contract-body h2 { font-size: .95rem; font-weight: 700; margin: 22px 0 8px; color: #0f766e; }
        .contract-body p  { margin: 0 0 12px; }
        .contract-body hr { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }
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
        @media (max-width: 600px) {
            .card-header, .card-body { padding: 18px 18px; }
            .contract-body { padding: 18px 18px; }
            input[type="text"] { max-width: 100%; }
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

    @if(session('otp_sent'))
        <div class="banner banner-otp-sent">
            <span class="banner-icon">✉️</span>
            <div>Codice OTP inviato a <strong>{{ session('otp_email') }}</strong> — valido 15 minuti.</div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h1>📜 Contratto di Nomina ad Agente KNM</h1>
            <p>Versione {{ $contractVer }} — la tua richiesta è stata approvata. Firma per attivare il tuo profilo agente.</p>
        </div>
        <div class="card-body">

            <div class="contract-wrapper">
                <div class="contract-toolbar">
                    <span>📄 Contratto di nomina ad agente — versione {{ $contractVer }}</span>
                    <button class="expand-btn" onclick="toggleExpand(this)">⤢ Espandi</button>
                </div>
                <div class="contract-body" id="contractBody">
                    {!! $contractHtml !!}
                </div>
            </div>

            <hr class="divider">

            <div class="sign-section">
                <p class="sign-title">✍️ Firma digitale con OTP email</p>

                @if(! session('otp_sent'))
                    <p class="sign-subtitle">
                        Dichiaro di aver letto e accettato integralmente il contratto di nomina ad agente KNM.<br>
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
                                    ✅ Conferma e firma il contratto
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
