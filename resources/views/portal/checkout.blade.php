@extends('layouts.portal')

@section('content')
{{--
    La cassa (fase A del piano "esperienza d'acquisto", 26/08/2026).

    Prima di questa pagina fra il carrello e l'addebito c'era soltanto un
    confirm() del browser. Qui il compratore vede dove arriva il pacco e puo'
    correggerlo senza uscire, lascia una nota al venditore, accetta le
    condizioni, e paga con un bottone solo che dice la cifra.

    I soldi non li muove questa pagina: il POST finisce in
    CartController::checkout() -> CartService::checkout() -> OrderService::place(),
    che rileggono prezzi, scorte e saldo sotto lock. Qui si raccoglie il
    consenso, non si decide niente.
--}}
<div style="margin-bottom:16px;">
    <a href="{{ route('portal.cart') }}" class="shop-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Torna al carrello
    </a>
</div>

<form method="POST" action="{{ route('portal.cart.checkout') }}" id="form-cassa">
    @csrf

    <div class="cart-grid" style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

        <div class="stack">

            {{-- ── 1. Dove arriva ─────────────────────────────────────────── --}}
            @if($serveIndirizzo)
            <section class="card light-card">
                <span class="eyebrow">Passo 1</span>
                <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 4px;">Indirizzo di spedizione</h3>
                <p class="subtle" style="font-size:12.5px;margin:0 0 16px;">
                    Correggilo qui se serve: viene salvato sul tuo conto e riusato ai prossimi acquisti.
                </p>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div style="grid-column:1 / -1;">
                        <label class="field-label" for="ship-nome">Destinatario</label>
                        <input class="field-input" id="ship-nome" type="text" name="shipping_recipient_name" required
                               maxlength="150" value="{{ old('shipping_recipient_name', $currentAccount->shipping_recipient_name) }}">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label class="field-label" for="ship-via">Indirizzo</label>
                        <input class="field-input" id="ship-via" type="text" name="shipping_address" required
                               maxlength="255" placeholder="Via, numero civico, scala, interno"
                               value="{{ old('shipping_address', $currentAccount->shipping_address) }}">
                    </div>
                    <div>
                        <label class="field-label" for="ship-cap">CAP</label>
                        <input class="field-input" id="ship-cap" type="text" name="shipping_postal_code" required
                               maxlength="12" value="{{ old('shipping_postal_code', $currentAccount->shipping_postal_code) }}">
                    </div>
                    <div>
                        <label class="field-label" for="ship-citta">Città</label>
                        <input class="field-input" id="ship-citta" type="text" name="shipping_city" required
                               maxlength="100" value="{{ old('shipping_city', $currentAccount->shipping_city) }}">
                    </div>
                    <div>
                        <label class="field-label" for="ship-prov">Provincia <span class="subtle" style="font-weight:400;">(facoltativa)</span></label>
                        <input class="field-input" id="ship-prov" type="text" name="shipping_province"
                               maxlength="60" value="{{ old('shipping_province', $currentAccount->shipping_province) }}">
                    </div>
                    <div>
                        <label class="field-label" for="ship-tel">Telefono <span class="subtle" style="font-weight:400;">(facoltativo)</span></label>
                        <input class="field-input" id="ship-tel" type="text" name="shipping_phone"
                               maxlength="30" value="{{ old('shipping_phone', $currentAccount->shipping_phone) }}">
                    </div>
                </div>
            </section>
            @endif

            {{-- ── 2. Che cosa stai comprando, e come ti arriva ────────────── --}}
            <section class="card light-card">
                <span class="eyebrow">Passo {{ $serveIndirizzo ? 2 : 1 }}</span>
                <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 4px;">
                    Il tuo ordine
                    @if($gruppi->count() > 1)
                        <span class="subtle" style="font-weight:500;font-size:14px;">— {{ $gruppi->count() }} venditori, {{ $gruppi->count() }} pacchi</span>
                    @endif
                </h3>
                <p class="subtle" style="font-size:12.5px;margin:0 0 16px;">
                    Prezzi e disponibilità vengono ricontrollati al momento del pagamento.
                </p>

                @foreach($gruppi as $gruppo)
                <div style="border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
                        <strong style="color:#10263d;font-size:15px;">{{ $gruppo['company']->name }}</strong>
                        <span style="font-weight:700;color:#10263d;font-size:15px;">
                            {{ ky_format($gruppo['ky']) }} KY{{ $gruppo['eur'] > 0 ? ' + € ' . number_format($gruppo['eur'] / 100, 2, ',', '.') : '' }}
                        </span>
                    </div>

                    @foreach($gruppo['righe'] as $riga)
                    <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;font-size:13.5px;color:#475569;border-top:1px solid #f1f5f9;">
                        <span style="min-width:0;">
                            {{ $riga->quantity }} × {{ $riga->listing->title }}
                            @if($riga->etichettaVariante())
                                <span class="subtle">— {{ $riga->etichettaVariante() }}</span>
                            @endif
                            <br>
                            <span class="subtle" style="font-size:12px;">
                                {{ $riga->listing->delivery_type_label }}
                            </span>
                        </span>
                        <span style="white-space:nowrap;color:#10263d;font-weight:600;">{{ ky_format($riga->totaleKy()) }} KY</span>
                    </div>
                    @endforeach

                    @if($gruppo['spedizione_ky'] > 0 || $gruppo['spedizione_eur'] > 0)
                    <div style="display:flex;justify-content:space-between;padding:7px 0 0;border-top:1px solid #f1f5f9;font-size:13px;color:#475569;">
                        <span>Spedizione <span class="subtle">(una sola per venditore)</span></span>
                        <span>{{ ky_format($gruppo['spedizione_ky']) }} KY{{ $gruppo['spedizione_eur'] > 0 ? ' + € ' . number_format($gruppo['spedizione_eur'] / 100, 2, ',', '.') : '' }}</span>
                    </div>
                    @endif
                </div>
                @endforeach

                <div style="margin-top:16px;">
                    <label class="field-label" for="buyer-note">
                        Nota per il venditore <span class="subtle" style="font-weight:400;">(facoltativa)</span>
                    </label>
                    <textarea class="field-input" id="buyer-note" name="buyer_note" rows="3" maxlength="500"
                              placeholder="Es. citofono a nome…, consegnare dopo le 15, taglia da confermare…"
                              style="resize:vertical;">{{ old('buyer_note') }}</textarea>
                    <p class="subtle" style="font-size:11.5px;margin:6px 0 0;">
                        Massimo 500 caratteri.@if($gruppi->count() > 1) La stessa nota arriva a tutti e {{ $gruppi->count() }} i venditori.@endif
                    </p>
                </div>
            </section>

            {{-- ── 3. Il consenso ──────────────────────────────────────────── --}}
            <section class="card light-card">
                <span class="eyebrow">Passo {{ $serveIndirizzo ? 3 : 2 }}</span>
                <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 12px;">Conferma</h3>

                <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-size:13.5px;color:#334155;line-height:1.55;">
                    <input type="checkbox" name="accetto_condizioni" value="1" required
                           style="margin-top:3px;width:17px;height:17px;flex:0 0 auto;cursor:pointer;"
                           {{ old('accetto_condizioni') ? 'checked' : '' }}>
                    <span>
                        Ho letto e accetto i
                        <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">termini di servizio</a>
                        e l'<a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">informativa privacy</a>,
                        e confermo che questo ordine comporta l'<strong>obbligo di pagamento</strong>
                        di {{ ky_format($totaleKy) }} KY{{ $totaleEuro > 0 ? ' e di € ' . number_format($totaleEuro / 100, 2, ',', '.') : '' }}.
                    </span>
                </label>
            </section>

        </div>

        {{-- ── Il riepilogo e il bottone unico ──────────────────────────── --}}
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
                <div class="metric-label">Di cui spedizione{{ $gruppi->count() > 1 ? ' (' . $gruppi->count() . ' venditori)' : '' }}</div>
                <div class="metric-value">{{ ky_format($spedizioniKy) }} KY</div>
            </div>
            @endif

            <div class="metric">
                <div class="metric-label">Saldo dopo l'acquisto</div>
                <div class="metric-value">{{ ky_format($saldoDisponibile - $totaleKy) }} KY</div>
            </div>

            <div class="quick-actions" style="margin-top:20px;">
                <button type="submit" class="cta" id="bottone-paga" style="width:100%;text-align:center;">
                    Paga {{ ky_format($totaleKy) }} KY{{ $totaleEuro > 0 ? ' + quota EUR' : '' }}
                </button>
                <p style="font-size:11.5px;line-height:1.5;margin:10px 0 0;color:rgba(255,255,255,.68);text-align:center;">
                    @if($totaleEuro > 0)
                        I KY partono subito; la quota in euro la saldi nella pagina successiva.
                    @else
                        I KY partono subito dal tuo conto.
                    @endif
                </p>
            </div>
        </section>

    </div>
</form>

<script>
    // Il bottone si spegne al primo clic: il doppio invio e' gia' innocuo lato
    // server (il secondo POST trova il carrello ordinato e non riaddebita
    // niente), ma vedere il bottone rispondere evita del tutto la seconda
    // pressione — e con essa il messaggio "il carrello e' vuoto" a chi ha
    // appena pagato.
    document.getElementById('form-cassa').addEventListener('submit', function () {
        var b = document.getElementById('bottone-paga');
        if (!b) return;
        setTimeout(function () {
            b.disabled = true;
            b.style.opacity = '.65';
            b.style.cursor = 'wait';
            b.textContent = 'Pagamento in corso…';
        }, 0);
    });
</script>
@endsection
