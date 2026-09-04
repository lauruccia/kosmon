@extends('layouts.portal')

@section('content')
<x-shop.styles />
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<section class="card light-card" style="padding:20px 22px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
    <div>
        <span class="eyebrow">Shop del circuito</span>
        <h2 class="section-title" style="font-size:22px;margin:6px 0 4px;">🔥 Offerte della settimana</h2>
        <p class="subtle" style="margin:0;">Prodotti selezionati dallo shop, per un periodo limitato, a un prezzo scontato.</p>
    </div>
    <a href="{{ route('portal.shop') }}" class="cta secondary" style="white-space:nowrap;">Vai allo shop completo</a>
</section>

@if($listings->isEmpty())
    <x-shop.empty message="Nessuna offerta attiva al momento. Torna a trovarci presto!">
        <a href="{{ route('portal.shop') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Vai allo shop completo</a>
    </x-shop.empty>
@else
    {{-- Ogni offerta è un blocco a piena pagina, uno sotto l'altro (richiesta
         di Laura, 2026-08-14: niente griglia, un blocco per offerta). --}}
    <div class="offer-stack">
        @foreach($listings as $listing)
        @php $offer = $listing->activeOffer; @endphp
        <article class="offer-hero">
            <x-shop.media
                :listing="$listing"
                :href="route('portal.shop.show', $listing)"
                class="offer-hero-media"
                :placeholder-size="64"
                sizes="(max-width: 780px) 100vw, 55vw"
                :overlay="$listing->isInStock() ? null : 'Esaurito'">
                @if($offer)
                    <span class="product-badge product-badge--offer">-{{ $offer->discount_percent }}%</span>
                @endif
                @if($listing->effective_ky_percentage === 100)
                    <span class="product-badge product-badge--full-ky">100% KY</span>
                @endif
            </x-shop.media>
            <div class="offer-hero-body">
                <span class="chip chip-ellipsis" title="{{ $listing->company->name }}">{{ $listing->company->name }}</span>
                <h2 class="offer-hero-title"><a href="{{ route('portal.shop.show', $listing) }}">{{ $listing->title }}</a></h2>
                <div class="offer-hero-price-row">
                    <span class="offer-hero-price">{{ ky_format($listing->effective_price_ky) }} <small>KY</small></span>
                    @if($offer)
                        <span class="offer-hero-price-old">{{ ky_format($offer->full_price_ky_snapshot) }} KY</span>
                    @endif
                </div>
                @if($listing->effective_ky_percentage !== 100)
                    <x-shop.mix-badge :label="$listing->effective_ky_badge_label" :style="$listing->effective_ky_badge_color" />
                @endif
                @if($offer)
                    <div class="offer-hero-expiry">⏱ Scade il {{ $offer->expires_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}</div>
                @endif
                {{-- Stessa correzione del catalogo (27/08): questo bottone
                     apre la scheda, non compra. Diceva "Acquista ora". --}}
                <a class="cta" style="margin-top:10px;" href="{{ route('portal.shop.show', $listing) }}">Vedi il prodotto</a>
            </div>
        </article>
        @endforeach
    </div>

    {{-- La pagina adesso e' paginata: prima caricava TUTTE le offerte attive
         in memoria e le ordinava in PHP. --}}
    <div style="margin-top:18px;">
        {{ $listings->links() }}
    </div>
@endif
@endsection
