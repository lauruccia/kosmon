@extends('layouts.portal')

@section('content')
<div style="max-width:520px;margin:0 auto;padding:0 16px 48px;">

    <div style="margin-bottom:24px;">
        <div class="eyebrow">Sicurezza account</div>
        <h1 class="page-title">Cambia email</h1>
        <p class="subtle">Ti invieremo un codice di verifica al nuovo indirizzo. La modifica richiede la password attuale.</p>
    </div>

    @if(session('success'))
        <div class="alert success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif
    @if(session('info'))
        <div class="alert info" style="margin-bottom:16px;">{{ session('info') }}</div>
    @endif

    @if($hasPending)
        <div class="alert" style="margin-bottom:20px;border-left:4px solid var(--amber,#f59e0b);background:rgba(245,158,11,.08);padding:14px 16px;border-radius:8px;">
            <strong>Verifica in attesa</strong> — Hai una richiesta di cambio email in attesa di conferma.
            <a href="{{ route('portal.email-change.verify-form') }}" class="cta" style="margin-top:10px;display:inline-flex;">Inserisci codice →</a>
            <form method="POST" action="{{ route('portal.email-change.cancel') }}" style="display:inline;">
                @csrf @method('DELETE')
                <button type="submit" class="cta secondary" style="margin-top:6px;margin-left:8px;">Annulla richiesta</button>
            </form>
        </div>
    @endif

    <section class="card card-pad">
        <form method="POST" action="{{ route('portal.email-change.request') }}">
            @csrf

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Email attuale</label>
                <input type="email" class="form-control" value="{{ $currentUser->email }}" disabled style="opacity:.6;">
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Nuova email <span style="color:#dc2626;">*</span></label>
                <input type="email" name="new_email" class="form-control @error('new_email') is-invalid @enderror"
                       value="{{ old('new_email') }}" required autocomplete="off">
                @error('new_email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label">Password attuale <span style="color:#dc2626;">*</span></label>
                <input type="password" name="current_password" class="form-control @error('current_password') is-invalid @enderror" required>
                @error('current_password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="cta">Invia codice di verifica</button>
        </form>
    </section>
</div>
@endsection
