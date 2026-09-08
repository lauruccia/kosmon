@extends('layouts.portal')

@section('content')
<x-shop.styles />
<div style="margin-bottom:16px;">
    <a href="{{ route('portal.shop') }}" class="shop-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Torna allo shop
    </a>
</div>

<div class="product-detail-grid" style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

    {{-- Colonna principale --}}
    <div class="stack">
        <section class="card light-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                <div>
                    <span class="eyebrow">{{ $listing->category_label }}</span>
                    <h2 style="font-size:26px;font-weight:700;color:var(--ink);margin:6px 0 0;">{{ $listing->title }}</h2>
                    <div class="subtle" style="margin-top:6px;">
                        {{-- Anche qui il venditore e' una porta, non un'etichetta
                             (08/09/2026): porta ai SUOI prodotti, non alla scheda
                             azienda — chi legge il nome sotto un prodotto si sta
                             chiedendo cos'altro vende, non che partita IVA ha.
                             La scheda azienda resta a un clic, nella colonna a
                             destra. --}}
                        Pubblicato da <a href="{{ route('portal.shop', ['company' => $listing->company_id]) }}" class="seller-link"><strong>{{ $listing->company->name }}</strong></a>
                        · {{ $listing->created_at->locale('it')->isoFormat('D MMM YYYY') }}
                        · {{ $listing->views_count }} visualizzazioni
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    @if($listing->is_on_offer)
                        <span class="pill" style="background:var(--danger-soft);color:var(--danger);">🔥 -{{ $listing->offer_discount_percent }}% offerta</span>
                    @endif
                    @if($listing->featured)
                        <span class="pill warn">★ In evidenza</span>
                    @endif
                </div>
            </div>

            {{-- Galleria immagini --}}
            @php
                // Tre elenchi, tre pesi (27/08/2026). La foto grande arriva
                // nella misura media, la striscia sotto nella misura card, e
                // l'originale a piena risoluzione lo scarica SOLO la lente
                // d'ingrandimento, cioe' quando qualcuno vuole davvero
                // guardare da vicino. Prima questa pagina scaricava cinque
                // originali da qualche megabyte l'uno per mostrarne uno.
                $urls    = $listing->image_urls;
                $medi    = $listing->medium_image_urls;
                $piccole = $listing->card_image_urls;
            @endphp
            {{-- LA FOTO NON E' PIU' UNO STRISCIONE (08/09/2026, scelta di Laura
                 dopo il confronto a due colonne). Era larga quanto la colonna,
                 alta 420px e RITAGLIATA per riempirla: allo spremiagrumi
                 spariva la parte alta, e la prima schermata se ne andava tutta
                 in una foto, con la descrizione sotto la piega.
                 Adesso: riquadro quadrato con la foto intera, miniature a
                 fianco, e la larghezza che avanza la prende la descrizione. Le
                 regole stanno in shop.css (blocco 11), qui resta solo la
                 struttura. --}}
            <div class="scheda-media">
                @if(count($urls) > 0)
                <div class="scheda-galleria">
                    @if(count($urls) > 1)
                    <div class="scheda-thumbs">
                        @foreach($urls as $i => $url)
                        <img src="{{ $piccole[$i] ?? $url }}"
                             alt="Foto {{ $i + 1 }}"
                             onclick="selectThumb({{ $i }})"
                             id="thumb-{{ $i }}"
                             class="thumb-strip-img{{ $i === 0 ? ' is-active' : '' }}">
                        @endforeach
                    </div>
                    @endif
                    <div class="scheda-foto" onclick="openLightbox(0)">
                        <img id="gallery-main"
                             src="{{ $medi[0] ?? $urls[0] }}"
                             alt="{{ $listing->title }}">
                        @if(count($urls) > 1)
                        <span class="scheda-contatore" id="gallery-counter">1 / {{ count($urls) }}</span>
                        @endif
                        @if(! $listing->isInStock())
                        <span class="scheda-esaurito">Esaurito</span>
                        @endif
                    </div>
                </div>
                @else
                <div class="scheda-foto scheda-foto--vuota">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                @endif

                <div class="scheda-descrizione">{{ $listing->description }}</div>
            </div>

            <hr style="border:none;border-top:1px solid var(--line);margin:20px 0;">

            <div style="margin-top:20px;background:var(--info-soft);border-left:3px solid var(--info);border-radius:8px;padding:12px 16px;font-size:14px;color:var(--ink);">
                🚚 <strong>{{ $listing->delivery_type_label }}</strong>
                @if($listing->delivery_note)
                    — {{ $listing->delivery_note }}
                @endif
                @if($listing->requiresShippingAddress() && $listing->shipping_cost)
                <div style="margin-top:4px;font-size:12.5px;color:var(--info);">
                    Costo di spedizione: <strong>{{ ky_format($listing->shipping_cost) }} KY</strong>
                </div>
                @endif
            </div>

            @if($listing->expires_at)
            <div style="margin-top:12px;background:var(--warning-soft);border-left:3px solid var(--warning-line);border-radius:8px;padding:12px 16px;font-size:14px;color:var(--warning);">
                ⏱ <strong>Offerta valida fino al:</strong> {{ $listing->expires_at->locale('it')->isoFormat('D MMMM YYYY') }}
            </div>
            @endif

            {{-- "Offerta della settimana" (2026-08-13): NON va confusa col box sopra
                 (quello è la scadenza generica del prodotto, listings.expires_at) —
                 questo è lo sconto a tempo su prezzo/percentuale KY, vedi
                 Listing::activeOffer()/ListingOffer. --}}
            @if($listing->is_on_offer)
            <div style="margin-top:12px;background:var(--danger-soft);border-left:3px solid var(--danger);border-radius:8px;padding:12px 16px;font-size:14px;color:var(--danger);">
                🔥 <strong>Offerta della settimana:</strong> -{{ $listing->offer_discount_percent }}% rispetto al prezzo pieno,
                scade il {{ $listing->activeOffer->expires_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}.
            </div>
            @endif
        </section>

        {{-- ALTRI PRODOTTI DELLO STESSO VENDITORE (08/09/2026, richiesta di
             Laura). Fino a ieri questa fascia pescava per CATEGORIA da tutto
             il circuito: sotto un prodotto comparivano tre prodotti simili di
             tre concorrenti, e la scheda finiva per mandare via il compratore
             invece di trattenerlo. Il negozio che paga per stare qui dentro si
             vedeva la vetrina dei rivali stampata in fondo alla propria
             pagina.

             Adesso e' il venditore a decidere la fascia: sono i suoi altri
             prodotti, con quelli della stessa categoria per primi (vedi
             ListingController::show). Se non ne ha altri la sezione non
             compare: nessun ripiego sul catalogo generale, o si tornerebbe
             esattamente al problema di prima. --}}
        @if($related->isNotEmpty())
        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Dallo stesso venditore</span>
                    <h3 class="section-title">Altri prodotti di {{ $listing->company->name }}</h3>
                </div>
                <a href="{{ route('portal.shop', ['company' => $listing->company_id]) }}" class="cta secondary" style="font-size:12px;padding:0 12px;min-height:32px;">Vedi tutto il negozio &rarr;</a>
            </div>
            {{-- `related-grid` e non `catalog-grid`: quattro colonne fisse,
                 tutte su una riga. Il catalogo va ad `auto-fill` sullo spazio
                 che ha, e qui — colonna sinistra della scheda prodotto, piu'
                 stretta della pagina catalogo — ne entravano tre, col quarto
                 prodotto solo su una seconda riga. Vedi shop.css, blocco 15. --}}
            <div class="related-grid">
                @foreach($related as $rel)
                <x-shop.product-card
                    :listing="$rel"
                    :href="route('portal.shop.show', $rel)"
                    :overlay="$rel->isInStock() ? null : 'Esaurito'">
                    {{-- Il chip col nome del venditore qui non serve: e' lo
                         stesso venditore della pagina, lo dice il titolo della
                         sezione. Al suo posto la categoria, che invece cambia
                         da prodotto a prodotto.

                         Niente slot `actions`: foto e titolo sono gia' il link
                         alla scheda, e quattro bottoni "Vedi il prodotto" in
                         fila pesavano piu' dei prodotti che dovevano mostrare. --}}
                    <x-slot:meta>
                        <span class="chip">{{ $rel->category_label }}</span>
                    </x-slot:meta>
                </x-shop.product-card>
                @endforeach
            </div>
        </section>
        @endif
    </div>

    {{-- Sidebar acquisto --}}
    @php
        $isOwnCompany = auth()->user()->company_id === $listing->company_id;
        $inStock      = $listing->isVariabile()
            ? $listing->variantiAttive->contains(fn ($v) => $v->isDisponibile())
            : $listing->isInStock();

        // Il prodotto variabile non ha scorte proprie: l'unica cosa vera che
        // si puo' dire PRIMA della scelta e' se ne resta almeno una taglia.
        $etichettaScorte = $listing->isVariabile()
            ? ($inStock ? 'Disponibile' : 'Esaurito')
            : $listing->stock_label;

        $needsShippingAddress = $listing->requiresShippingAddress();
        $hasShippingAddress   = $currentAccount->hasShippingAddress();
        // Link alla sezione indirizzo di spedizione del profilo, con
        // redirect_to (path relativo, MAI l'URL assoluto di route() — la
        // sanitizzazione anti open-redirect in PortalController lo
        // rifiuterebbe) cosi' l'utente torna qui in automatico dopo il
        // salvataggio invece di restare sul profilo.
        $shippingReturnUrl = route('portal.shop.show', $listing, false);
        $shippingEditUrl = ($currentAccount->owner_type === 'private'
                ? route('portal.personal-profile.edit', ['redirect_to' => $shippingReturnUrl])
                : route('portal.profile.edit', ['redirect_to' => $shippingReturnUrl]))
            . '#shipping-address';
        // Il saldo minimo necessario include anche l'eventuale quota KY di
        // spedizione (una sola volta, non moltiplicata per quantità) —
        // per coerenza con quanto viene poi realmente addebitato in buy().
        // effective_ky_amount (non ky_amount, 2026-08-13): se il prodotto ha
        // un'offerta attiva, il prezzo/percentuale da considerare sono quelli
        // dell'offerta — vedi Listing::activeOffer().
        // Prodotti variabili (fase D, 25/08/2026): se il prodotto ha
        // combinazioni, il prezzo mostrato in cima e' quello della piu'
        // economica ancora disponibile, e il bottone resta spento finche' non
        // se ne sceglie una.
        // ORDINE: quello deciso dall'admin sui valori (S, M, L, XL), NON il
        // prezzo. Le taglie hanno un ordine loro che col prezzo non c'entra, e
        // con dieci taglie ordinate per prezzo il selettore diventa
        // illeggibile — vedi ListingVariant::chiaveOrdinamento().
        $varianti = $listing->isVariabile()
            ? $listing->variantiAttive->sortBy(fn ($v) => $v->chiaveOrdinamento())->values()
            : collect();
        $variantiDisponibili = $varianti->filter(fn ($v) => $v->isDisponibile());
        // La piu' economica si cerca a parte, perche' l'elenco non e' piu'
        // ordinato per prezzo.
        $variantePiuEconomica = $variantiDisponibili->sortBy(fn ($v) => $v->prezzoEffettivo())->first();

        // Il saldo che serve DAVVERO. Su un prodotto variabile il prezzo base
        // non e' quello che si paga: se la S costa meno del prezzo del
        // prodotto, chiedere il prezzo base vorrebbe dire dire "saldo
        // insufficiente" a chi la S se la puo' permettere (25/08/2026).
        $prezzoDiIngresso = $variantePiuEconomica
            ? $variantePiuEconomica->prezzoEffettivo()
            : $listing->effective_price_ky;
        $requiredKy = ($variantePiuEconomica
                ? $variantePiuEconomica->quotaKy()
                : $listing->effective_ky_amount)
            + ($needsShippingAddress ? $listing->shipping_ky_amount : 0);
        $canAfford    = $currentAccount->saldoDisponibile() >= $requiredKy;

        // Il selettore delle varianti sta IN CIMA, sopra il prezzo (richiesta
        // di Laura, 25/08/2026): è la prima decisione che prende chi compra, e
        // il prezzo lo si legge dopo aver visto quale taglia si sceglie.
        //
        // Sta quindi FUORI dal form, e i radio ci si agganciano con
        // l'attributo `form` dell'HTML — che serve esattamente a questo:
        // tenere un campo dove ha senso per chi legge, senza spostare il form.
        // In ogni ramo qui sotto di form ce n'è al massimo uno, quindi un id
        // solo basta e i radio non possono finire nel posto sbagliato.
        $formAcquistoId = 'form-acquisto';

        // LE TAGLIE SI VEDONO SEMPRE, anche quando non si puo' comprare.
        //
        // Prima il riquadro compariva solo se esisteva un form di acquisto, e
        // cosi' chi non aveva ancora messo l'indirizzo di spedizione vedeva
        // "completa il tuo indirizzo" e nient'altro: del fatto che quel
        // prodotto avesse delle taglie non c'era traccia (segnalato da Laura il
        // 26/08/2026 su /shop/42, utenza azienda senza indirizzo). Stessa cosa
        // sui prodotti esauriti e su quelli della propria azienda.
        //
        // Che cosa c'e' in vendita e' un'informazione che si legge sempre; se
        // poi si possa comprare o no e' un'altra questione, e la risolvono i
        // bottoni qui sotto. E' anche una regola piu' semplice da tenere in
        // testa: "ci sono combinazioni -> si vedono", senza eccezioni da
        // ricordare.
        $mostraSelettoreVarianti = $varianti->isNotEmpty();

        // Il form invece c'e' solo quando si puo' davvero fare qualcosa. I
        // radio ci si agganciano con l'attributo `form` soltanto in quel caso:
        // altrove restano lì da guardare, e il prezzo grande segue comunque la
        // taglia scelta.
        // Una sospensione toglie il form come lo toglie l'essere il proprietario:
        // le taglie restano visibili (si legge sempre cosa c'e' in vendita), ma
        // non c'e' niente da premere.
        $venditoreSospeso  = (bool) $listing->company?->isSuspended();
        $compratoreSospeso = ! $venditoreSospeso && (bool) $currentAccount?->company?->isSuspended();

        $formAcquistoPresente = ! $isOwnCompany
            && ! $venditoreSospeso
            && ! $compratoreSospeso
            && $inStock
            && ! ($needsShippingAddress && ! $hasShippingAddress);
    @endphp
    <div class="stack" style="position:sticky;top:20px;">
        <section class="card account-hero card-pad">
            <div class="k-tag">Acquisto nel circuito KMoney</div>

            @if($mostraSelettoreVarianti)
                @include('portal.partials.variant-select', [
                    'varianti' => $varianti,
                    'formId'   => $formAcquistoPresente ? $formAcquistoId : null,
                    'speseKy'  => $needsShippingAddress ? $listing->shipping_ky_amount : 0,
                ])
            @endif

            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin:16px 0 4px;">
                {{-- "da" finche' non si sceglie: 100,00 secchi su un prodotto
                     in cui la S costa 90 e la XL 110 e' un'informazione
                     sbagliata. Scelta la taglia, il "da" sparisce e resta il
                     prezzo di quella taglia — e' il JavaScript in fondo alla
                     pagina a toglierlo. --}}
                @if($varianti->count() > 1)
                    <div id="prezzo-da" style="font-size:14px;color:rgba(255,255,255,.65);">da</div>
                @endif
                {{-- Il pannello e' scuro (account-hero): il blu #0c4a86 di prima
                     ci spariva dentro (segnalato da Laura il 25/08/2026). --}}
                <div id="prezzo-grande" style="font-size:36px;font-weight:300;color:#fff;letter-spacing:.06em;">
                    {{ ky_format($prezzoDiIngresso) }}
                </div>
                @if($listing->is_on_offer)
                    <div style="font-size:16px;color:rgba(255,255,255,.55);text-decoration:line-through;">
                        {{ ky_format($listing->price_ky) }}
                    </div>
                @endif
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
                <span style="font-size:14px;color:rgba(255,255,255,.7);">KY (KMoney)</span>
                <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:14px;{{ $listing->effective_ky_badge_color }}">
                    {{ $listing->effective_ky_badge_label }}
                </span>
                {{--
                    Il badge delle scorte segue quello che l'utente sta
                    guardando, non il prodotto padre (audit 26/08, blocco 5).

                    Su un prodotto variabile le scorte stanno sulle
                    combinazioni e il padre non ne ha: `stock_label` diceva
                    quindi "Disponibile" sempre, anche quando erano finite
                    tutte le taglie — pastiglia rossa con scritto
                    "Disponibile", che e' il modo piu' rapido di far perdere
                    fiducia a chi legge. Scelta una taglia, il JavaScript qui
                    sotto ci scrive quante ne restano DI QUELLA.
                --}}
                <span id="badge-scorte"
                      class="stock-pill {{ $inStock ? 'stock-pill--in' : 'stock-pill--out' }}">
                    {{ $etichettaScorte }}
                </span>
            </div>
            @if($listing->effective_ky_percentage < 100)
            <div style="background:var(--info-soft);border:1px solid var(--info-line);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:var(--info);">
                {{-- QUANTO E QUANDO, non come funziona il circuito (08/09/2026,
                     testo scritto da Laura: quello di prima era lungo tre righe
                     e mezzo e spiegava la contabilita' del circuito — che quegli
                     euro non siano KY e non li incassi KNM e' roba nostra, non
                     del compratore. Resta scritto in checkout-thanks, dopo
                     l'acquisto, dove serve davvero.

                     euro_amount = price_ky - ky_amount: anche questo in
                     centesimi, quindi ky_format() e non number_format(). --}}
                <strong>Pagamento misto:</strong>
                Paga {{ ky_format($listing->effective_ky_amount) }} KY ora e
                {{ ky_format($listing->effective_euro_amount) }} &euro; subito dopo
                con carta, PayPal o bonifico.
            </div>
            @endif

            <div class="metric">
                <div class="metric-label">Venditore</div>
                <div class="metric-value" style="font-size:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="{{ route('portal.shop', ['company' => $listing->company_id]) }}" class="seller-link">{{ $listing->company->name }}</a>
                    @if($listing->company->plan)
                        <span style="font-size:10px;font-weight:800;letter-spacing:.03em;padding:2px 8px;border-radius:999px;color:#fff;background:{{ $listing->company->plan->effective_badge_color }};">{{ strtoupper($listing->company->plan->name) }}</span>
                    @endif
                </div>
                {{-- Il nome adesso porta al negozio, quindi il profilo azienda —
                     che fino a ieri stava proprio su quel nome — avrebbe perso
                     l'unica strada che aveva da questa pagina. Qui sotto, in
                     piccolo: chi cerca partita IVA, settore e contatti la trova
                     dov'era, chi cerca gli altri prodotti non ci finisce dentro
                     per sbaglio. --}}
                <div style="margin-top:6px;display:flex;gap:12px;flex-wrap:wrap;font-size:12px;">
                    <a href="{{ route('portal.shop', ['company' => $listing->company_id]) }}" style="color:var(--info);font-weight:600;text-decoration:none;">Tutti i suoi prodotti &rarr;</a>
                    <a href="{{ route('portal.companies.show', $listing->company->slug) }}" style="color:var(--ink-muted);text-decoration:none;">Scheda azienda</a>
                </div>
            </div>
            @if($listing->contact_info)
            <div class="metric">
                <div class="metric-label">Contatto</div>
                <div class="metric-value" style="font-size:14px;">{{ $listing->contact_info }}</div>
            </div>
            @endif
            <div class="metric">
                <div class="metric-label">Il tuo saldo</div>
                <div class="metric-value">{{ ky_format($currentAccount->saldoDisponibile()) }} KY</div>
            </div>

            @if($needsShippingAddress && $hasShippingAddress && ! $isOwnCompany && $inStock)
            <div class="hero-box">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                    <div class="hero-box-label">Spedizione a</div>
                    <a href="{{ $shippingEditUrl }}" style="font-size:11px;font-weight:600;color:var(--info);text-decoration:none;white-space:nowrap;">Modifica</a>
                </div>
                @foreach($currentAccount->shipping_address_lines as $line)
                    {{ $line }}@if(! $loop->last)<br>@endif
                @endforeach
            </div>
            @endif

            <div class="quick-actions" style="margin-top:20px;">
                {{-- Venditore o compratore sospeso: fuori dal commercio
                     (decisione di Laura, 26/08/2026). Sta in cima alla catena
                     perche' e' la ragione piu' forte di tutte - inutile
                     proporre il carrello o "ricarica il conto" a chi comunque
                     non puo' concludere. --}}
                @if($venditoreSospeso)
                    <p style="font-size:12.5px;color:var(--warning);background:var(--warning-soft);border:1px solid var(--warning-line);border-radius:8px;padding:10px 14px;margin:0;">
                        Questo venditore non è al momento operativo nel circuito: i suoi prodotti non sono acquistabili.
                    </p>
                @elseif($compratoreSospeso)
                    <p style="font-size:12.5px;color:var(--warning);background:var(--warning-soft);border:1px solid var(--warning-line);border-radius:8px;padding:10px 14px;margin:0;">
                        La tua azienda è sospesa: non puoi effettuare acquisti finché la sospensione è attiva. Contatta il supporto.
                    </p>
                @elseif($isOwnCompany)
                    <p style="font-size:12px;color:var(--ink-muted);text-align:center;margin:0;">È un prodotto pubblicato dalla tua azienda.</p>
                @elseif(! $inStock)
                    <button disabled class="cta" style="width:100%;text-align:center;opacity:.5;cursor:not-allowed;">
                        Prodotto esaurito
                    </button>
                @elseif($needsShippingAddress && ! $hasShippingAddress)
                    <p style="font-size:12.5px;color:var(--warning);background:var(--warning-soft);border:1px solid var(--warning-line);border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                        Questo prodotto va spedito: completa il tuo indirizzo di spedizione nella sezione dedicata del tuo profilo per poterlo acquistare.
                    </p>
                    <a href="{{ $shippingEditUrl }}" class="cta" style="width:100%;text-align:center;display:block;">
                        Completa indirizzo di spedizione
                    </a>
                @elseif(! $canAfford)
                    <p style="font-size:12.5px;color:var(--warning);background:var(--warning-soft);border:1px solid var(--warning-line);border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                        Saldo insufficiente: ti mancano {{ ky_format($requiredKy - $currentAccount->saldoDisponibile()) }} KY per acquistare questo prodotto.
                    </p>
                    <a href="{{ route('portal.ky-cards.index', ['redirect_to' => route('portal.shop.show', $listing)]) }}" class="cta" style="width:100%;text-align:center;display:block;">
                        Ricarica il tuo conto
                    </a>
                    {{-- Il carrello resta possibile anche senza saldo: si mette
                         da parte adesso e si ricarica con calma. Il selettore
                         della variante DEVE stare anche qui: senza, chi non ha
                         abbastanza KY non vedeva le taglie da nessuna parte e
                         il bottone finiva contro "Scegli una variante prima di
                         aggiungere il prodotto al carrello" (25/08/2026). --}}
                    <form method="POST" action="{{ route('portal.cart.add', $listing) }}" id="{{ $formAcquistoId }}" data-carrello>
                        @csrf
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="cta-outline">Aggiungi al carrello</button>
                    </form>
                @else
                    {{-- Il saldo qui basta per la combinazione PIU' ECONOMICA:
                         se se ne sceglie una piu' cara puo' non bastare piu'.
                         Prima il prezzo stava su ogni pulsante e si vedeva; ora
                         che il prezzo e' uno solo, ci pensa il JavaScript ad
                         accendere questo avviso e a spegnere il bottone. Il
                         server resta comunque l'autorita': se il JavaScript non
                         gira, l'acquisto viene rifiutato li' con lo stesso
                         messaggio, e nessun KY si muove. --}}
                    <p id="avviso-saldo-variante"
                       style="display:none;font-size:12.5px;color:var(--warning);background:var(--warning-soft);border:1px solid var(--warning-line);border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                    </p>

                    {{-- Il form punta al CARRELLO, non ai soldi (audit 26/08,
                         blocco 3). "Acquista" apre la cassa - la stessa del
                         carrello, con dentro questo solo prodotto - e l'addebito
                         si conferma li'. Prima da qui partiva un POST che pagava
                         davvero, con un confirm() del browser come unica
                         conferma: su mobile i dialoghi si possono sopprimere, e
                         allora un tocco diventava un addebito. --}}
                    <form method="POST" action="{{ route('portal.cart.add', $listing) }}" id="{{ $formAcquistoId }}" data-carrello>
                        @csrf

                        @if($listing->hasLimitedStock() && $listing->stock_quantity > 1)
                        <div class="qty-field">
                            <label>Quantità</label>
                            <input type="number" name="quantity" value="1" min="1" max="{{ $listing->stock_quantity }}">
                        </div>
                        @else
                        <input type="hidden" name="quantity" value="1">
                        @endif
                        {{-- Niente piu' confirm(): questo bottone non paga, apre
                             la cassa. La conferma vera - con l'indirizzo, la nota
                             al venditore e la spunta sulle condizioni di vendita -
                             si da' li'. --}}
                        {{-- I due bottoni sulla STESSA RIGA (27/08/2026, richiesta
                             di Laura): sono le due strade possibili da qui, e in
                             colonna la seconda sembrava un ripensamento. Sotto i
                             ~340px di pannello vanno a capo da soli (flex-wrap).
                             Il carrello usa la destinazione predefinita del form;
                             l'acquisto la scavalca col formaction. La quantita'
                             scelta vale per tutti e due. --}}
                        <div class="acquisto-azioni">
                            <button type="submit" class="cta" id="bottone-acquisto"
                                    formaction="{{ route('portal.shop.buy.form', $listing) }}">
                                Acquista ora
                            </button>
                            <button type="submit" class="cta-outline">
                                Aggiungi al carrello
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </section>

        @if(auth()->user()->company_id === $listing->company_id || auth()->user()->is_super_admin)
        {{-- Varianti (fase D, 2026-08-25): la gestione sta in una pagina sua. --}}
        <section class="card light-card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <div>
                    <span class="eyebrow">Varianti</span>
                    <p style="margin:6px 0 0;font-size:13.5px;color:var(--ink-soft);">
                        @if($listing->isVariabile())
                            Questo prodotto ha <strong>{{ $listing->variantiAttive->count() }}</strong>
                            {{ $listing->variantiAttive->count() === 1 ? 'combinazione in vendita' : 'combinazioni in vendita' }}.
                        @else
                            Vendi questo prodotto in più taglie, colori o formati? Puoi crearne le combinazioni.
                        @endif
                    </p>
                </div>
                <a href="{{ route('portal.shop.variants', $listing) }}" class="cta" style="white-space:nowrap;">
                    {{ $listing->isVariabile() ? 'Gestisci varianti' : 'Aggiungi varianti' }}
                </a>
            </div>
        </section>

        <section class="card light-card">
            <h3 class="card-title">Gestione prodotto</h3>
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px;">
                <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary" style="text-align:center;">Modifica</a>
                <form method="POST" action="{{ route('portal.shop.destroy', $listing) }}" onsubmit="return confirm('Rimuovere questo prodotto dallo shop?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="width:100%;padding:10px;background:var(--danger-soft);color:var(--danger);border:1px solid var(--danger-line);border-radius:10px;font-weight:600;cursor:pointer;">Rimuovi</button>
                </form>
            </div>
        </section>
        @endif
    </div>
</div>

{{-- Lightbox --}}
@php
    $urls = $listing->image_urls;
    $medi = $listing->medium_image_urls;
@endphp
@if(count($urls) > 0)
<div id="lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center;" onclick="closeLightbox(event)">
    <button onclick="lightboxPrev(event)" style="position:absolute;left:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:48px;height:48px;border-radius:50%;cursor:pointer;">‹</button>
    <img id="lightbox-img" src="" alt="" style="max-width:90vw;max-height:88vh;object-fit:contain;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.6);">
    <button onclick="lightboxNext(event)" style="position:absolute;right:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:48px;height:48px;border-radius:50%;cursor:pointer;">›</button>
    <button onclick="closeLightbox()" style="position:absolute;top:16px;right:20px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:20px;width:40px;height:40px;border-radius:50%;cursor:pointer;">✕</button>
    <div id="lightbox-counter" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,.7);font-size:13px;"></div>
</div>

<script>
(function () {
    // `urls` sono gli originali e li usa SOLO la lente; `medi` e' quello che
    // finisce nella foto grande quando si cambia miniatura. Tenerli separati
    // e' l'unico modo perche' il primo clic su una miniatura non scarichi
    // l'originale intero vanificando tutto.
    const urls  = @json($urls);
    const medi  = @json($medi);
    let current = 0;

    window.openLightbox = function (idx) {
        current = idx;
        updateLightbox();
        document.getElementById('lightbox').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };
    window.closeLightbox = function (e) {
        if (e && e.target !== document.getElementById('lightbox')) return;
        document.getElementById('lightbox').style.display = 'none';
        document.body.style.overflow = '';
    };
    window.lightboxPrev = function (e) { e.stopPropagation(); current = (current - 1 + urls.length) % urls.length; updateLightbox(); };
    window.lightboxNext = function (e) { e.stopPropagation(); current = (current + 1) % urls.length; updateLightbox(); };

    function updateLightbox() {
        document.getElementById('lightbox-img').src = urls[current];
        document.getElementById('lightbox-counter').textContent = urls.length > 1 ? `${current + 1} / ${urls.length}` : '';
    }

    window.selectThumb = function (idx) {
        current = idx;
        document.getElementById('gallery-main').src = medi[idx] ?? urls[idx];
        // Un id suo (08/09/2026): prima si cercava "il div subito dopo la foto",
        // e la galleria nuova gliene mette accanto un altro.
        const counter = document.getElementById('gallery-counter');
        if (counter) counter.textContent = `${idx + 1} / ${urls.length}`;
        document.querySelectorAll('[id^="thumb-"]').forEach((el, i) => {
            el.classList.toggle('is-active', i === idx);
        });
    };

    document.addEventListener('keydown', function (e) {
        const lb = document.getElementById('lightbox');
        if (lb.style.display === 'none') return;
        if (e.key === 'ArrowLeft')  lightboxPrev(e);
        if (e.key === 'ArrowRight') lightboxNext(e);
        if (e.key === 'Escape')     { lb.style.display='none'; document.body.style.overflow=''; }
    });
})();
</script>
@endif


@if($mostraSelettoreVarianti)
{{--
    Il prezzo grande segue la taglia scelta (25/08/2026, richiesta di Laura con
    Amazon come riferimento): sui pulsanti c'e' solo la taglia, il prezzo e' uno
    solo ed e' quello qui sopra.

    Tutto quello che serve sta gia' nel `data-` dei radio, scritto dal server:
    qui non si calcola niente, si legge e si scrive. E se questo script non
    gira — JavaScript spento, errore, browser vecchio — la pagina resta quella
    di prima, con "da <la piu' economica>": nessun bottone si blocca e il conto
    vero lo fa comunque il server, che rifiuta l'acquisto con lo stesso
    messaggio se il saldo non basta. Il controllo di qui e' una cortesia, non
    una difesa.
--}}
<script>
(function () {
    var radios  = document.querySelectorAll('.variant-radio, .variant-select');
    var prezzo  = document.getElementById('prezzo-grande');
    var daLabel = document.getElementById('prezzo-da');
    var avviso  = document.getElementById('avviso-saldo-variante');
    var scorte  = document.getElementById('badge-scorte');
    var bottone = document.getElementById('bottone-acquisto');
    var saldo   = {{ (int) $currentAccount->saldoDisponibile() }};

    if (! prezzo || ! radios.length) { return; }

    // Stessa formattazione di ky_format(): centesimi -> "1.234,56".
    function formatta(centesimi) {
        return (centesimi / 100).toLocaleString('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function aggiorna(elemento) {
        if (! elemento) { return; }

        var costa    = parseInt(elemento.getAttribute('data-prezzo'), 10);
        var richiede = parseInt(elemento.getAttribute('data-richiesto'), 10);
        if (isNaN(costa)) { return; }

        prezzo.textContent = formatta(costa);
        // Scelta la taglia, "da" non ha piu' senso: il prezzo e' quello.
        if (daLabel) { daLabel.style.display = 'none'; }

        // E nemmeno le scorte del padre: adesso contano quelle di questa
        // taglia. Se il dato non c'e' si lascia stare quello che c'era, che
        // e' comunque vero.
        var etichetta = elemento.getAttribute('data-scorte');

        if (scorte && etichetta) {
            scorte.textContent = etichetta;

            var ceNe = elemento.getAttribute('data-disponibile') !== '0';
            // I colori stanno nel foglio di stile (stock-pill--in/--out), non qui:
            // prima erano scritti due volte, e il badge cambiava tinta al primo clic.
            scorte.className = 'stock-pill ' + (ceNe ? 'stock-pill--in' : 'stock-pill--out');
        }

        if (! avviso || ! bottone || isNaN(richiede)) { return; }

        if (richiede > saldo) {
            avviso.textContent = 'Saldo insufficiente per questa combinazione: '
                + 'ti mancano ' + formatta(richiede - saldo) + ' KY. '
                + 'Scegline un\'altra oppure ricarica il conto.';
            avviso.style.display = '';
            bottone.disabled = true;
            bottone.style.opacity = '.5';
            bottone.style.cursor = 'not-allowed';
        } else {
            avviso.style.display = 'none';
            bottone.disabled = false;
            bottone.style.opacity = '';
            bottone.style.cursor = '';
        }
    }

    Array.prototype.forEach.call(radios, function (elemento) {
        elemento.addEventListener('change', function () {
            aggiorna(elemento.tagName === 'SELECT'
                ? elemento.options[elemento.selectedIndex]
                : elemento);
        });
    });
})();
</script>
@endif
@endsection
