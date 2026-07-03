@extends('layouts.portal')

@section('content')
<style>
.movements-feed {
    display: none;
}
.movement-card {
    display: grid;
    gap: 10px;
    padding: 14px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: var(--shadow-xs);
}
.movement-card__top {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: start;
}
.movement-card__name {
    display: block;
    font-size: 14px;
    font-weight: 800;
    color: var(--ink);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.movement-card__desc {
    display: block;
    margin-top: 3px;
    font-size: 12px;
    color: var(--ink-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.movement-card__amount {
    font-size: 15px;
    font-weight: 900;
    text-align: right;
    white-space: nowrap;
}
.movement-card__meta {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    align-items: center;
    font-size: 11.5px;
    color: var(--ink-muted);
}
@media (max-width: 768px) {
    .account-hero {
        display: none !important;
    }
    .light-card {
        max-width: calc(100vw - 20px);
        overflow: hidden;
    }
    .section-head {
        align-items: flex-start;
    }
    .section-head .cta {
        width: auto !important;
        max-width: 160px;
    }
    #filters-form > div {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        gap: 8px !important;
    }
    #filters-form > div > div,
    #filters-form select,
    #filters-form input,
    #filters-form a {
        width: 100% !important;
        min-width: 0 !important;
        max-width: none !important;
    }
    #filters-form > div > div[style*="margin-left:auto"] {
        grid-column: 1 / -1;
        margin-left: 0 !important;
        display: grid !important;
        grid-template-columns: 1fr 1fr;
    }
    .movements-feed {
        display: grid;
        gap: 10px;
        margin-top: 14px;
        min-width: 0;
    }
    .movements-table-desktop {
        display: none !important;
    }
    .movement-card {
        min-width: 0;
        overflow: hidden;
    }
    .movement-card__top {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .movement-card__amount {
        max-width: 128px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .movement-card__meta {
        flex-wrap: wrap;
    }
}
@media (max-width: 480px) {
    #filters-form > div {
        grid-template-columns: 1fr;
    }
    #filters-form > div > div[style*="margin-left:auto"] {
        grid-template-columns: 1fr;
    }
    .movement-card__top {
        grid-template-columns: 1fr;
    }
    .movement-card__amount {
        max-width: none;
        text-align: left;
    }
}
</style>
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
                        {{ ky_format($currentBalance) }}&thinsp;KY
                    </div>
                </div>
                <div style="padding:0 20px;border-right:1px solid rgba(255,255,255,.12);">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);white-space:nowrap;">Disponibile</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.01em;margin-top:3px;white-space:nowrap;">
                        {{ ky_format($availableBalance) }}&thinsp;KY
                    </div>
                </div>
                <div style="padding:0 20px;border-right:1px solid rgba(255,255,255,.12);">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);white-space:nowrap;">Massimale</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.01em;margin-top:3px;white-space:nowrap;">
                        {{ ky_format($massimale) }}&thinsp;KY
                    </div>
                </div>
                <div style="padding:0 20px;">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);white-space:nowrap;">Disponib. commerciale</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.01em;margin-top:3px;white-space:nowrap;">
                        {{ ky_format($commercialAvailability) }}&thinsp;KY
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

                {{-- Ricerca per nome controparte / causale / riferimento --}}
                <div>
                    <label for="filter-search" style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca</label>
                    <input type="text" id="filter-search" name="search" value="{{ $filters['search'] }}"
                        placeholder="Nome, causale, riferimento..." maxlength="100"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:200px;"
                        onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('filters-form').submit();}">
                </div>

                {{-- Periodo preimpostato (stile bancario) --}}
                <div>
                    <label for="period-preset" style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Periodo</label>
                    <select id="period-preset"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:170px;">
                        <option value="">— Personalizzato —</option>
                        <optgroup label="Giorni">
                            <option value="last7">Ultimi 7 giorni</option>
                            <option value="last30">Ultimi 30 giorni</option>
                            <option value="last90">Ultimi 90 giorni</option>
                            <option value="last365">Ultimi 365 giorni</option>
                        </optgroup>
                        <optgroup label="Mese">
                            <option value="cur_month">Mese corrente</option>
                            <option value="prev_month">Mese precedente</option>
                        </optgroup>
                        <optgroup label="Trimestre">
                            <option value="cur_quarter">Trimestre corrente</option>
                            <option value="prev_quarter">Trimestre precedente</option>
                        </optgroup>
                        <optgroup label="Anno">
                            <option value="cur_year">Anno corrente</option>
                            <option value="prev_year">Anno precedente</option>
                        </optgroup>
                    </select>
                </div>

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Da</label>
                    <input type="date" id="filter-from" name="from" value="{{ $filters['from'] }}"
                        max="{{ date('Y-m-d') }}"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;"
                        onchange="document.getElementById('period-preset').value='';document.getElementById('filters-form').submit()">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">A</label>
                    <input type="date" id="filter-to" name="to" value="{{ $filters['to'] }}"
                        max="{{ date('Y-m-d') }}"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;"
                        onchange="document.getElementById('period-preset').value='';document.getElementById('filters-form').submit()">
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
                        <optgroup label="— Operazioni admin —">
                            <option value="portal_fee" {{ $filters['kind'] === 'portal_fee' ? 'selected' : '' }}>Commissione</option>
                        </optgroup>
                    </select>
                </div>
                {{-- Filtro sottoconto (solo per titolari con sottoconti) --}}
                @if(isset($childAccounts) && $childAccounts->isNotEmpty())
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Sottoconto</label>
                    <select name="sub_account_id" onchange="document.getElementById('filters-form').submit()"
                        style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;max-width:200px;">
                        <option value="">Tutti i conti</option>
                        <option value="{{ $currentAccount->id }}" {{ ($filters['sub_account_id'] ?? 0) == $currentAccount->id ? 'selected' : '' }}>
                            {{ $currentAccount->account_name ?? $currentAccount->display_name }} (principale)
                        </option>
                        @foreach($childAccounts as $child)
                            <option value="{{ $child->id }}" {{ ($filters['sub_account_id'] ?? 0) == $child->id ? 'selected' : '' }}>
                                {{ $child->account_name ?? $child->display_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @endif

                @if(array_filter(array_map('strval', $filters)))
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

        <div class="movements-feed">
            @forelse ($transfers as $transfer)
                @php
                    $childIds = isset($childAccounts) ? $childAccounts->pluck('id')->all() : [];
                    $isOutgoing = $transfer->from_account_id === $currentAccount->id
                        || in_array($transfer->from_account_id, $childIds, true);
                    $counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount;
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
                @endphp
                <a class="movement-card" href="{{ route('portal.movements.show', $transfer) }}">
                    <span class="movement-card__top">
                        <span style="min-width:0;">
                            <span class="movement-card__name">{{ $counterparty?->display_name ?? 'N/D' }}</span>
                            <span class="movement-card__desc">{{ $transfer->description ?: 'Movimento circuito' }}</span>
                        </span>
                        <span class="movement-card__amount" style="color:{{ $isOutgoing ? 'var(--danger)' : 'var(--success)' }};">
                            {{ $isOutgoing ? '-' : '+' }}{{ ky_format($transfer->amount) }} KY
                        </span>
                    </span>
                    <span class="movement-card__meta">
                        <span>{{ optional($eventDate)->format('d/m/Y H:i') }} · {{ $flowLabel }}</span>
                        <span class="chip {{ $statusClass }}" style="font-size:9.5px;min-height:20px;padding:0 8px;">{{ $statusLabel }}</span>
                    </span>
                </a>
            @empty
                <div class="empty-state" style="padding:18px;">
                    <strong>Nessun movimento presente.</strong>
                </div>
            @endforelse
        </div>

        <table class="transactions-table movements-table-desktop" style="margin-top:14px;">
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
                        // Per il conto padre: il movimento è "outgoing" se parte da padre o da un figlio
                        $childIds = isset($childAccounts) ? $childAccounts->pluck('id')->all() : [];
                        $isOutgoing = $transfer->from_account_id === $currentAccount->id
                            || in_array($transfer->from_account_id, $childIds, true);
                        $counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount;

                        // Individua se il movimento appartiene a un sottoconto
                        $subAccountSource = null;
                        if (isset($childAccounts) && $childAccounts->isNotEmpty()) {
                            if (in_array($transfer->from_account_id, $childIds, true)) {
                                $subAccountSource = $childAccounts->firstWhere('id', $transfer->from_account_id);
                            } elseif (in_array($transfer->to_account_id, $childIds, true)) {
                                $subAccountSource = $childAccounts->firstWhere('id', $transfer->to_account_id);
                            }
                        }
                        $isPendingRequestToConfirm = $transfer->status === 'pending' && $isOutgoing && $transfer->kind === 'portal_collection_request';
                        $refundableKinds = ['portal_payment', 'portal_payment_request', 'portal_collection_request', 'trade_payment', 'nfc_card', 'code'];
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
                                @if($subAccountSource)
                                    <span style="background:#ede9fe;border:1px solid #c4b5fd;color:#6d28d9;border-radius:5px;padding:1px 6px;font-size:9px;font-weight:700;white-space:nowrap;" title="Sottoconto: {{ $subAccountSource->account_name }}">
                                        ↳ {{ $subAccountSource->account_name }}
                                    </span>
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
                                {{ $isOutgoing ? '−' : '+' }}{{ ky_format($transfer->amount) }}&thinsp;{{ $transfer->currency_code }}
                            </span>
                        </td>

                        {{-- Stato --}}
                        <td><span class="chip {{ $statusClass }}" style="font-size:9.5px;min-height:20px;padding:0 8px;">{{ $statusLabel }}</span></td>

                        {{-- Azioni --}}
                        <td style="white-space:nowrap;text-align:right;padding-right:14px;">
                            <a href="{{ route('portal.movements.show', $transfer) }}"
                               class="cta secondary"
                               style="font-size:11px;padding:4px 9px;min-height:26px;display:inline-flex;align-items:center;gap:4px;text-decoration:none;"
                               title="Dettaglio movimento">
                                🔍
                            </a>
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
                            @if($isOutgoing && $counterparty && !$counterparty->is_system_account)
                            <a href="{{ route('portal.invia') }}?to={{ $counterparty->id }}&amount={{ ky_input($transfer->amount) }}&desc={{ urlencode($transfer->description ?? '') }}"
                               class="cta secondary"
                               style="font-size:11px;padding:4px 9px;min-height:26px;display:inline-flex;align-items:center;gap:4px;text-decoration:none;margin-top:4px;"
                               title="Ripeti questo pagamento">
                                🔁
                            </a>
                            @endif
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

@push('scripts')
<script>
(function () {
    const preset  = document.getElementById('period-preset');
    const fromEl  = document.getElementById('filter-from');
    const toEl    = document.getElementById('filter-to');
    const form    = document.getElementById('filters-form');
    const today   = new Date();

    function fmt(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dd}`;
    }

    function addDays(d, n) {
        return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
    }

    function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
    function endOfMonth(d)   { return new Date(d.getFullYear(), d.getMonth() + 1, 0); }
    function quarter(d)      { return Math.floor(d.getMonth() / 3); }

    function computeRange(value) {
        const t = today;
        switch (value) {
            case 'last7':
                return { from: fmt(addDays(t, -6)),   to: fmt(t) };
            case 'last30':
                return { from: fmt(addDays(t, -29)),  to: fmt(t) };
            case 'last90':
                return { from: fmt(addDays(t, -89)),  to: fmt(t) };
            case 'last365':
                return { from: fmt(addDays(t, -364)), to: fmt(t) };
            case 'cur_month':
                return { from: fmt(startOfMonth(t)),  to: fmt(t) };
            case 'prev_month': {
                const pm = new Date(t.getFullYear(), t.getMonth() - 1, 1);
                return { from: fmt(pm), to: fmt(endOfMonth(pm)) };
            }
            case 'cur_quarter': {
                const q = quarter(t);
                return { from: fmt(new Date(t.getFullYear(), q * 3, 1)), to: fmt(t) };
            }
            case 'prev_quarter': {
                const pq = quarter(t) - 1;
                const yr = pq < 0 ? t.getFullYear() - 1 : t.getFullYear();
                const qn = ((pq % 4) + 4) % 4;
                const qs = new Date(yr, qn * 3, 1);
                const qe = endOfMonth(new Date(yr, qn * 3 + 2, 1));
                return { from: fmt(qs), to: fmt(qe) };
            }
            case 'cur_year':
                return { from: fmt(new Date(t.getFullYear(), 0, 1)), to: fmt(t) };
            case 'prev_year':
                return {
                    from: fmt(new Date(t.getFullYear() - 1, 0, 1)),
                    to:   fmt(new Date(t.getFullYear() - 1, 11, 31))
                };
            default:
                return null;
        }
    }

    preset.addEventListener('change', function () {
        const range = computeRange(this.value);
        if (!range) return;
        fromEl.value = range.from;
        toEl.value   = range.to;
        form.submit();
    });

    // Rileva il preset attivo al caricamento della pagina
    (function detectActivePreset() {
        const curFrom = fromEl.value;
        const curTo   = toEl.value;
        if (!curFrom && !curTo) return;
        const candidates = [
            'last7','last30','last90','last365',
            'cur_month','prev_month',
            'cur_quarter','prev_quarter',
            'cur_year','prev_year',
        ];
        for (const v of candidates) {
            const r = computeRange(v);
            if (r && r.from === curFrom && r.to === curTo) {
                preset.value = v;
                return;
            }
        }
    })();
})();
</script>
@endpush

@endsection
                                                                                                        
