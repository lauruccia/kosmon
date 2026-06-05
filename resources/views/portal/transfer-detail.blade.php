@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('portal.movements') }}">← Torna ai movimenti</a>
@endsection

@section('content')

@php
    $mesiIt = ['','Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
    $eventDate = $transfer->booked_at ?? $transfer->created_at;

    $kindLabels = [
        'trade_payment'              => 'Pagamento circuito',
        'portal_payment'             => 'Pagamento portale',
        'portal_payment_request'     => 'Richiesta pagamento',
        'portal_collection_request'  => 'Richiesta incasso',
        'portal_refund'              => 'Rimborso',
        'portal_credit_note'         => 'Nota di credito',
        'portal_fee'                 => 'Commissione',
        'portal_cashback'            => 'Cashback',
        'portal_installment'         => 'Rata piano rateale',
        'portal_netting'             => 'Compensazione (netting)',
    ];
    $kindLabel = $kindLabels[$transfer->kind] ?? ucfirst(str_replace('_', ' ', $transfer->kind ?? ''));

    $statusLabel = match($transfer->status) {
        'pending'  => 'In attesa',
        'booked'   => 'Contabilizzato',
        'rejected' => 'Rifiutato',
        default    => ucfirst($transfer->status ?? 'N/D'),
    };
    $statusColor = match($transfer->status) {
        'booked'   => '#059669',
        'pending'  => '#d97706',
        'rejected' => '#dc2626',
        default    => 'var(--ink-muted)',
    };

    // Fee collegata a questo movimento
    $feeTransfer = $transfer->feeTransfers()->where('status', 'booked')->first();
@endphp

<div class="summary-grid" style="margin-bottom:24px;">

    {{-- Scheda principale --}}
    <section class="card light-card" style="grid-column: 1 / -1;">
        <div class="section-head" style="margin-bottom:20px;">
            <div>
                <span class="eyebrow">Movimento #{{ $transfer->reference }}</span>
                <h3 class="section-title">Dettaglio movimento</h3>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span style="background:{{ $isOutgoing ? '#fee2e2' : '#d1fae5' }};color:{{ $isOutgoing ? '#991b1b' : '#065f46' }};border-radius:20px;padding:4px 14px;font-size:13px;font-weight:700;">
                    {{ $isOutgoing ? 'Uscita' : 'Entrata' }}
                </span>
                <span style="background:#f1f5f9;color:{{ $statusColor }};border-radius:20px;padding:4px 14px;font-size:13px;font-weight:700;border:1.5px solid {{ $statusColor }};">
                    {{ $statusLabel }}
                </span>
            </div>
        </div>

        {{-- Importo principale --}}
        <div style="text-align:center;padding:28px 16px;background:var(--bg);border-radius:16px;margin-bottom:24px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-muted);margin-bottom:6px;">
                {{ $kindLabel }}
            </div>
            <div style="font-size:42px;font-weight:900;letter-spacing:-.02em;color:{{ $isOutgoing ? '#dc2626' : '#059669' }};">
                {{ $isOutgoing ? '−' : '+' }}{{ ky_format($transfer->amount) }}&thinsp;<span style="font-size:22px;">{{ $transfer->currency_code }}</span>
            </div>
            @if($feeTransfer)
                <div style="font-size:12px;color:var(--ink-muted);margin-top:4px;">
                    di cui {{ ky_format($feeTransfer->amount) }} KY di commissione
                </div>
            @endif
            <div style="font-size:13px;color:var(--ink-muted);margin-top:8px;">
                {{ $eventDate?->format('d') }} {{ $mesiIt[(int)$eventDate?->format('n')] }} {{ $eventDate?->format('Y') }}
                alle {{ $eventDate?->format('H:i') }}
            </div>
        </div>

        {{-- Parti coinvolte --}}
        <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:center;margin-bottom:24px;">
            <div style="background:var(--bg);border:1.5px solid var(--line);border-radius:12px;padding:14px 16px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:6px;">Da</div>
                <div style="font-weight:700;font-size:15px;">{{ $transfer->fromAccount?->display_name ?? '—' }}</div>
                <div style="font-size:11px;color:var(--ink-muted);font-family:monospace;margin-top:2px;">{{ $transfer->fromAccount?->account_number }}</div>
            </div>
            <div style="font-size:24px;color:var(--ink-muted);">→</div>
            <div style="background:var(--bg);border:1.5px solid var(--line);border-radius:12px;padding:14px 16px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:6px;">A</div>
                <div style="font-weight:700;font-size:15px;">{{ $transfer->toAccount?->display_name ?? '—' }}</div>
                <div style="font-size:11px;color:var(--ink-muted);font-family:monospace;margin-top:2px;">{{ $transfer->toAccount?->account_number }}</div>
            </div>
        </div>

        {{-- Dettagli tecnici --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;">
            <div style="background:var(--bg);border-radius:10px;padding:12px 14px;">
                <div style="font-size:11px;color:var(--ink-muted);margin-bottom:3px;">Riferimento</div>
                <code style="font-size:13px;font-weight:600;">{{ $transfer->reference }}</code>
            </div>
            <div style="background:var(--bg);border-radius:10px;padding:12px 14px;">
                <div style="font-size:11px;color:var(--ink-muted);margin-bottom:3px;">Tipo movimento</div>
                <div style="font-size:13px;font-weight:600;">{{ $kindLabel }}</div>
            </div>
            @if($transfer->description)
            <div style="grid-column:1/-1;background:var(--bg);border-radius:10px;padding:12px 14px;">
                <div style="font-size:11px;color:var(--ink-muted);margin-bottom:3px;">Causale</div>
                <div style="font-size:14px;">{{ $transfer->description }}</div>
            </div>
            @endif
            @if($transfer->initiator)
            <div style="background:var(--bg);border-radius:10px;padding:12px 14px;">
                <div style="font-size:11px;color:var(--ink-muted);margin-bottom:3px;">Eseguito da</div>
                <div style="font-size:13px;font-weight:600;">{{ $transfer->initiator->name }}</div>
            </div>
            @endif
            <div style="background:var(--bg);border-radius:10px;padding:12px 14px;">
                <div style="font-size:11px;color:var(--ink-muted);margin-bottom:3px;">UUID</div>
                <code style="font-size:11px;word-break:break-all;">{{ $transfer->uuid }}</code>
            </div>
        </div>

        {{-- DEBUG TEMP --}}
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;font-family:monospace;">
            kind: <strong>{{ $transfer->kind }}</strong> |
            status: <strong>{{ $transfer->status }}</strong> |
            isOutgoing: <strong>{{ $isOutgoing ? 'true' : 'false' }}</strong> |
            isRefundable: <strong>{{ $isRefundable ? 'true' : 'false' }}</strong>
        </div>

        {{-- Azioni --}}
        <div style="display:flex;flex-wrap:wrap;gap:10px;padding-top:16px;border-top:1px solid var(--line);">
            @if($transfer->status === 'booked')
                <a href="{{ route('portal.receipt.download', $transfer->uuid) }}"
                   class="cta secondary"
                   style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                    📄 Scarica ricevuta PDF
                </a>
            @endif
            @if($isRefundable)
                <a href="{{ route('portal.refund.form', $transfer) }}" class="cta secondary"
                   style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;background:#fff7ed;border-color:#f59e0b;color:#92400e;">
                    ↩️ Emetti rimborso
                </a>
                <a href="{{ route('portal.credit-note.from-transfer', $transfer) }}" class="cta secondary"
                   style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                    📝 Nota di credito
                </a>
            @endif
        </div>
    </section>

    {{-- Box rimborsi già emessi (se presenti) --}}
    @if($relatedRefunds->isNotEmpty())
    <section class="card light-card" style="grid-column: 1 / -1;">
        <div class="section-head" style="margin-bottom:16px;">
            <div>
                <span class="eyebrow">Storico</span>
                <h3 class="section-title">Rimborsi emessi su questo movimento</h3>
            </div>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:2px solid var(--line);">
                    <th style="text-align:left;padding:8px 10px;font-weight:700;color:var(--ink-muted);">Data</th>
                    <th style="text-align:left;padding:8px 10px;font-weight:700;color:var(--ink-muted);">Rif.</th>
                    <th style="text-align:left;padding:8px 10px;font-weight:700;color:var(--ink-muted);">A</th>
                    <th style="text-align:right;padding:8px 10px;font-weight:700;color:var(--ink-muted);">Importo</th>
                </tr>
            </thead>
            <tbody>
                @foreach($relatedRefunds as $refund)
                @php $rd = $refund->booked_at ?? $refund->created_at; @endphp
                <tr style="border-bottom:1px solid var(--line);">
                    <td style="padding:10px;">{{ $rd?->format('d/m/Y H:i') }}</td>
                    <td style="padding:10px;"><code style="font-size:11px;">{{ $refund->reference }}</code></td>
                    <td style="padding:10px;">{{ $refund->toAccount?->display_name ?? '—' }}</td>
                    <td style="padding:10px;text-align:right;font-weight:700;color:#059669;">
                        −{{ ky_format($refund->amount) }} KY
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="padding:10px;font-weight:700;text-align:right;color:var(--ink-muted);">Totale rimborsato</td>
                    <td style="padding:10px;text-align:right;font-weight:800;color:#f59e0b;">
                        {{ ky_format($alreadyRefunded) }} KY
                    </td>
                </tr>
                @if($maxRefundable > 0)
                <tr>
                    <td colspan="3" style="padding:10px;font-weight:700;text-align:right;color:var(--ink-muted);">Ancora rimborsabile</td>
                    <td style="padding:10px;text-align:right;font-weight:800;color:#059669;">
                        {{ ky_format($maxRefundable) }} KY
                    </td>
                </tr>
                @endif
            </tfoot>
        </table>
    </section>
    @endif

</div>

@endsection
