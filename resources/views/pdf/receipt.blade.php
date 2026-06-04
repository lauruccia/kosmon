<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; background: #fff; }

  /* Header */
  .header { border-bottom: 3px solid #3d5566; padding-bottom: 18px; margin-bottom: 28px; }
  .header-top { display: flex; justify-content: space-between; align-items: flex-start; }
  .brand-name { font-size: 24px; font-weight: 700; color: #3d5566; letter-spacing: -0.5px; }
  .brand-sub { font-size: 10px; color: #7a9098; margin-top: 2px; }
  .receipt-title { text-align: right; }
  .receipt-title h2 { font-size: 18px; font-weight: 700; color: #1a1a2e; }
  .receipt-title .ref { font-size: 11px; color: #6b7280; margin-top: 4px; font-family: monospace; }
  .receipt-title .date { font-size: 11px; color: #6b7280; margin-top: 2px; }

  /* Status badge */
  .status-bar { margin-bottom: 24px; padding: 14px 20px; border-radius: 8px; display: flex; align-items: center; gap: 12px; }
  .status-bar.booked { background: #d1fae5; border: 1px solid #6ee7b7; }
  .status-bar.pending { background: #fef3c7; border: 1px solid #fcd34d; }
  .status-bar.cancelled { background: #fee2e2; border: 1px solid #fca5a5; }
  .status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .status-dot.booked { background: #059669; }
  .status-dot.pending { background: #d97706; }
  .status-dot.cancelled { background: #dc2626; }
  .status-text { font-size: 12px; font-weight: 700; }
  .status-text.booked { color: #065f46; }
  .status-text.pending { color: #92400e; }
  .status-text.cancelled { color: #991b1b; }

  /* Amount hero */
  .amount-hero { text-align: center; padding: 32px; background: #f8fafc; border-radius: 12px; margin-bottom: 28px; border: 1px solid #e2e8f0; }
  .amount-label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; margin-bottom: 8px; }
  .amount-value { font-size: 40px; font-weight: 800; letter-spacing: -1px; }
  .amount-value.outgoing { color: #dc2626; }
  .amount-value.incoming { color: #059669; }
  .amount-sign { font-size: 28px; }
  .amount-currency { font-size: 20px; font-weight: 600; color: #6b7280; margin-left: 6px; }

  /* Details grid */
  .details-grid { display: flex; gap: 16px; margin-bottom: 28px; }
  .detail-card { flex: 1; padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
  .detail-card .dc-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; font-weight: 700; margin-bottom: 6px; }
  .detail-card .dc-value { font-size: 13px; font-weight: 700; color: #1a1a2e; }
  .detail-card .dc-sub { font-size: 10px; color: #6b7280; margin-top: 3px; }

  /* Table */
  .info-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
  .info-table th { font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; font-weight: 700; padding: 8px 12px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; text-align: left; }
  .info-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 11px; color: #374151; vertical-align: top; }
  .info-table td.label { font-weight: 600; color: #6b7280; width: 42%; }
  .info-table td.value { font-weight: 600; color: #1a1a2e; }
  .mono { font-family: monospace; font-size: 10px; }

  /* Kind badge */
  .kind-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: #ede9fe; color: #5b21b6; }

  /* Footer */
  .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: flex-end; }
  .footer-left { font-size: 9px; color: #9ca3af; }
  .footer-right { font-size: 9px; color: #9ca3af; text-align: right; }
  .watermark { text-align: center; margin-top: 20px; }
  .watermark .wm-text { font-size: 9px; color: #d1d5db; text-transform: uppercase; letter-spacing: 2px; }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div class="header-top">
    <div>
      <div class="brand-name">KMoney</div>
      <div class="brand-sub">Circuito Kosmos &mdash; KNM S.R.L.</div>
    </div>
    <div class="receipt-title">
      <h2>Ricevuta di Pagamento</h2>
      <div class="ref">{{ $transfer->reference }}</div>
      <div class="date">Emessa il {{ $generatedAt->format('d/m/Y \a\l\l\e H:i') }}</div>
    </div>
  </div>
</div>

<!-- Status -->
@php
  $statusMap = ['booked' => 'Completato', 'pending' => 'In attesa', 'cancelled' => 'Annullato'];
  $statusLabel = $statusMap[$transfer->status] ?? ucfirst($transfer->status);
@endphp
<div class="status-bar {{ $transfer->status }}">
  <div class="status-dot {{ $transfer->status }}"></div>
  <span class="status-text {{ $transfer->status }}">Stato: {{ $statusLabel }}</span>
</div>

<!-- Amount hero -->
<div class="amount-hero">
  <div class="amount-label">{{ $isOutgoing ? 'Importo inviato' : 'Importo ricevuto' }}</div>
  <div class="amount-value {{ $isOutgoing ? 'outgoing' : 'incoming' }}">
    <span class="amount-sign">{{ $isOutgoing ? '−' : '+' }}</span>{{ ky_format($transfer->amount) }}<span class="amount-currency">KY</span>
  </div>
</div>

<!-- Parti coinvolte -->
<div class="details-grid">
  <div class="detail-card">
    <div class="dc-label">Mittente</div>
    <div class="dc-value">{{ $transfer->fromAccount->company->name ?? ($transfer->fromAccount->ownerUser->name ?? 'N/D') }}</div>
    @if($transfer->fromAccount->company)
      <div class="dc-sub">{{ $transfer->fromAccount->company->vat_number ?? '' }}</div>
    @endif
  </div>
  <div class="detail-card">
    <div class="dc-label">Destinatario</div>
    <div class="dc-value">{{ $transfer->toAccount->company->name ?? ($transfer->toAccount->ownerUser->name ?? 'N/D') }}</div>
    @if($transfer->toAccount->company)
      <div class="dc-sub">{{ $transfer->toAccount->company->vat_number ?? '' }}</div>
    @endif
  </div>
</div>

<!-- Dettagli transazione -->
<table class="info-table">
  <thead>
    <tr><th colspan="2">Dettagli transazione</th></tr>
  </thead>
  <tbody>
    <tr>
      <td class="label">Numero riferimento</td>
      <td class="value mono">{{ $transfer->reference }}</td>
    </tr>
    <tr>
      <td class="label">UUID transazione</td>
      <td class="value mono">{{ $transfer->uuid }}</td>
    </tr>
    <tr>
      <td class="label">Data e ora</td>
      <td class="value">{{ $transfer->booked_at ? $transfer->booked_at->format('d/m/Y \a\l\l\e H:i:s') : '—' }}</td>
    </tr>
    <tr>
      <td class="label">Valuta</td>
      <td class="value">{{ $transfer->currency_code ?? 'KY' }} &mdash; Crediti Kosmos</td>
    </tr>
    <tr>
      <td class="label">Importo</td>
      <td class="value" style="font-size:14px;font-weight:800;">{{ ky_format($transfer->amount) }} KY</td>
    </tr>
    <tr>
      <td class="label">Tipologia</td>
      <td class="value"><span class="kind-badge">{{ $transfer->kind ?? 'transfer' }}</span></td>
    </tr>
    @if($transfer->description)
    <tr>
      <td class="label">Causale</td>
      <td class="value">{{ $transfer->description }}</td>
    </tr>
    @endif
  </tbody>
</table>

<!-- Footer -->
<div class="footer">
  <div class="footer-left">
    Documento generato automaticamente da KMoney &mdash; KNM S.R.L.<br>
    P.IVA 13273091002 &mdash; info@kosmomoney.com
  </div>
  <div class="footer-right">
    Ricevuta #{!! $transfer->reference !!}<br>
    Generata il {{ $generatedAt->format('d/m/Y H:i') }}
  </div>
</div>

<div class="watermark">
  <div class="wm-text">Documento ufficiale KMoney &mdash; Circuito Kosmos</div>
</div>

</body>
</html>
