@extends('layouts.portal')

@section('content')
<style>
.receipt-wrap {
    max-width: 500px;
    margin: 0 auto;
    padding-bottom: 40px;
}

.receipt-success-icon {
    width: 72px; height: 72px; border-radius: 50%;
    background: var(--success-soft);
    border: 3px solid #6ee7b7;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px;
    margin: 0 auto 20px;
}

.receipt-heading {
    text-align: center;
    margin-bottom: 28px;
}
.receipt-heading h1 {
    font-size: 26px; font-weight: 900; color: var(--ink); margin: 0 0 6px;
}
.receipt-heading p {
    font-size: 14px; color: var(--ink-muted); margin: 0;
}

.receipt-card {
    background: var(--surface);
    border-radius: 20px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 16px;
}

.receipt-amount-band {
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    padding: 28px 24px;
    text-align: center;
    color: #fff;
}
.receipt-amount-band .r-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; opacity: .65; }
.receipt-amount-band .r-amount { font-size: 44px; font-weight: 900; line-height: 1.1; margin: 8px 0 2px; letter-spacing: -1px; }
.receipt-amount-band .r-ky { font-size: 20px; opacity: .7; }

.receipt-rows { padding: 8px 24px; }
.receipt-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 13px 0; border-bottom: 1px solid var(--line);
}
.receipt-row:last-child { border-bottom: none; }
.r-row-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-muted); }
.r-row-value { font-size: 14px; font-weight: 600; color: var(--ink); text-align: right; max-width: 65%; word-break: break-word; }

.receipt-ref {
    text-align: center;
    padding: 14px 24px;
    background: var(--surface-soft);
    border-top: 1px solid var(--line);
    font-size: 12px; color: var(--ink-muted);
    font-family: monospace;
}

.receipt-actions {
    display: flex; flex-direction: column; gap: 10px;
}
.receipt-actions .cta { justify-content: center; text-align: center; width: 100%; box-sizing: border-box; }

@media (min-width: 420px) {
    .receipt-actions { flex-direction: row; }
}
</style>

<div class="receipt-wrap">

    {{-- Icona successo ───────────────────────────────────────────────────── --}}
    <div class="receipt-heading">
        <div class="receipt-success-icon">✓</div>
        <h1>Pagamento inviato!</h1>
        <p>
            @php $date = $transfer->booked_at ?? $transfer->created_at; @endphp
            {{ $date?->format('d/m/Y H:i') ?? '—' }}
        </p>
    </div>

    {{-- Scheda ricevuta ─────────────────────────────────────────────────── --}}
    <div class="receipt-card">

        {{-- Banda importo --}}
        <div class="receipt-amount-band">
            <div class="r-label">Importo inviato</div>
            <div class="r-amount">{{ ky_format($transfer->amount) }} <span class="r-ky">KY</span></div>
        </div>

        {{-- Righe dettaglio --}}
        <div class="receipt-rows">
            <div class="receipt-row">
                <span class="r-row-label">A</span>
                <span class="r-row-value">{{ $counterparty?->display_name ?? '—' }}</span>
            </div>
            @if($counterparty?->ky_account_number)
            <div class="receipt-row">
                <span class="r-row-label">Conto</span>
                <span class="r-row-value" style="font-family:monospace;font-size:13px;">{{ $counterparty->ky_account_number }}</span>
            </div>
            @endif
            <div class="receipt-row">
                <span class="r-row-label">Da</span>
                <span class="r-row-value">{{ $currentAccount->display_name }}</span>
            </div>
            @if($transfer->description)
            <div class="receipt-row">
                <span class="r-row-label">Causale</span>
                <span class="r-row-value">{{ $transfer->description }}</span>
            </div>
            @endif
            @php
                $fee = $transfer->feeTransfers->where('status', 'booked')->first();
            @endphp
            @if($fee)
            <div class="receipt-row">
                <span class="r-row-label">Commissione</span>
                <span class="r-row-value" style="color:var(--danger);">{{ ky_format($fee->amount) }} KY</span>
            </div>
            @endif
            <div class="receipt-row">
                <span class="r-row-label">Stato</span>
                <span class="r-row-value" style="color:var(--success);font-weight:800;">
                    ✓ Contabilizzato
                </span>
            </div>
        </div>

        {{-- Riferimento --}}
        <div class="receipt-ref">
            Rif. {{ $transfer->reference }} · {{ $transfer->uuid }}
        </div>
    </div>

    {{-- Azioni ──────────────────────────────────────────────────────────── --}}
    <div class="receipt-actions">
        <a href="{{ route('portal.receipt.download', $transfer->uuid) }}" class="cta secondary" target="_blank">
            ↓ Scarica PDF
        </a>
        <a href="{{ route('portal.invia') }}" class="cta secondary">
            ↗ Invia ancora
        </a>
        <a href="{{ route('portal.dashboard') }}" class="cta">
            Dashboard →
        </a>
    </div>

    {{-- Condivisione WhatsApp (solo su pagamenti in uscita) ─────────────── --}}
    @if($isOutgoing)
    @php
        $waText = urlencode(
            'Ho inviato ' . ky_format($transfer->amount) . ' KY a ' . ($counterparty->display_name ?? 'un contatto')
            . ' tramite KosmoPay! 🟢'
        );
    @endphp
    <div style="text-align:center; margin-top:18px;">
        <a href="https://wa.me/?text={{ $waText }}" target="_blank" rel="noopener"
           style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:50px;
                  background:#25d366;color:#fff;font-size:14px;font-weight:600;text-decoration:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            Condividi su WhatsApp
        </a>
    </div>
    @endif

    {{-- Vibrazione haptic dopo pagamento riuscito ───────────────────────── --}}
    <script>
    if (navigator.vibrate) { navigator.vibrate([80, 40, 80]); }
    </script>

</div>
@endsection
