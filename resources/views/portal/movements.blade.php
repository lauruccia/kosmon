@extends('layouts.portal')

@section('content')
    {{-- ===== STRIP SALDI ORIZZONTALE ===== --}}
    <section class="card account-hero" style="padding:0;margin-bottom:16px;">
        <div style="position:relative;z-index:1;display:flex;align-items:center;gap:0;flex-wrap:wrap;padding:18px 22px;">

            {{-- Nome conto --}}
            <div style="padding-right:24px;border-right:1px solid rgba(255,255,255,.15);min-width:160px;">
                <div class="k-tag" style="font-size:9px;margin-bottom:6px;opacity:.8;">Timeline saldi</div>
                <div style="font-size:16px;font-weight:800;color:#fff;line-height:1.25;white-space:nowrap;">
                    {{ $currentAccount->display_name }}
                </div>
            </div>

            {{-- Metriche --}}
            <div style="flex:1;display:flex;gap:0;flex-wrap:wrap;padding:0 8px;">
                <div style="padding:0 20px;border-right:1px solid rgba(255,255,255,.12);">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);white-space:nowrap;">Saldo attuale</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.01em;margin-top:3px;white-space:nowrap;">
                        {{ number_format($currentBalance, 2, ',', '.') }}&thinsp;KY
                    </div>
                </div>
                <div style="padding:0 20px;border-right:1px solid rgba(255,255,255,.12);">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);white-space:nowrap;">Disponibile</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.01em;margin-top:3px;white-space:nowrap;">
                        {{ number_format($availableBalance, 2, ',', '.') }}&thinsp;KY
                    </div>
                </div>
                <div style="padding:0 20px;border-right:1px solid rgba(255,255,255,.12);">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);white-space:nowrap;">Massimale</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.01em;margin-top:3px;white-space:nowrap;">
                        {{ number_format($massimale, 2, ',', '.') }}&thinsp;KY
                    </div>
                </div>
                <div style="padding:0 20px;">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);white-space:nowrap;">Disponib. commerciale</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.01em;margin-top:3px;white-space:nowrap;">
                        {{ number_format($commercialAvailability, 2, ',', '.') }}&thinsp;KY
                    </div>
                </div>
            </div>

            {{-- CTA Paga + Incassa --}}
            <div style="display:flex;gap:8px;flex-shrink:0;padding-left:16px;border-left:1px solid rgba(255,255,255,.15);">
                <a href="{{ route('portal.pay.form') }}"
                   style="display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 22px;border-radius:8px;font-size:13px;font-weight:700;letter-spacing:.02em;background:#fff;color:var(--primary);border:none;text-decoration:none;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.18);transition:transform .12s,box-shadow .12s;"
                   onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 16px rgba(0,0,0,.28)'"
                   onmouseout="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(0,0,0,.18)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                    Paga
                </a>
                <a href="{{ route('portal.receive.form') }}"
                   style="display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 22px;border-radius:8px;font-size:13px;font-weight:700;letter-spacing:.02em;background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.3);text-decoration:none;white-space:nowrap;transition:background .14s,transform .12s;margin-right:2px;"
                   onmouseover="this.style.background='rgba(255,255,255,.24)';this.style.transform='translateY(-1px)'"
                   onmouseout="this.style.background='rgba(255,255,255,.14)';this.style.transform=''">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                    Incassa
                </a>
            </div>
        </div>
    </section>

    {{-- ===== TIMELINE MOVIMENTI 100% ===== --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <div class="k-tag">Timeline</div>
                <h2 class="card-title" style="margin-top:12px;">Tutti i movimenti</h2>
            </div>
            <a class="cta secondary" href="{{ route('portal.accounts.structure') }}">Sottoconti</a>
        </div>

        {{-- Barra filtri --}}
        <form method="GET" action="{{ route('portal.movements') }}" id="filters-form" style="margin:16px 0 4px;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Da</label>
                    <input type="date" name="from" value="{{ $filters['from'] }}"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;"
                        onchange="document.getElementById('filters-form').submit()">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">A</label>
                    <input type="date" name="to" value="{{ $filters['to'] }}"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;"
                        onchange="document.getElementById('filters-form').submit()">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Direzione</label>
                    <select name="direction" onchange="document.getElementById('filters-form').submit()"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
                        <option value="">Tutte</option>
                        <option value="in" {{ $filters['direction'] === 'in' ? 'selected' : '' }}>Entrate</option>
                        <option value="out" {{ $filters['direction'] === 'out' ? 'selected' : '' }}>Uscite</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Stato</label>
                    <select name="status" onchange="document.getElementById('filters-form').submit()"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
                        <option value="">Tutti</option>
                        <option value="booked" {{ $filters['status'] === 'booked' ? 'selected' : '' }}>Contabilizzato</option>
                        <option value="pending" {{ $filters['status'] === 'pending' ? 'selected' : '' }}>In attesa</option>
                        <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Annullato</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Tipo</label>
                    <select name="kind" onchange="document.getElementById('filters-form').submit()"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
                        <option value="">Tutti</option>
                        <option value="trade_payment" {{ $filters['kind'] === 'trade_payment' ? 'selected' : '' }}>Pagamento</option>
                        <option value="portal_installment" {{ $filters['kind'] === 'portal_installment' ? 'selected' : '' }}>Rata</option>
                        <option value="portal_netting" {{ $filters['kind'] === 'portal_netting' ? 'selected' : '' }}>Compensazione</option>
                        <option value="portal_refund" {{ $filters['kind'] === 'portal_refund' ? 'selected' : '' }}>Rimborso</option>
                        <option value="portal_credit_note" {{ $filters['kind'] === 'portal_credit_note' ? 'selected' : '' }}>Nota credito</option>
                        <option value="portal_qr_payment" {{ $filters['kind'] === 'portal_qr_payment' ? 'selected' : '' }}>QR Payment</option>
                        <option value="portal_cashback" {{ $filters['kind'] === 'portal_cashback' ? 'selected' : '' }}>Cashback</option>
                    </select>
                </div>
                @if(array_filter($filters))
                    <a href="{{ route('portal.movements') }}"
                       style="padding:7px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;white-space:nowrap;align-self:flex-end;">
                        Azzera filtri
                    </a>
                @endif

                {{-- Export — stessa riga, spinto a destra --}}
                <div style="margin-left:auto;display:flex;gap:8px;align-self:flex-end;">
                    <a id="csv-export-btn"
                       href="{{ route('portal.movements.export-csv', array_filter($filters)) }}"
                       style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;background:var(--surface-soft);color:var(--ink-muted);border:1px solid var(--line);text-decoration:none;white-space:nowrap;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Scarica CSV
                    </a>
                    <a href="{{ route('portal.prima-nota.export', array_filter(array_intersect_key($filters, array_flip(['from','to'])))) }}"
                       style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;background:var(--primary-soft, #eff6ff);color:var(--primary);border:1px solid var(--primary-soft, #bfdbfe);text-decoration:none;white-space:nowrap;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Prima nota
                    </a>
                </div>
            </div>
        </form>

        @php
        $mesiIt = [
            1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',
            5=>'Maggio',6=>'Giugno',7=>'Luglio',8=>'Agosto',
            9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre',
        ];
        $prevMonthKey = null;
        @endphp

        <table class="transactions-table" style="margin-top:14px;">
            <thead>
                <tr>
                    <th style="width:70px;">Data</th>
                    <th>Movimento</th>
                    <th style="width:90px;">Tipo</th>
                    <th style="width:110px;text-align:right;">Importo</th>
                    <th style="width:110px;">Stato</th>
                    <th style="width:1%;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transfers as $transfer)
                    @php
                        $isOutgoing = $transfer->from_account_id === $currentAccount->id;
                        $counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount;
                        $isPendingRequestToConfirm = $transfer->status === 'pending' && $isOutgoing && $transfer->kind === 'portal_collection_request';
                        $refundableKinds = ['portal_payment', 'portal_collection_request', 'trade_payment'];
                        $isRefundable = $transfer->status === 'booked'
                            && ! $isOutgoing
                            && in_array($transfer->kind, $refundableKinds, true);
                        $isCashback = $transfer->kind === 'portal_cashback';
                        $flowLabel = match (true) {
                            $isCashback                                     => 'Cashback',
                            $transfer->status === 'pending' && $isOutgoing  => 'Da confermare',
                            $transfer->status === 'pending' && !$isOutgoing => 'In attesa',
                            $isOutgoing                                     => 'Addebito',
                            default                                         => 'Accredito',
                        };
                        $statusLabel = match ($transfer->status) {
                            'pending'  => 'In attesa',
                            'booked'   => 'Contabilizzato',
                            'rejected' => 'Rifiutato',
                            default    => ucfirst($transfer->status ?? 'N/D'),
                        };
                        $statusClass = $transfer->status === 'booked' ? 'success' : 'pink';
                        $eventDate   = $transfer->booked_at ?? $transfer->created_at;
                        $monthKey    = optional($eventDate)->format('Y-m');
                        $monthLabel  = optional($eventDate)
                            ? ($mesiIt[(int)$eventDate->format('n')] . ' ' . $eventDate->format('Y'))
                            : '';
                    @endphp

                    @if($monthKey && $monthKey !== $prevMonthKey)
                        <tr class="trx-month-group">
                            <td colspan="6">{{ $monthLabel }}</td>
                        </tr>
                        @php $prevMonthKey = $monthKey; @endphp
                    @endif

                    <tr>
                        {{-- Data compatta: "28 mag" --}}
                        <td>
                            <div class="date-block">
                                {{ optional($eventDate)->format('d') }}
                                <span>{{ optional($eventDate)->isoFormat('MMM') }}</span>
                            </div>
                        </td>

                        {{-- Movimento: controparte + reference + descrizione --}}
                        <td style="max-width:0;">
                            <div style="display:flex;align-items:baseline;gap:8px;min-width:0;">
                                <span style="font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;">
                                    {{ $counterparty?->display_name ?? 'N/D' }}
                                </span>
                                <span style="font-size:11px;color:var(--ink-muted);font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;">
                                    {{ $transfer->reference }}
                                </span>
                                @if($isCashback)
                                    <span style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;border-radius:5px;padding:1px 6px;font-size:9px;font-weight:700;white-space:nowrap;">CASHBACK</span>
                                @endif
                            </div>
                            <div style="font-size:11.5px;color:var(--ink-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:480px;margin-top:1px;">
                                {{ $transfer->description ?: 'Movimento circuito' }}
                            </div>
                        </td>

                        {{-- Tipo flusso (solo badge colorato, no subtitle) --}}
                        <td>
                            <span class="flow {{ $isOutgoing ? 'out' : 'in' }}">{{ $flowLabel }}</span>
                        </td>

                        {{-- Importo su unica riga --}}
                        <td style="text-align:right;white-space:nowrap;">
                            <span style="font-size:14px;font-weight:800;letter-spacing:-.01em;color:{{ $isOutgoing ? 'var(--danger)' : 'var(--success)' }};">
                                {{ $isOutgoing ? '−' : '+' }}{{ number_format($transfer->amount, 2, ',', '.') }}&thinsp;{{ $transfer->currency_code }}
                            </span>
                        </td>

                        {{-- Stato --}}
                        <td><span class="chip {{ $statusClass }}" style="font-size:9.5px;min-height:20px;padding:0 8px;">{{ $statusLabel }}</span></td>

                        {{-- Azioni --}}
                        <td style="white-space:nowrap;text-align:right;padding-right:14px;">
                            @if ($isPendingRequestToConfirm)
                                <form method="post" action="{{ route('portal.receive.requests.confirm', $transfer) }}" style="display:inline-flex;gap:6px;align-items:center;">
                                    @csrf
                                    <button type="submit" class="cta" style="font-size:12px;padding:5px 11px;min-height:28px;">Conferma</button>
                                </form>
                                <form method="post" action="{{ route('portal.receive.requests.reject', $transfer) }}" style="display:inline-flex;gap:6px;align-items:center;margin-left:4px;">
                                    @csrf
                                    <button type="submit" class="cta secondary" style="font-size:12px;padding:5px 11px;min-height:28px;">Rifiuta</button>
                                </form>
                            @endif
                            @if ($isRefundable)
                                <a href="{{ route('portal.refund.form', $transfer) }}" class="cta secondary" style="font-size:11px;padding:5px 10px;min-height:26px;">Rimborso</a>
                                <a href="{{ route('portal.credit-note.from-transfer', $transfer) }}" class="cta secondary" style="font-size:11px;padding:5px 10px;min-height:26px;margin-left:4px;">Nota credito</a>
                            @endif
                            @if($transfer->status === 'booked')
                            <a href="{{ route('portal.receipt.download', $transfer->uuid) }}"
                               class="cta secondary"
                               style="font-size:11px;padding:4px 9px;min-height:26px;display:inline-flex;align-items:center;gap:4px;text-decoration:none;margin-top:4px;"
                               title="Scarica ricevuta PDF">
                                &#x1F4C4; PDF
                            </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="subtle" style="text-align:center;padding:28px;">Nessun movimento presente.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{ $transfers->links() }}
        @if($transfers->total() > 0)
            <div style="font-size:12px;color:var(--ink-muted);text-align:right;margin-top:8px;">
                Mostrando {{ $transfers->firstItem() }}--{{ $transfers->lastItem() }} di {{ $transfers->total() }} movimenti
            </div>
        @endif
    </section>
@endsection
                                                                                                        