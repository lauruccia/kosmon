@extends('layouts.portal')

@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<section class="card light-card shop-toolbar-card">
    <form method="GET" action="{{ route('portal.shop') }}" class="shop-toolbar">
        <div class="shop-toolbar-field" style="flex:1;min-width:220px;">
            <label>Cerca</label>
            <div class="shop-search-input">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="q" value="{{ $searchQuery }}" placeholder="Prodotto, azienda...">
            </div>
        </div>
        <div class="shop-toolbar-field" style="min-width:210px;">
            <label>Categoria</label>
            <select name="category" class="km-select">
                <option value="">Tutte le categorie</option>
                @foreach($categories as $slug => $label)
                    <option value="{{ $slug }}" @selected($selectedCategory === $slug)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="cta">Filtra</button>
        @if($searchQuery || $selectedCategory)
            <a href="{{ route('portal.shop') }}" class="cta secondary">✕ Reset</a>
        @endif
        <div style="margin-left:auto;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            @if(auth()->user()->canAccessMarketplace())
                @if(auth()->user()->company?->hasEcommercePlan())
                    <a class="cta" href="{{ route('portal.shop.create') }}" style="white-space:nowrap;">+ Pubblica prodotto</a>
                @else
                    <span title="Per pubblicare prodotti serve il piano Ecommerce. Contatta l'amministrazione per attivarlo." class="cta disabled" style="white-space:nowrap;">+ Pubblica prodotto (piano Ecommerce richiesto)</span>
                @endif
            @endif
            @if(auth()->user()->company && (auth()->user()->canAccessMarketplace() || auth()->user()->is_super_admin))
                <a class="cta secondary" href="{{ route('portal.payment-gateways.index') }}" style="white-space:nowrap;">Metodi di pagamento EUR</a>
            @endif
            <a class="cta secondary" href="{{ route('portal.announcements') }}" style="white-space:nowrap;">Vai agli annunci</a>
        </div>
    </form>
</section>

@if($featuredListings->isNotEmpty() && !$searchQuery && !$selectedCategory)
<section class="card light-card" style="margin-top:18px;">
    <div class="section-head">
        <div><span class="eyebrow">In evidenza</span><h3 class="section-title">Prodotti in primo piano</h3></div>
    </div>
    <div class="featured-strip">
        @foreach($featuredListings as $listing)
        <article class="featured-card">
            <div class="product-media">
                @if($listing->first_image_url)
                    <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" loading="lazy">
                @else
                    <div class="product-media-placeholder"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                @endif
                <span class="product-badge product-badge--featured">★ Evidenza</span>
                @if(! $listing->isInStock())
                    <span class="product-badge product-badge--soldout">Esaurito</span>
                @endif
            </div>
            <div class="product-body">
                <span class="eyebrow">{{ $listing->category_label }}</span>
                <h3 class="product-title">{{ $listing->title }}</h3>
                <div class="subtle" style="font-size:12.5px;">{{ $listing->company->name }}</div>
                <div class="product-price-row">
                    <span class="product-price">{{ ky_format($listing->price_ky) }} <small>KY</small></span>
                </div>
                <a class="cta" style="width:100%;text-align:center;" href="{{ route('portal.shop.show', $listing) }}">Vedi dettaglio</a>
            </div>
        </article>
        @endforeach
    </div>
</section>
@endif

