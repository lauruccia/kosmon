@extends('layouts.portal')

@section('page-actions')
<a class="cta" href="{{ route('admin.listing-offers.create') }}">+ Nuova offerta</a>
<a class="cta secondary" href="{{ route('portal.shop.offers') }}" target="_blank">Vista pubblica</a>
<a class="cta secondary" href="{{ route('admin.listings.index') }}">← Torna allo shop</a>
@endsection

@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<section class="grid-cards" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:16px;max-width:420px;">
    <article class="stat-card">
        <div class="eyebrow">Offerte attive ora</div>
        <div class="section-title" style="color:var(--success);">{{ $activeCount }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Totale storico</div>
        <div class="section-title">{{ $offers->total() }}</div>
    </article>
</section>

<section class="card light-card">
    <div class="section-head">
        <div><span class="eyebrow">Shop del circuito</span><h3 class="section-title">Offerte della settimana</h3></div>
        <span class="pill">{{ $offers->total() }} offerte</span>
    </div>

    @if($offers->isEmpty())
        <div class="empty-state">Nessuna offerta creata finora. <a href="{{ route('admin.listing-offers.create') }}">Creane una</a>.</div>
    @else
    <div style="overflow-x:auto;">
        <table class="admin-table" style="min-width:1040px;">
            <thead>
                <tr>
                    <th>Prodotto</th>
                    <th>Azienda</th>
                    <th style="text-align:right;">Prezzo pieno</th>
                    <th style="text-align:right;">Prezzo offerta</th>
                    <th style="text-align:center;">Sconto</th>
                    <th style="text-align:center;">% KY offerta</th>
                    <th>Scade il</th>
                    <th>Stato</th>
                    <th>Creata da</th>
                    <th style="text-align:center;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @foreach($offers as $offer)
                @php
                    $status = $offer->is_active ? 'active' : ($offer->cancelled_at ? 'cancelled' : 'expired');
                    $statusLabel = ['active' => 'Attiva', 'cancelled' => 'Terminata', 'expired' => 'Scaduta'][$status];
                    $statusColor = ['active' => 'var(--success)', 'cancelled' => 'var(--danger)', 'expired' => 'var(--ink-muted)'][$status];
                @endphp
                <tr>
                    <td>
                        @if($offer->listing)
                            <a href="{{ route('portal.shop.show', $offer->listing) }}" target="_blank" style="font-weight:700;color:var(--primary);text-decoration:none;">{{ $offer->listing->title }}</a>
                        @else
                            <span style="color:var(--ink-muted);">Prodotto eliminato</span>
                        @endif
                    </td>
                    <td style="color:var(--ink-soft);">{{ $offer->listing?->company?->name ?? '—' }}</td>
                    {{-- Importi sempre in centesimi: ky_format() fa la conversione, mai number_format() diretto. --}}
                    <td style="text-align:right;color:var(--ink-muted);text-decoration:line-through;">{{ ky_format($offer->full_price_ky_snapshot) }} KY</td>
                    <td style="text-align:right;font-weight:700;color:var(--success);">{{ ky_format($offer->offer_price_ky) }} KY</td>
                    <td style="text-align:center;font-weight:700;">-{{ $offer->discount_percent }}%</td>
                    <td style="text-align:center;">{{ $offer->offer_ky_percentage }}%</td>
                    <td style="font-size:12px;white-space:nowrap;">{{ $offer->expires_at->format('d/m/Y H:i') }}</td>
                    <td><span style="font-weight:700;color:{{ $statusColor }};">{{ $statusLabel }}</span></td>
                    <td style="font-size:12px;color:var(--ink-soft);">{{ $offer->createdByUser->name ?? '—' }}</td>
                    <td style="text-align:center;white-space:nowrap;">
                        @if($offer->is_active)
                        <form method="POST" action="{{ route('admin.listing-offers.destroy', $offer) }}" style="display:inline;" onsubmit="return confirm('Terminare subito questa offerta? Il prodotto tornerà al prezzo pieno.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="cta secondary" style="font-size:11px;padding:3px 10px;color:var(--danger);border-color:rgba(159,18,57,.3);">Termina ora</button>
                        </form>
                        @else
                            <span style="color:var(--line-strong);">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if($offers->hasPages())
        <div style="margin-top:14px;">{{ $offers->withQueryString()->links() }}</div>
    @endif
</section>
@endsection
