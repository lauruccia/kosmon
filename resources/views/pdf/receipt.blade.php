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
  .top-band    { background: #1a2e3b; margin: -28px -36px 0 -36px; height: 5px; }
  .bottom-band { background: #1a2e3b; margin: 14px -36px -20px -36px; height: 4px; }

  /* ── HEADER ── */
  .header-table { width: 100%; border-collapse: collapse; padding: 16px 0 14px 0; margin-bottom: 14px; }
  .header-table td { vertical-align: middle; padding: 16px 0 14px 0; border-bottom: 1.5px solid #1a2e3b; }
  .logo-img   { height: 42px; width: auto; vertical-align: middle; }
  .brand-name { font-size: 18px; font-weight: 800; color: #1a2e3b; letter-spacing: -0.5px; }
  .brand-sub  { font-size: 7.5px; color: #7a9098; text-transform: uppercase; letter-spacing: 1.1px; margin-top: 2px; }
  .brand-legal{ font-size: 7.5px; color: #9ca3af; margin-top: 4px; line-height: 1.4; }
  .doc-type   { font-size: 13px; font-weight: 700; color: #1a2e3b; text-transform: uppercase; letter-spacing: 0.4px; }
  .doc-ref    { font-family: "DejaVu Sans Mono", monospace; font-size: 10px; font-weight: 700; color: #3d5566; margin-top: 4px; }
  .doc-date   { font-size: 8px; color: #6b7280; margin-top: 3px; }

  /* ── STATUS ── */
  .status-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; border-radius: 4px; }
  .status-table td { padding: 8px 12px; vertical-align: middle; }
  .status-booked    { background: #f0fdf4; border-left: 4px solid #16a34a; }
  .status-pending   { background: #fffbeb; border-left: 4px solid #d97706; }
  .status-cancelled { background: #fef2f2; border-left: 4px solid #dc2626; }
  .status-dot { display: inline-block; width: 7px; height: 7px; border-radius: 4px; margin-right: 6px; vertical-align: middle; }
  .dot-booked    { background: #16a34a; }
  .dot-pending   { background: #d97706; }
  .dot-cancelled { background: #dc2626; }
  .status-lbl    { font-size: 9.5px; font-weight: 700; vertical-align: middle; }
  .lbl-booked    { color: #15803d; }
  .lbl-pending   { color: #b45309; }
  .lbl-cancelled { color: #b91c1c; }
  .status-ts     { font-size: 8px; color: #6b7280; text-align: right; }

  /* ── AMOUNT PANEL ── */
  .amount-table { width: 100%; border-collapse: collapse; border: 1.5px solid #1a2e3b; margin-bottom: 12px; }
  .amount-table td { padding: 12px 18px; vertical-align: middle; }
  .amount-lbl  { font-size: 7.5px; text-transform: uppercase; letter-spacing: 1.1px; color: #6b7280; font-weight: 700; margin-bottom: 4px; }
  .amount-sign { font-size: 26px; font-weight: 300; line-height: 1; vertical-align: baseline; }
  .amount-num  { font-size: 32px; font-weight: 800; letter-spacing: -1px; line-height: 1; vertical-align: baseline; }
  .amount-cur  { font-size: 14px; font-weight: 600; color: #6b7280; vertical-align: baseline; }
  .out { color: #dc2626; }
  .inc { color: #16a34a; }
  .ar-lbl { font-size: 7.5px; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 2px; }
  .ar-val { font-size: 9.5px; font-weight: 700; color: #374151; }

  /* ── PARTIES ── */
  .section-lbl {
    font-size: 7px; text-transform: uppercase; letter-spacing: 1.4px;
    color: #9ca3af; font-weight: 700; margin-bottom: 4px; padding-left: 1px;
  }
  .parties-table { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; margin-bottom: 12px; }
  .parties-table td { padding: 10px 14px; background: #f8fafc; vertical-align: top; }
  .parties-table td.arrow-cell { width: 22px; text-align: center; color: #9ca3af; font-size: 14px; border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; vertical-align: middle; }
  .party-role  { font-size: 7px; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; font-weight: 700; margin-bottom: 3px; }
  .party-name  { font-size: 11px; font-weight: 800; color: #1a2e3b; margin-bottom: 2px; }
  .party-vat   { font-size: 8px; color: #6b7280; margin-bottom: 2px; }
  .party-acct  {
    font-family: "DejaVu Sans Mono", monospace; font-size: 8.5px; font-weight: 700;
    color: #3d5566; background: #e8f0f5; padding: 1px 6px; margin-top: 4px;
    display: inline-block; letter-spacing: 0.4px;
  }

  /* ── DETAIL TABLE ── */
  .detail-table { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; margin-bottom: 12px; }
  .detail-table tr:last-child td { border-bottom: none; }
  .detail-table td { padding: 7px 12px; border-bottom: 1px solid #f1f5f9; font-size: 9.5px; vertical-align: middle; }
  .detail-table tr.even td { background: #fafbfc; }
  .detail-table td.lbl { color: #6b7280; font-weight: 600; width: 36%; }
  .detail-table td.val { color: #1a2e3b; font-weight: 700; }
  .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5px; letter-spacing: 0.3px; }
  .kind-pill {
    display: inline; padding: 1px 7px;
    font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    background: #ede9fe; color: #5b21b6;
  }
  .amount-inline { font-size: 12px; font-weight: 800; }

  /* ── LEGAL BOX ── */
  .legal-box { border: 1px solid #e2e8f0; padding: 8px 12px; background: #f8fafc; margin-bottom: 14px; }
  .legal-box p { font-size: 7.5px; color: #9ca3af; line-height: 1.55; }
  .legal-box strong { color: #6b7280; }

  /* ── FOOTER ── */
  .footer-table { width: 100%; border-collapse: collapse; border-top: 1.5px solid #1a2e3b; }
  .footer-table td { padding-top: 10px; vertical-align: bottom; font-size: 7.5px; color: #6b7280; line-height: 1.5; }
  .footer-brand { font-size: 9px; font-weight: 800; color: #1a2e3b; }
</style>
</head>
<body>

@php
  $logoPath    = public_path('assets/brand/kmoney-logo.png');
  $logoB64     = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;
  $statusMap   = ['booked' => 'Pagamento completato', 'pending' => 'In attesa di conferma', 'cancelled' => 'Annullato'];
  $statusLabel = $statusMap[$transfer->status] ?? ucfirst($transfer->status);
  $isOut       = $isOutgoing;
  $fromName    = $transfer->fromAccount->company->name ?? ($transfer->fromAccount->ownerUser->name ?? null);
  $toName      = $transfer->toAccount->company->name   ?? ($transfer->toAccount->ownerUser->name   ?? null);
  $fromVat     = $transfer->fromAccount->company->vat_number ?? null;
  $toVat       = $transfer->toAccount->company->vat_number   ?? null;
  $fromAcct    = $transfer->fromAccount->ky_account_number ?? null;
  $toAcct      = $transfer->toAccount->ky_account_number   ?? null;
@endphp

<div class="top-band"></div>

<!-- HEADER -->
<table class="header-table">
  <tr>
    <td style="width:55%;">
      <table style="border-collapse:collapse;"><tr>
        @if($logoB64)
          <td style="padding-right:10px; vertical-align:middle;">
            <img class="logo-img" src="{{ $logoB64 }}" alt="KMoney">
          </td>
        @endif
        <td style="vertical-align:middle;">
          <div class="brand-name">KMoney</div>
          <div class="brand-sub">Circuito Monetario Locale</div>
          <div class="brand-legal">KNM S.R.L. &mdash; P.IVA 13273091002 &mdash; info@kosmomoney.com</div>
        </td>
      </tr></table>
    </td>
    <td style="text-align:right; vertical-align:middle;">
      <div class="doc-type">Ricevuta di Pagamento</div>
      <div class="doc-ref">{{ $transfer->reference }}</div>
      <div class="doc-date">Emessa il {{ $generatedAt->format('d/m/Y \a\l\l\e H:i') }}</div>
    </td>
  </tr>
</table>

<!-- STATUS -->
<table class="status-table">
  <tr class="status-{{ $transfer->status }}">
    <td>
      <span class="status-dot dot-{{ $transfer->status }}"></span>
      <span class="status-lbl lbl-{{ $transfer->status }}">{{ $statusLabel }}</span>
    </td>
    <td class="status-ts">
      @if($transfer->booked_at) {{ $transfer->booked_at->format('d/m/Y H:i:s') }} @endif
    </td>
  </tr>
</table>

<!-- IMPORTO -->
<table class="amount-table">
  <tr>
    <td>
      <div class="amount-lbl">{{ $isOut ? 'Importo addebitato' : 'Importo accreditato' }}</div>
      <span class="amount-sign {{ $isOut ? 'out' : 'inc' }}">{{ $isOut ? '-' : '+' }}</span>
      <span class="amount-num  {{ $isOut ? 'out' : 'inc' }}">{{ ky_format($transfer->amount) }}</span>
      <span class="amount-cur">KY</span>
    </td>
    <td style="text-align:right; width:38%;">
      <div class="ar-lbl">Rif. transazione</div>
      <div class="ar-val mono">{{ $transfer->reference }}</div>
      @if($transfer->booked_at)
        <div class="ar-lbl" style="margin-top:6px;">Data valuta</div>
        <div class="ar-val">{{ $transfer->booked_at->format('d/m/Y') }}</div>
      @endif
    </td>
  </tr>
</table>

<!-- PARTI -->
<div class="section-lbl">Parti della transazione</div>
<table class="parties-table">
  <tr>
    <td style="width:47%;">
      <div class="party-role">Mittente (Ordinante)</div>
      @if($fromName) <div class="party-name">{{ $fromName }}</div> @endif
      @if($fromVat)  <div class="party-vat">P.IVA {{ $fromVat }}</div> @endif
      @if($fromAcct) <div class="party-acct">{{ $fromAcct }}</div> @endif
    </td>
    <td class="arrow-cell">&rsaquo;</td>
    <td style="width:47%;">
      <div class="party-role">Destinatario (Beneficiario)</div>
      @if($toName) <div class="party-name">{{ $toName }}</div> @endif
      @if($toVat)  <div class="party-vat">P.IVA {{ $toVat }}</div> @endif
      @if($toAcct) <div class="party-acct">{{ $toAcct }}</div> @endif
    </td>
  </tr>
</table>

<!-- DETTAGLI -->
<div class="section-lbl">Dettagli transazione</div>
<table class="detail-table">
  <tr>
    <td class="lbl">Numero riferimento</td>
    <td class="val mono">{{ $transfer->reference }}</td>
  </tr>
  <tr class="even">
    <td class="lbl">UUID transazione</td>
    <td class="val mono" style="font-size:7.5px;">{{ $transfer->uuid }}</td>
  </tr>
  <tr>
    <td class="lbl">Data e ora contabile</td>
    <td class="val">{{ $transfer->booked_at ? $transfer->booked_at->format('d/m/Y H:i:s') : '—' }}</td>
  </tr>
  <tr class="even">
    <td class="lbl">Valuta</td>
    <td class="val">{{ $transfer->currency_code ?? 'KY' }} &mdash; Crediti Kosmos (KMoney)</td>
  </tr>
  <tr>
    <td class="lbl">Importo</td>
    <td class="val amount-inline">{{ ky_format($transfer->amount) }} KY</td>
  </tr>
  <tr class="even">
    <td class="lbl">Tipologia operazione</td>
    <td class="val"><span class="kind-pill">{{ $transfer->kind ?? 'transfer' }}</span></td>
  </tr>
  @if($transfer->description)
  <tr>
    <td class="lbl">Causale</td>
    <td class="val">{{ $transfer->description }}</td>
  </tr>
  @endif
  <tr class="{{ $transfer->description ? 'even' : '' }}">
    <td class="lbl">Stato</td>
    <td class="val">{{ $statusLabel }}</td>
  </tr>
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
<table class="footer-table">
  <tr>
    <td>
      <div class="footer-brand">KMoney &mdash; Circuito Kosmos</div>
      KNM S.R.L. &mdash; P.IVA 13273091002 &mdash; info@kosmomoney.com
    </td>
    <td style="text-align:right;">
      Ricevuta n. {{ $transfer->reference }}<br>
      Generata il {{ $generatedAt->format('d/m/Y') }} alle {{ $generatedAt->format('H:i') }}<br>
      Documento ufficiale KMoney
    </td>
  </tr>
</table>

<div class="bottom-band"></div>

</body>
</html>