<div class="catalog-grid" style="margin-top:18px;">
    @forelse($listings as $listing)
    <article class="catalog-card">
        <a href="{{ route('portal.shop.show', $listing) }}" class="product-media">
            @if($listing->first_image_url)
                <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" loading="lazy">
            @else
                <div class="product-media-placeholder">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            @endif
            <span class="product-badge product-badge--category">{{ $listing->category_label }}</span>
            @if($listing->featured)<span class="product-badge product-badge--featured">★</span>@endif
            @if(! $listing->isInStock())
                <span class="product-media-overlay">Esaurito</span>
            @endif
        </a>
        <div class="product-body">
            <h3 class="product-title">
                <a href="{{ route('portal.shop.show', $listing) }}">{{ $listing->title }}</a>
            </h3>
            <p class="subtle product-desc">{{ Str::limit($listing->description, 90) }}</p>
            <div class="entity-meta">
                <span class="chip">{{ $listing->company->name }}</span>
                @if($listing->delivery_note)<span class="chip">{{ $listing->delivery_note }}</span>@endif
            </div>
            <div class="product-price-row">
                <span class="product-price">{{ ky_format($listing->price_ky) }} <small>KY</small></span>
                @if($listing->ky_percentage < 100)
                    <span class="mix-badge" style="{{ $listing->ky_badge_color }}">{{ $listing->ky_badge_label }}</span>
                @endif
            </div>
            <div class="page-actions" style="margin-top:2px;">
                <a class="cta" style="flex:1;text-align:center;" href="{{ route('portal.shop.show', $listing) }}">Vedi e acquista</a>
                @if(auth()->user()->company_id === $listing->company_id || auth()->user()->is_super_admin)
                    <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary">Modifica</a>
                @endif
            </div>
        </div>
    </article>
    @empty
    <div class="shop-empty">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <p class="subtle">Nessun prodotto trovato nel catalogo.</p>
        @if($searchQuery || $selectedCategory)
            <a href="{{ route('portal.shop') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Rimuovi filtri</a>
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
    /* ── Toolbar shop: ricerca con icona + select coerenti col design system ── */
    .shop-toolbar-card { padding: 18px 22px; }
    .shop-toolbar { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
    .shop-toolbar-field label {
        display: block; font-size: 11.5px; font-weight: 700; color: var(--ink-soft);
        margin-bottom: 6px; text-transform: uppercase; letter-spacing: .06em;
    }
    .shop-search-input {
        position: relative; display: flex; align-items: center;
    }
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
    .cta.disabled {
        display: inline-flex; align-items: center; padding: 10px 18px; font-size: 14px;
        border: 1.5px dashed var(--line-strong); border-radius: 10px; color: var(--ink-muted); cursor: not-allowed;
    }

    /* ── Catalogo: griglia responsive stile ecommerce ── */
    .catalog-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px; }
    .catalog-card {
        padding: 0; overflow: hidden; display: flex; flex-direction: column;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .catalog-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: var(--line-strong);
    }
    .product-media {
        position: relative; display: block; aspect-ratio: 4 / 3;
        background: linear-gradient(150deg, var(--surface-soft), var(--surface));
        overflow: hidden; text-decoration: none;
    }
    .product-media img {
        width: 100%; height: 100%; object-fit: cover; display: block;
        transition: transform .4s ease;
    }
    .catalog-card:hover .product-media img,
    .featured-card:hover .product-media img { transform: scale(1.07); }
    .product-media-placeholder {
        width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
        color: var(--ink-muted);
    }
    .product-media-overlay {
        position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
        background: rgba(13,28,48,.55); color: #fff; font-weight: 800; font-size: 13px;
        letter-spacing: .04em; text-transform: uppercase;
    }
    .product-badge {
        position: absolute; top: 10px; font-size: 10.5px; font-weight: 700;
        padding: 4px 10px; border-radius: 999px; letter-spacing: .02em;
        background: rgba(255,255,255,.94); color: var(--ink-soft);
        box-shadow: var(--shadow-xs);
    }
    .product-badge--category { left: 10px; }
    .product-badge--featured { right: 10px; background: #fef3c7; color: #92400e; }
    .product-badge--soldout { right: 10px; background: rgba(159,18,57,.92); color: #fff; }
    .product-body { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .product-title { margin: 0; font-size: 15px; font-weight: 700; line-height: 1.3; }
    .product-title a { color: var(--ink); text-decoration: none; }
    .product-title a:hover { color: var(--primary); }
    .product-desc { margin: 0; font-size: 12.5px; line-height: 1.6; }
    .product-price-row {
        margin-top: auto; padding-top: 4px;
        display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
    }
    .product-price {
        font-size: 21px; font-weight: 800; color: var(--primary-strong); letter-spacing: -.02em;
    }
    .product-price small { font-size: 12px; font-weight: 700; margin-left: 2px; }
    .mix-badge { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; }

    /* ── Fascia "In evidenza": scroll orizzontale ── */
    .featured-strip {
        display: flex; gap: 16px; overflow-x: auto; padding-bottom: 4px; margin-top: 4px;
        scroll-snap-type: x proximity;
    }
    .featured-card {
        flex: 0 0 240px; scroll-snap-align: start;
        border: 1px solid var(--line); border-radius: var(--radius-sm);
        background: var(--surface); box-shadow: var(--shadow-xs);
        overflow: hidden; display: flex; flex-direction: column;
        transition: box-shadow .18s ease, border-color .18s ease;
    }
    .featured-card:hover { box-shadow: var(--shadow); border-color: var(--line-strong); }
    .featured-card .product-body { padding: 12px 14px 14px; gap: 6px; }
    .featured-card .product-title { font-size: 14px; }

    /* ── Stato vuoto ── */
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
