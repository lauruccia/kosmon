@extends('layouts.portal')

@section('content')

<div style="max-width:640px; margin:0 auto; padding:8px 0 40px;">

    {{-- Success / error --}}
    @if(session('portal_success'))
        <div style="background:var(--success-soft);border:1px solid #a7f3d0;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:var(--success);">
            {{ session('portal_success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="background:var(--danger-soft);border:1px solid #fecdd3;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:var(--danger);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Stato 2FA --}}
    <section class="card card-pad" style="margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:{{ $enabled ? 'var(--success-soft)' : 'var(--surface-soft)' }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                @if($enabled)
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#065f46" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <polyline points="9 12 11 14 15 10"/>
                    </svg>
                @else
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--ink-muted)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                @endif
            </div>
            <div>
                <div style="font-size:16px;font-weight:700;color:var(--ink);">Autenticazione a due fattori</div>
                <div style="font-size:13px;color:{{ $enabled ? 'var(--success)' : 'var(--ink-muted)' }};font-weight:600;margin-top:2px;">
                    {{ $enabled ? 'Attiva' : 'Non attiva' }}
                </div>
            </div>
        </div>

        @if($enabled)
            {{-- 2FA attiva: mostra opzione disattiva --}}
            <p style="font-size:14px;color:var(--ink-soft);margin-bottom:20px;">
                Il tuo account e protetto da Google Authenticator o app compatibile TOTP.
                Ad ogni accesso ti verra richiesto un codice OTP.
            </p>
            <form method="POST" action="{{ route('portal.2fa.disable') }}" id="disable-form">
                @csrf
                <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Password attuale (conferma)</label>
                <input
                    type="password"
                    name="password"
                    required
                    placeholder="La tua password"
                    style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;outline:none;"
                >
                @error('password') <div style="color:var(--danger);font-size:13px;margin-top:4px;">{{ $message }}</div> @enderror
                <button
                    type="submit"
                    style="margin-top:14px;padding:10px 18px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;"
                    onclick="return confirm('Sei sicuro di voler disattivare la 2FA?')"
                >
                    Disattiva 2FA
                </button>
            </form>

        @elseif($pendingSecret)
            {{-- Setup in corso: mostra QR --}}
            <p style="font-size:14px;color:var(--ink-soft);margin-bottom:20px;">
                Scansiona il QR code con la tua app (Google Authenticator, Authy, ecc.)
                poi inserisci il codice a 6 cifre per confermare l'attivazione.
            </p>

            <div style="text-align:center;margin-bottom:24px;">
                <div style="display:inline-block;padding:12px;background:#fff;border:2px solid var(--line);border-radius:12px;">
                    {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(180)->generate($qrUri) !!}
                </div>
                <div style="margin-top:10px;font-size:11px;color:var(--ink-muted);">O inserisci il codice manualmente:</div>
                <div style="font-family:monospace;font-size:14px;font-weight:700;letter-spacing:3px;color:var(--ink);margin-top:4px;">{{ $pendingSecret }}</div>
            </div>

            <form method="POST" action="{{ route('portal.2fa.confirm') }}" autocomplete="off">
                @csrf
                <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Codice OTP dall'app</label>
                <input
                    id="otp-input"
                    type="text"
                    name="code"
                    inputmode="numeric"
                    maxlength="6"
                    pattern="\d{6}"
                    placeholder="000000"
                    required
                    autofocus
                    style="width:100%;font-size:24px;font-weight:700;letter-spacing:6px;text-align:center;border:2px solid var(--line);border-radius:10px;padding:12px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;outline:none;"
                >
                @error('code') <div style="color:var(--danger);font-size:13px;margin-top:4px;">{{ $message }}</div> @enderror
                <button
                    type="submit"
                    style="width:100%;margin-top:14px;padding:12px;background:var(--primary);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;"
                >
                    Attiva 2FA
                </button>
            </form>

        @else
            {{-- 2FA non attiva, non in setup --}}
            <p style="font-size:14px;color:var(--ink-soft);margin-bottom:20px;">
                Con la 2FA attiva, dopo ogni accesso ti verra chiesto un codice temporaneo
                dall'app Google Authenticator (o simile). Aggiunge un secondo livello di
                protezione anche se la password viene compromessa.
            </p>
            <form method="POST" action="{{ route('portal.2fa.start') }}">
                @csrf
                <button type="submit" style="padding:12px 22px;background:var(--primary);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;">
                    Configura 2FA
                </button>
            </form>
        @endif
    </section>

    {{-- Recovery codes (visibile solo quando 2FA attiva) --}}
    @if($enabled)
    <section class="card card-pad" style="margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="width:40px;height:40px;border-radius:10px;background:#f0f9ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
            </div>
            <div>
                <div style="font-size:15px;font-weight:700;color:var(--ink);">Codici di recupero</div>
                <div style="font-size:13px;color:var(--ink-muted);margin-top:2px;">
                    @if($recoveryCodesCount > 0)
                        {{ $recoveryCodesCount }} {{ $recoveryCodesCount === 1 ? 'codice rimasto' : 'codici rimasti' }}
                    @else
                        <span style="color:var(--danger);font-weight:600;">Nessun codice disponibile</span>
                    @endif
                </div>
            </div>
        </div>
        <p style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
            Usa un codice di recupero per accedere se perdi l'accesso all'app di autenticazione.
            Ogni codice e monouso. Rigenera i codici se pensi che siano stati compromessi.
        </p>

        <details style="border:1px solid var(--line);border-radius:8px;padding:2px;">
            <summary style="padding:10px 14px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;list-style:none;display:flex;align-items:center;gap:6px;">
                Rigenera codici di recupero
            </summary>
            <form method="POST" action="{{ route('portal.2fa.regenerate-codes') }}" style="padding:14px;border-top:1px solid var(--line);">
                @csrf
                <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Password attuale (conferma)</label>
                <input
                    type="password"
                    name="password"
                    required
                    placeholder="La tua password"
                    style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;outline:none;"
                >
                <button
                    type="submit"
                    style="margin-top:12px;padding:9px 16px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;"
                    onclick="return confirm('I codici esistenti verranno invalidati. Continuare?')"
                >
                    Genera nuovi codici
                </button>
            </form>
        </details>
    </section>
    @endif

    {{-- ── Accesso biometrico (WebAuthn / Passkey) ───────────────────────────── --}}
    <section class="card card-pad" style="margin-bottom:20px;" id="webauthn-section">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#f0f9ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/>
                    <path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/>
                    <path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/>
                    <path d="M12 12v.01"/>
                </svg>
            </div>
            <div style="flex:1;">
                <div style="font-size:16px;font-weight:700;color:var(--ink);">Accesso con impronta (Passkey)</div>
                <div id="webauthn-badge" style="font-size:13px;color:var(--ink-muted);margin-top:2px;">Caricamento…</div>
            </div>
        </div>

        <p style="font-size:14px;color:var(--ink-soft);margin-bottom:20px;">
            Registra il tuo dispositivo per accedere con l'impronta digitale, Face ID o PIN del dispositivo,
            senza inserire la password ogni volta.
        </p>

        {{-- Lista dispositivi registrati --}}
        <div id="webauthn-credentials-list" style="margin-bottom:16px;"></div>

        {{-- Messaggio feedback --}}
        <div id="webauthn-msg" style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:10px;font-size:14px;font-weight:600;"></div>

        {{-- Nome dispositivo + bottone aggiungi --}}
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input
                id="webauthn-name"
                type="text"
                placeholder="Nome dispositivo (es. iPhone di Laura)"
                maxlength="60"
                style="flex:1;min-width:180px;border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:14px;background:var(--surface-soft);color:var(--ink);outline:none;"
            >
            <button
                id="webauthn-register-btn"
                type="button"
                style="padding:10px 18px;background:var(--primary);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;"
            >
                + Aggiungi dispositivo
            </button>
        </div>
    </section>
    {{-- ─────────────────────────────────────────────────────────────────────── --}}

    {{-- Contratto firmato --}}
    @if(auth()->user()->contract_signed_at)
    <section class="card card-pad" style="margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:22px;">
                &#x1F4DC;
            </div>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:2px;">Contratto di Adesione</div>
                <div style="font-size:13px;color:var(--ink-soft);">
                    Firmato il {{ auth()->user()->contract_signed_at->format('d/m/Y \\a\\l\\l\\e H:i') }}
                </div>
            </div>
            <a href="{{ route('portal.contract.view') }}"
               style="padding:8px 16px;background:var(--surface-soft);border:1px solid var(--line);border-radius:8px;font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;white-space:nowrap;">
                Visualizza &#x2192;
            </a>
        </div>
    </section>
    @endif


    {{-- Suggerimento app --}}
    @if(!$enabled)
    <section class="card card-pad" style="background:var(--surface-soft);border:1px solid var(--line);">
        <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:8px;">App consigliate</div>
        <div style="font-size:13px;color:var(--ink-soft);">Google Authenticator, Authy, 1Password, Bitwarden, o qualsiasi app compatibile TOTP (RFC 6238).</div>
    </section>
    @endif

    {{-- ── PIN di pagamento ──────────────────────────────────────────────────── --}}
    @php $pinThreshold = \App\Models\SystemSetting::userLimitDefaults()->payment_pin_threshold; @endphp
    @if($pinThreshold !== null)
    <section class="card card-pad" style="margin-top:20px;" id="pin-section">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:{{ auth()->user()->payment_pin_hash ? 'var(--success-soft)' : 'var(--surface-soft)' }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="{{ auth()->user()->payment_pin_hash ? '#065f46' : 'var(--ink-muted)' }}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                    <circle cx="12" cy="16" r="1" fill="currentColor"/>
                </svg>
            </div>
            <div>
                <div style="font-size:16px;font-weight:700;color:var(--ink);">PIN di pagamento</div>
                <div style="font-size:13px;color:{{ auth()->user()->payment_pin_hash ? 'var(--success)' : 'var(--ink-muted)' }};font-weight:600;margin-top:2px;">
                    {{ auth()->user()->payment_pin_hash ? 'Configurato' : 'Non configurato' }}
                </div>
            </div>
        </div>

        <p style="font-size:14px;color:var(--ink-soft);margin-bottom:20px;">
            Il PIN protegge i pagamenti superiori a <strong>{{ ky_format($pinThreshold) }} KY</strong>.
            Sotto questa soglia i pagamenti vengono confermati direttamente, senza richiesta di PIN.
        </p>

        {{-- Form imposta / cambia PIN --}}
        <details id="pin-set-details" style="border:1px solid var(--line);border-radius:10px;margin-bottom:12px;" {{ $errors->has('pin') ? 'open' : '' }}>
            <summary style="padding:12px 16px;font-size:14px;font-weight:600;color:var(--ink);cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;user-select:none;">
                <span>{{ auth()->user()->payment_pin_hash ? '🔄 Cambia PIN' : '➕ Imposta PIN' }}</span>
            </summary>
            <div style="padding:16px;border-top:1px solid var(--line);">
                <p style="font-size:13px;color:var(--ink-muted);margin-bottom:16px;">Inserisci un PIN di 6 cifre. Verrà richiesto per confermare i pagamenti sopra soglia.</p>

                {{-- Numpad PIN --}}
                <div style="display:flex;gap:10px;justify-content:center;margin-bottom:16px;" id="set-pin-dots">
                    @for($i = 0; $i < 6; $i++)
                    <div id="set-dot-{{ $i }}" style="width:14px;height:14px;border-radius:50%;border:2px solid var(--line);background:var(--surface-soft);transition:background .15s,border-color .15s;"></div>
                    @endfor
                </div>

                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;max-width:220px;margin:0 auto 16px;" id="set-numpad">
                    @foreach([1,2,3,4,5,6,7,8,9,'','0','⌫'] as $k)
                        @if($k === '')
                            <div></div>
                        @elseif($k === '⌫')
                            <button type="button" onclick="setPinBack()"
                                style="padding:14px;background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;font-size:18px;cursor:pointer;color:var(--ink);">⌫</button>
                        @else
                            <button type="button" onclick="setPinPress('{{ $k }}')"
                                style="padding:14px;background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;font-size:18px;font-weight:600;cursor:pointer;color:var(--ink);">{{ $k }}</button>
                        @endif
                    @endforeach
                </div>

                <form method="POST" action="{{ route('portal.invia.pin.imposta') }}" id="set-pin-form">
                    @csrf
                    <input type="hidden" id="set_pin" name="pin">
                    @error('pin')
                        <div style="color:var(--danger);font-size:13px;margin-bottom:10px;">{{ $message }}</div>
                    @enderror
                    <button type="submit" id="set-pin-submit-btn" disabled
                        style="width:100%;padding:12px;background:var(--primary);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;opacity:.5;transition:opacity .2s;">
                        Salva PIN
                    </button>
                </form>
            </div>
        </details>

        {{-- Rimuovi PIN (solo se configurato) --}}
        @if(auth()->user()->payment_pin_hash)
        <details style="border:1px solid #fecdd3;border-radius:10px;">
            <summary style="padding:12px 16px;font-size:14px;font-weight:600;color:var(--danger);cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;user-select:none;">
                <span>🗑 Rimuovi PIN</span>
            </summary>
            <div style="padding:16px;border-top:1px solid #fecdd3;">
                <p style="font-size:13px;color:var(--ink-muted);margin-bottom:14px;">Rimuovendo il PIN, i pagamenti sopra soglia non richiederanno più conferma aggiuntiva.</p>
                <form method="POST" action="{{ route('portal.invia.pin.rimuovi') }}" onsubmit="return confirm('Sei sicuro di voler rimuovere il PIN di pagamento?')">
                    @csrf
                    <button type="submit"
                        style="padding:10px 20px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">
                        Rimuovi PIN
                    </button>
                </form>
            </div>
        </details>
        @endif
    </section>
    @endif

</div>

@endsection

@push('scripts')
<script>
    // OTP auto-submit
    const otpInput = document.getElementById('otp-input');
    if (otpInput) {
        otpInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value.length === 6) this.closest('form').submit();
        });
    }

    // ── WebAuthn helpers ────────────────────────────────────────────────────────
    function b64urlToBuffer(b64url) {
        const b64    = b64url.replace(/-/g, '+').replace(/_/g, '/');
        const padded = b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=');
        return Uint8Array.from(atob(padded), c => c.charCodeAt(0)).buffer;
    }

    function bufferToB64url(buf) {
        const bytes = new Uint8Array(buf);
        let bin = '';
        bytes.forEach(b => bin += String.fromCharCode(b));
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    function wMsg(text, type) {
        const el = document.getElementById('webauthn-msg');
        el.textContent = text;
        el.style.cssText = type === 'ok'
            ? 'display:block;margin-bottom:14px;padding:10px 14px;border-radius:10px;font-size:14px;font-weight:600;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;'
            : 'display:block;margin-bottom:14px;padding:10px 14px;border-radius:10px;font-size:14px;font-weight:600;background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;';
    }

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // ── Carica lista dispositivi ────────────────────────────────────────────────
    async function loadCredentials() {
        try {
            const res  = await fetch('{{ route("webauthn.credentials") }}', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            const list = await res.json();
            const container = document.getElementById('webauthn-credentials-list');
            const badge     = document.getElementById('webauthn-badge');

            badge.textContent = list.length > 0
                ? list.length + ' ' + (list.length === 1 ? 'dispositivo registrato' : 'dispositivi registrati')
                : 'Nessun dispositivo registrato';
            badge.style.color = list.length > 0 ? 'var(--success, #166534)' : 'var(--ink-muted)';

            if (list.length === 0) {
                container.innerHTML = '';
                return;
            }

            container.innerHTML = list.map(c => `
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border:1px solid var(--line);border-radius:10px;margin-bottom:8px;background:var(--surface-soft);">
                    <div>
                        <div style="font-size:14px;font-weight:700;color:var(--ink);">${escapeHtml(c.name)}</div>
                        <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">
                            Aggiunto ${c.created_at} &nbsp;·&nbsp; Ultimo uso: ${c.last_used_at}
                        </div>
                    </div>
                    <button
                        onclick="deleteCredential(${c.id})"
                        style="padding:6px 12px;background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;"
                    >Rimuovi</button>
                </div>
            `).join('');
        } catch (e) {
            console.error('WebAuthn list error', e);
        }
    }

    function escapeHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Rimuovi dispositivo ─────────────────────────────────────────────────────
    async function deleteCredential(id) {
        if (!confirm('Rimuovere questo dispositivo? Dovrai usare la password per accedere.')) return;
        try {
            const res = await fetch(`/webauthn/credentials/${id}`, {
                method:  'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            if (res.ok) {
                wMsg('Dispositivo rimosso.', 'ok');
                loadCredentials();
            } else {
                const d = await res.json();
                wMsg(d.error || 'Errore durante la rimozione.', 'err');
            }
        } catch (e) {
            wMsg('Errore di rete.', 'err');
        }
    }

    // ── Registra nuovo dispositivo ─────────────────────────────────────────────
    document.getElementById('webauthn-register-btn').addEventListener('click', async () => {
        const btn  = document.getElementById('webauthn-register-btn');
        const name = document.getElementById('webauthn-name').value.trim()
                  || 'Dispositivo ' + new Date().toLocaleDateString('it-IT');

        btn.disabled    = true;
        btn.textContent = 'In attesa…';

        try {
            // 1. Ottieni le opzioni di creazione
            const optRes = await fetch('{{ route("webauthn.register.options") }}', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: '{}',
            });

            const optData = await optRes.json();
            if (!optRes.ok) { wMsg(optData.error || 'Errore nel recupero delle opzioni.', 'err'); return; }

            // 2. Decodifica i campi binari
            optData.challenge = b64urlToBuffer(optData.challenge);
            optData.user.id   = b64urlToBuffer(optData.user.id);
            if (optData.excludeCredentials) {
                optData.excludeCredentials = optData.excludeCredentials.map(c => ({
                    ...c, id: b64urlToBuffer(c.id),
                }));
            }

            // 3. Prompt biometrico del browser
            const credential = await navigator.credentials.create({ publicKey: optData });

            // 4. Codifica risposta
            const payload = {
                name,
                id:    credential.id,
                rawId: bufferToB64url(credential.rawId),
                type:  credential.type,
                response: {
                    clientDataJSON:  bufferToB64url(credential.response.clientDataJSON),
                    attestationObject: bufferToB64url(credential.response.attestationObject),
                },
            };

            // 5. Verifica sul server
            const verRes = await fetch('{{ route("webauthn.register.verify") }}', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(payload),
            });

            const verData = await verRes.json();
            if (verRes.ok) {
                wMsg('Dispositivo registrato con successo!', 'ok');
                document.getElementById('webauthn-name').value = '';
                loadCredentials();
            } else {
                wMsg(verData.error || 'Registrazione fallita.', 'err');
            }

        } catch (err) {
            if (err.name === 'NotAllowedError') {
                wMsg('Operazione annullata o non autorizzata.', 'err');
            } else if (err.name === 'NotSupportedError') {
                wMsg('Questo dispositivo non supporta le passkey.', 'err');
            } else {
                wMsg('Errore: ' + err.message, 'err');
            }
        } finally {
            btn.disabled    = false;
            btn.textContent = '+ Aggiungi dispositivo';
        }
    });


    // ── PIN di pagamento ────────────────────────────────────────────────────────
    (function () {
        const setPinDigits = [];

        function updateSetPinDots() {
            for (let i = 0; i < 6; i++) {
                const dot = document.getElementById('set-dot-' + i);
                if (!dot) return;
                dot.style.background     = i < setPinDigits.length ? 'var(--primary)' : 'var(--surface-soft)';
                dot.style.borderColor    = i < setPinDigits.length ? 'var(--primary)' : 'var(--line)';
            }
            const submitBtn = document.getElementById('set-pin-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = setPinDigits.length < 6;
                submitBtn.style.opacity = setPinDigits.length >= 6 ? '1' : '.5';
            }
        }

        window.setPinPress = function (digit) {
            if (setPinDigits.length >= 6) return;
            setPinDigits.push(digit);
            updateSetPinDots();
        };

        window.setPinBack = function () {
            if (!setPinDigits.length) return;
            setPinDigits.pop();
            updateSetPinDots();
        };

        const setPinForm = document.getElementById('set-pin-form');
        if (setPinForm) {
            setPinForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (setPinDigits.length < 6) return;
                document.getElementById('set_pin').value = setPinDigits.join('');
                this.submit();
            });
        }
    })();

    // Carica la lista all'avvio
    loadCredentials();
</script>
@endpush
