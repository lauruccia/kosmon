@extends('layouts.portal')

@section('content')
<x-shop.styles />
@php
    // Calcolati UNA VOLTA per pagina, non per card: qui dentro ci sono due
    // relazioni, e chiederle quindici volte sarebbe stato un N+1 travestito
    // da comodita' (27/08/2026).
    $ioSonoSospeso = (bool) $currentAccount->company?->isSuspended();
    $miaAzienda    = $currentAccount->company_id;

    // Il bottone "Aggiungi" compare solo dove il server direbbe di si':
    // mostrarlo e poi rifiutare sarebbe peggio che non mostrarlo. I prodotti
    // con le taglie restano fuori di proposito — la taglia va scelta, e si
    // sceglie nella scheda.
    $siPuoAggiungere = function ($listing) use ($ioSonoSospeso, $miaAzienda) {
        return $listing->status === 'active'
            && ! $listing->has_variants
            && $listing->isInStock()
            && ! $ioSonoSospeso
            && (int) $listing->company_id !== (int) $miaAzienda;
        // NIENTE controllo sul venditore sospeso: qui sarebbe codice morto.
        // I prodotti di un'azienda sospesa non arrivano affatto in questa
        // griglia — li esclude lo scope `active()` — e gli unici che sfuggono
        // allo scope sono i PROPRI, gia' esclusi dalla riga qui sopra. Una
        // mutazione l'ha dimostrato: toglierlo non faceva cadere niente.
        // Il controllo vero, quello che conta, e' in CartService.
    };

    $sonoIlProprietario = fn ($listing) => auth()->user()->company_id === $listing->company_id
        || auth()->user()->is_super_admin;
@endphp
{{-- Niente banner qui: li stampa gia' il layout (layouts/portal.blade.php).
     Ristamparli voleva dire leggere DUE VOLTE lo stesso avviso dopo ogni
     aggiunta al carrello — audit 26/08, blocco 5. --}}

