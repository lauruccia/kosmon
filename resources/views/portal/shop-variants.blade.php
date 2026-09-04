@extends('layouts.portal')

@section('content')
<x-shop.styles />

<div style="margin-bottom:16px;">
    <a href="{{ route('portal.shop.show', $listing) }}" class="var-back">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Torna al prodotto
    </a>
</div>

@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<section class="card light-card" style="margin-bottom:20px;">
    <span class="eyebrow">Varianti</span>
    <h2 style="font-size:20px;font-weight:700;color:var(--ink);margin:6px 0 4px;">{{ $listing->title }}</h2>
    <p class="subtle" style="margin:0;font-size:13px;">
        Prezzo di listino <strong>{{ ky_format($listing->price_ky) }} KY</strong>
        · mix {{ $listing->ky_badge_label }}
        @if($listing->is_on_offer)
            · <span style="color:var(--danger);font-weight:600;">in offerta a {{ ky_format($listing->effective_price_ky) }} KY</span>
        @endif
    </p>
</section>

@if($attributi->isEmpty())

    <section class="card light-card">
        <p style="margin:0;font-size:13.5px;line-height:1.6;">
            Non ci sono ancora attributi disponibili (Taglia, Colore…). Li definisce l'amministratore
            del circuito: scrivigli e chiedigli di aggiungere quelli che ti servono.
        </p>
    </section>

@else

    {{-- ── 1. Quali valori usa questo prodotto ────────────────────────────── --}}
    <section class="card light-card" style="margin-bottom:20px;">
        <h3 style="font-size:16px;font-weight:700;margin:0 0 6px;">1. Scegli i valori</h3>
        <p class="subtle" style="margin:0 0 16px;font-size:12.5px;line-height:1.55;">
            Spunta quello che vendi davvero. Il sistema creerà una riga per ogni combinazione —
            due taglie e due colori fanno quattro combinazioni. Le combinazioni che hai già
            non vengono toccate: prezzi e giacenze restano dove sono.
        </p>

        <form method="POST" action="{{ route('portal.shop.variants.generate', $listing) }}">
            @csrf
            @foreach($attributi as $attributo)
            <div class="attr-box">
                <h4>{{ $attributo->name }}</h4>
                @foreach($attributo->valoriAttivi as $valore)
                <label class="val-check">
                    <input type="checkbox" name="valori[]" value="{{ $valore->id }}"
                           @checked(in_array($valore->id, $valoriInUso, true))>
                    {{ $valore->value }}
                </label>
                @endforeach
            </div>
            @endforeach

            <button type="submit" class="cta" style="margin-top:6px;">Genera le combinazioni</button>
        </form>
    </section>

    {{-- ── 2. Prezzo e giacenza di ogni combinazione ──────────────────────── --}}
    <section class="card light-card">
        <h3 style="font-size:16px;font-weight:700;margin:0 0 6px;">2. Prezzo e disponibilità</h3>

        @if($listing->variants->isEmpty())

            <p class="subtle" style="margin:0;font-size:13px;">
                Ancora nessuna combinazione. Scegli i valori qui sopra e premi "Genera le combinazioni".
            </p>

        @else

            <p class="subtle" style="margin:0 0 16px;font-size:12.5px;line-height:1.55;">
                Il prezzo si scrive <strong>per intero</strong>, non come differenza: se la XL costa
                {{ ky_format($listing->price_ky + 500) }} KY, scrivi quello. Lascia vuota la giacenza
                per indicare disponibilità illimitata.
                @if($listing->is_on_offer)
                    <br><strong>Nota:</strong> il prodotto è in offerta. I prezzi qui sotto restano
                    riferiti al listino ({{ ky_format($listing->price_ky) }} KY): durante l'offerta
                    ogni combinazione scala della stessa cifra, da sola.
                @endif
            </p>

            <form method="POST" action="{{ route('portal.shop.variants.update', $listing) }}">
                @csrf
                @method('PUT')

                <div style="overflow-x:auto;">
                <table class="var-table">
                    <thead>
                        <tr>
                            <th>Combinazione</th>
                            <th class="col-stretta">Prezzo (KY)</th>
                            <th class="col-stretta">Giacenza</th>
                            <th class="col-stretta">Codice</th>
                            <th>Stato</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($listing->variants as $variante)
                        <tr>
                            <td><strong>{{ $variante->etichetta }}</strong></td>
                            <td>
                                <input type="text" name="varianti[{{ $variante->id }}][prezzo]"
                                       value="{{ number_format(($listing->price_ky + $variante->price_delta_ky) / 100, 2, ',', '') }}"
                                       inputmode="decimal">
                            </td>
                            <td>
                                <input type="number" name="varianti[{{ $variante->id }}][scorte]"
                                       value="{{ $variante->stock_quantity }}" min="0" max="999999"
                                       placeholder="∞">
                            </td>
                            <td>
                                <input type="text" name="varianti[{{ $variante->id }}][sku]"
                                       value="{{ $variante->sku }}" maxlength="80" placeholder="—">
                            </td>
                            <td>
                                <span class="{{ $variante->is_active ? 'badge-active' : 'badge-inactive' }}">
                                    {{ $variante->is_active ? 'in vendita' : 'spenta' }}
                                </span>
                            </td>
                            {{-- I bottoni puntano a form dichiarati DOPO la tabella
                                 (attributo form="..."): dentro un altro form non si
                                 possono annidare, e usare formaction+_method su un
                                 form che ha gia' il suo @method finirebbe per
                                 mandare due verbi diversi nella stessa richiesta. --}}
                            <td style="white-space:nowrap;text-align:right;">
                                <button type="submit" class="link-btn" style="color:var(--info);"
                                        form="toggle-{{ $variante->id }}">
                                    {{ $variante->is_active ? 'Disattiva' : 'Riattiva' }}
                                </button>
                                <button type="submit" class="link-btn" style="color:var(--danger);"
                                        form="elimina-{{ $variante->id }}"
                                        onclick="return confirm('Eliminare la combinazione «{{ $variante->etichetta }}»?')">
                                    Elimina
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>

                <button type="submit" class="cta" style="margin-top:16px;">Salva prezzi e giacenze</button>
            </form>

            {{-- I form dei bottoni riga per riga, fuori dalla tabella. --}}
            @foreach($listing->variants as $variante)
                <form id="toggle-{{ $variante->id }}" method="POST"
                      action="{{ route('portal.shop.variants.toggle', [$listing, $variante]) }}" style="display:none;">
                    @csrf
                    @method('PATCH')
                </form>
                <form id="elimina-{{ $variante->id }}" method="POST"
                      action="{{ route('portal.shop.variants.destroy', [$listing, $variante]) }}" style="display:none;">
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach

        @endif
    </section>

@endif
@endsection
