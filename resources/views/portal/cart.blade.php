@extends('layouts.portal')

@section('content')
<div style="margin-bottom:16px;">
    <a href="{{ route('portal.shop') }}" class="shop-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Continua a fare acquisti
    </a>
</div>

@if($cart->isVuoto())

    <section class="card light-card" style="text-align:center;padding:48px 24px;">
        <div style="font-size:44px;line-height:1;margin-bottom:12px;">🛒</div>
        <h2 style="font-size:20px;font-weight:700;color:#10263d;margin:0 0 8px;">Il carrello è vuoto</h2>
        <p class="subtle" style="margin:0 0 20px;">Quello che aggiungi resta qui anche se cambi dispositivo.</p>
        <a href="{{ route('portal.shop') }}" class="cta" style="display:inline-block;">Vai allo shop</a>
    </section>

@else

    @php
        // Stessa logica della pagina prodotto: il profilo da aprire dipende da
        // chi sei, e il redirect_to e' un path RELATIVO (route(..., false)) —
        // la sanitizzazione anti open-redirect in PortalController rifiuta gli
        // URL assoluti.
        $ritorno = route('portal.cart', [], false);
        $shippingEditUrl = ($currentAccount->owner_type === 'private'
                ? route('portal.personal-profile.edit', ['redirect_to' => $ritorno])
                : route('portal.profile.edit', ['redirect_to' => $ritorno]))
            . '#shipping-address';

        $indisponibili = $cart->items->filter(fn ($r) => ! $r->isDisponibile());
        $serveIndirizzo = $gruppi->contains(fn ($g) => $g['richiede_indirizzo']);
        $saldoBasta = $saldoDisponibile >= $totaleKy;
        $puoPagare = $indisponibili->isEmpty() && $saldoBasta && (! $serveIndirizzo || $indirizzoCompleto);
    @endphp

    <div class="cart-grid" style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

        {{-- ── I prodotti, raggruppati per venditore ────────────────────── --}}
        <div class="stack">

            @foreach($gruppi as $gruppo)
            <section class="card light-card">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
                    <div>
                        <span class="eyebrow">Venditore</span>
                        <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 0;">
                            <a href="{{ route('portal.shop', ['company' => $gruppo['company']->id]) }}" style="color:inherit;text-decoration:none;">
                                {{ $gruppo['company']->name }}
                            </a>
                        </h3>
                    </div>
                    <span class="pill">{{ $gruppo['righe']->count() }} {{ $gruppo['righe']->count() === 1 ? 'prodotto' : 'prodotti' }}</span>
                </div>

                @foreach($gruppo['righe'] as $riga)
                @php
                    $listing = $riga->listing;
                    $immagini = $listing->image_urls;
                    $motivo = $riga->motivoIndisponibilita();
                @endphp
                <div class="cart-row" style="display:flex;gap:14px;padding:14px 0;border-top:1px solid #eef2f7;{{ $motivo ? 'opacity:.72;' : '' }}">

                    <a href="{{ route('portal.shop.show', $listing) }}" style="flex:0 0 72px;">
                        @if(count($immagini) > 0)
                            <img src="{{ $immagini[0] }}" alt="{{ $listing->title }}" style="width:72px;height:72px;object-fit:cover;border-radius:10px;display:block;">
                        @else
                            <div style="width:72px;height:72px;border-radius:10px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:22px;">📦</div>
                        @endif
                    </a>

                    <div style="flex:1;min-width:0;">
                        <a href="{{ route('portal.shop.show', $listing) }}" style="font-weight:600;color:#10263d;text-decoration:none;font-size:15px;">
                            {{ $listing->title }}
                        </a>

                        @if($riga->etichettaVariante())
                            <div style="margin-top:4px;font-size:13px;color:#475569;">{{ $riga->etichettaVariante() }}</div>
                        @endif

                        <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <span class="pill" style="{{ $listing->effective_ky_badge_color }}">{{ $listing->effective_ky_badge_label }}</span>
                            @if($listing->is_on_offer)
                                <span class="pill" style="background:#fee2e2;color:#991b1b;">🔥 -{{ $listing->offer_discount_percent }}%</span>
                            @endif
                            <span class="subtle" style="font-size:12px;">{{ ky_format($riga->prezzoUnitario()) }} KY l'uno</span>
                        </div>

                        @if($motivo)
                        <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:7px 11px;margin:8px 0 0;">
                            {{ $motivo }}
                        </p>
                        @endif

                        <div style="margin-top:10px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                            <form method="POST" action="{{ route('portal.cart.item.update', $riga) }}" style="display:flex;align-items:center;gap:6px;">
                                @csrf
                                @method('PATCH')
                                <label class="subtle" style="font-size:12px;">Quantità</label>
                                <input type="number" name="quantity" value="{{ $riga->quantity }}" min="1"
                                       @if($riga->variant && $riga->variant->hasLimitedStock()) max="{{ $riga->variant->stock_quantity }}"
                                       @elseif(! $riga->variant && $listing->hasLimitedStock()) max="{{ $listing->stock_quantity }}" @endif
                                       style="width:66px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;">
                                <button type="submit" style="background:none;border:none;color:#0c4a86;font-size:12px;font-weight:600;cursor:pointer;padding:4px 2px;">Aggiorna</button>
                            </form>

                            <form method="POST" action="{{ route('portal.cart.item.remove', $riga) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="background:none;border:none;color:#b91c1c;font-size:12px;font-weight:600;cursor:pointer;padding:4px 2px;">Rimuovi</button>
                            </form>
                        </div>
                    </div>

                    <div style="flex:0 0 auto;text-align:right;">
                        <div style="font-weight:700;color:#10263d;font-size:15px;">{{ ky_format($riga->totaleKy()) }} KY</div>
                        @if($riga->totaleEuro() > 0)
                            <div class="subtle" style="font-size:12.5px;margin-top:2px;">+ € {{ number_format($riga->totaleEuro() / 100, 2, ',', '.') }}</div>
                        @endif
                    </div>
                </div>
                @endforeach

                @if($gruppo['spedizione_ky'] > 0 || $gruppo['spedizione_eur'] > 0)
                <div style="display:flex;justify-content:space-between;padding:12px 0 0;border-top:1px solid #eef2f7;font-size:13px;color:#475569;">
                    <span>Spedizione <span class="subtle">(una sola per venditore)</span></span>
                    <span>
                        {{ ky_format($gruppo['spedizione_ky']) }} KY{{ $gruppo['spedizione_eur'] > 0 ? ' + € ' . number_format($gruppo['spedizione_eur'] / 100, 2, ',', '.') : '' }}
                    </span>
                </div>
                @endif

                <div style="display:flex;justify-content:space-between;padding:12px 0 0;margin-top:10px;border-top:2px solid #10263d;font-weight:700;color:#10263d;">
                    <span>Totale {{ $gruppo['company']->name }}</span>
                    <span>
                        {{ ky_format($gruppo['ky']) }} KY{{ $gruppo['eur'] > 0 ? ' + € ' . number_format($gruppo['eur'] / 100, 2, ',', '.') : '' }}
                    </span>
                </div>
            </section>
            @endforeach

            <form method="POST" action="{{ route('portal.cart.clear') }}" style="text-align:right;">
                @csrf
                <button type="submit" style="background:none;border:none;color:#94a3b8;font-size:12.5px;cursor:pointer;"
                        onclick="return confirm('Vuoi svuotare tutto il carrello?')">
                    Svuota il carrello
                </button>
            </form>
        </div>

        {{-- ── Il riepilogo e la cassa ──────────────────────────────────── --}}
        <section class="card account-hero card-pad">
            <div class="k-tag">Riepilogo</div>
            <h3 style="font-size:19px;font-weight:700;margin:10px 0 18px;color:#fff;">
                {{ $cart->totalePezzi() }} {{ $cart->totalePezzi() === 1 ? 'pezzo' : 'pezzi' }}
                @if($gruppi->count() > 1)
                    <span style="font-weight:500;opacity:.75;">da {{ $gruppi->count() }} venditori</span>
                @endif
            </h3>

            <div class="metric">
                <div class="metric-label">Totale in KY</div>
                <div class="metric-value">{{ ky_format($totaleKy) }} KY</div>
            </div>

            @if($totaleEuro > 0)
            <div class="metric">
                <div class="metric-label">Quota in euro</div>
                <div class="metric-value">€ {{ number_format($totaleEuro / 100, 2, ',', '.') }}</div>
            </div>
            @endif

            @php $spedizioniKy = (int) $gruppi->sum('spedizione_ky'); @endphp
            @if($spedizioniKy > 0)
            <div class="metric">
                <div class="metric-label">
                    Di cui spedizione{{ $gruppi->count() > 1 ? ' (' . $gruppi->count() . ' venditori)' : '' }}
                </div>
                <div class="metric-value">{{ ky_format($spedizioniKy) }} KY</div>
            </div>
            @endif

            <div class="metric">
                <div class="metric-label">Il tuo saldo</div>
                <div class="metric-value">{{ ky_format($saldoDisponibile) }} KY</div>
            </div>

            @if($gruppi->count() > 1)
            <p style="font-size:12px;line-height:1.5;margin:14px 0 0;color:rgba(255,255,255,.68);">
                I prodotti vengono da {{ $gruppi->count() }} venditori diversi: paghi una volta sola, ma
                il circuito genera un ordine e un movimento per ciascuno — e <strong>una spedizione per
                venditore</strong>, perché sono pacchi diversi.
            </p>
            @endif

            <div class="quick-actions" style="margin-top:20px;">

                @if($indisponibili->isNotEmpty())
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                        {{ $indisponibili->count() === 1 ? "C'è un prodotto" : "Ci sono {$indisponibili->count()} prodotti" }}
                        che non si {{ $indisponibili->count() === 1 ? 'può' : 'possono' }} più acquistare.
                        {{ $indisponibili->count() === 1 ? 'Rimuovilo' : 'Rimuovili' }} o riduci la quantità per procedere.
                    </p>
                @elseif($serveIndirizzo && ! $indirizzoCompleto)
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                        Nel carrello ci sono prodotti da spedire: completa il tuo indirizzo di spedizione per procedere.
                    </p>
                    {{-- Dal 26/08/2026 l'indirizzo non sta piu' dentro al form del
                         profilo ma in una rubrica sua, e da li' si torna dritti
                         alla cassa. --}}
                    <a href="{{ route('portal.shipping-addresses.index', ['redirect_to' => route('portal.cart.checkout.form', [], false)]) }}"
                       class="cta" style="width:100%;text-align:center;display:block;">
                        Aggiungi un indirizzo di spedizione
                    </a>
                @elseif(! $saldoBasta)
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0 0 10px;">
                        Saldo insufficiente: ti mancano {{ ky_format($totaleKy - $saldoDisponibile) }} KY.
                    </p>
                    <a href="{{ route('portal.ky-cards.index', ['redirect_to' => route('portal.cart')]) }}" class="cta" style="width:100%;text-align:center;display:block;">
                        Ricarica il tuo conto
                    </a>
                @else
                    {{-- Fase A (26/08/2026): non si paga piu' da qui. Questo
                         bottone porta alla CASSA, dove si controlla l'indirizzo,
                         si lascia una nota al venditore e si accettano le
                         condizioni. Il confirm() del browser che stava qui e'
                         sparito: non era brandizzato, non era accessibile e su
                         mobile poteva essere soppresso, trasformando un clic in
                         un addebito senza conferma. --}}
                    <a href="{{ route('portal.cart.checkout.form') }}" class="cta" style="width:100%;text-align:center;display:block;">
                        Vai alla cassa — {{ ky_format($totaleKy) }} KY{{ $totaleEuro > 0 ? ' + quota EUR' : '' }}
                    </a>
                    <p class="subtle" style="font-size:11.5px;text-align:center;margin:8px 0 0;">
                        Non paghi ancora: prima vedi il riepilogo.
                    </p>
                @endif
            </div>
        </section>
    </div>

@endif

<style>
    .shop-back-link {
        display: inline-flex; align-items: center; gap: 6px;
        color: var(--ink-soft); text-decoration: none; font-size: 14px; font-weight: 600;
        transition: color .15s;
    }
    .shop-back-link:hover { color: var(--primary); }

    @media (max-width: 900px) {
        .cart-grid { grid-template-columns: 1fr !important; }
    }
    @media (max-width: 560px) {
        .cart-row { flex-wrap: wrap; }
        .cart-row > div:last-child { text-align: left !important; width: 100%; }
    }
</style>
@endsection
