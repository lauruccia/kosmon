<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; background: #fff; }

  .header { border-bottom: 3px solid #6d28d9; padding-bottom: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; }
  .header-left h1 { font-size: 22px; font-weight: 700; color: #6d28d9; letter-spacing: -0.5px; }
  .header-left .subtitle { font-size: 12px; color: #6b7280; margin-top: 2px; }
  .header-right { text-align: right; font-size: 10px; color: #6b7280; }
  .header-right .period { font-size: 14px; font-weight: 700; color: #1a1a2e; }

  .meta { display: flex; gap: 0; margin-bottom: 24px; }
  .meta-block { flex: 1; padding: 12px 16px; background: #f5f3ff; border-radius: 6px; margin-right: 10px; }
  .meta-block:last-child { margin-right: 0; }
  .meta-block .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #7c3aed; font-weight: 700; margin-bottom: 4px; }
  .meta-block .value { font-size: 13px; font-weight: 700; color: #1a1a2e; }
  .meta-block .sub { font-size: 9px; color: #6b7280; margin-top: 2px; }

  .balance-row { display: flex; gap: 0; margin-bottom: 24px; }
  .balance-card { flex: 1; padding: 14px 16px; border-radius: 6px; margin-right: 10px; text-align: center; }
  .balance-card:last-child { margin-right: 0; }
  .balance-card.opening { background: #f3f4f6; border: 1px solid #e5e7eb; }
  .balance-card.closing { background: #f5f3ff; border: 2px solid #6d28d9; }
  .balance-card .bal-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; font-weight: 700; margin-bottom: 6px; }
  .balance-card .bal-value { font-size: 20px; font-weight: 800; }
  .balance-card.opening .bal-value { color: #374151; }
  .balance-card.closing .bal-value { color: #6d28d9; }
  .balance-card .bal-currency { font-size: 12px; font-weight: 400; color: #9ca3af; }

  .section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; color: #7c3aed; margin-bottom: 10px; border-bottom: 1px solid #e5e7eb; padding-bottom: 5px; }

  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  thead tr { background: #7c3aed; color: #fff; }
  thead th { padding: 8px 10px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
  tbody tr { border-bottom: 1px solid #f3f4f6; }
  tbody tr:nth-child(even) { background: #fafafa; }
  tbody td { padding: 8px 10px; font-size: 10px; vertical-align: top; }
  .td-date { color: #6b7280; white-space: nowrap; }
  .td-ref { color: #9ca3af; font-size: 9px; }
  .td-counterparty { font-weight: 600; }
  .td-desc { color: #6b7280; font-size: 9px; max-width: 160px; }
  .td-amount { text-align: right; font-weight: 700; white-space: nowrap; }
  .td-amount.credit { color: #059669; }
  .td-amount.debit  { color: #dc2626; }
  .td-balance { text-align: right; font-size: 9px; color: #6b7280; white-space: nowrap; }

  .empty-state { text-align: center; padding: 32px; color: #9ca3af; font-style: italic; }

  .footer { border-top: 1px solid #e5e7eb; padding-top: 10px; display: flex; justify-content: space-between; font-size: 8px; color: #9ca3af; margin-top: 16px; }

  .chip { display: inline-block; padding: 2px 7px; border-radius: 99px; font-size: 8px; font-weight: 600; }
  .chip-credit { background: #d1fae5; color: #065f46; }
  .chip-debit  { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>

<div class="header">
  <div class="header-left">
    <h1>KMoney</h1>
    <div class="subtitle">Estratto conto mensile</div>
  </div>
  <div class="header-right">
    <div class="period">{{ $period }}</div>
    <div style="margin-top:4px;">Dal {{ $periodStart->format('d/m/Y') }} al {{ $periodEnd->format('d/m/Y') }}</div>
    <div style="margin-top:2px;">Generato il {{ $generatedAt->format('d/m/Y \a\l\l\e H:i') }}</div>
  </div>
</div>

<div class="meta">
  <div class="meta-block">
    <div class="label">Intestatario</div>
    <div class="value">{{ $company?->name ?? $account->display_name }}</div>
    @if($company?->vat_number)
    <div class="sub">P.IVA {{ $company->vat_number }}</div>
    @endif
  </div>
  <div class="meta-block">
    <div class="label">Numero conto</div>
    <div class="value" style="font-family: monospace; font-size: 12px;">{{ $account->account_number }}</div>
    <div class="sub">Valuta: {{ $account->currency_code }}</div>
  </div>
  <div class="meta-block">
    <div class="label">Movimenti nel periodo</div>
    <div class="value">{{ $transfers->count() }}</div>
    <div class="sub">solo movimenti contabilizzati</div>
  </div>
</div>

<div class="balance-row">
  <div class="balance-card opening">
    <div class="bal-label">Saldo iniziale</div>
    <div class="bal-value">{{ ky_format($openingBalance) }} <span class="bal-currency">KY</span></div>
    <div style="font-size:9px;color:#6b7280;margin-top:4px;">al {{ $periodStart->format('d/m/Y') }}</div>
  </div>
  <div class="balance-card closing">
    <div class="bal-label">Saldo finale</div>
    <div class="bal-value">{{ ky_format($closingBalance) }} <span class="bal-currency">KY</span></div>
    <div style="font-size:9px;color:#7c3aed;margin-top:4px;">al {{ $periodEnd->format('d/m/Y') }}</div>
  </div>
</div>

<div class="section-title">Dettaglio movimenti</div>

@if($transfers->isEmpty())
  <div class="empty-state">Nessun movimento contabilizzato nel periodo selezionato.</div>
@else
<table>
  <thead>
    <tr>
      <th>Data</th>
      <th>Riferimento</th>
      <th>Controparte</th>
      <th>Descrizione</th>
      <th style="text-align:right;">Dare / Avere</th>
      <th style="text-align:right;">Saldo dopo</th>
    </tr>
  </thead>
  <tbody>
    @foreach($transfers as $transfer)
      @php
        $isCredit   = $transfer->to_account_id === $account->id;
        $counterparty = $isCredit ? $transfer->fromAccount : $transfer->toAccount;
        $cpName     = $counterparty?->company?->name ?? $counterparty?->display_name ?? '—';
        $ledger     = $transfer->ledgerEntries->firstWhere('account_id', $account->id);
        $balAfter   = $ledger ? (int) $ledger->balance_after : null;
      @endphp
      <tr>
        <td class="td-date">{{ \Carbon\Carbon::parse($transfer->booked_at)->format('d/m/Y') }}<br><span class="td-ref">{{ $transfer->reference }}</span></td>
        <td></td>
        <td class="td-counterparty">{{ $cpName }}</td>
        <td class="td-desc">{{ $transfer->description ?: '—' }}</td>
        <td class="td-amount {{ $isCredit ? 'credit' : 'debit' }}">
          <span class="chip {{ $isCredit ? 'chip-credit' : 'chip-debit' }}">{{ $isCredit ? '+' : '-' }}</span>
          {{ ky_format($transfer->amount) }} KY
        </td>
        <td class="td-balance">{{ $balAfter !== null ? ky_format($balAfter) . ' KY' : '—' }}</td>
      </tr>
    @endforeach
  </tbody>
</table>
@endif

<div class="footer">
  <div>KMoney — Circuito di credito reciproco in KY</div>
  <div>Documento generato automaticamente · non ha valore fiscale</div>
</div>

</body>
</html>
