<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 10.5px;
    color: #1a1a2e;
    background: #ffffff;
    padding: 36px 42px;
  }

  /* ── TOP BAND ── */
  .top-band {
    background: #1a2e3b;
    margin: -36px -42px 0 -42px;
    padding: 0 42px;
    height: 6px;
  }

  /* ── HEADER ── */
  .header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 24px 0 20px 0;
    border-bottom: 1.5px solid #1a2e3b;
    margin-bottom: 24px;
  }
  .brand-block {}
  .brand-name {
    font-size: 22px;
    font-weight: 800;
    color: #1a2e3b;
    letter-spacing: -0.5px;
  }
  .brand-tagline {
    font-size: 9px;
    color: #7a9098;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin-top: 3px;
  }
  .brand-legal {
    font-size: 8.5px;
    color: #9ca3af;
    margin-top: 6px;
    line-height: 1.5;
  }
  .doc-block { text-align: right; }
  .doc-type {
    font-size: 15px;
    font-weight: 700;
    color: #1a2e3b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .doc-ref {
    font-family: "DejaVu Sans Mono", monospace;
    font-size: 11px;
    color: #3d5566;
    font-weight: 700;
    margin-top: 5px;
    letter-spacing: 0.3px;
  }
  .doc-date { font-size: 9px; color: #6b7280; margin-top: 4px; }

  /* ── STATUS STRIP ── */
  .status-strip {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    margin-bottom: 22px;
    border-radius: 4px;
    border-left: 4px solid transparent;
  }
  .status-strip.booked    { background: #f0fdf4; border-color: #16a34a; }
  .status-strip.pending   { background: #fffbeb; border-color: #d97706; }
  .status-strip.cancelled { background: #fef2f2; border-color: #dc2626; }
  .status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .status-dot.booked    { background: #16a34a; }
  .status-dot.pending   { background: #d97706; }
  .status-dot.cancelled { background: #dc2626; }
  .status-label { font-size: 10px; font-weight: 700; }
  .status-label.booked    { color: #15803d; }
  .status-label.pending   { color: #b45309; }
  .status-label.cancelled { color: #b91c1c; }
  .status-sublabel { font-size: 9px; color: #6b7280; margin-left: auto; }

  /* ── AMOUNT PANEL ── */
  .amount-panel {
    border: 1.5px solid #1a2e3b;
    border-radius: 6px;
    padding: 20px 28px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .amount-left {}
  .amount-lbl {
    font-size: 8.5px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #6b7280;
    font-weight: 700;
    margin-bottom: 6px;
  }
  .amount-figure { display: flex; align-items: baseline; gap: 6px; }
  .amount-sign { font-size: 30px; font-weight: 300; line-height: 1; }
  .amount-sign.outgoing { color: #dc2626; }
  .amount-sign.incoming { color: #16a34a; }
  .amount-number { font-size: 38px; font-weight: 800; letter-spacing: -1.5px; line-height: 1; }
  .amount-number.outgoing { color: #dc2626; }
  .amount-number.incoming { color: #16a34a; }
  .amount-currency {
    font-size: 16px;
    font-weight: 600;
    color: #6b7280;
    letter-spacing: 0.5px;
  }
  .amount-right { text-align: right; }
  .amount-right .ar-label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 3px; }
  .amount-right .ar-val { font-size: 10.5px; font-weight: 700; color: #374151; }

  /* ── PARTIES ROW ── */
  .parties-row {
    display: flex;
    gap: 0;
    margin-bottom: 24px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
  }
  .party-cell {
    flex: 1;
    padding: 14px 18px;
    background: #f8fafc;
  }
  .party-cell:first-child {
    border-right: 1px solid #e2e8f0;
  }
  .party-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 12px;
    background: #f8fafc;
    border-right: 1px solid #e2e8f0;
    color: #9ca3af;
    font-size: 16px;
  }
  .party-role {
    font-size: 8px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #9ca3af;
    font-weight: 700;
    margin-bottom: 5px;
  }
  .party-name { font-size: 12px; font-weight: 800; color: #1a2e3b; margin-bottom: 3px; }
  .party-vat  { font-size: 9px; color: #6b7280; margin-bottom: 2px; }
  .party-acct {
    font-family: "DejaVu Sans Mono", monospace;
    font-size: 9.5px;
    font-weight: 700;
    color: #3d5566;
    background: #e8f0f5;
    display: inline-block;
    padding: 2px 7px;
    border-radius: 3px;
    margin-top: 4px;
    letter-spacing: 0.5px;
  }

  /* ── DETAIL TABLE ── */
  .section-title {
    font-size: 8px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #9ca3af;
    font-weight: 700;
    margin-bottom: 6px;
    padding-left: 2px;
  }
  .detail-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 24px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
  }
  .detail-table tr:last-child td { border-bottom: none; }
  .detail-table td {
    padding: 9px 14px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 10.5px;
    vertical-align: middle;
  }
  .detail-table tr:nth-child(even) td { background: #fafbfc; }
  .detail-table td.lbl {
    color: #6b7280;
    font-weight: 600;
    width: 38%;
  }
  .detail-table td.val {
    color: #1a2e3b;
    font-weight: 700;
  }
  .mono {
    font-family: "DejaVu Sans Mono", monospace;
    font-size: 9.5px;
    letter-spacing: 0.3px;
  }
  .kind-pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 3px;
    font-size: 8.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    background: #ede9fe;
    color: #5b21b6;
  }
  .amount-inline { font-size: 13px; font-weight: 800; }

  /* ── LEGAL BOX ── */
  .legal-box {
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 11px 14px;
    background: #f8fafc;
    margin-bottom: 28px;
  }
  .legal-box p {
    font-size: 8.5px;
    color: #9ca3af;
    line-height: 1.6;
  }

  /* ── FOOTER ── */
  .footer {
    border-top: 1.5px solid #1a2e3b;
    padding-top: 14px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
  }
  .footer-left { font-size: 8.5px; color: #6b7280; line-height: 1.6; }
  .footer-right { font-size: 8.5px; color: #6b7280; text-align: right; line-height: 1.6; }
  .footer-brand { font-size: 10px; font-weight: 800; color: #1a2e3b; }

  /* ── BOTTOM BAND ── */
  .bottom-band {
    background: #1a2e3b;
    margin: 28px -42px -36px -42px;
    height: 4px;
  }
</style>
</head>
<body>

<div class="top-band"></div>

<!-- ── HEADER ── -->
<div class="header">
  <div class="brand-block">
    <div class="brand-name">KMoney</div>
    <div class="brand-tagline">Circuito Monetario Locale</div>
    <div class="brand-legal">
      KNM S.R.L. &mdash; P.IVA 13273091002<br>
      info@kosmomoney.com
    </div>
  </div>
  <div class="doc-block">
    <div class="doc-type">Ricevuta di Pagamento</div>
    <div class="doc-ref">{{ $transfer->reference }}</div>
    <div class="doc-date">Emessa il {{ $generatedAt->format('d/m/Y \a\l\l\e H:i') }}</div>
  </div>
</div>

<!-- ── STATUS ── -->
@php
  $statusMap = ['booked' => 'Pagamento completato', 'pending' => 'In attesa di conferma', 'cancelled' => 'Annullato'];
  $statusLabel = $statusMap[$transfer->status] ?? ucfirst($transfer->status);
  $bookedAt = $transfer->booked_at ? $transfer->booked_at->format('d/m/Y \a\l\l\e H:i:s') : null;
@endphp
<div class="status-strip {{ $transfer->status }}">
  <div class="status-dot {{ $transfer->status }}"></div>
  <span class="status-label {{ $transfer->status }}">{{ $statusLabel }}</span>
  @if($bookedAt)
    <span class="status-sublabel">{{ $bookedAt }}</span>
  @endif
</div>

<!-- ── IMPORTO ── -->
<div class="amount-panel">
  <div class="amount-left">
    <div class="amount-lbl">{{ $isOutgoing ? 'Importo addebitato' : 'Importo accreditato' }}</div>
    <div class="amount-figure">
      <span class="amount-sign {{ $isOutgoing ? 'outgoing' : 'incoming' }}">{{ $isOutgoing ? '−' : '+' }}</span>
      <span class="amount-number {{ $isOutgoing ? 'outgoing' : 'incoming' }}">{{ ky_format($transfer->amount) }}</span>
      <span class="amount-currency">KY</span>
    </div>
  </div>
  <div class="amount-right">
    <div class="ar-label">Rif. transazione</div>
    <div class="ar-val mono">{{ $transfer->reference }}</div>
    @if($transfer->booked_at)
      <div class="ar-label" style="margin-top:8px;">Data valuta</div>
      <div class="ar-val">{{ $transfer->booked_at->format('d/m/Y') }}</div>
    @endif
  </div>
</div>

<!-- ── PARTI COINVOLTE ── -->
<div class="section-title">Parti della transazione</div>
<div class="parties-row">
  <div class="party-cell">
    <div class="party-role">Mittente (Ordinante)</div>
    <div class="party-name">{{ $transfer->fromAccount->company->name ?? ($transfer->fromAccount->ownerUser->name ?? 'N/D') }}</div>
    @if($transfer->fromAccount->company && $transfer->fromAccount->company->vat_number)
      <div class="party-vat">P.IVA {{ $transfer->fromAccount->company->vat_number }}</div>
    @endif
    <span class="party-acct">{{ $transfer->fromAccount->ky_account_number ?? 'N/D' }}</span>
  </div>
  <div class="party-arrow">&rsaquo;</div>
  <div class="party-cell">
    <div class="party-role">Destinatario (Beneficiario)</div>
    <div class="party-name">{{ $transfer->toAccount->company->name ?? ($transfer->toAccount->ownerUser->name ?? 'N/D') }}</div>
    @if($transfer->toAccount->company && $transfer->toAccount->company->vat_number)
      <div class="party-vat">P.IVA {{ $transfer->toAccount->company->vat_number }}</div>
    @endif
    <span class="party-acct">{{ $transfer->toAccount->ky_account_number ?? 'N/D' }}</span>
  </div>
</div>

<!-- ── DETTAGLI TRANSAZIONE ── -->
<div class="section-title">Dettagli transazione</div>
<table class="detail-table">
  <tbody>
    <tr>
      <td class="lbl">Numero riferimento</td>
      <td class="val mono">{{ $transfer->reference }}</td>
    </tr>
    <tr>
      <td class="lbl">Identificativo univoco (UUID)</td>
      <td class="val mono" style="font-size:8.5px;">{{ $transfer->uuid }}</td>
    </tr>
    <tr>
      <td class="lbl">Data e ora contabile</td>
      <td class="val">{{ $transfer->booked_at ? $transfer->booked_at->format('d/m/Y H:i:s') : '—' }}</td>
    </tr>
    <tr>
      <td class="lbl">Valuta</td>
      <td class="val">{{ $transfer->currency_code ?? 'KY' }} &mdash; Crediti Kosmos (KMoney)</td>
    </tr>
    <tr>
      <td class="lbl">Importo</td>
      <td class="val amount-inline">{{ ky_format($transfer->amount) }} KY</td>
    </tr>
    <tr>
      <td class="lbl">Tipologia operazione</td>
      <td class="val"><span class="kind-pill">{{ $transfer->kind ?? 'transfer' }}</span></td>
    </tr>
    @if($transfer->description)
    <tr>
      <td class="lbl">Causale</td>
      <td class="val">{{ $transfer->description }}</td>
    </tr>
    @endif
    <tr>
      <td class="lbl">Stato</td>
      <td class="val">{{ $statusLabel }}</td>
    </tr>
  </tbody>
</table>

<!-- ── NOTE LEGALI ── -->
<div class="legal-box">
  <p>
    Il presente documento costituisce ricevuta ufficiale del movimento registrato nel sistema KMoney, circuito monetario complementare gestito da KNM S.R.L.
    I crediti KY non sono moneta legale e non sono rimborsabili in euro ai sensi del regolamento del circuito.
    Per contestazioni o chiarimenti contattare: <strong>info@kosmomoney.com</strong>.
    Documento generato automaticamente &mdash; non richiede firma.
  </p>
</div>

<!-- ── FOOTER ── -->
<div class="footer">
  <div class="footer-left">
    <div class="footer-brand">KMoney &mdash; Circuito Kosmos</div>
    KNM S.R.L. &mdash; P.IVA 13273091002<br>
    info@kosmomoney.com
  </div>
  <div class="footer-right">
    Ricevuta n. {{ $transfer->reference }}<br>
    Generata il {{ $generatedAt->format('d/m/Y') }} alle {{ $generatedAt->format('H:i') }}<br>
    Documento ufficiale KMoney
  </div>
</div>

<div class="bottom-band"></div>

</body>
</html>
