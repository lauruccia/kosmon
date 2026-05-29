@extends('layouts.portal')

@section('content')

<div class="portal-grid" style="--grid-cols:2;">

    {{-- Form --}}
    <div class="stack">
        <section class="card card-pad">
            <div class="k-tag" style="margin-bottom:20px;">Dettagli pagamento</div>

            @if(session('portal_error'))
                <div class="alert alert-error" style="margin-bottom:20px;">{{ session('portal_error') }}</div>
            @endif

            <form method="POST" action="{{ route('portal.payment-links.store') }}">
                @csrf

                {{-- Importo --}}
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" for="amount">Importo (KY)</label>
                    <div style="position:relative;">
                        <input
                            type="number"
                            id="amount"
                            name="amount"
                            class="form-control @error('amount') is-invalid @enderror"
                            placeholder="es. 250"
                            min="1"
                            max="9999999"
                            value="{{ old('amount') }}"
                            autofocus
                            style="font-size:28px;font-weight:700;padding:16px 56px 16px 16px;letter-spacing:-.5px;"
                            required
                        >
                        <span style="position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:18px;font-weight:700;color:var(--ink-muted);">KY</span>
                    </div>
                    @error('amount')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Causale --}}
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" for="description">Causale (opzionale)</label>
                    <input
                        type="text"
                        id="description"
                        name="description"
                        class="form-control @error('description') is-invalid @enderror"
                        placeholder="es. Fattura n. 42, Consulenza maggio..."
                        maxlength="200"
                        value="{{ old('description') }}"
                    >
                    @error('description')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Scadenza --}}
                <div class="form-group" style="margin-bottom:28px;">
                    <label class="form-label" for="expires_days">Scadenza</label>
                    <select
                        id="expires_days"
                        name="expires_days"
                        class="form-control @error('expires_days') is-invalid @enderror"
                        style="background:var(--surface-soft);"
                        required
                    >
                        <option value="1"  {{ old('expires_days', '7') === '1'  ? 'selected' : '' }}>24 ore</option>
                        <option value="3"  {{ old('expires_days', '7') === '3'  ? 'selected' : '' }}>3 giorni</option>
                        <option value="7"  {{ old('expires_days', '7') === '7'  ? 'selected' : '' }}>7 giorni</option>
                        <option value="30" {{ old('expires_days', '7') === '30' ? 'selected' : '' }}>30 giorni</option>
                        <option value="90" {{ old('expires_days', '7') === '90' ? 'selected' : '' }}>90 giorni</option>
                    </select>
                    @error('expires_days')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="cta" style="width:100%;justify-content:center;font-size:17px;padding:14px;">
                    Genera link &rarr;
                </button>

                <a href="{{ route('portal.payment-links.index') }}"
                   style="display:block;text-align:center;margin-top:14px;font-size:13px;color:var(--ink-muted);text-decoration:none;">
                    Annulla
                </a>
            </form>
        </section>
    </div>

    {{-- Info --}}
    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Come funziona</div>
            <h3 class="card-title" style="margin-top:12px;">Link, non QR a tempo</h3>
            <div style="margin-top:16px;display:grid;gap:16px;">
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;">1</div>
                    <div>
                        <strong style="display:block;margin-bottom:2px;">Imposta importo e scadenza</strong>
                        <span style="font-size:13px;color:var(--ink-muted);">Il link resta valido per tutta la durata scelta, da 24 ore fino a 90 giorni.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;">2</div>
                    <div>
                        <strong style="display:block;margin-bottom:2px;">Condividi via WhatsApp o email</strong>
                        <span style="font-size:13px;color:var(--ink-muted);">Il destinatario riceve un link cliccabile, niente app da scaricare.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:32px;height:32px;border-radius:50%;background:#059669;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;">3</div>
                    <div>
                        <strong style="display:block;margin-bottom:2px;">Pagamento in un tap</strong>
                        <span style="font-size:13px;color:var(--ink-muted);">Il pagatore accede al portale KMoney e conferma. Ricevi una notifica istantanea.</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card light-card card-pad">
            <div class="k-tag">Il tuo conto</div>
            <div style="margin-top:12px;">
                <div style="font-size:15px;font-weight:700;">{{ $account->company?->name ?? $account->display_name }}</div>
                <div style="font-size:12px;color:var(--ink-muted);font-family:monospace;margin-top:4px;">{{ $account->account_number }}</div>
            </div>
        </section>
    </div>

</div>
@endsection
