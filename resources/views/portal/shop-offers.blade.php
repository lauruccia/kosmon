@extends('layouts.portal')

@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<section class="card light-card" style="padding:20px 22px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
    <div>
        <span class="eyebrow">Shop del circuito</span>
        <h2 style="font-size:22px;font-weight:700;color:#10263d;margin:6px 0 4px;">🔥 Offerte della settimana</h2>
        <p class="subtle" style="margin:0;">Prodotti selezionati dallo shop, per un periodo limitato, a un prezzo scontato.</p>
    </div>
    <a href="{{ route('portal.shop') }}" class="cta secondary" style="white-space:nowrap;">Vai allo shop completo</a>
</section>

@php
    // Layout adattivo in base al numero di offerte attive (richiesta di
    // Laura, 2026-08-14): con 1 sola offerta, un banner "hero" a piena
    // pagina; con 2-4 offerte, tante colonne larghe quante le offerte (le
    // stesse card del catalogo, ma ingrandite); da 5 offerte in su si torna
    // alla griglia compatta a righe multiple di sempre — oltre le 4 colonne
    // le card diventerebbero troppo strette per avere senso.
    $offerCount = $listings->count();
    $scaled = $offerCount >= 2 && $offerCount <= 4;
@endphp

@if($offerCount === 0)
    <div class="shop-empty">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <p class="subtle">Nessuna offerta attiva al momento. Torna a trovarci presto!</p>
        <a href="{{ route('portal.shop') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Vai allo shop completo</a>
    </div>
