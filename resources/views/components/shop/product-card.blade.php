@props([
    'listing',
    'as'          => 'catalog',   // catalog (griglia) | featured (striscia)
    'href'        => null,        // link alla scheda; null = non cliccabile
    'price'       => null,        // centesimi; default: prezzo effettivo (offerta inclusa)
    'oldPrice'    => null,        // centesimi barrati
    'mixLabel'    => null,
    'mixStyle'    => null,
    'overlay'     => null,        // testo sul velo scuro
    'inactive'    => false,
    'statusBadge' => null,        // ['label' => ..., 'status' => ...] per "I miei prodotti"
    'showOffer'   => true,
    'showFullKy'  => true,
    'showStar'    => true,
])
@php
    /*
     * LA card prodotto. Una sola.
     *
     * Prima del 02/09/2026 questo markup era copiato in quattro punti — shop,
     * shop-mine, shop-offers e la striscia "in evidenza" — e ogni ritocco
     * andava rifatto quattro volte, sbagliandone sempre uno. Se serve una
     * variante nuova, si aggiunge una prop qui: non si copia il file.
     *
     * Le prop di prezzo e mix sono esplicite invece che lette dal modello
     * perche' le viste non guardano tutte la stessa cosa: il catalogo mostra il
     * prezzo EFFETTIVO (con l'offerta gia' applicata), "I miei prodotti" mostra
     * il prezzo di listino. Lasciare la scelta al chiamante evita di indovinare.
     */
    $wrapper   = $as === 'featured' ? 'featured-card' : 'catalog-card';
    $prezzo    = $price    ?? $listing->effective_price_ky;
    $prezzoVec = $oldPrice ?? ($listing->is_on_offer ? $listing->price_ky : null);
    $etichetta = $mixLabel ?? $listing->effective_ky_badge_label;
    $stileMix  = $mixStyle ?? $listing->effective_ky_badge_color;
    $mixDaMostrare = $mixLabel !== null
        ? true
        : (int) $listing->effective_ky_percentage !== 100;
@endphp
<article class="{{ $wrapper }}{{ $inactive ? ' catalog-card--inactive' : '' }}">
    <x-shop.media :listing="$listing" :href="$href" :overlay="$overlay">
        {{-- L'ordine conta: il badge dell'offerta va stampato PRIMA della
             stella, cosi' il combinatore ~ del foglio di stile sposta giu' la
             stella quando ci sono tutt'e due. --}}
        @if($showOffer && $listing->is_on_offer)
            <span class="product-badge product-badge--offer">-{{ $listing->offer_discount_percent }}%</span>
        @endif
        @if($showStar && $listing->featured)
            <span class="product-badge product-badge--featured" title="In evidenza">★</span>
        @endif
        @if($showFullKy && (int) $listing->effective_ky_percentage === 100)
            <span class="product-badge product-badge--full-ky">100% KY</span>
        @endif
        @if($statusBadge)
            <span class="mine-status-badge mine-status-badge--{{ $statusBadge['status'] }}">{{ $statusBadge['label'] }}</span>
        @endif
    </x-shop.media>

    <div class="product-body">
        <h3 class="product-title" title="{{ $listing->title }}">
            @if($href)
                <a href="{{ $href }}">{{ $listing->title }}</a>
            @else
                <span>{{ $listing->title }}</span>
            @endif
        </h3>

        <div class="entity-meta">
            @if(isset($meta) && ! $meta->isEmpty())
                {{ $meta }}
            @else
                <span class="chip chip-ellipsis" title="{{ $listing->company->name }}">{{ $listing->company->name }}</span>
            @endif
        </div>

        <div class="product-price-row">
            <x-shop.price :amount="$prezzo" :old="$prezzoVec" />
            @if($mixDaMostrare)
                <x-shop.mix-badge :label="$etichetta" :style="$stileMix" />
            @endif
        </div>

        {{ $extra ?? '' }}

        @if(isset($actions) && ! $actions->isEmpty())
            <div class="page-actions">{{ $actions }}</div>
        @endif
    </div>
</article>
