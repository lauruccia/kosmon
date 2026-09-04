@extends('layouts.portal')

{{--
    "I miei prodotti" (2026-08-12): elenco dedicato dei prodotti della PROPRIA
    azienda, in qualunque stato (attivo/sospeso/bozza/scaduto). A differenza
    dello shop pubblico (portal.shop), qui non compaiono MAI prodotti di
    altre aziende — serve a chi pubblica prodotti per ritrovarli e
    verificarli senza scorrere l'intero catalogo del circuito.
    Vedi ListingController::mine().
--}}

@section('content')
<x-shop.styles />
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<div style="margin-bottom:16px;">
    <a href="{{ route('portal.shop') }}" class="shop-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Torna allo shop
    </a>
</div>

<section class="card light-card shop-toolbar-card">
    <form method="GET" action="{{ route('portal.shop.mine') }}" class="shop-toolbar">
        <div class="shop-toolbar-field shop-toolbar-field--grow">
            <label>Cerca</label>
            <div class="shop-search-input">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="q" value="{{ $searchQuery }}" placeholder="Titolo prodotto...">
            </div>
        </div>
        <div class="shop-toolbar-field" style="min-width:180px;">
            <label>Stato</label>
            <select name="status" class="km-select" data-no-search>
                <option value="">Tutti gli stati</option>
                @foreach($statuses as $s)
                    <option value="{{ $s }}" @selected($statusFilter === $s)>{{ \App\Models\Listing::statusLabel($s) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="cta">Filtra</button>
        @if($searchQuery || $statusFilter !== '')
            <a href="{{ route('portal.shop.mine') }}" class="cta secondary">✕ Reset</a>
        @endif
        <div class="shop-toolbar-actions">
            @if(auth()->user()->canAccessMarketplace() && auth()->user()->company?->isInDirectory())
                <a class="cta" href="{{ route('portal.shop.create') }}" style="white-space:nowrap;">Pubblica un nuovo prodotto</a>
            @endif
        </div>
    </form>
</section>

<div class="catalog-grid" style="margin-top:18px;">
    @forelse($listings as $listing)
    {{-- Qui il prezzo mostrato e' quello di LISTINO, non quello effettivo:
         questa e' la pagina del venditore, e il venditore deve vedere il
         prezzo che ha impostato lui, non quello scontato dall'offerta. Per lo
         stesso motivo niente badge "-X%" e niente "100% KY": al loro posto c'e'
         il badge di stato, che qui e' l'informazione che conta davvero. --}}
    <x-shop.product-card
        :listing="$listing"
        :href="$listing->status === 'active' ? route('portal.shop.show', $listing) : null"
        :price="$listing->price_ky"
        :old-price="null"
        :mix-label="$listing->ky_percentage !== 100 ? $listing->ky_badge_label : null"
        :mix-style="$listing->ky_badge_color"
        :inactive="$listing->status !== 'active'"
        :show-offer="false"
        :show-full-ky="false"
        :status-badge="['status' => $listing->status, 'label' => \App\Models\Listing::statusLabel($listing->status)]">

        <x-slot:meta>
            <span class="chip">{{ $listing->category_label }}</span>
            <span class="subtle" style="font-size:12px;">{{ $listing->views_count }} visualizzazioni</span>
        </x-slot:meta>

        <x-slot:extra>
            <div class="subtle" style="font-size:12px;">
                {{ $listing->stock_label }}
                @if($listing->expires_at) · scade il {{ $listing->expires_at->locale('it')->isoFormat('D MMM YYYY') }} @endif
            </div>
        </x-slot:extra>

        <x-slot:actions>
            <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary" style="flex:1;text-align:center;">Modifica</a>
            {{-- Varianti (2026-08-25, fase D): taglie, colori, formati. --}}
            <a href="{{ route('portal.shop.variants', $listing) }}" class="cta secondary" style="flex:1;text-align:center;">
                Varianti{{ $listing->has_variants ? ' ✓' : '' }}
            </a>
            @if(in_array($listing->status, ['active', 'suspended'], true))
            <form method="POST" action="{{ route('portal.shop.status', $listing) }}" style="flex:1;">
                @csrf
                <input type="hidden" name="status" value="{{ $listing->status === 'active' ? 'suspended' : 'active' }}">
                <button type="submit" class="cta secondary" style="width:100%;">{{ $listing->status === 'active' ? 'Sospendi' : 'Riattiva' }}</button>
            </form>
            @endif
            <form method="POST" action="{{ route('portal.shop.destroy', $listing) }}" onsubmit="return confirm('Rimuovere definitivamente questo prodotto dallo shop?')" style="flex:1;">
                @csrf @method('DELETE')
                <button type="submit" class="cta secondary shop-danger-btn" style="width:100%;">Elimina</button>
            </form>
        </x-slot:actions>
    </x-shop.product-card>
    @empty
    <x-shop.empty :message="($searchQuery || $statusFilter !== '')
        ? 'Nessun prodotto trovato con questi filtri.'
        : 'Non hai ancora pubblicato nessun prodotto nello shop.'">
        @if($searchQuery || $statusFilter !== '')
            <a href="{{ route('portal.shop.mine') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Rimuovi filtri</a>
        @elseif(auth()->user()->canAccessMarketplace() && auth()->user()->company?->isInDirectory())
            <a href="{{ route('portal.shop.create') }}" class="cta" style="margin-top:6px;display:inline-block;">Pubblica il primo prodotto</a>
        @endif
    </x-shop.empty>
    @endforelse
</div>

@if($listings->hasPages())
<div style="margin-top:24px;display:flex;justify-content:center;">
    {{ $listings->appends(request()->query())->links() }}
</div>
@endif

@endsection
