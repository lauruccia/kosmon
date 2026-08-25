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
            @php $urls = $listing->image_urls; @endphp
            @if(count($urls) > 0)
            <div style="margin-top:20px;">
                {{-- Immagine principale --}}
                <div style="position:relative;border-radius:12px;overflow:hidden;background:#f1f5f9;cursor:zoom-in;" onclick="openLightbox(0)">
                    <img id="gallery-main"
                         src="{{ $urls[0] }}"
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
                    <img src="{{ $url }}"
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
                    Costo di spedizione: <strong>{{ ky_format($listing->shipping_cost) }} KY</strong> equiv., una sola volta per ordine (non per pezzo).
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
        $inStock      = $listing->isInStock();
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
        $requiredKy   = $listing->effective_ky_amount + ($needsShippingAddress ? $listing->shipping_ky_amount : 0);
        $canAfford    = $currentAccount->saldoDisponibile() >= $requiredKy;
    @endphp
    <div class="stack" style="position:sticky;top:20px;">
        <section class="card account-hero card-pad">
            <div class="k-tag">Acquisto nel circuito KMoney</div>
            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin:16px 0 4px;">
                <div style="font-size:36px;font-weight:300;color:#0c4a86;letter-spacing:.06em;">
                    {{ ky_format($listing->effective_price_ky) }}
                </div>
                @if($listing->is_on_offer)
                    <div style="font-size:16px;color:rgba(255,255,255,.55);text-decoration:line-through;">
                        {{ ky_format($listing->price_ky) }}
                    </div>
                @endif
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
                <span style="font-size:14px;color:#64748b;">KY (KMoney)</span>
                <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:14px;{{ $listing->effective_ky_badge_color }}">
                    {{ $listing->effective_ky_badge_label }}
                </span>
                <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:14px;{{ $inStock ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;' }}">
                    {{ $listing->stock_label }}
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
                @if($isOwnCompany)
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
                         da parte adesso e si ricarica con calma. --}}
                    <form method="POST" action="{{ route('portal.cart.add', $listing) }}">
                        @csrf
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="cta-outline">Aggiungi al carrello</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('portal.shop.buy', $listing) }}">
                        @csrf
                        @if($listing->hasLimitedStock() && $listing->stock_quantity > 1)
                        <div class="qty-field">
                            <label>Quantità</label>
                            <input type="number" name="quantity" value="1" min="1" max="{{ $listing->stock_quantity }}">
                        </div>
                        @else
                        <input type="hidden" name="quantity" value="1">
                        @endif
                        <button type="submit" class="cta" style="width:100%;text-align:center;"
                            onclick="return confirm('Confermi l\'acquisto di questo prodotto? Verranno addebitati {{ ky_format($requiredKy) }} KY dal tuo conto{{ $needsShippingAddress && $listing->shipping_cost ? ' (incluso il costo di spedizione)' : '' }}.')">
                            Acquista — {{ ky_format($requiredKy) }} KY{{ $listing->effective_ky_percentage < 100 ? ' + quota EUR' : '' }}
                        </button>

                        {{-- Carrello (2026-08-25, fase C). Stesso form del bottone
                             qui sopra, cambia solo la destinazione: cosi' la
                             quantita' scelta vale per entrambi. "Compra ora"
                             resta la strada principale — chi vuole un pezzo solo
                             non deve passare da tre pagine. --}}
                        <button type="submit" class="cta-outline"
                                formaction="{{ route('portal.cart.add', $listing) }}">
                            Aggiungi al carrello
                        </button>
                    </form>
                @endif
            </div>
        </section>

        @if(auth()->user()->company_id === $listing->company_id || auth()->user()->is_super_admin)
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
@php $urls = $listing->image_urls; @endphp
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
    const urls  = @json($urls);
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
        document.getElementById('gallery-main').src = urls[idx];
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

    @media (max-width: 900px) {
        .product-detail-grid { grid-template-columns: 1fr !important; }
        .product-detail-grid > div:last-child { position: static !important; }
    }
</style>
@endsection
