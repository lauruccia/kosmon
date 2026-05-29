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

        <form method="POST" action="{{ route('portal.step-up.verify') }}">
            @csrf

            @if($has2fa)
            {{-- Se 2FA attivo: mostra sia OTP che password --}}
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
                    autofocus
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
                    {{ $has2fa ? '' : 'autofocus' }}
                >
            </div>

            <button type="submit" class="cta" style="width:100%;">
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

@if(!$has2fa)
{{-- Auto-focus password field --}}
<script>document.querySelector('input[name="password"]')?.focus();</script>
@endif
@endsection
