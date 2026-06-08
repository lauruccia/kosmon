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

</div>
@endsection
