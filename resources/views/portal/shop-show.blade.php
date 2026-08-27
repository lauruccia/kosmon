@extends('layouts.portal')

@section('content')
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
                    <h2 style="font-size:26px;font-weight:700;color:#10263d;margin:6px 0 0;">{{ $listing->title }}</h2>
                    <div class="subtle" style="margin-top:6px;">
                        Pubblicato da <strong>{{ $listing->company->name }}</strong>
                        · {{ $listing->created_at->locale('it')->isoFormat('D MMM YYYY') }}
                        · {{ $listing->views_count }} visualizzazioni
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    @if($listing->is_on_offer)
                        <span class="pill" style="background:#fee2e2;color:#991b1b;">🔥 -{{ $listing->offer_discount_percent }}% offerta</span>
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
            @if(count($urls) > 0)
            <div style="margin-top:20px;">
                {{-- Immagine principale --}}
                <div style="position:relative;border-radius:12px;overflow:hidden;background:#f1f5f9;cursor:zoom-in;" onclick="openLightbox(0)">
                    <img id="gallery-main"
                         src="{{ $medi[0] ?? $urls[0] }}"
                         alt="{{ $listing->title }}"
                         style="width:100%;max-height:420px;object-fit:cover;display:block;">
                    @if(count($urls) > 1)
                    <div style="position:absolute;bottom:10px;right:14px;background:rgba(0,0,0,.5);color:#fff;font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;">
                        1 / {{ count($urls) }}
                    </div>
                    @endif
                    @if(! $listing->isInStock())
                    <div style="position:absolute;top:12px;left:12px;background:rgba(159,18,57,.92);color:#fff;font-size:11.5px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;padding:5px 12px;border-radius:999px;">
                        Esaurito
                    </div>
                    @endif
                </div>
                {{-- Thumbnail strip --}}
                @if(count($urls) > 1)
                <div style="display:flex;gap:8px;margin-top:10px;overflow-x:auto;padding-bottom:4px;">
                    @foreach($urls as $i => $url)
                    <img src="{{ $piccole[$i] ?? $url }}"
                         alt="Foto {{ $i + 1 }}"
                         onclick="selectThumb({{ $i }})"
                         id="thumb-{{ $i }}"
                         style="width:72px;height:72px;object-fit:cover;border-radius:8px;cursor:pointer;border:2.5px solid {{ $i === 0 ? '#0c4a86' : '#e2e8f0' }};flex-shrink:0;transition:border-color .15s;">
                    @endforeach
                </div>
                @endif
            </div>
            @else
            <div style="margin-top:20px;border-radius:12px;background:linear-gradient(150deg,#f8fafc,#fff);border:1px solid #f1f5f9;display:flex;align-items:center;justify-content:center;height:220px;color:#94a3b8;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            @endif

            <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0;">

            <div style="font-size:15px;line-height:1.8;color:#334155;white-space:pre-line;">{{ $listing->description }}</div>

            <div style="margin-top:20px;background:#eff6ff;border-left:3px solid #0c4a86;border-radius:8px;padding:12px 16px;font-size:14px;color:#1e3a5f;">
                🚚 <strong>{{ $listing->delivery_type_label }}</strong>
                @if($listing->delivery_note)
                    — {{ $listing->delivery_note }}
                @endif
                @if($listing->requiresShippingAddress() && $listing->shipping_cost)
                <div style="margin-top:4px;font-size:12.5px;color:#0369a1;">
                    Costo di spedizione: <strong>{{ ky_format($listing->shipping_cost) }} KY</strong>
                </div>
                @endif
            </div>

            @if($listing->expires_at)
            <div style="margin-top:12px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:8px;padding:12px 16px;font-size:14px;color:#78350f;">
                ⏱ <strong>Offerta valida fino al:</strong> {{ $listing->expires_at->locale('it')->isoFormat('D MMMM YYYY') }}
            </div>
            @endif

            {{-- "Offerta della settimana" (2026-08-13): NON va confusa col box sopra
                 (quello è la scadenza generica del prodotto, listings.expires_at) —
                 questo è lo sconto a tempo su prezzo/percentuale KY, vedi
                 Listing::activeOffer()/ListingOffer. --}}
            @if($listing->is_on_offer)
            <div style="margin-top:12px;background:#fef2f2;border-left:3px solid #dc2626;border-radius:8px;padding:12px 16px;font-size:14px;color:#7f1d1d;">
                🔥 <strong>Offerta della settimana:</strong> -{{ $listing->offer_discount_percent }}% rispetto al prezzo pieno,
                scade il {{ $listing->activeOffer->expires_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}.
            </div>
            @endif
        </section>

        @if($related->isNotEmpty())
        <section class="card light-card">
            <div class="section-head">
                <div><span class="eyebrow">Stessa categoria</span><h3 class="section-title">Prodotti correlati</h3></div>
            </div>
            <div style="display:flex;flex-direction:column;gap:12px;">
                @foreach($related as $rel)
                <article style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f1f5f9;">
                    <div>
                        <div style="font-weight:600;font-size:14px;color:#10263d;">{{ $rel->title }}</div>
                        <div class="subtle">{{ $rel->company->name }}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <strong style="color:#0c4a86;">{{ ky_format($rel->effective_price_ky) }} KY</strong>
                        <a href="{{ route('portal.shop.show', $rel) }}" class="cta secondary" style="padding:6px 14px;font-size:13px;">Vedi</a>
                    </div>
                </article>
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
                      style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:14px;{{ $inStock ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;' }}">
                    {{ $etichettaScorte }}
                </span>
            </div>
            @if($listing->effective_ky_percentage < 100)
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#0369a1;">
                <strong>Pagamento misto:</strong>
                Al momento dell'acquisto vengono addebitati solo {{ ky_format($listing->effective_ky_amount) }} KY nel circuito
                {{-- euro_amount = price_ky - ky_amount, quindi anche questo è in centesimi:
                     number_format() diretto lo mostrava grezzo (senza /100), stesso bug ×100. --}}
                (per unità); il restante {{ 100 - $listing->effective_ky_percentage }}% ({{ ky_format($listing->effective_euro_amount) }} KY equiv.) va saldato in EUR direttamente col venditore, fuori dal circuito.
            </div>
            @endif

            <div class="metric">
                <div class="metric-label">Venditore</div>
                <div class="metric-value" style="font-size:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="{{ route('portal.companies.show', $listing->company->slug) }}" style="color:inherit;text-decoration:none;">{{ $listing->company->name }}</a>
                    @if($listing->company->plan)
                        <span style="font-size:10px;font-weight:800;letter-spacing:.03em;padding:2px 8px;border-radius:999px;color:#fff;background:{{ $listing->company->plan->effective_badge_color }};">{{ strtoupper($listing->company->plan->name) }}</span>
                    @endif
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
            <div style="background:var(--bg,#f8fafc);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#334155;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">Spedizione a</div>
                    <a href="{{ $shippingEditUrl }}" style="font-size:11px;font-weight:600;color:#0c4a86;text-decoration:none;white-space:nowrap;">Modifica</a>
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
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0;">
                        Questo venditore non è al momento operativo nel circuito: i suoi prodotti non sono acquistabili.
                    </p>
                @elseif($compratoreSospeso)
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0;">
                        La tua azienda è sospesa: non puoi effettuare acquisti finché la sospensione è attiva. Contatta il supporto.
                    </p>
                @elseif($isOwnCompany)
                    <p style="font-size:12px;color:#94a3b8;text-align:center;margin:0;">È un prodotto pubblicato dalla tua azienda.</p>
                @elseif(! $inStock)
                    <button disabled class="cta" style="width:100%;text-align:center;opacity:.5;cursor:not-allowed;">
                        Prodotto esaurito
                    </button>
                @elseif($needsShippingAddress && ! $hasShippingAddress)
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                        Questo prodotto va spedito: completa il tuo indirizzo di spedizione nella sezione dedicata del tuo profilo per poterlo acquistare.
                    </p>
                    <a href="{{ $shippingEditUrl }}" class="cta" style="width:100%;text-align:center;display:block;">
                        Completa indirizzo di spedizione
                    </a>
                @elseif(! $canAfford)
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0 0 10px;">
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
                    <form method="POST" action="{{ route('portal.cart.add', $listing) }}" id="{{ $formAcquistoId }}">
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
                       style="display:none;font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                    </p>

                    {{-- Il form punta al CARRELLO, non ai soldi (audit 26/08,
                         blocco 3). "Acquista" apre la cassa - la stessa del
                         carrello, con dentro questo solo prodotto - e l'addebito
                         si conferma li'. Prima da qui partiva un POST che pagava
                         davvero, con un confirm() del browser come unica
                         conferma: su mobile i dialoghi si possono sopprimere, e
                         allora un tocco diventava un addebito. --}}
                    <form method="POST" action="{{ route('portal.cart.add', $listing) }}" id="{{ $formAcquistoId }}">
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
                    <p style="margin:6px 0 0;font-size:13.5px;color:#334155;">
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
                    <button type="submit" style="width:100%;padding:10px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:10px;font-weight:600;cursor:pointer;">Rimuovi</button>
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
        const counter = document.querySelector('#gallery-main + div');
        if (counter) counter.textContent = `${idx + 1} / ${urls.length}`;
        document.querySelectorAll('[id^="thumb-"]').forEach((el, i) => {
            el.style.borderColor = i === idx ? '#0c4a86' : '#e2e8f0';
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

<style>
    .shop-back-link {
        display: inline-flex; align-items: center; gap: 6px;
        color: var(--ink-soft); text-decoration: none; font-size: 14px; font-weight: 600;
        transition: color .15s;
    }
    .shop-back-link:hover { color: var(--primary); }


    /* Campo quantità nel box acquisto (card scura .account-hero):
       non esisteva CSS per .field-label/.field-input, l'input era
       completamente privo di stile. */
    .qty-field { margin-bottom: 12px; }
    .qty-field label {
        display: block; margin-bottom: 6px; font-size: 11.5px; font-weight: 700;
        color: rgba(255,255,255,.75); text-transform: uppercase; letter-spacing: .06em;
    }
    .qty-field input {
        width: 100%; min-height: 42px; padding: 9px 14px; font-size: 14px;
        border-radius: 9px; border: 1px solid rgba(255,255,255,.25);
        background: rgba(255,255,255,.95); color: #0d1c30;
        outline: none; transition: border-color .15s, box-shadow .15s;
    }
    .qty-field input:focus { border-color: #fff; box-shadow: 0 0 0 3px rgba(255,255,255,.28); }

    /* ── Scelta della variante ────────────────────────────────────────────
       Pulsanti invece di una tendina (25/08/2026): la tendina nascondeva
       l'esistenza stessa delle taglie dietro un clic. Sono radio veri con la
       label vestita da pulsante — nessuna riga di JavaScript. */
    /* Un riquadro suo, con uno sfondo diverso dal pannello: e' la prima cosa
       da fare su questa pagina, e deve staccarsi dal resto invece di
       confondersi con le altre righe (richiesta di Laura, 25/08/2026). */
    .variant-picker {
        margin: 16px 0 18px; padding: 13px 15px 15px;
        background: rgba(255,255,255,.10);
        border: 1px solid rgba(255,255,255,.22);
        border-left: 3px solid #7dd3fc;
        border-radius: 12px;
    }
    .variant-picker-title {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        font-size: 12.5px; font-weight: 700; color: #fff;
        text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px;
    }
    .variant-picker-hint {
        font-size: 9.5px; font-weight: 700; letter-spacing: .06em;
        padding: 2px 7px; border-radius: 999px;
        background: rgba(125,211,252,.22); color: #bae6fd;
    }
    .variant-options { display: flex; flex-wrap: wrap; gap: 8px; }

    /* Il radio sparisce alla vista ma NON al lettore di schermo e alla
       tastiera: niente display:none, che lo toglierebbe dal giro del Tab. */
    .variant-radio {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
    }

    /* ATTENZIONE ALLA SPECIFICITA' (25/08/2026). Il layout portale ha
       ".card label { display:block; color:var(--ink-soft) }" — una regola
       classe+elemento che BATTE un ".variant-option" da sola. La prima
       versione di questi pulsanti la perdeva: le taglie uscivano grigie e
       schiacciate su una riga sola, illeggibili sul pannello scuro. Il
       selettore discendente ".variant-options .variant-option" pesa due
       classi e vince. Se un domani questi stili tornano grigi, e' qui che si
       guarda. */
    .variant-options .variant-option {
        display: flex; align-items: center; justify-content: center;
        min-width: 52px; min-height: 40px; padding: 8px 14px;
        border-radius: 9px; cursor: pointer;
        margin: 0; letter-spacing: .02em; text-transform: none;
        font-size: 15px; font-weight: 700; line-height: 1;
        border: 1.5px solid rgba(255,255,255,.35); background: rgba(255,255,255,.10);
        color: #fff; transition: background .15s, border-color .15s;
    }
    .variant-options .variant-option:hover {
        background: rgba(255,255,255,.22); border-color: #fff;
    }

    /* Selezionata: si inverte. Il contrasto pieno e' l'unica cosa che si legge
       davvero a colpo d'occhio su un pannello scuro. */
    .variant-options .variant-radio:checked + .variant-option {
        background: #fff; border-color: #fff; color: #0d1c30;
        box-shadow: 0 0 0 2px rgba(255,255,255,.35);
    }

    /* Il focus da tastiera deve vedersi: senza, chi naviga col Tab non sa
       dove si trova. */
    .variant-options .variant-radio:focus-visible + .variant-option {
        box-shadow: 0 0 0 3px rgba(255,255,255,.55);
    }

    /* Esaurita: resta in elenco, tratteggiata e barrata — come le taglie finite
       su Amazon. Sparire sarebbe peggio: chi cerca la M vuole sapere che la M
       esiste ma e' finita, non credere che quel venditore non la faccia. */
    .variant-options .variant-option.is-out {
        opacity: .45; cursor: not-allowed; border-style: dashed;
        background: transparent; text-decoration: line-through;
    }
    .variant-options .variant-option.is-out:hover {
        background: transparent; border-color: rgba(255,255,255,.35);
    }

    /* Tendina di riserva oltre le 12 combinazioni. */
    .variant-select {
        width: 100%; min-height: 42px; padding: 9px 14px; font-size: 14px;
        border-radius: 9px; border: 1px solid rgba(255,255,255,.25);
        background: rgba(255,255,255,.95); color: #0d1c30;
        outline: none; appearance: auto;
    }
    .variant-select:focus { border-color: #fff; box-shadow: 0 0 0 3px rgba(255,255,255,.28); }

    /* Bottone secondario dentro il box scuro dell'acquisto: stessa forma del
       .cta ma vuoto, per non mettere in concorrenza "Acquista" e "Aggiungi al
       carrello". */
    .cta-outline {
        display: block; width: 100%; margin-top: 10px; min-height: 42px;
        padding: 10px 16px; text-align: center; font-size: 14px; font-weight: 600;
        border-radius: 9px; cursor: pointer;
        background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.45);
        transition: background .15s, border-color .15s;
    }
    .cta-outline:hover { background: rgba(255,255,255,.12); border-color: rgba(255,255,255,.75); }

    /* I due bottoni d'acquisto affiancati. Due trappole, tutte e due prese in
       pieno il 27/08/2026 (i bottoni restavano incolonnati sul sito vero
       mentre in prova sembravano a posto):

       1. il form sta dentro .quick-actions, che e' gia' un flex container:
          senza `flex:1 1 100%` il form si stringe sul contenuto e la riga dei
          bottoni non prende la larghezza del pannello;
       2. la colonna d'acquisto e' larga 360px, ma lo spazio DAVVERO
          disponibile dentro la card e' ~280px: con una base di 140px per
          bottone, 140+140+gap non ci stava e il flex-wrap li mandava a capo —
          cioe' esattamente l'aspetto incolonnato di prima, ma per una ragione
          diversa. Serve `flex:1 1 auto` (base = contenuto) con il testo su una
          riga sola e un font leggermente piu' piccolo.

       Sotto i ~280px vanno a capo per davvero, ed e' giusto: "Aggiungi al
       carrello" spezzato su due righe sarebbe peggio. align-items di default
       (stretch) li tiene alti uguali nonostante le min-height diverse. */
    .quick-actions > form { flex: 1 1 100%; }
    .acquisto-azioni { display: flex; flex-wrap: wrap; gap: 8px; }
    .acquisto-azioni > .cta,
    .acquisto-azioni > .cta-outline {
        flex: 1 1 auto; min-width: 0; width: auto; margin-top: 0;
        text-align: center; font-size: 13px; padding: 8px 10px;
        white-space: nowrap;
    }

    @media (max-width: 900px) {
        .product-detail-grid { grid-template-columns: 1fr !important; }
        .product-detail-grid > div:last-child { position: static !important; }
    }
</style>

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
            scorte.style.background = ceNe ? '#dcfce7' : '#fee2e2';
            scorte.style.color      = ceNe ? '#166534' : '#991b1b';
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