<section class="card light-card shop-toolbar-card">
    <form method="GET" action="{{ route('portal.shop') }}" class="shop-toolbar">
        {{-- Filtro venditore: chi arriva dal pulsante "SHOP" della directory
             aziende deve restare dentro il negozio di quell'azienda anche
             dopo aver cercato o cambiato categoria (2026-08-25). --}}
        @if($selectedCompany)
            <input type="hidden" name="company" value="{{ $selectedCompany->id }}">
        @endif
        <div class="shop-toolbar-field shop-toolbar-field--grow">
            <label>Cerca</label>
            <div class="shop-search-input">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="q" value="{{ $searchQuery }}" placeholder="Prodotto, azienda...">
            </div>
        </div>
        <div class="shop-toolbar-field" style="min-width:210px;">
            <label>Categoria</label>
            <select name="category" id="shop-category-select" class="km-select" data-no-search onchange="this.form.submit()">
                <option value="">Tutte le categorie</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->slug }}" @selected($selectedCategory === $cat->slug)>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        @if($selectedCategory !== '')
        <div class="shop-toolbar-field" style="min-width:190px;">
            <label>Sotto-categoria</label>
            <select name="subcategory" id="shop-subcategory-select" class="km-select" data-no-search>
                <option value="">Tutte</option>
                @foreach(($subcategoriesBySlug[$selectedCategory] ?? []) as $sub)
                    <option value="{{ $sub['slug'] }}" @selected($selectedSubcategory === $sub['slug'])>{{ $sub['name'] }}</option>
                @endforeach
            </select>
        </div>
        @endif
        {{-- Filtro % Kmoney: un'unica select (esatta o "da", non due campi separati). --}}
        <div class="shop-toolbar-field" style="min-width:170px;">
            <label>Filtro Kmoney</label>
            <select name="ky_filter" class="km-select" data-no-search>
                <option value="">Qualsiasi</option>
                <optgroup label="Esatta">
                    @foreach($kyPercentages as $pct)
                        <option value="exact:{{ $pct }}" @selected($kyFilter === "exact:{$pct}")>{{ $pct }}%</option>
                    @endforeach
                </optgroup>
                <optgroup label="Da">
                    @foreach(array_filter($kyPercentages) as $pct)
                        <option value="min:{{ $pct }}" @selected($kyFilter === "min:{$pct}")>Da {{ $pct }}%</option>
                    @endforeach
                </optgroup>
            </select>
        </div>
        <button type="submit" class="cta">Filtra</button>
        @if($searchQuery || $selectedCategory || $selectedSubcategory || $kyFilter !== '')
            <a href="{{ route('portal.shop', $selectedCompany ? ['company' => $selectedCompany->id] : []) }}" class="cta secondary">✕ Reset</a>
        @endif
        <div class="shop-toolbar-actions">
            {{-- "Offerte della settimana" (2026-08-13): link diretto dalla toolbar shop,
                 stessa visibilità del link nella sidebar (layouts/portal.blade.php). --}}
            <a class="cta secondary" href="{{ route('portal.shop.offers') }}" style="white-space:nowrap;">🔥 Offerte della settimana</a>
            @if(auth()->user()->canAccessMarketplace() && auth()->user()->company?->isInDirectory())
                <a class="cta" href="{{ route('portal.shop.create') }}" style="white-space:nowrap;">Pubblica un prodotto</a>
            @endif
            {{-- "I miei prodotti" (2026-08-12): chi pubblica prodotti non aveva modo
                 di ritrovare/verificare i propri, mescolati nello shop pubblico tra
                 quelli di tutte le altre aziende — link diretto alla vista dedicata. --}}
            @if(auth()->user()->company_id)
                <a class="cta secondary" href="{{ route('portal.shop.mine') }}" style="white-space:nowrap;">I miei prodotti</a>
            @endif
            @if(auth()->user()->company && (auth()->user()->canAccessMarketplace() || auth()->user()->is_super_admin))
                <a class="cta secondary" href="{{ route('portal.payment-gateways.index') }}" style="white-space:nowrap;">Metodi di pagamento EUR</a>
            @endif
            <a class="cta secondary" href="{{ route('portal.announcements') }}" style="white-space:nowrap;">Vai agli annunci</a>
        </div>
    </form>
</section>

@if($selectedCompany)
<section class="card light-card shop-seller-banner">
    <div>
        <span class="eyebrow">Stai vedendo solo</span>
        <h3 class="section-title" style="margin:0;">Prodotti di {{ $selectedCompany->name }}</h3>
    </div>
    <div class="shop-seller-banner-actions">
        @if($selectedCompany->slug)
            <a class="cta secondary" href="{{ route('portal.companies.show', $selectedCompany->slug) }}">Scheda azienda</a>
        @endif
        <a class="cta secondary" href="{{ route('portal.shop') }}">✕ Vedi tutto lo shop</a>
    </div>
</section>
@endif

@if($featuredListings->isNotEmpty() && !$searchQuery && !$selectedCategory)
<section class="card light-card" style="margin-top:18px;">
    <div class="section-head">
        <div><span class="eyebrow">In evidenza</span><h3 class="section-title">Prodotti in primo piano</h3></div>
    </div>
    <div class="featured-strip">
        @foreach($featuredListings as $listing)
        <x-shop.product-card
            :listing="$listing"
            as="featured"
            :href="route('portal.shop.show', $listing)"
            :overlay="$listing->isInStock() ? null : 'Esaurito'"
            :show-star="true">
            <x-slot:actions>
                @if($siPuoAggiungere($listing))
                <form method="POST" action="{{ route('portal.cart.add', $listing) }}" data-carrello style="width:100%;">
                    @csrf
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" class="cta buy" style="width:100%;">Aggiungi al carrello</button>
                </form>
                @else
                <a class="cta" style="width:100%;text-align:center;" href="{{ route('portal.shop.show', $listing) }}">Vedi il prodotto</a>
                @endif
            </x-slot:actions>
        </x-shop.product-card>
        @endforeach
    </div>
</section>
@endif

<div class="catalog-grid" style="margin-top:18px;">
    @forelse($listings as $listing)
    {{-- Prodotti non 'active' (sospesi da azienda/admin) compaiono qui SOLO per
         il proprietario/admin (vedi ListingController::index, query con orWhere
         company_id) — al pubblico restano invisibili grazie allo scope active()
         applicato comunque come condizione base. Overlay + azioni sotto rendono
         chiaro che non sono visibili nello shop pubblico (2026-07-30).

         Il prodotto sospeso non ha una pagina dettaglio raggiungibile (show()
         la blocca per chiunque, proprietario incluso): niente link su foto e
         titolo in quel caso, per non portare a un redirect "non disponibile"
         quando basta il pulsante "Riattiva" qui sotto per gestirlo. --}}
    @php $attivo = $listing->status === 'active'; @endphp
    <x-shop.product-card
        :listing="$listing"
        :href="$attivo ? route('portal.shop.show', $listing) : null"
        :inactive="! $attivo"
        :overlay="! $attivo
            ? \App\Models\Listing::statusLabel($listing->status)
            : (! $listing->isInStock() ? 'Esaurito' : null)">
        <x-slot:actions>
            @if($attivo)
                @if($siPuoAggiungere($listing))
                    {{-- Un form vero, non un bottone finto: senza JavaScript
                         aggiunge lo stesso, ricaricando la pagina come prima.
                         Con il JavaScript parte in background e risponde il
                         mini-carrello. --}}
                    <form method="POST" action="{{ route('portal.cart.add', $listing) }}" data-carrello style="flex:1;">
                        @csrf
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="cta buy" style="width:100%;">Aggiungi al carrello</button>
                    </form>
                    <a class="cta secondary" href="{{ route('portal.shop.show', $listing) }}">Vedi</a>
                @else
                    <a class="cta" style="flex:1;text-align:center;" href="{{ route('portal.shop.show', $listing) }}">Vedi il prodotto</a>
                @endif
            @else
                <span class="listing-hidden-note">Non visibile al pubblico</span>
            @endif
            @if($sonoIlProprietario($listing))
                <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary">Modifica</a>
                {{-- Sospendi/Riattiva (2026-07-30): azienda proprietaria E admin possono
                     nascondere temporaneamente il prodotto dal pubblico e riattivarlo
                     quando serve, senza doverlo eliminare. Solo toggle active<->suspended:
                     'draft'/'expired' restano stati gestiti solo da admin/sistema. --}}
                <form method="POST" action="{{ route('portal.shop.status', $listing) }}" style="display:inline;">
                    @csrf
                    <input type="hidden" name="status" value="{{ $attivo ? 'suspended' : 'active' }}">
                    <button type="submit" class="cta secondary">{{ $attivo ? 'Sospendi' : 'Riattiva' }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-shop.product-card>
    @empty
    <x-shop.empty :message="$selectedCompany
        ? $selectedCompany->name . ' non ha prodotti in vendita al momento.'
        : 'Nessun prodotto trovato nel catalogo.'">
        @if($searchQuery || $selectedCategory || $selectedSubcategory || $kyFilter !== '')
            <a href="{{ route('portal.shop', $selectedCompany ? ['company' => $selectedCompany->id] : []) }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Rimuovi filtri</a>
        @endif
        @if($selectedCompany)
            <a href="{{ route('portal.shop') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Vedi tutto lo shop</a>
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
