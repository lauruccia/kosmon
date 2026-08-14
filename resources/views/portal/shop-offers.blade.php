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

@if($listings->isEmpty())
    <div class="shop-empty">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <p class="subtle">Nessuna offerta attiva al momento. Torna a trovarci presto!</p>
        <a href="{{ route('portal.shop') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Vai allo shop completo</a>
    </div>
@else
    {{-- Ogni offerta è un blocco a piena pagina, uno sotto l'altro (richiesta
         di Laura, 2026-08-14: niente griglia, un blocco per offerta). --}}
    <div class="offer-stack">
        @foreach($listings as $listing)
        @php $offer = $listing->activeOffer; @endphp
        <article class="offer-hero">
            <a href="{{ route('portal.shop.show', $listing) }}" class="offer-hero-media">
                @if($listing->first_image_url)
                    <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" loading="lazy">
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
        @endforeach
    </div>
@endif

<style>
    .shop-empty {
        text-align: center; padding: 56px 24px;
        display: flex; flex-direction: column; align-items: center; gap: 10px; color: var(--ink-muted);
    }

    /* Elenco verticale: un blocco "hero" a piena larghezza per ogni offerta,
       uno sotto l'altro (2026-08-14, richiesta Laura — niente griglia). */
    .offer-stack { display: flex; flex-direction: column; gap: 20px; }

    .offer-hero {
        display: grid; grid-template-columns: 1.1fr 1fr; gap: 0;
        background: var(--surface); border: 1px solid var(--line); border-radius: 18px;
        overflow: hidden; box-shadow: var(--shadow-lg); min-height: 360px;
    }
    .offer-hero-media {
        position: relative; display: block; overflow: hidden; text-decoration: none;
        background: linear-gradient(150deg, var(--surface-soft), var(--surface));
    }
    .offer-hero-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
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
    .mix-badge { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; }

    .offer-hero-body {
        padding: 32px 40px; display: flex; flex-direction: column; align-items: flex-start;
        justify-content: center; gap: 10px;
    }
    .offer-hero-title { margin: 4px 0 0; font-size: 28px; font-weight: 800; line-height: 1.2; }
    .offer-hero-title a { color: var(--ink); text-decoration: none; }
    .offer-hero-title a:hover { color: var(--primary); }
    .offer-hero-price-row { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
    .offer-hero-price { font-size: 38px; font-weight: 800; color: var(--primary-strong); letter-spacing: -.02em; }
    .offer-hero-price small { font-size: 15px; font-weight: 700; margin-left: 3px; }
    .offer-hero-price-old { text-decoration: line-through; color: var(--ink-muted); font-size: 18px; }
    .offer-hero-expiry { font-size: 13px; font-weight: 700; color: #92400e; margin-top: 2px; }
    .offer-hero .cta { padding: 12px 28px; font-size: 15px; }
    @media (max-width: 780px) {
        .offer-hero { grid-template-columns: 1fr; min-height: 0; }
        .offer-hero-media { aspect-ratio: 16 / 10; }
        .offer-hero-body { padding: 22px 20px; }
        .offer-hero-title { font-size: 22px; }
        .offer-hero-price { font-size: 30px; }
    }
</style>
@endsection
