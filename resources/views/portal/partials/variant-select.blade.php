{{--
    Il selettore della combinazione, fase D — 25/08/2026.

    Sta in un partial perché il pannello di acquisto ha due form diversi a
    seconda del saldo — comprare subito, oppure mettere da parte nel carrello —
    e questo riquadro deve funzionare con tutti e due. Prima il selettore stava
    scritto dentro il solo form di acquisto, e chi non aveva abbastanza KY non
    vedeva le taglie da nessuna parte (segnalato da Laura il 25/08/2026 su
    /shop/123).

    STA SOPRA IL PREZZO, fuori dal form. I radio ci si agganciano con
    l'attributo `form` dell'HTML, che serve esattamente a questo: tenere un
    campo dove ha senso per chi legge, senza dover spostare il form intorno.

    PULSANTI CON LA SOLA TAGLIA, COME SU AMAZON (25/08/2026, seconda richiesta
    di Laura, con la pagina di Amazon come riferimento). Il prezzo NON si
    ripete su ogni pulsante: quello vero è uno solo, quello grande qui sotto,
    e cambia quando scegli. Ripeterlo sei volte riempiva la colonna di numeri
    quasi tutti uguali — sui prodotti dove le taglie costano lo stesso, cioè
    la maggior parte, erano sei volte lo stesso numero.

    Il prezzo grande lo aggiorna una ventina di righe di JavaScript (in
    shop-show), che leggono i `data-` qui sotto. Senza JavaScript non si rompe
    niente: resta scritto "da <la più economica>" e il conto vero lo fa
    comunque il server al momento dell'acquisto.

    Le combinazioni finite restano in elenco, tratteggiate e non cliccabili —
    come le taglie esaurite di Amazon. Sparire sarebbe peggio: chi cerca la M
    vuole sapere che la M esiste ma è finita, non credere che quel venditore
    non la faccia.

    @param \Illuminate\Support\Collection<int, \App\Models\ListingVariant> $varianti
    @param string|null $formId       id del form a cui appartengono i radio
    @param int         $speseKy      quota KY di spedizione, da sommare al richiesto
--}}
@php
    // "Scegli la taglia" dice più di "Scegli la variante", e il nome giusto ce
    // l'hanno già gli attributi usati dalle combinazioni.
    $nomiAttributi = $varianti
        ->flatMap(fn ($v) => $v->values->map(fn ($val) => $val->attribute?->name))
        ->filter()
        ->unique()
        ->values();

    $titoloScelta = $nomiAttributi->isEmpty()
        ? 'Scegli la variante'
        : 'Scegli ' . mb_strtolower($nomiAttributi->join(' e '), 'UTF-8');

    $troppePerIPulsanti = $varianti->count() > 12;
    $gruppo = 'var-' . uniqid();
    $formId  = $formId ?? null;
    $speseKy = $speseKy ?? 0;
@endphp

<div class="variant-picker">
    <div class="variant-picker-title">
        {{ $titoloScelta }}
        <span class="variant-picker-hint">obbligatorio</span>
    </div>

    @if($troppePerIPulsanti)
        <select name="variant_id" class="variant-select" required @if($formId) form="{{ $formId }}" @endif>
            <option value="">— seleziona —</option>
            @foreach($varianti as $variante)
                <option value="{{ $variante->id }}"
                        data-prezzo="{{ $variante->prezzoEffettivo() }}"
                        data-richiesto="{{ $variante->quotaKy() + $speseKy }}"
                        @disabled(! $variante->isDisponibile())>
                    {{ $variante->etichetta_corta }}{{ $variante->isDisponibile() ? '' : ' (esaurita)' }}
                </option>
            @endforeach
        </select>
    @else
        <div class="variant-options" role="radiogroup" aria-label="{{ $titoloScelta }}">
            @foreach($varianti as $variante)
                @php $disponibile = $variante->isDisponibile(); @endphp
                <input type="radio"
                       class="variant-radio"
                       id="{{ $gruppo }}-{{ $variante->id }}"
                       name="variant_id"
                       value="{{ $variante->id }}"
                       data-prezzo="{{ $variante->prezzoEffettivo() }}"
                       data-richiesto="{{ $variante->quotaKy() + $speseKy }}"
                       required
                       @if($formId) form="{{ $formId }}" @endif
                       @disabled(! $disponibile)>
                <label for="{{ $gruppo }}-{{ $variante->id }}"
                       class="variant-option{{ $disponibile ? '' : ' is-out' }}"
                       title="{{ $variante->etichetta_corta }}{{ $disponibile ? '' : ' — esaurita' }}">
                    {{ $variante->etichetta_corta }}
                </label>
            @endforeach
        </div>
    @endif
</div>
