@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('admin.listings.index') }}">Moderazione</a>
<a class="cta secondary" href="{{ route('portal.shop') }}">Vista portale</a>
@endsection

@section('content')

<section class="grid-cards" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:16px;">
    <article class="stat-card">
        <div class="eyebrow">Ordini filtrati</div>
        <div class="section-title">{{ $orderTotals['count'] }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Contabilizzati</div>
        <div class="section-title" style="color:var(--success);">{{ $orderTotals['bookedCount'] }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Volume</div>
        <div class="section-title">{{ ky_format($orderTotals['volume']) }} KY</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Stornati</div>
        <div class="section-title" style="color:var(--danger);">{{ $orderTotals['refunded'] }}</div>
    </article>
</section>

<section class="card light-card">
    <div class="section-head">
        <div><span class="eyebrow">Shop del circuito</span><h3 class="section-title">Ordini</h3></div>
        <span class="pill">{{ $orders->total() }} risultati</span>
    </div>

    @unless($supportsTransferRefunds)
        <div class="card light-card" style="border-left:4px solid #b58900;margin-bottom:12px;padding:10px 14px;font-size:12.5px;">
            Il database non ha ancora le colonne per lo storno amministrativo: gli ordini sono visibili ma l'azione "Storna" non è disponibile finché non viene applicato l'aggiornamento allo schema.
        </div>
    @endunless

    <form method="get" action="{{ route('admin.listings.orders') }}" style="margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:1fr 200px auto;gap:8px;align-items:end;">
            <div class="field">
                <label>Cerca prodotto, azienda o cliente</label>
                <input type="text" name="q" value="{{ $search }}" placeholder="Cerca…">
            </div>
            <div class="field">
                <label>Stato</label>
                <select name="status">
                    <option value="">Tutti</option>
                    <option value="booked" @selected($statusFilter === 'booked')>Contabilizzato</option>
                    <option value="pending" @selected($statusFilter === 'pending')>In elaborazione</option>
                    <option value="rejected" @selected($statusFilter === 'rejected')>Respinto</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;padding-bottom:1px;">
                <button type="submit" class="cta secondary">Filtra</button>
                @if($search || $statusFilter)
                    <a href="{{ route('admin.listings.orders') }}" class="cta secondary">Reset</a>
                @endif
            </div>
        </div>
    </form>

    @if($orders->isEmpty())
        <div class="empty-state">
            Nessun ordine trovato{{ $search || $statusFilter ? ' per i filtri selezionati' : '' }}.
        </div>
    @else
    <div style="overflow-x:auto;">
        <table class="admin-table" style="min-width:960px;">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Prodotto</th>
                    <th>Cliente</th>
                    <th>Azienda venditrice</th>
                    <th style="text-align:right;">Importo</th>
                    <th>Stato</th>
                    <th style="text-align:center;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                @php
                    $isRefundable = $supportsTransferRefunds
                        && $order->status === 'booked'
                        && $order->reversalChildren->isEmpty()
                        && $order->booked_at !== null
                        && $order->booked_at->greaterThanOrEqualTo(now()->subDays($refundWindowDays));

                    $isAlreadyRefunded = $supportsTransferRefunds && $order->reversalChildren->isNotEmpty();

                    $statoLabel = match ($order->status) {
                        'booked'   => 'Contabilizzato',
                        'pending'  => 'In elaborazione',
                        'rejected' => 'Respinto',
                        default    => ucfirst(str_replace('_', ' ', $order->status ?? 'N/D')),
                    };
                    $statoChip = $order->status === 'booked' ? 'success' : 'pink';

                    $buyerLabel = $order->fromAccount?->ownerUser?->name
                        ?? $order->fromAccount?->company?->name
                        ?? 'N/D';
                    $sellerLabel = $order->toAccount?->company?->name ?? 'N/D';
                @endphp
                <tr>
                    <td style="white-space:nowrap;font-size:12px;color:var(--ink-soft);">
                        {{ $order->booked_at?->format('d/m/Y') ?? '—' }}<br>
                        <span style="font-size:11px;color:var(--ink-muted);">{{ $order->booked_at?->format('H:i') }}</span>
                    </td>
                    <td>
                        @if($order->listing)
                            <a href="{{ route('portal.shop.show', $order->listing) }}" target="_blank" style="font-weight:700;color:var(--primary);text-decoration:none;">
                                {{ $order->listing->title }}
                            </a>
                        @else
                            <span style="color:var(--ink-muted);">Prodotto rimosso</span>
                        @endif
                        @if(($order->quantity ?? 1) > 1)
                            <div style="font-size:11px;color:var(--ink-muted);">x{{ $order->quantity }}</div>
                        @endif
                    </td>
                    <td style="font-size:12.5px;">{{ $buyerLabel }}</td>
                    <td style="font-size:12.5px;">{{ $sellerLabel }}</td>
                    <td style="text-align:right;font-weight:700;white-space:nowrap;">
                        {{ ky_format($order->amount) }} <span style="font-size:11px;font-weight:400;color:var(--ink-muted);">{{ $order->currency_code }}</span>
                    </td>
                    <td style="white-space:nowrap;">
                        <span class="chip {{ $statoChip }}" style="font-size:11px;padding:2px 7px;">{{ $statoLabel }}</span>
                        @if($isAlreadyRefunded)
                            <div style="font-size:11px;color:#e07e00;margin-top:3px;" title="Storno {{ $order->reversalChildren->first()->reference }}">↩ stornato</div>
                        @endif
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        @if($isRefundable)
                            <button type="button"
                                onclick="document.getElementById('refund-modal-{{ $order->id }}').showModal()"
                                class="cta secondary"
                                style="font-size:11px;padding:3px 10px;">
                                Storna
                            </button>
                        @elseif($isAlreadyRefunded)
                            <span style="font-size:11px;color:var(--ink-muted);">già stornato</span>
                        @elseif($supportsTransferRefunds && $order->status === 'booked')
                            <span style="font-size:11px;color:var(--ink-muted);">finestra scaduta</span>
                        @else
                            <span style="font-size:11px;color:var(--ink-muted);">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Modal storno per ogni ordine stornabile: riusa la stessa route admin.transfers.refund
         già usata per lo storno dei movimenti generici. --}}
    @foreach($orders as $order)
        @php
            $isRefundable = $supportsTransferRefunds
                && $order->status === 'booked'
                && $order->reversalChildren->isEmpty()
                && $order->booked_at !== null
                && $order->booked_at->greaterThanOrEqualTo(now()->subDays($refundWindowDays));
        @endphp
        @if($isRefundable)
        <dialog id="refund-modal-{{ $order->id }}" style="border:none;border-radius:16px;padding:28px 32px;max-width:440px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,.15);">
            <h4 style="margin:0 0 6px;">Storno ordine</h4>
            <p style="margin:0 0 16px;font-size:13px;color:var(--ink-soft);">
                {{ $order->listing->title ?? 'Prodotto rimosso' }} —
                {{ ky_format($order->amount) }} {{ $order->currency_code }}<br>
                Cliente <strong>{{ $order->fromAccount?->ownerUser?->name ?? $order->fromAccount?->company?->name ?? 'N/D' }}</strong>
                → azienda <strong>{{ $order->toAccount?->company?->name ?? 'N/D' }}</strong>
            </p>
            <form method="post" action="{{ route('admin.transfers.refund', $order) }}">
                @csrf
                <div class="field" style="margin-bottom:14px;">
                    <label>Motivazione storno</label>
                    <input name="reason" type="text" placeholder="Reso, prodotto non consegnato, errore..." required>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="cta secondary" onclick="document.getElementById('refund-modal-{{ $order->id }}').close()">Annulla</button>
                    <button type="submit" class="cta">Conferma storno</button>
                </div>
            </form>
        </dialog>
        @endif
    @endforeach

    @endif

    @if($orders->hasPages())
        <div style="margin-top:14px;">{{ $orders->withQueryString()->links() }}</div>
    @endif
</section>

@endsection
