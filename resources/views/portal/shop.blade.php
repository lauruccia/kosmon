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
        {{-- Filtro venditore: chi arriva dal pulsante "SHOP" della directory
             aziende deve restare dentro il negozio di quell'azienda anche
             dopo aver cercato o cambiato categoria (2026-08-25). --}}
        @if($selectedCompany)
            <input type="hidden" name="company" value="{{ $selectedCompany->id }}">
        @endif
        <div class="shop-toolbar-field" style="flex:1;min-width:220px;">
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
        <div style="margin-left:auto;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
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
        <article class="featured-card">
            <a href="{{ route('portal.shop.show', $listing) }}" class="product-media">
                @if($listing->first_image_url)
                    <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" loading="lazy">
                @else
                    <div class="product-media-placeholder"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                @endif
                @if($listing->is_on_offer)
                    <span class="product-badge product-badge--offer">-{{ $listing->offer_discount_percent }}%</span>
                @endif
                @if($listing->effective_ky_percentage === 100)
                    <span class="product-badge product-badge--full-ky">100% KY</span>
                @endif
                <span class="product-badge product-badge--featured">★</span>
                @if(! $listing->isInStock())
                    <span class="product-media-overlay">Esaurito</span>
                @endif
            </a>
            <div class="product-body">
                <h3 class="product-title" title="{{ $listing->title }}">
                    <a href="{{ route('portal.shop.show', $listing) }}">{{ $listing->title }}</a>
                </h3>
                <div class="entity-meta">
                    <span class="chip chip-ellipsis" title="{{ $listing->company->name }}">{{ $listing->company->name }}</span>
                </div>
                <div class="product-price-row">
                    <span class="product-price">{{ ky_format($listing->effective_price_ky) }} <small>KY</small></span>
                    @if($listing->is_on_offer)
                        <span style="text-decoration:line-through;color:var(--ink-muted);font-size:12px;">{{ ky_format($listing->price_ky) }} KY</span>
                    @endif
                    @if($listing->effective_ky_percentage !== 100)
                        <span class="mix-badge" style="{{ $listing->effective_ky_badge_color }}">{{ $listing->effective_ky_badge_label }}</span>
                    @endif
                </div>
                <a class="cta" style="width:100%;text-align:center;" href="{{ route('portal.shop.show', $listing) }}">Acquista ora</a>
            </div>
        </article>
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
         chiaro che non sono visibili nello shop pubblico (2026-07-30). --}}
    <article class="catalog-card{{ $listing->status !== 'active' ? ' catalog-card--inactive' : '' }}">
        {{-- Il prodotto sospeso non ha una pagina dettaglio raggiungibile (show()
             la blocca per chiunque, proprietario incluso): niente link su foto/
             titolo in quel caso, per non portare a un redirect "non disponibile"
             quando basta il pulsante "Riattiva" qui sotto per gestirlo. --}}
        @if($listing->status === 'active')
        <a href="{{ route('portal.shop.show', $listing) }}" class="product-media">
        @else
        <div class="product-media">
        @endif
            @if($listing->first_image_url)
                <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" loading="lazy">
            @else
                <div class="product-media-placeholder">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            @endif
            @if($listing->is_on_offer)
                <span class="product-badge product-badge--offer">-{{ $listing->offer_discount_percent }}%</span>
            @endif
            @if($listing->effective_ky_percentage === 100)
                <span class="product-badge product-badge--full-ky">100% KY</span>
            @endif
            @if($listing->featured)<span class="product-badge product-badge--featured">★</span>@endif
            @if($listing->status !== 'active')
                <span class="product-media-overlay">{{ \App\Models\Listing::statusLabel($listing->status) }}</span>
            @elseif(! $listing->isInStock())
                <span class="product-media-overlay">Esaurito</span>
            @endif
        @if($listing->status === 'active')
        </a>
        @else
        </div>
        @endif
        <div class="product-body">
            <h3 class="product-title" title="{{ $listing->title }}">
                @if($listing->status === 'active')
                    <a href="{{ route('portal.shop.show', $listing) }}">{{ $listing->title }}</a>
                @else
                    <span>{{ $listing->title }}</span>
                @endif
            </h3>
            <div class="entity-meta">
                <span class="chip chip-ellipsis" title="{{ $listing->company->name }}">{{ $listing->company->name }}</span>
            </div>
            <div class="product-price-row">
                <span class="product-price">{{ ky_format($listing->effective_price_ky) }} <small>KY</small></span>
                @if($listing->is_on_offer)
                    <span style="text-decoration:line-through;color:var(--ink-muted);font-size:12px;">{{ ky_format($listing->price_ky) }} KY</span>
                @endif
                @if($listing->effective_ky_percentage !== 100)
                    <span class="mix-badge" style="{{ $listing->effective_ky_badge_color }}">{{ $listing->effective_ky_badge_label }}</span>
                @endif
            </div>
            <div class="page-actions" style="margin-top:2px;">
                @if($listing->status === 'active')
                    <a class="cta" style="flex:1;text-align:center;" href="{{ route('portal.shop.show', $listing) }}">Acquista ora</a>
                @else
                    <span class="listing-hidden-note">Non visibile al pubblico</span>
                @endif
                @if(auth()->user()->company_id === $listing->company_id || auth()->user()->is_super_admin)
                    <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary">Modifica</a>
                    {{-- Sospendi/Riattiva (2026-07-30): azienda proprietaria E admin possono
                         nascondere temporaneamente il prodotto dal pubblico e riattivarlo
                         quando serve, senza doverlo eliminare. Solo toggle active<->suspended:
                         'draft'/'expired' restano stati gestiti solo da admin/sistema. --}}
                    <form method="POST" action="{{ route('portal.shop.status', $listing) }}" style="display:inline;">
                        @csrf
                        <input type="hidden" name="status" value="{{ $listing->status === 'active' ? 'suspended' : 'active' }}">
                        <button type="submit" class="cta secondary">{{ $listing->status === 'active' ? 'Sospendi' : 'Riattiva' }}</button>
                    </form>
                @endif
            </div>
        </div>
    </article>
    @empty
    <div class="shop-empty">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <p class="subtle">
            @if($selectedCompany)
                {{ $selectedCompany->name }} non ha prodotti in vendita al momento.
            @else
                Nessun prodotto trovato nel catalogo.
            @endif
        </p>
        @if($searchQuery || $selectedCategory || $selectedSubcategory || $kyFilter !== '')
            <a href="{{ route('portal.shop', $selectedCompany ? ['company' => $selectedCompany->id] : []) }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Rimuovi filtri</a>
        @endif
        @if($selectedCompany)
            <a href="{{ route('portal.shop') }}" class="cta secondary" style="margin-top:6px;display:inline-block;">Vedi tutto lo shop</a>
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
    .shop-seller-banner {
        margin-top: 18px; display: flex; align-items: center; justify-content: space-between;
        gap: 12px; flex-wrap: wrap;
    }
    .shop-seller-banner-actions { display: flex; gap: 8px; flex-wrap: wrap; }

    /* ── Toolbar shop: ricerca con icona + select coerenti col design system ── */
    .shop-toolbar-card { padding: 18px 22px; }
    /* Niente a capo (2026-07-29 sera): la barra filtri resta su un'unica
       riga anche con il nuovo campo "Filtro Kmoney" — flex-wrap:nowrap +
       overflow-x:auto come rete di sicurezza se lo spazio non basta,
       invece di lasciar scendere i campi su una seconda riga. */
    .shop-toolbar { display: flex; gap: 14px; flex-wrap: nowrap; align-items: flex-end; overflow-x: auto; padding-bottom: 2px; }
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

    /* ── Catalogo: griglia responsive stile ecommerce ──
       Schede più corte (2026-07-30): foto meno alta (aspect-ratio) + meno
       padding/gap nel corpo card, per ridurre lo spazio bianco inutile. */
    .catalog-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .catalog-card {
        padding: 0; overflow: hidden; display: flex; flex-direction: column;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .catalog-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: var(--line-strong);
    }
    /* Prodotto sospeso/non attivo, visibile solo al proprietario/admin (2026-07-30) */
    .catalog-card--inactive { opacity: .72; }
    .catalog-card--inactive:hover { opacity: 1; }
    .listing-hidden-note {
        flex: 1; font-size: 12px; font-weight: 600; color: var(--ink-muted);
        display: flex; align-items: center; justify-content: center; text-align: center;
    }
    .product-media {
        position: relative; display: block; aspect-ratio: 16 / 10;
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
    .product-badge--featured { right: 10px; background: #fef3c7; color: #92400e; }
    /* Ribbon "100% KY" (2026-07-29): sostituisce il vecchio badge piano/Ecommerce
       — è la vera informazione utile per chi acquista, quindi va evidenziata
       direttamente sulla foto invece che in un chip generico nel corpo card. */
    .product-badge--full-ky {
        left: 10px; background: #059669; color: #fff; font-weight: 800;
        box-shadow: 0 2px 8px rgba(5,150,105,.35);
    }
    /* Badge sconto "-X%" (2026-08-13, offerta della settimana): stesso angolo
       del badge "In evidenza" (right:10px) — se un prodotto è ENTRAMBI in
       evidenza e in offerta, il selettore sotto sposta giù la stella per non
       sovrapporsi (l'offerta, più urgente, resta in alto). L'ordine nel markup
       (badge offerta renderizzato PRIMA di quello "In evidenza") è quello che
       fa funzionare il combinatore ~ qui sotto. */
    .product-badge--offer { right: 10px; background: #dc2626; color: #fff; font-weight: 800; box-shadow: 0 2px 8px rgba(220,38,38,.35); }
    .product-badge--offer ~ .product-badge--featured { top: 40px; }
    .product-body { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 5px; flex: 1; }
    /* Titolo bloccato a 2 righe (2026-08-26, richiesta Laura): l'altezza e' FISSA
       anche quando il titolo sta su una riga sola, cosi' il bianco che avanza
       si raccoglie tutto qui sotto e non fra venditore e prezzo.
       min-height in EM e non in px: vale anche per la variante piu' piccola
       delle card "in evidenza" (13px), senza una seconda regola.
       `overflow:hidden` e' obbligatorio, senza quello line-clamp non taglia.
       ATTENZIONE: questo blocco era gia' stato scritto una volta e cancellato
       per sbaglio dal commit f46d731 (due sessioni sullo stesso file). */
    .product-title {
        margin: 0; font-size: 14px; font-weight: 700; line-height: 1.25;
        display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;
        overflow: hidden; min-height: 2.5em;
    }
    .product-title a { color: var(--ink); text-decoration: none; }
    .product-title a:hover { color: var(--primary); }
    /* Il blocco venditore + prezzo + bottone e' incollato in fondo alla card
       (2026-08-26, richiesta Laura): l'unico `margin-top:auto` di tutto il
       corpo card sta QUI, sulla riga del venditore, cosi' tutto lo spazio che
       avanza finisce in un punto solo — sotto il titolo. ATTENZIONE: in
       flexbox lo spazio libero si divide fra TUTTI i margini auto, quindi se
       si rimette `margin-top:auto` anche su .product-price-row il bianco si
       spacca in due e il venditore si stacca dal prezzo. */
    .catalog-card .product-body .entity-meta,
    .featured-card .product-body .entity-meta { margin-top: auto; }
    .product-price-row {
        margin-top: 0; padding-top: 2px;
        display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
    }
    .product-price {
        font-size: 19px; font-weight: 800; color: var(--primary-strong); letter-spacing: -.02em;
    }
    .product-price small { font-size: 11px; font-weight: 700; margin-left: 2px; }
    .mix-badge { font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
    /* CTA più prominente in stile "Acquista ora" (Amazon-like) */
    .catalog-card .page-actions .cta,
    .featured-card .product-body .cta {
        font-weight: 700; letter-spacing: .01em;
    }
    .catalog-card .page-actions { margin-top: 0 !important; }

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
    .featured-card .product-body { padding: 9px 11px 11px; gap: 5px; }
    .featured-card .product-title { font-size: 13px; }

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
