@extends('layouts.portal')

@section('content')
<div style="max-width:440px;margin:60px auto;">
    <section class="card card-pad">

        <div style="text-align:center;margin-bottom:28px;">
            <div style="font-size:48px;line-height:1;margin-bottom:12px;">🔐</div>
            <h2 style="font-size:20px;font-weight:800;margin:0 0 8px;">Conferma identità</h2>
            <p style="font-size:14px;color:var(--text-muted);margin:0;">
                {{ $reason }}
            </p>
        </div>

        @if($errors->has('credential'))
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#b91c1c;">
                {{ $errors->first('credential') }}
            </div>
        @endif

        {{-- ── Opzione 1: Biometria/Passkey (se disponibile) ──────────────── --}}
        @if($hasPasskey)
        <div id="webauthn-confirm-block" style="margin-bottom:20px;">
            <button
                id="webauthn-confirm-btn"
                type="button"
                class="cta"
                style="width:100%;background:#0b2244;display:flex;align-items:center;justify-content:center;gap:10px;"
            >
                <span style="font-size:20px;">🪪</span>
                <span>Usa Face ID / Impronta digitale</span>
            </button>
            <p id="webauthn-confirm-error" style="display:none;font-size:12px;color:#b91c1c;margin-top:8px;text-align:center;"></p>
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <hr style="flex:1;border:none;border-top:1px solid var(--border);">
            <span style="font-size:11px;color:var(--text-muted);white-space:nowrap;">oppure con password / OTP</span>
            <hr style="flex:1;border:none;border-top:1px solid var(--border);">
        </div>
        @endif

        {{-- ── Opzione 2: OTP + Password ──────────────────────────────────── --}}
        <form method="POST" action="{{ route('portal.step-up.verify') }}">
            @csrf

            @if($has2fa)
            <div style="margin-bottom:18px;">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:6px;">
                    Codice autenticatore (6 cifre)
                </label>
                <input
                    type="text"
                    name="totp_code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    class="form-input"
                    placeholder="000000"
                    style="width:100%;text-align:center;font-size:22px;letter-spacing:6px;font-weight:700;"
                    {{ !$hasPasskey ? 'autofocus' : '' }}
                >
                <p style="font-size:12px;color:var(--text-muted);margin-top:6px;">
                    Oppure usa la tua password qui sotto se non hai l'app con te.
                </p>
            </div>
            @endif

            <div style="margin-bottom:22px;">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:6px;">
                    Password attuale
                </label>
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    class="form-input"
                    placeholder="La tua password"
                    style="width:100%;"
                    {{ (!$has2fa && !$hasPasskey) ? 'autofocus' : '' }}
                >
            </div>

            <button type="submit" class="cta" style="width:100%;background:var(--color-surface-2,#4a5568);">
                Conferma e continua
            </button>
        </form>

        <div style="margin-top:18px;text-align:center;">
            <a href="{{ route('portal.dashboard') }}" style="font-size:13px;color:var(--text-muted);">
                Annulla e torna alla dashboard
            </a>
        </div>

        <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted);">
            Per motivi di sicurezza questa verifica è richiesta prima di operazioni sensibili (cambio password,
            disattivazione 2FA, revoca token API). La sessione rimane autorizzata per
            {{ \App\Http\Middleware\RequireStepUp::STEP_UP_WINDOW_MINUTES }} minuti.
        </div>

    </section>
</div>

@if($hasPasskey)
<script>
(function () {
    var btn = document.getElementById('webauthn-confirm-btn');
    var errEl = document.getElementById('webauthn-confirm-error');

    function showError(msg) {
        errEl.textContent = msg;
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<span style="font-size:20px;">🪪</span><span>Usa Face ID / Impronta digitale</span>';
    }

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        btn.innerHTML = '<span>Attendere…</span>';
        errEl.style.display = 'none';

        try {
            // 1. Ottieni la challenge dal server
            const optRes = await fetch('{{ route('webauthn.confirm.options') }}', {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            });
            if (!optRes.ok) {
                const err = await optRes.json();
                showError(err.error || 'Errore nella preparazione della verifica.');
                return;
            }
            const options = await optRes.json();

            // 2. Converti i buffer base64url → ArrayBuffer
            function b64ToBuffer(b64) {
                var s = b64.replace(/-/g, '+').replace(/_/g, '/');
                while (s.length % 4) s += '=';
                var bin = atob(s), arr = new Uint8Array(bin.length);
                for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
                return arr.buffer;
            }
            function bufferToB64(buf) {
                return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
                    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
            }

            options.challenge = b64ToBuffer(options.challenge);
            if (options.allowCredentials) {
                options.allowCredentials = options.allowCredentials.map(c => ({
                    ...c, id: b64ToBuffer(c.id)
                }));
            }

            // 3. Richiedi autenticazione biometrica al browser
            const credential = await navigator.credentials.get({ publicKey: options });

            // 4. Serializza e invia al server
            const body = {
                id:    credential.id,
                rawId: bufferToB64(credential.rawId),
                type:  credential.type,
                response: {
                    authenticatorData: bufferToB64(credential.response.authenticatorData),
                    clientDataJSON:    bufferToB64(credential.response.clientDataJSON),
                    signature:         bufferToB64(credential.response.signature),
                    userHandle:        credential.response.userHandle
                        ? bufferToB64(credential.response.userHandle) : null,
                },
            };

            const verRes = await fetch('{{ route('webauthn.confirm.verify') }}', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify(body),
            });

            const result = await verRes.json();
            if (!verRes.ok || !result.verified) {
                showError(result.error || 'Verifica non riuscita. Riprova.');
                return;
            }

            // 5. Redirect all'URL di ritorno
            window.location.href = result.return_url;

        } catch (err) {
            if (err.name === 'NotAllowedError') {
                showError('Verifica annullata o non riuscita. Riprova oppure usa password/OTP.');
            } else {
                showError('Errore: ' + err.message);
            }
        }
    });
})();
</script>
@elseif(!$has2fa)
<script>document.querySelector('input[name="password"]')?.focus();</script>
@endif
@endsection
