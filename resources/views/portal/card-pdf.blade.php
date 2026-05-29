<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    @page {
        margin: 0;
        size: 85.6mm 53.98mm landscape;
    }

    html, body {
        width: 85.6mm;
        height: 53.98mm;
        overflow: hidden;
        font-family: DejaVu Sans, Arial, sans-serif;
    }

    .card {
        width: 85.6mm;
        height: 53.98mm;
        background: #1a1a2e;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: row;
        align-items: center;
        padding: 5mm 5mm 5mm 4mm;
        gap: 4mm;
    }

    /* Cerchi decorativi */
    .circle-top {
        position: absolute;
        top: -12mm;
        right: -8mm;
        width: 30mm;
        height: 30mm;
        border-radius: 50%;
        background: rgba(109, 40, 217, 0.45);
    }
    .circle-bottom {
        position: absolute;
        bottom: -8mm;
        left: -5mm;
        width: 20mm;
        height: 20mm;
        border-radius: 50%;
        background: rgba(109, 40, 217, 0.30);
    }
    .stripe {
        position: absolute;
        top: 0; right: 0;
        width: 28mm;
        height: 53.98mm;
        background: linear-gradient(180deg, #6d28d9 0%, #4c1d95 100%);
        opacity: 0.35;
    }

    /* Colonna QR */
    .qr-col {
        flex-shrink: 0;
        width: 26mm;
        height: 26mm;
        background: #ffffff;
        border-radius: 2mm;
        padding: 1.5mm;
        position: relative;
        z-index: 2;
    }
    .qr-col img {
        width: 100%;
        height: 100%;
    }
    .qr-placeholder {
        width: 100%;
        height: 100%;
        background: #f3f4f6;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 5pt;
        color: #6b7280;
        text-align: center;
    }

    /* Colonna testo */
    .info-col {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        z-index: 2;
        overflow: hidden;
    }

    .brand {
        font-size: 13pt;
        font-weight: 800;
        color: #ffffff;
        letter-spacing: 0.5pt;
        line-height: 1;
        margin-bottom: 1mm;
    }
    .brand-sub {
        font-size: 5pt;
        color: rgba(255,255,255,0.55);
        letter-spacing: 0.3pt;
        margin-bottom: 4mm;
    }

    .company-name {
        font-size: 8pt;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 1.5mm;
        white-space: nowrap;
        overflow: hidden;
    }

    .account-number {
        font-size: 7.5pt;
        font-family: DejaVu Sans Mono, Courier New, monospace;
        color: rgba(255,255,255,0.80);
        letter-spacing: 1pt;
        margin-bottom: 3mm;
    }

    .footer-row {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 3mm;
    }
    .currency-badge {
        font-size: 6pt;
        font-weight: 700;
        color: #1a1a2e;
        background: #a78bfa;
        border-radius: 1mm;
        padding: 0.5mm 2mm;
        letter-spacing: 0.5pt;
    }
    .circuit-label {
        font-size: 5.5pt;
        color: rgba(255,255,255,0.45);
    }
</style>
</head>
<body>
<div class="card">
    <!-- Elementi decorativi -->
    <div class="circle-top"></div>
    <div class="circle-bottom"></div>
    <div class="stripe"></div>

    <!-- QR Code -->
    <div class="qr-col">
        @if($qrDataUri)
            <img src="{{ $qrDataUri }}" alt="QR KMoney">
        @else
            <div class="qr-placeholder">QR<br>{{ $account->account_number }}</div>
        @endif
    </div>

    <!-- Info -->
    <div class="info-col">
        <div class="brand">KMoney</div>
        <div class="brand-sub">Circuito di credito reciproco</div>

        <div class="company-name">{{ $account->company?->name ?? $account->display_name }}</div>
        <div class="account-number">{{ chunk_split($account->account_number, 4, ' ') }}</div>

        <div class="footer-row">
            <span class="currency-badge">KY</span>
            <span class="circuit-label">Scansiona per pagare</span>
        </div>
    </div>
</div>
</body>
</html>
