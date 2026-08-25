{{--
    Il selettore della combinazione, fase D — 25/08/2026.

    Sta in un partial perché serve in DUE form diversi della stessa sidebar:
    quello di acquisto e quello "aggiungi al carrello" che compare quando il
    saldo non basta. Prima stava scritto solo dentro il form di acquisto, e chi
    non aveva abbastanza KY non vedeva le taglie da nessuna parte — segnalato da
    Laura il 25/08/2026 su /shop/123.

    Le combinazioni esaurite restano in elenco, disabilitate: chi cerca la M
    vuole sapere che la M esiste ma è finita, non credere che non la fai.

    @param \Illuminate\Support\Collection<int, \App\Models\ListingVariant> $varianti
--}}
<div class="qty-field">
    <label>Scegli la variante</label>
    <select name="variant_id" class="variant-select" required>
        <option value="">— seleziona —</option>
        @foreach($varianti as $variante)
            <option value="{{ $variante->id }}" @disabled(! $variante->isDisponibile())>
                {{ $variante->etichetta_corta }}
                — {{ ky_format($variante->prezzoEffettivo()) }} KY{{ $variante->isDisponibile() ? '' : ' (esaurita)' }}
            </option>
        @endforeach
    </select>
</div>
