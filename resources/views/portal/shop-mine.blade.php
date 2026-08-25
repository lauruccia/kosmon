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
        <div class="shop-toolbar-field" style="flex:1;min-width:220px;">
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
        <div style="margin-left:auto;">
            @if(auth()->user()->canAccessMarketplace() && auth()->user()->company?->isInDirectory())
                <a class="cta" href="{{ route('portal.shop.create') }}" style="white-space:nowrap;">Pubblica un nuovo prodotto</a>
            @endif
        </div>
    </form>
</section>

<div class="catalog-grid" style="margin-top:18px;">
    @forelse($listings as $listing)
    <article class="catalog-card{{ $listing->status !== 'active' ? ' catalog-card--inactive' : '' }}">
        <div class="product-media">
            @if($listing->first_image_url)
                <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" loading="lazy">
            @else
                <div class="product-media-placeholder">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            @endif
            <span class="mine-status-badge mine-status-badge--{{ $listing->status }}">{{ \App\Models\Listing::statusLabel($listing->status) }}</span>
            @if($listing->featured)<span class="product-badge product-badge--featured">★</span>@endif
        </div>
        <div class="product-body">
            <h3 class="product-title">
                @if($listing->status === 'active')
                    <a href="{{ route('portal.shop.show', $listing) }}">{{ $listing->title }}</a>
                @else
                    <span>{{ $listing->title }}</span>
                @endif
            </h3>
            <div class="entity-meta">
                <span class="chip">{{ $listing->category_label }}</span>
                <span class="subtle" style="font-size:12px;">{{ $listing->views_count }} visualizzazioni</span>
            </div>
            <div class="product-price-row">
                <span class="product-price">{{ ky_format($listing->price_ky) }} <small>KY</small></span>
                @if($listing->ky_percentage !== 100)
                    <span class="mix-badge" style="{{ $listing->ky_badge_color }}">{{ $listing->ky_badge_label }}</span>
                @endif
            </div>
            <div class="subtle" style="font-size:12px;">
                {{ $listing->stock_label }}
                @if($listing->expires_at) · scade il {{ $listing->expires_at->locale('it')->isoFormat('D MMM YYYY') }} @endif
            </div>
            <div class="page-actions" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
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
                    <button type="submit" class="cta secondary" style="width:100%;color:#991b1b;">Elimina</button>
                </form>
            </div>
        </div>
    </article>
    @empty
    <div class="shop-empty">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
        @if($searchQuery || $statusFilter !== '')
            <p class="subtle">Nessun prodotto trovato con questi filtri.</p>
            <a href="{{ route('portal.shop.mine') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Rimuovi filtri</a>
        @else
            <p class="subtle">Non hai ancora pubblicato nessun prodotto nello shop.</p>
            @if(auth()->user()->canAccessMarketplace() && auth()->user()->company?->isInDirectory())
                <a href="{{ route('portal.shop.create') }}" class="cta" style="margin-top:6px;display:inline-block;">Pubblica il primo prodotto</a>
            @endif
        @endif
    </div>
    @endforelse
</div>

@if($listings->hasPages())
<div style="margin-top:24px;display:flex;justify-content:center;">
    {{ $listings->appends(request()->query())->links() }}
</div>
@endif

<style>
    /* Stessi stili scoped dello shop pubblico (portal/shop.blade.php) — ogni
       view del portale li definisce a livello di pagina, niente foglio
       condiviso per il catalogo shop. */
    .shop-toolbar-card { padding: 18px 22px; }
    .shop-toolbar { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
    .shop-toolbar-field label {
        display: block; font-size: 11.5px; font-weight: 700; color: var(--ink-soft);
        margin-bottom: 6px; text-transform: uppercase; letter-spacing: .06em;
    }
    .shop-search-input { position: relative; display: flex; align-items: center; }
    .shop-search-input svg { position: absolute; left: 12px; color: var(--ink-muted); pointer-events: none; }
    .shop-search-input input {
        width: 100%; padding: 10px 14px 10px 36px;
        border: 1.5px solid var(--line-strong); border-radius: 10px;
        background: var(--surface); color: var(--ink); font-size: 14px;
        transition: border-color .15s, box-shadow .15s;
    }
    .shop-search-input input:focus {
        outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light);
    }
    .km-select {
        appearance: none; -webkit-appearance: none; -moz-appearance: none;
        width: 100%; padding: 10px 34px 10px 14px; font-size: 14px; font-family: inherit;
        border: 1.5px solid var(--line-strong); border-radius: 10px;
        background-color: var(--surface); color: var(--ink); cursor: pointer;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='11' height='7' viewBox='0 0 11 7'><path d='M1 1l4.5 4.5L10 1' stroke='%234a637d' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>");
        background-repeat: no-repeat; background-position: right 12px center; background-size: 11px 7px;
        outline: none; transition: border-color .15s, box-shadow .15s;
    }
    .km-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

    .catalog-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .catalog-card {
        padding: 0; overflow: hidden; display: flex; flex-direction: column;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .catalog-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: var(--line-strong); }
    .catalog-card--inactive { opacity: .78; }
    .catalog-card--inactive:hover { opacity: 1; }
    .product-media {
        position: relative; display: block; aspect-ratio: 16 / 10;
        background: linear-gradient(150deg, var(--surface-soft), var(--surface));
        overflow: hidden;
    }
    .product-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .product-media-placeholder {
        width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
        color: var(--ink-muted);
    }
    .product-badge {
        position: absolute; top: 10px; font-size: 10.5px; font-weight: 700;
        padding: 4px 10px; border-radius: 999px; letter-spacing: .02em;
        background: rgba(255,255,255,.94); color: var(--ink-soft);
        box-shadow: var(--shadow-xs);
    }
    .product-badge--featured { right: 10px; background: #fef3c7; color: #92400e; }
    /* Badge di stato ben visibile: a differenza dello shop pubblico, qui deve
       saltare subito all'occhio quali prodotti NON sono visibili ai clienti. */
    .mine-status-badge {
        position: absolute; top: 10px; left: 10px; font-size: 10.5px; font-weight: 800;
        padding: 4px 10px; border-radius: 999px; letter-spacing: .02em; text-transform: uppercase;
        box-shadow: var(--shadow-xs);
    }
    .mine-status-badge--active { background: #d1fae5; color: #065f46; }
    .mine-status-badge--suspended { background: #fef3c7; color: #92400e; }
    .mine-status-badge--draft { background: #e2e8f0; color: #475569; }
    .mine-status-badge--expired { background: #fee2e2; color: #991b1b; }
    .product-body { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 5px; flex: 1; }
    .product-title { margin: 0; font-size: 14px; font-weight: 700; line-height: 1.25; }
    .product-title a { color: var(--ink); text-decoration: none; }
    .product-title a:hover { color: var(--primary); }
    .entity-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .product-price-row {
        margin-top: 2px;
        display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
    }
    .product-price { font-size: 17px; font-weight: 800; color: var(--primary-strong); letter-spacing: -.02em; }
    .product-price small { font-size: 11px; font-weight: 700; margin-left: 2px; }
    .mix-badge { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; }

    .shop-empty {
        grid-column: 1 / -1; text-align: center; padding: 56px 24px;
        display: flex; flex-direction: column; align-items: center; gap: 10px; color: var(--ink-muted);
    }

    @media (max-width: 640px) {
        .shop-toolbar { flex-direction: column; align-items: stretch; }
        .shop-toolbar > div { width: 100%; }
        .catalog-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
    }
</style>

@endsection
