<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 10px;
    color: #1a1a2e;
    background: #fff;
    padding: 28px 36px 20px 36px;
  }

  /* ── BANDE ── */
  .top-band {
    background: #1a2e3b;
    margin: -28px -36px 0 -36px;
    height: 5px;
  }

  /* ── HEADER ── */
  .header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0 14px 0;
    border-bottom: 1.5px solid #1a2e3b;
    margin-bottom: 14px;
  }
  .header-left { display: flex; align-items: center; gap: 12px; }
  .logo-img { height: 44px; width: auto; }
  .brand-text {}
  .brand-name { font-size: 18px; font-weight: 800; color: #1a2e3b; letter-spacing: -0.5px; }
  .brand-sub  { font-size: 7.5px; color: #7a9098; text-transform: uppercase; letter-spacing: 1.1px; margin-top: 2px; }
  .brand-legal { font-size: 7.5px; color: #9ca3af; margin-top: 4px; line-height: 1.4; }
  .header-right { text-align: right; }
  .doc-type { font-size: 13px; font-weight: 700; color: #1a2e3b; text-transform: uppercase; letter-spacing: 0.4px; }
  .doc-ref  { font-family: "DejaVu Sans Mono", monospace; font-size: 10px; font-weight: 700; color: #3d5566; margin-top: 4px; }
  .doc-date { font-size: 8px; color: #6b7280; margin-top: 3px; }

  /* ── STATUS ── */
  .status-strip {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; border-radius: 4px; border-left: 4px solid transparent;
    margin-bottom: 12px;
  }
  .status-strip.booked    { background: #f0fdf4; border-color: #16a34a; }
  .status-strip.pending   { background: #fffbeb; border-color: #d97706; }
  .status-strip.cancelled { background: #fef2f2; border-color: #dc2626; }
  .status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
  .status-dot.booked    { background: #16a34a; }
  .status-dot.pending   { background: #d97706; }
  .status-dot.cancelled { background: #dc2626; }
  .status-lbl { font-size: 9.5px; font-weight: 700; }
  .status-lbl.booked    { color: #15803d; }
  .status-lbl.pending   { color: #b45309; }
  .status-lbl.cancelled { color: #b91c1c; }
  .status-ts { font-size: 8px; color: #6b7280; margin-left: auto; }

  /* ── AMOUNT PANEL ── */
  .amount-panel {
    border: 1.5px solid #1a2e3b; border-radius: 5px;
    padding: 12px 18px; margin-bottom: 12px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .amount-lbl  { font-size: 7.5px; text-transform: uppercase; letter-spacing: 1.1px; color: #6b7280; font-weight: 700; margin-bottom: 4px; }
  .amount-fig  { display: flex; align-items: baseline; gap: 4px; }
  .amount-sign { font-size: 26px; font-weight: 300; line-height: 1; }
  .amount-sign.out { color: #dc2626; }
  .amount-sign.inc { color: #16a34a; }
  .amount-num  { font-size: 32px; font-weight: 800; letter-spacing: -1px; line-height: 1; }
  .amount-num.out  { color: #dc2626; }
  .amount-num.inc  { color: #16a34a; }
  .amount-cur  { font-size: 14px; font-weight: 600; color: #6b7280; }
  .amount-right { text-align: right; }
  .ar-lbl { font-size: 7.5px; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 2px; }
  .ar-val { font-size: 9.5px; font-weight: 700; color: #374151; }
  .ar-val.mono { font-family: "DejaVu Sans Mono", monospace; font-size: 9px; }

  /* ── PARTIES ── */
  .section-lbl {
    font-size: 7px; text-transform: uppercase; letter-spacing: 1.4px;
    color: #9ca3af; font-weight: 700; margin-bottom: 4px; padding-left: 1px;
  }
  .parties-row {
    display: flex; gap: 0; margin-bottom: 12px;
    border: 1px solid #e2e8f0; border-radius: 5px; overflow: hidden;
  }
  .party-cell { flex: 1; padding: 10px 14px; background: #f8fafc; }
  .party-cell:first-child { border-right: 1px solid #e2e8f0; }
  .party-arrow {
    display: flex; align-items: center; justify-content: center;
    padding: 0 10px; background: #f8fafc; border-right: 1px solid #e2e8f0;
    color: #9ca3af; font-size: 14px;
  }
  .party-role  { font-size: 7px; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; font-weight: 700; margin-bottom: 3px; }
  .party-name  { font-size: 11px; font-weight: 800; color: #1a2e3b; margin-bottom: 2px; }
  .party-vat   { font-size: 8px; color: #6b7280; margin-bottom: 2px; }
  .party-acct  {
    font-family: "DejaVu Sans Mono", monospace; font-size: 8.5px; font-weight: 700;
    color: #3d5566; background: #e8f0f5; display: inline-block;
    padding: 1px 6px; border-radius: 3px; margin-top: 3px; letter-spacing: 0.4px;
  }

  /* ── DETAIL TABLE ── */
  .detail-table {
    width: 100%; border-collapse: collapse; margin-bottom: 12px;
    border: 1px solid #e2e8f0; border-radius: 5px; overflow: hidden;
  }
  .detail-table tr:last-child td { border-bottom: none; }
  .detail-table td {
    padding: 7px 12px; border-bottom: 1px solid #f1f5f9;
    font-size: 9.5px; vertical-align: middle;
  }
  .detail-table tr:nth-child(even) td { background: #fafbfc; }
  .detail-table td.lbl { color: #6b7280; font-weight: 600; width: 36%; }
  .detail-table td.val { color: #1a2e3b; font-weight: 700; }
  .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5px; letter-spacing: 0.3px; }
  .kind-pill {
    display: inline-block; padding: 1px 7px; border-radius: 3px;
    font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    background: #ede9fe; color: #5b21b6;
  }
  .amount-inline { font-size: 12px; font-weight: 800; }

  /* ── LEGAL BOX ── */
  .legal-box {
    border: 1px solid #e2e8f0; border-radius: 4px;
    padding: 8px 12px; background: #f8fafc; margin-bottom: 14px;
  }
  .legal-box p { font-size: 7.5px; color: #9ca3af; line-height: 1.55; }
  .legal-box strong { color: #6b7280; }

  /* ── FOOTER ── */
  .footer {
    border-top: 1.5px solid #1a2e3b; padding-top: 10px;
    display: flex; justify-content: space-between; align-items: flex-end;
  }
  .footer-brand { font-size: 9px; font-weight: 800; color: #1a2e3b; }
  .footer-left  { font-size: 7.5px; color: #6b7280; line-height: 1.5; }
  .footer-right { font-size: 7.5px; color: #6b7280; text-align: right; line-height: 1.5; }

  .bottom-band {
    background: #1a2e3b;
    margin: 14px -36px -20px -36px;
    height: 4px;
  }
</style>
</head>
<body>

<div class="top-band"></div>

@php
  $logoPath = public_path('assets/brand/kmoney-logo.png');
  $logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;
  $statusMap   = ['booked' => 'Pagamento completato', 'pending' => 'In attesa di conferma', 'cancelled' => 'Annullato'];
  $statusLabel = $statusMap[$transfer->status] ?? ucfirst($transfer->status);
  $isOut = $isOutgoing;
@endphp

<!-- HEADER -->
<div class="header">
  <div class="header-left">
    @if($logoB64)
      <img class="logo-img" src="{{ $logoB64 }}" alt="KMoney Logo">
    @endif
    <div class="brand-text">
      <div class="brand-name">KMoney</div>
      <div class="brand-sub">Circuito Monetario Locale</div>
      <div class="brand-legal">KNM S.R.L. &mdash; P.IVA 13273091002 &mdash; info@kosmomoney.com</div>
    </div>
  </div>
  <div class="header-right">
    <div class="doc-type">Ricevuta di Pagamento</div>
    <div class="doc-ref">{{ $transfer->reference }}</div>
    <div class="doc-date">Emessa il {{ $generatedAt->format('d/m/Y \a\l\l\e H:i') }}</div>
  </div>
</div>

<!-- STATUS -->
<div class="status-strip {{ $transfer->status }}">
  <div class="status-dot {{ $transfer->status }}"></div>
  <span class="status-lbl {{ $transfer->status }}">{{ $statusLabel }}</span>
  @if($transfer->booked_at)
    <span class="status-ts">{{ $transfer->booked_at->format('d/m/Y H:i:s') }}</span>
  @endif
</div>

<!-- IMPORTO -->
<div class="amount-panel">
  <div>
    <div class="amount-lbl">{{ $isOut ? 'Importo addebitato' : 'Importo accreditato' }}</div>
    <div class="amount-fig">
      <span class="amount-sign {{ $isOut ? 'out' : 'inc' }}">{{ $isOut ? '−' : '+' }}</span>
      <span class="amount-num  {{ $isOut ? 'out' : 'inc' }}">{{ ky_format($transfer->amount) }}</span>
      <span class="amount-cur">KY</span>
    </div>
  </div>
  <div class="amount-right">
    <div class="ar-lbl">Rif. transazione</div>
    <div class="ar-val mono">{{ $transfer->reference }}</div>
    @if($transfer->booked_at)
      <div class="ar-lbl" style="margin-top:6px;">Data valuta</div>
      <div class="ar-val">{{ $transfer->booked_at->format('d/m/Y') }}</div>
    @endif
  </div>
</div>

<!-- PARTI -->
<div class="section-lbl">Parti della transazione</div>
<div class="parties-row">
  <div class="party-cell">
    <div class="party-role">Mittente (Ordinante)</div>
    <div class="party-name">{{ $transfer->fromAccount->company->name ?? ($transfer->fromAccount->ownerUser->name ?? 'N/D') }}</div>
    @if(!empty($transfer->fromAccount->company->vat_number))
      <div class="party-vat">P.IVA {{ $transfer->fromAccount->company->vat_number }}</div>
    @endif
    <span class="party-acct">{{ $transfer->fromAccount->ky_account_number ?? 'N/D' }}</span>
  </div>
  <div class="party-arrow">&rsaquo;</div>
  <div class="party-cell">
    <div class="party-role">Destinatario (Beneficiario)</div>
    <div class="party-name">{{ $transfer->toAccount->company->name ?? ($transfer->toAccount->ownerUser->name ?? 'N/D') }}</div>
    @if(!empty($transfer->toAccount->company->vat_number))
      <div class="party-vat">P.IVA {{ $transfer->toAccount->company->vat_number }}</div>
    @endif
    <span class="party-acct">{{ $transfer->toAccount->ky_account_number ?? 'N/D' }}</span>
  </div>
</div>

<!-- DETTAGLI -->
<div class="section-lbl">Dettagli transazione</div>
<table class="detail-table">
  <tbody>
    <tr>
      <td class="lbl">Numero riferimento</td>
      <td class="val mono">{{ $transfer->reference }}</td>
    </tr>
    <tr>
      <td class="lbl">UUID transazione</td>
      <td class="val mono" style="font-size:7.5px;">{{ $transfer->uuid }}</td>
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

<!-- NOTE LEGALI -->
<div class="legal-box">
  <p>
    Ricevuta ufficiale del movimento registrato nel circuito KMoney, gestito da KNM S.R.L.
    I crediti KY non sono moneta legale e non sono rimborsabili in euro ai sensi del regolamento del circuito.
    Per contestazioni: <strong>info@kosmomoney.com</strong> &mdash; Documento generato automaticamente, non richiede firma.
  </p>
</div>

<!-- FOOTER -->
<div class="footer">
  <div class="footer-left">
    <div class="footer-brand">KMoney &mdash; Circuito Kosmos</div>
    KNM S.R.L. &mdash; P.IVA 13273091002 &mdash; info@kosmomoney.com
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
