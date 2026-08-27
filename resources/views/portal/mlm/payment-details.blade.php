@extends('layouts.portal')

@section('content')

{{-- Niente max-width (2026-08-27): la scheda si fermava a 560px e il resto
     della pagina restava bianco. I campi vanno in una griglia che si
     ridispone da sola, cosi' la larghezza viene usata senza allungare a
     dismisura le caselle. --}}
<div class="card card-pad">

    @if(! $detail)
        <div style="margin-bottom:20px;padding:10px 14px;border-radius:8px;background:rgba(0,0,0,.03);font-size:13px;color:var(--ink-muted);">
            Nessun dato bancario inserito. Compila il modulo per ricevere le liquidazioni EUR delle tue commissioni e bonus KNM.
        </div>
    @endif

    <form method="POST" action="{{ route('portal.mlm.payment-details.update') }}">
        @csrf

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:0 18px;">

        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Intestatario conto</label>
            <input type="text" value="{{ auth()->user()->name }}" disabled class="form-control" style="background:rgba(0,0,0,.03);color:var(--ink-muted);">
            <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;">L'intestatario è sempre il nome di registrazione del tuo account.</div>
        </div>

        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">IBAN *</label>
            <input type="text" name="iban"
                   value="{{ old('iban', $detail->iban ?? '') }}"
                   required maxlength="34" class="form-control" style="font-family:monospace;letter-spacing:.5px;"
                   placeholder="IT60X0542811101000000123456">
            @error('iban')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">BIC/SWIFT (opzionale)</label>
            <input type="text" name="bic_swift"
                   value="{{ old('bic_swift', $detail->bic_swift ?? '') }}"
                   maxlength="11" class="form-control" style="max-width:260px;font-family:monospace;">
            @error('bic_swift')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-group" style="margin-bottom:24px;">
            <label class="form-label">Banca (opzionale)</label>
            <input type="text" name="bank_name"
                   value="{{ old('bank_name', $detail->bank_name ?? '') }}"
                   maxlength="150" class="form-control">
            @error('bank_name')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        </div>

        <button type="submit" class="btn btn-primary">Salva dati bancari</button>
    </form>
</div>
@endsection
