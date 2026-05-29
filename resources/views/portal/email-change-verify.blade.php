@extends('layouts.portal')

@section('content')
<div style="max-width:440px;margin:0 auto;padding:0 16px 48px;">

    <div style="margin-bottom:24px;">
        <div class="eyebrow">Sicurezza account</div>
        <h1 class="page-title">Verifica nuova email</h1>
        <p class="subtle">Abbiamo inviato un codice di 8 caratteri a <strong>{{ $currentUser->pending_email }}</strong>. Inseriscilo qui sotto.</p>
    </div>

    @if(session('info'))
        <div class="alert info" style="margin-bottom:16px;">{{ session('info') }}</div>
    @endif

    <section class="card card-pad">
        <form method="POST" action="{{ route('portal.email-change.verify') }}">
            @csrf

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label">Codice di verifica <span style="color:#dc2626;">*</span></label>
                <input type="text" name="token"
                       class="form-control @error('token') is-invalid @enderror"
                       maxlength="8" autocomplete="off" autofocus
                       placeholder="XXXXXXXX"
                       style="font-size:22px;letter-spacing:.15em;text-transform:uppercase;text-align:center;"
                       value="{{ old('token') }}">
                @error('token')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="subtle" style="margin-top:6px;font-size:12px;">Il codice scade entro 30 minuti.</div>
            </div>

            <button type="submit" class="cta" style="width:100%;">Conferma cambio email</button>
        </form>

        <div style="margin-top:16px;text-align:center;">
            <a href="{{ route('portal.email-change') }}" style="font-size:13px;color:var(--ink-soft);">← Torna indietro</a>
        </div>
    </section>
</div>
@endsection
