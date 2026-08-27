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
    <a href="{{ $urlIndietro }}" class="shop-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        {{ $etichettaIndietro }}
    </a>
</div>

{{-- La stessa cassa serve due strade: il carrello e "Compra ora" dalla pagina
     prodotto. Cambiano solo `$formAction` e i `$campiNascosti` (combinazione e
     quantita' dell'acquisto immediato); tutto il resto - indirizzo, nota,
     spunta condizioni, riepilogo, bottone unico - e' identico di proposito. --}}
<form method="POST" action="{{ $formAction }}" id="form-cassa">
    @csrf
    @foreach($campiNascosti as $nome => $valore)
        <input type="hidden" name="{{ $nome }}" value="{{ $valore }}">
    @endforeach

    <div class="cart-grid" style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

        <div class="stack">

            {{-- ── 1. Dove arriva ─────────────────────────────────────────── --}}
            @if($serveIndirizzo)
            <section class="card light-card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <div>
                        <span class="eyebrow">Passo 1</span>
                        <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 4px;">Dove lo spediamo</h3>
                    </div>
                    <a href="{{ route('portal.shipping-addresses.index', ['redirect_to' => $ritornoIndirizzi]) }}"
                       style="font-size:12.5px;font-weight:600;color:#0c4a86;text-decoration:none;">Gestisci la rubrica</a>
                </div>

                <div style="margin-top:14px;">
                    @foreach($indirizzi as $indirizzo)
                    <label style="display:flex;gap:11px;align-items:flex-start;padding:12px 14px;border:1px solid #e2e8f0;border-radius:11px;margin-bottom:9px;cursor:pointer;">
                        <input type="radio" name="indirizzo_scelto" value="{{ $indirizzo->id }}"
                               style="margin-top:3px;width:16px;height:16px;flex:0 0 auto;cursor:pointer;"
                               {{ (string) old('indirizzo_scelto', $indirizzo->is_default ? $indirizzo->id : '') === (string) $indirizzo->id ? 'checked' : '' }}
                               onchange="document.getElementById('blocco-nuovo-indirizzo').style.display='none';">
                        <span style="min-width:0;">
                            @if($indirizzo->label)
                                <strong style="color:#10263d;font-size:14px;">{{ $indirizzo->label }}</strong>
                                @if($indirizzo->is_default)<span class="pill" style="background:#dcfce7;color:#166534;margin-left:6px;">Predefinito</span>@endif
                                <br>
                            @elseif($indirizzo->is_default)
                                <span class="pill" style="background:#dcfce7;color:#166534;">Predefinito</span><br>
                            @endif
                            <span style="font-size:13.5px;color:#334155;line-height:1.6;">
                                @foreach($indirizzo->righe as $riga){{ $riga }}@if(! $loop->last)<br>@endif @endforeach
                            </span>
                        </span>
                    </label>
                    @endforeach

                    <label style="display:flex;gap:11px;align-items:center;padding:12px 14px;border:1px dashed #cbd5e1;border-radius:11px;cursor:pointer;">
                        <input type="radio" name="indirizzo_scelto" value="nuovo"
                               style="width:16px;height:16px;flex:0 0 auto;cursor:pointer;"
                               {{ old('indirizzo_scelto') === 'nuovo' || $indirizzi->isEmpty() ? 'checked' : '' }}
                               onchange="document.getElementById('blocco-nuovo-indirizzo').style.display='block';">
                        <span style="font-size:13.5px;color:#334155;font-weight:600;">Spedisci a un nuovo indirizzo</span>
                    </label>
                </div>

                <div id="blocco-nuovo-indirizzo" style="display:{{ old('indirizzo_scelto') === 'nuovo' || $indirizzi->isEmpty() ? 'block' : 'none' }};margin-top:16px;padding-top:16px;border-top:1px solid #eef2f7;">
                    @include('portal.partials.shipping-address-fields', ['indirizzo' => null, 'prefissoId' => 'cassa'])

                    @if($indirizzi->count() < $tettoIndirizzi)
                    <label style="display:flex;gap:9px;align-items:center;margin-top:13px;font-size:13px;color:#334155;cursor:pointer;">
                        <input type="checkbox" name="salva_indirizzo" value="1" style="width:16px;height:16px;cursor:pointer;"
                               {{ old('salva_indirizzo', '1') ? 'checked' : '' }}>
                        Salvalo nella mia rubrica ({{ $indirizzi->count() }} di {{ $tettoIndirizzi }} usati)
                    </label>
                    @if($indirizzi->isNotEmpty())
                    <label style="display:flex;gap:9px;align-items:center;margin-top:8px;font-size:13px;color:#334155;cursor:pointer;">
                        <input type="checkbox" name="rendi_predefinito" value="1" style="width:16px;height:16px;cursor:pointer;"
                               {{ old('rendi_predefinito') ? 'checked' : '' }}>
                        E usalo come predefinito d'ora in poi
                    </label>
                    @endif
                    @else
                    <p style="font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:9px 12px;margin:13px 0 0;">
                        Hai già {{ $tettoIndirizzi }} indirizzi in rubrica: questo vale solo per l'ordine di adesso.
                        Per salvarlo, eliminane prima uno dalla rubrica.
                    </p>
                    @endif
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
                {{ $totalePezzi }} {{ $totalePezzi === 1 ? 'pezzo' : 'pezzi' }}
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
