@extends('layouts.portal')

@section('content')
<div class="portal-grid" style="--grid-cols:2;">

    {{-- Form --}}
    <div class="stack">
        <section class="card card-pad">
            <div class="k-tag" style="margin-bottom:20px;">Nuova richiesta QR</div>

            @if(session('portal_error'))
                <div class="alert alert-error" style="margin-bottom:20px;">{{ session('portal_error') }}</div>
            @endif

            <form method="POST" action="{{ route('portal.incasso-qr.store') }}" id="qr-form">
                @csrf

                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" for="amount">Importo da incassare (KY)</label>
                    <div style="position:relative;">
                        <input
                            type="number"
                            id="amount"
                            name="amount"
                            class="form-control @error('amount') is-invalid @enderror"
                            placeholder="es. 150,00"
                            min="0.01"
                            step="0.01"
                            max="9999999"
                            value="{{ old('amount') }}"
                            autofocus
                            style="font-size:28px;font-weight:700;padding:16px 56px 16px 16px;letter-spacing:-.5px;"
                            required
                        >
                        <span style="position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:18px;font-weight:700;color:var(--text-muted);">KY</span>
                    </div>
                    @error('amount')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group" style="margin-bottom:28px;">
                    <label class="form-label" for="description">Causale (opzionale)</label>
                    <input
                        type="text"
                        id="description"
                        name="description"
                        class="form-control @error('description') is-invalid @enderror"
                        placeholder="es. Pranzo di lavoro, Consulenza maggio..."
                        maxlength="200"
                        value="{{ old('description') }}"
                    >
                    @error('description')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="cta" style="width:100%;justify-content:center;font-size:17px;padding:14px;">
                    Genera QR &rarr;
                </button>
            </form>
        </section>
    </div>

    {{-- Info --}}
    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Come funziona</div>
            <h3 class="card-title" style="margin-top:12px;">3 passi, 10 secondi</h3>
            <div style="margin-top:16px;display:grid;gap:16px;">
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;">1</div>
                    <div>
                        <strong style="display:block;margin-bottom:2px;">Inserisci l'importo</strong>
                        <span style="font-size:13px;color:var(--text-muted);">Digita quanto vuoi incassare e una causale opzionale.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;">2</div>
                    <div>
                        <strong style="display:block;margin-bottom:2px;">Mostra il QR al cliente</strong>
                        <span style="font-size:13px;color:var(--text-muted);">Viene generato un QR unico che scade in 10 minuti. Il cliente lo scansiona con la fotocamera.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:32px;height:32px;border-radius:50%;background:#059669;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;">3</div>
                    <div>
                        <strong style="display:block;margin-bottom:2px;">Pagamento istantaneo</strong>
                        <span style="font-size:13px;color:var(--text-muted);">Il cliente conferma in un tap. Il tuo schermo si aggiorna in tempo reale con la notifica di pagamento.</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card light-card card-pad">
            <div class="k-tag">Il tuo conto</div>
            <div style="margin-top:12px;">
                <div style="font-size:15px;font-weight:700;">{{ $account->company?->name ?? $account->display_name }}</div>
                <div style="font-size:12px;color:var(--text-muted);font-family:monospace;margin-top:4px;">{{ $account->account_number }}</div>
                <div style="margin-top:10px;font-size:13px;color:var(--text-muted);">
                    Saldo disponibile: <strong style="color:var(--text);">{{ ky_format($account->saldoDisponibile()) }} KY</strong>
                </div>
            </div>
        </section>
    </div>

</div>
@endsection
