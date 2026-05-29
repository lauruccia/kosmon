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

    {{-- Suggerimento app --}}
    @if(!$enabled)
    <section class="card card-pad" style="background:var(--surface-soft);border:1px solid var(--line);">
        <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:8px;">App consigliate</div>
        <div style="font-size:13px;color:var(--ink-soft);">Google Authenticator, Authy, 1Password, Bitwarden, o qualsiasi app compatibile TOTP (RFC 6238).</div>
    </section>
    @endif

</div>

@endsection

@push('scripts')
<script>
    const otpInput = document.getElementById('otp-input');
    if (otpInput) {
        otpInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value.length === 6) this.closest('form').submit();
        });
    }
</script>
@endpush
