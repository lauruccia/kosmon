{{--
  Blocco compatto "scarica l'estratto conto" per le pagine di servizio
  (scheda azienda in backoffice, scheda cliente del broker).

  Variabili attese:
    $azione — URL della rotta di download (portale, admin o broker)

  Le scelte rapide sono le stesse del portale: StatementPeriod::quickPresets()
  e' l'unico elenco, quindi una scelta aggiunta li' compare qui senza toccare
  questo file.
--}}
<form method="get" action="{{ $azione }}"
      style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px;">
    <select name="periodo" aria-label="Periodo dell'estratto conto"
            style="font-size:12px;min-height:32px;padding:4px 8px;max-width:190px;">
        @foreach(\App\Support\StatementPeriod::quickPresets() as $valore => $etichetta)
            <option value="{{ $valore }}" @selected($valore === \App\Support\StatementPeriod::DEFAULT_PRESET)>{{ $etichetta }}</option>
        @endforeach
    </select>
    <button type="submit" name="formato" value="pdf" class="cta secondary" style="font-size:12px;min-height:32px;">PDF</button>
    <button type="submit" name="formato" value="csv" class="cta secondary" style="font-size:12px;min-height:32px;">CSV</button>
    <button type="submit" name="formato" value="prima-nota" class="cta secondary" style="font-size:12px;min-height:32px;">Prima nota</button>
</form>
