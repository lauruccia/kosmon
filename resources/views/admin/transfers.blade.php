@extends('layouts.portal')

@section('page-actions')
@php
    $csvParams = http_build_query(array_filter([
        'period'    => $movementFilters['period'],
        'from_date' => $movementFilters['from_date'],
        'to_date'   => $movementFilters['to_date'],
    ]));
@endphp
<a class="cta" href="{{ route('admin.report.export-csv') }}?{{ $csvParams }}">⬇ CSV</a>
<a class="cta secondary" href="{{ route('admin.report') }}">Rapporti</a>
<a class="cta secondary" href="{{ route('admin.accounts.index') }}">Conti</a>
<a class="cta secondary" href="{{ route('admin.users.index') }}">Utenti</a>
@endsection

@section('content')
    <section class="grid-cards">
        <article class="stat-card"><div class="eyebrow">Movimenti filtrati</div><div class="section-title">{{ $movementTotals['count'] }}</div></article>
        <article class="stat-card"><div class="eyebrow">Contabilizzati</div><div class="section-title">{{ $movementTotals['bookedCount'] }}</div></article>
        <article class="stat-card"><div class="eyebrow">Volume</div><div class="section-title">{{ ky_format($movementTotals['volume']) }} KY</div></article>
        <article class="stat-card"><div class="eyebrow">Storni</div><div class="section-title">{{ $movementTotals['refunds'] }}</div></article>
    </section>

    <section class="card light-card">
        <div class="section-head">
            <div><span class="eyebrow">Registro movimenti</span><h3 class="section-title">Movimenti registrati</h3></div>
            <span class="pill">{{ $movementFilters['label'] }}</span>
        </div>

        <form method="get" action="{{ route('admin.transfers.index') }}" style="margin-bottom:10px;">
            <div style="display:grid;grid-template-columns:160px 1fr 1fr 1fr auto;gap:8px;align-items:end;">
                <div class="field">
                    <label>Periodo</label>
                    <select name="period">
                        @foreach ($movementPeriodOptions as $value => $label)
                            <option value="{{ $value }}" @selected($movementFilters['period'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Da data</label><input type="date" name="from_date" value="{{ $movementFilters['from_date'] }}"></div>
                <div class="field"><label>A data</label><input type="date" name="to_date" value="{{ $movementFilters['to_date'] }}"></div>
                <div class="field"><label>Cerca utente / azienda</label><input type="text" name="search" value="{{ $search }}" placeholder="Nome mittente o destinatario…"></div>
                <div style="padding-bottom:1px;"><button type="submit" class="cta secondary">Filtra</button></div>
            </div>
        </form>

        @if ($transfers->isEmpty())
            <div class="empty-state">Nessun movimento trovato per i filtri selezionati.</div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#f6f9fb;border-bottom:2px solid #e4edf2;text-align:left;">
                        <th style="padding:6px 10px;white-space:nowrap;">Data</th>
                        <th style="padding:6px 10px;">Riferimento</th>
                        <th style="padding:6px 10px;">Da</th>
                        <th style="padding:6px 10px;">A</th>
                        <th style="padding:6px 10px;text-align:right;white-space:nowrap;">Importo</th>
                        <th style="padding:6px 10px;">Tipo</th>
                        <th style="padding:6px 10px;">Stato</th>
                        <th style="padding:6px 10px;">Operatore</th>
                        <th style="padding:6px 10px;"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($transfers as $transfer)
                @php
                    $isRefundable = $supportsTransferRefunds
                        && $transfer->status === 'booked'
                        && $transfer->reversalChildren->isEmpty()
                        && $transfer->booked_at !== null
                        && $transfer->booked_at->greaterThanOrEqualTo(now()->subDays($refundWindowDays));

                    $statoLabel = match ($transfer->status) {
                        'booked'   => 'Contabilizzato',
                        'pending'  => 'In elaborazione',
                        'rejected' => 'Respinto',
                        default    => ucfirst(str_replace('_', ' ', $transfer->status ?? 'N/D')),
                    };
                    $statoChip = match ($transfer->status) {
                        'booked'  => 'success',
                        default   => 'pink',
                    };
                    $causale = match ($transfer->kind) {
                        'portal_payment'            => 'Pag. portale',
                        'portal_collection'         => 'Incasso',
                        'trade_payment'             => 'Pag. commerciale',
                        'admin_refund'              => 'Storno amm.',
                        'portal_refund'             => 'Rimborso',
                        'portal_credit_note'        => 'Nota credito',
                        'portal_fee'                => 'Commissione',
                        'portal_cashback'           => 'Cashback',
                        'portal_installment'        => 'Rata',
                        'portal_netting'            => 'Netting',
                        'portal_payment_request'    => 'Pag. richiesta',
                        'portal_collection_request' => 'Incasso richiesta',
                        default => $transfer->kind ? ucfirst(str_replace('_', ' ', $transfer->kind)) : 'Movimento',
                    };

                    $fromLabel = $transfer->fromAccount?->display_name
                        ?? $transfer->fromAccount?->company?->name
                        ?? $transfer->fromAccount?->ownerLabel
                        ?? 'N/D';
                    $toLabel = $transfer->toAccount?->display_name
                        ?? $transfer->toAccount?->company?->name
                        ?? $transfer->toAccount?->ownerLabel
                        ?? 'N/D';

                    $isAlreadyRefunded  = $supportsTransferRefunds && $transfer->reversedTransfer !== null;
                    $hasReversalChild   = $supportsTransferRefunds && $transfer->reversalChildren->isNotEmpty();
                    $rowBg = $isAlreadyRefunded || $hasReversalChild ? '#fff8f0' : 'transparent';
                @endphp
                <tr style="border-bottom:1px solid #edf1f4;background:{{ $rowBg }};vertical-align:middle;">
                    <td style="padding:5px 10px;white-space:nowrap;color:#5a6474;font-size:12px;">
                        {{ $transfer->booked_at?->format('d/m/Y') ?? '—' }}<br>
                        <span style="font-size:11px;">{{ $transfer->booked_at?->format('H:i') }}</span>
                    </td>
                    <td style="padding:5px 10px;white-space:nowrap;">
                        <strong style="font-size:12px;">{{ $transfer->reference }}</strong>
                        @if ($transfer->description)
                            <div style="font-size:11px;color:#8a94a6;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $transfer->description }}">{{ $transfer->description }}</div>
                        @endif
                    </td>
                    <td style="padding:5px 10px;max-width:160px;">
                        <span style="display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $fromLabel }}">{{ $fromLabel }}</span>
                        @if ($transfer->fromAccount?->number)
                            <span style="font-size:11px;color:#8a94a6;">{{ $transfer->fromAccount->number }}</span>
                        @endif
                    </td>
                    <td style="padding:5px 10px;max-width:160px;">
                        <span style="display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $toLabel }}">{{ $toLabel }}</span>
                        @if ($transfer->toAccount?->number)
                            <span style="font-size:11px;color:#8a94a6;">{{ $transfer->toAccount->number }}</span>
                        @endif
                    </td>
                    <td style="padding:5px 10px;text-align:right;white-space:nowrap;font-weight:700;">
                        {{ ky_format($transfer->amount) }} <span style="font-size:11px;font-weight:400;">{{ $transfer->currency_code }}</span>
                    </td>
                    <td style="padding:5px 10px;white-space:nowrap;font-size:12px;">{{ $causale }}</td>
                    <td style="padding:5px 10px;white-space:nowrap;">
                        <span class="chip {{ $statoChip }}" style="font-size:11px;padding:2px 7px;">{{ $statoLabel }}</span>
                        @if ($supportsTransferRefunds && $transfer->admin_action === 'refund')
                            <span class="chip pink" style="font-size:11px;padding:2px 7px;">Stornato</span>
                        @endif
                    </td>
                    <td style="padding:5px 10px;font-size:12px;color:#5a6474;white-space:nowrap;">{{ $transfer->initiator?->name ?? 'sistema' }}</td>
                    <td style="padding:5px 10px;white-space:nowrap;">
                        @if ($isAlreadyRefunded)
                            <span style="font-size:11px;color:#e07e00;" title="Storno di {{ $transfer->reversedTransfer->reference }}">↩ stornato</span>
                        @elseif ($hasReversalChild)
                            <span style="font-size:11px;color:#e07e00;" title="Corretto da {{ $transfer->reversalChildren->first()->reference }}">⚠ corretto</span>
                        @elseif ($isRefundable)
                            <button type="button"
                                onclick="document.getElementById('refund-modal-{{ $transfer->id }}').showModal()"
                                class="cta secondary"
                                style="font-size:11px;padding:3px 10px;">
                                Storna
                            </button>
                        @elseif ($supportsTransferRefunds)
                            <span style="font-size:11px;color:#b0bac4;">finestra scaduta</span>
                        @endif
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- Modal storno per ogni riga refundable --}}
        @foreach ($transfers as $transfer)
        @php
            $isRefundable = $supportsTransferRefunds
                && $transfer->status === 'booked'
                && $transfer->reversalChildren->isEmpty()
                && $transfer->booked_at !== null
                && $transfer->booked_at->greaterThanOrEqualTo(now()->subDays($refundWindowDays));
        @endphp
        @if ($isRefundable)
        <dialog id="refund-modal-{{ $transfer->id }}" style="border:none;border-radius:16px;padding:28px 32px;max-width:440px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,.15);">
            <h4 style="margin:0 0 6px;">Storno amministrativo</h4>
            <p style="margin:0 0 16px;font-size:13px;color:#5a6474;">
                Movimento <strong>{{ $transfer->reference }}</strong> —
                {{ ky_format($transfer->amount) }} {{ $transfer->currency_code }}<br>
                Da <strong>{{ $transfer->fromAccount?->display_name ?? 'N/D' }}</strong>
                a <strong>{{ $transfer->toAccount?->display_name ?? 'N/D' }}</strong>
            </p>
            <form method="post" action="{{ route('admin.transfers.refund', $transfer) }}">
                @csrf
                <div class="field" style="margin-bottom:14px;">
                    <label>Motivazione storno</label>
                    <input name="reason" type="text" placeholder="Storno operativo, errore utente, correzione contabile" required>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="cta secondary" onclick="document.getElementById('refund-modal-{{ $transfer->id }}').close()">Annulla</button>
                    <button type="submit" class="cta">Conferma storno</button>
                </div>
            </form>
        </dialog>
        @endif
        @endforeach

        @endif
    </section>
@endsection
