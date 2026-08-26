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

    @param \Illuminate\Support\Collection<int, \App\Models\ListingVariant> $varianti
    @param string|null $formId  id del form a cui appartengono i radio

    PULSANTI, NON UNA TENDINA (25/08/2026, sempre su richiesta di Laura). Una
    <select> nasconde la scelta dietro un clic: chi arriva sulla pagina non vede
    che esistono una M e una L, vede una riga di testo. Con i pulsanti le taglie
    sono lì, si contano a colpo d'occhio, e quelle finite si vedono barrate
    invece di sparire — chi cerca la M vuole sapere che la M esiste ma è finita,
    non credere che quel venditore non la faccia.

    Sono radio veri (input type=radio + label): niente JavaScript, il campo
    resta `variant_id` come prima, `required` impedisce l'invio senza scelta e
    la tastiera funziona da sola. Oltre le 12 combinazioni i pulsanti
    diventerebbero un muro: lì si torna alla tendina.
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
    $formId = $formId ?? null;
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
                <option value="{{ $variante->id }}" @disabled(! $variante->isDisponibile())>
                    {{ $variante->etichetta_corta }}
                    — {{ ky_format($variante->prezzoEffettivo()) }} KY{{ $variante->isDisponibile() ? '' : ' (esaurita)' }}
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
                       required
                       @if($formId) form="{{ $formId }}" @endif
                       @disabled(! $disponibile)>
                <label for="{{ $gruppo }}-{{ $variante->id }}"
                       class="variant-option{{ $disponibile ? '' : ' is-out' }}"
                       @if(! $disponibile) title="Combinazione esaurita" @endif>
                    <span class="variant-option-name">{{ $variante->etichetta_corta }}</span>
                    <span class="variant-option-price">
                        {{ $disponibile ? ky_format($variante->prezzoEffettivo()) . ' KY' : 'esaurita' }}
                    </span>
                </label>
            @endforeach
        </div>
    @endif
</div>