@elseif($offerCount === 1)
    @php $listing = $listings->first(); $offer = $listing->activeOffer; @endphp
    <article class="offer-hero">
        <a href="{{ route('portal.shop.show', $listing) }}" class="offer-hero-media">
            @if($listing->first_image_url)
                <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}">
            @else
                <div class="product-media-placeholder"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            @endif
            @if($offer)
                <span class="product-badge product-badge--offer">-{{ $offer->discount_percent }}%</span>
            @endif
            @if($listing->effective_ky_percentage === 100)
                <span class="product-badge product-badge--full-ky">100% KY</span>
            @endif
            @if(! $listing->isInStock())
                <span class="product-media-overlay">Esaurito</span>
            @endif
        </a>
        <div class="offer-hero-body">
            <span class="chip">{{ $listing->company->name }}</span>
            <h2 class="offer-hero-title"><a href="{{ route('portal.shop.show', $listing) }}">{{ $listing->title }}</a></h2>
            <div class="offer-hero-price-row">
                <span class="offer-hero-price">{{ ky_format($listing->effective_price_ky) }} <small>KY</small></span>
                @if($offer)
                    <span class="offer-hero-price-old">{{ ky_format($offer->full_price_ky_snapshot) }} KY</span>
                @endif
            </div>
            @if($listing->effective_ky_percentage !== 100)
                <span class="mix-badge" style="{{ $listing->effective_ky_badge_color }}">{{ $listing->effective_ky_badge_label }}</span>
            @endif
            @if($offer)
                <div class="offer-hero-expiry">⏱ Scade il {{ $offer->expires_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}</div>
            @endif
            <a class="cta" style="margin-top:10px;" href="{{ route('portal.shop.show', $listing) }}">Acquista ora</a>
        </div>
    </article>
@else
    <div class="catalog-grid @if($scaled) offers-grid-scaled offers-cols-{{ $offerCount }} @endif">
        @foreach($listings as $listing)
        @php $offer = $listing->activeOffer; @endphp
        <article class="catalog-card @if($scaled) catalog-card--large @endif">
            <a href="{{ route('portal.shop.show', $listing) }}" class="product-media">
                @if($listing->first_image_url)
                    <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" loading="lazy">
                @else
                    <div class="product-media-placeholder"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                @endif
                @if($offer)
                    <span class="product-badge product-badge--offer">-{{ $offer->discount_percent }}%</span>
                @endif
                @if($listing->effective_ky_percentage === 100)
                    <span class="product-badge product-badge--full-ky">100% KY</span>
                @endif
                @if(! $listing->isInStock())
                    <span class="product-media-overlay">Esaurito</span>
                @endif
            </a>
            <div class="product-body">
                <h3 class="product-title"><a href="{{ route('portal.shop.show', $listing) }}">{{ $listing->title }}</a></h3>
                <div class="entity-meta">
                    <span class="chip">{{ $listing->company->name }}</span>
                </div>
                <div class="product-price-row" style="flex-direction:column;align-items:flex-start;gap:2px;">
                    <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
                        <span class="product-price">{{ ky_format($listing->effective_price_ky) }} <small>KY</small></span>
                        @if($offer)
                            <span style="text-decoration:line-through;color:var(--ink-muted);font-size:13px;">{{ ky_format($offer->full_price_ky_snapshot) }} KY</span>
                        @endif
                    </div>
                    @if($listing->effective_ky_percentage !== 100)
                        <span class="mix-badge" style="{{ $listing->effective_ky_badge_color }}">{{ $listing->effective_ky_badge_label }}</span>
                    @endif
                </div>
                @if($offer)
                    <div style="font-size:11px;font-weight:700;color:#92400e;">⏱ Scade il {{ $offer->expires_at->locale('it')->isoFormat('D MMM YYYY, HH:mm') }}</div>
                @endif
                <a class="cta" style="width:100%;text-align:center;margin-top:4px;" href="{{ route('portal.shop.show', $listing) }}">Acquista ora</a>
            </div>
        </article>
        @endforeach
    </div>
@endif

<style>
    /* Stessa griglia/card dello shop principale (portal/shop.blade.php), qui
       riproposte in locale per non dipendere da CSS condiviso non esistente. */
    .catalog-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .catalog-card {
        padding: 0; overflow: hidden; display: flex; flex-direction: column;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .catalog-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: var(--line-strong); }
    .product-media {
        position: relative; display: block; aspect-ratio: 16 / 10;
        background: linear-gradient(150deg, var(--surface-soft), var(--surface));
        overflow: hidden; text-decoration: none;
    }
    .product-media img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .4s ease; }
    .catalog-card:hover .product-media img { transform: scale(1.07); }
    .product-media-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--ink-muted); }
    .product-media-overlay {
        position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
        background: rgba(13,28,48,.55); color: #fff; font-weight: 800; font-size: 13px;
        letter-spacing: .04em; text-transform: uppercase;
    }
    .product-badge {
        position: absolute; top: 10px; font-size: 10.5px; font-weight: 700;
        padding: 4px 10px; border-radius: 999px; letter-spacing: .02em;
        background: rgba(255,255,255,.94); color: var(--ink-soft); box-shadow: var(--shadow-xs);
    }
    .product-badge--full-ky { left: 10px; background: #059669; color: #fff; font-weight: 800; box-shadow: 0 2px 8px rgba(5,150,105,.35); }
    .product-badge--offer { right: 10px; background: #dc2626; color: #fff; font-weight: 800; box-shadow: 0 2px 8px rgba(220,38,38,.35); }
    .product-body { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 5px; flex: 1; }
    .product-title { margin: 0; font-size: 14px; font-weight: 700; line-height: 1.25; }
    .product-title a { color: var(--ink); text-decoration: none; }
    .product-title a:hover { color: var(--primary); }
    .product-price { font-size: 19px; font-weight: 800; color: var(--primary-strong); letter-spacing: -.02em; }
    .product-price small { font-size: 11px; font-weight: 700; margin-left: 2px; }
    .mix-badge { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
    .shop-empty {
        grid-column: 1 / -1; text-align: center; padding: 56px 24px;
        display: flex; flex-direction: column; align-items: center; gap: 10px; color: var(--ink-muted);
    }
    @media (max-width: 640px) {
        .catalog-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
    }

    /* ------------------------------------------------------------------ */
    /* Layout adattivo al numero di offerte (2026-08-14, richiesta Laura). */
    /* ------------------------------------------------------------------ */

    /* 1 sola offerta: banner "hero" a piena pagina invece della card
       compatta del catalogo — immagine grande a sinistra, dettagli grandi a
       destra, più impatto per un'unica offerta in vetrina. */
    .offer-hero {
        display: grid; grid-template-columns: 1.1fr 1fr; gap: 0;
        background: var(--surface); border: 1px solid var(--line); border-radius: 18px;
        overflow: hidden; box-shadow: var(--shadow-lg); min-height: 420px;
    }
    .offer-hero-media {
        position: relative; display: block; overflow: hidden; text-decoration: none;
        background: linear-gradient(150deg, var(--surface-soft), var(--surface));
    }
    .offer-hero-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .offer-hero-body {
        padding: 36px 40px; display: flex; flex-direction: column; align-items: flex-start;
        justify-content: center; gap: 10px;
    }
    .offer-hero-title { margin: 4px 0 0; font-size: 30px; font-weight: 800; line-height: 1.2; }
    .offer-hero-title a { color: var(--ink); text-decoration: none; }
    .offer-hero-title a:hover { color: var(--primary); }
    .offer-hero-price-row { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
    .offer-hero-price { font-size: 42px; font-weight: 800; color: var(--primary-strong); letter-spacing: -.02em; }
    .offer-hero-price small { font-size: 16px; font-weight: 700; margin-left: 3px; }
    .offer-hero-price-old { text-decoration: line-through; color: var(--ink-muted); font-size: 20px; }
    .offer-hero-expiry { font-size: 13px; font-weight: 700; color: #92400e; margin-top: 2px; }
    .offer-hero .cta { padding: 12px 28px; font-size: 15px; }
    @media (max-width: 780px) {
        .offer-hero { grid-template-columns: 1fr; min-height: 0; }
        .offer-hero-media { aspect-ratio: 16 / 10; }
        .offer-hero-body { padding: 22px 20px; }
        .offer-hero-title { font-size: 22px; }
        .offer-hero-price { font-size: 30px; }
    }

    /* 2-4 offerte: tante colonne larghe quante le offerte, con le stesse
       card del catalogo ma ingrandite — oltre le 4 si torna alla griglia
       compatta standard (.catalog-grid da sola, sopra). */
    .offers-grid-scaled { gap: 20px; }
    .offers-cols-2 { grid-template-columns: repeat(2, 1fr); }
    .offers-cols-3 { grid-template-columns: repeat(3, 1fr); }
    .offers-cols-4 { grid-template-columns: repeat(4, 1fr); }
    .catalog-card--large .product-media { aspect-ratio: 4 / 3; }
    .catalog-card--large .product-body { padding: 16px 18px 18px; gap: 8px; }
    .catalog-card--large .product-title { font-size: 17px; }
    .catalog-card--large .product-price { font-size: 24px; }
    @media (max-width: 900px) {
        .offers-cols-3, .offers-cols-4 { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 620px) {
        .offers-cols-2, .offers-cols-3, .offers-cols-4 { grid-template-columns: 1fr; }
    }
</style>
@endsection
