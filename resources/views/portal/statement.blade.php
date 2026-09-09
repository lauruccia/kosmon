{{--
  Pagina di generazione dell'estratto conto.

  PERCHE' RADIO E NON PULSANTI CON JAVASCRIPT (09/09/2026). Le scelte rapide
  sono `<input type="radio">` dentro una `<label>`, colorate via `:checked`.
  Nessuno stato da tenere in JS, nessun modo di inviare il form con un periodo
  diverso da quello che si vede selezionato, e il form funziona anche se lo
  script non parte. Il poco JS che c'e' fa una cosa sola: chi tocca un menu o
  una data seleziona da solo la riga corrispondente, cosi' non si finisce per
  scaricare "mese scorso" avendo scelto un anno dal menu.

  IL FORMATO E' IL PULSANTE. Tre `<button name="formato">` nello stesso form:
  periodo e conto sono gia' li', il formato lo decide quale pulsante si preme.
  Un solo giro, nessuna pagina intermedia.
--}}
@extends('layouts.portal')

@section('content')
<style>
    .ec-periodi { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-top: 14px; }
    .ec-chip { position: relative; display: block; cursor: pointer; }
    .ec-chip input { position: absolute; opacity: 0; width: 0; height: 0; }
    .ec-chip span {
        display: block; text-align: center; padding: 11px 10px;
        border: 1px solid var(--line); border-radius: var(--radius-sm);
        background: var(--surface-soft); color: var(--ink-soft);
        font-size: 13.5px; font-weight: 700; line-height: 1.25;
        transition: border-color .12s, background .12s, color .12s;
    }
    .ec-chip:hover span { background: var(--surface-hover); }
    .ec-chip input:checked + span {
        border-color: var(--accent); background: var(--accent-soft);
        color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent);
    }
    .ec-chip input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 2px; }

    .ec-avanzate { margin-top: 20px; border-top: 1px solid var(--line); padding-top: 16px; }
    .ec-riga {
        display: grid; grid-template-columns: 22px minmax(0, 190px) minmax(0, 1fr);
        gap: 10px; align-items: center; padding: 8px 0;
    }
    .ec-riga + .ec-riga { border-top: 1px dashed var(--line); }
    .ec-riga label.ec-nome { font-size: 13.5px; font-weight: 700; color: var(--ink); cursor: pointer; }
    .ec-riga select, .ec-riga input[type="date"] { width: 100%; }
    .ec-date { display: grid; grid-template-columns: 1fr auto 1fr; gap: 8px; align-items: center; }
    .ec-date .ec-al { font-size: 13px; color: var(--ink-muted); }

    .ec-azioni { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
    .ec-azioni .cta { flex: 1 1 190px; justify-content: center; }

    .ec-conto { margin-top: 14px; }

    @media (max-width: 560px) {
        .ec-riga { grid-template-columns: 22px minmax(0, 1fr); }
        .ec-riga > *:last-child { grid-column: 2 / -1; }
        .ec-date { grid-template-columns: 1fr; }
        .ec-date .ec-al { display: none; }
    }
</style>

<div class="portal-grid">
    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Estratto conto</div>
            <h3 class="card-title" style="margin-top:12px;">Scegli il periodo</h3>

            @if($months)
                <form method="get" action="{{ $downloadRoute }}" id="ec-form">

                    @if($accounts->count() > 1)
                        <div class="field ec-conto">
                            <label for="conto">Conto</label>
                            <select id="conto" name="conto">
                                @foreach($accounts as $c)
                                    <option value="{{ $c->id }}" @selected($c->id === $account->id)>
                                        {{ $c->display_name }} — {{ $c->account_number }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="table-muted" style="margin-top:6px;">
                                Ogni conto ha una contabilità separata e quindi un estratto conto proprio.
                            </p>
                        </div>
                    @endif

                    <div class="ec-periodi">
                        @foreach($quickPresets as $value => $etichetta)
                            <label class="ec-chip">
                                <input type="radio" name="periodo" value="{{ $value }}" @checked($period->preset === $value)>
                                <span>{{ $etichetta }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="ec-avanzate">
                        <div class="table-muted" style="margin-bottom:6px;">Oppure un periodo preciso</div>

                        <div class="ec-riga">
                            <input type="radio" name="periodo" id="p-mese" value="mese" @checked($period->preset === 'mese')>
                            <label class="ec-nome" for="p-mese">Un mese</label>
                            <select name="mese" data-attiva="p-mese" aria-label="Mese">
                                @foreach($months as $m)
                                    <option value="{{ $m['value'] }}" @selected($period->preset === 'mese' && $period->start->format('Y-m') === $m['value'])>{{ $m['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if($quarters)
                        <div class="ec-riga">
                            <input type="radio" name="periodo" id="p-trim" value="trimestre" @checked($period->preset === 'trimestre')>
                            <label class="ec-nome" for="p-trim">Un trimestre</label>
                            <select name="trimestre" data-attiva="p-trim" aria-label="Trimestre">
                                @foreach($quarters as $t)
                                    <option value="{{ $t['value'] }}" @selected($period->preset === 'trimestre' && ($period->start->format('Y') . '-T' . $period->start->quarter) === $t['value'])>{{ $t['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        @if($years)
                        <div class="ec-riga">
                            <input type="radio" name="periodo" id="p-anno" value="anno" @checked($period->preset === 'anno')>
                            <label class="ec-nome" for="p-anno">Un anno</label>
                            <select name="anno" data-attiva="p-anno" aria-label="Anno">
                                @foreach($years as $a)
                                    <option value="{{ $a['value'] }}" @selected($period->preset === 'anno' && $period->start->format('Y') === $a['value'])>{{ $a['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        <div class="ec-riga">
                            <input type="radio" name="periodo" id="p-pers" value="personalizzato" @checked($period->preset === 'personalizzato')>
                            <label class="ec-nome" for="p-pers">Date scelte da te</label>
                            <div class="ec-date">
                                <input type="date" name="dal" data-attiva="p-pers" aria-label="Data di inizio"
                                       value="{{ $period->preset === 'personalizzato' ? $period->start->format('Y-m-d') : '' }}">
                                <span class="ec-al">al</span>
                                <input type="date" name="al" data-attiva="p-pers" aria-label="Data di fine"
                                       value="{{ $period->preset === 'personalizzato' ? $period->end->format('Y-m-d') : '' }}">
                            </div>
                        </div>
                    </div>

                    <div class="ec-azioni">
                        <button type="submit" name="formato" value="pdf" class="cta">Scarica PDF</button>
                        <button type="submit" name="formato" value="csv" class="cta secondary">Scarica CSV (Excel)</button>
                        <button type="submit" name="formato" value="prima-nota" class="cta secondary">Prima nota (partita doppia)</button>
                    </div>
                </form>

                <script>
                    // Chi tocca un menu o una data seleziona la propria riga: cosi' il
                    // periodo che parte e' sempre quello che si sta guardando.
                    document.querySelectorAll('#ec-form [data-attiva]').forEach(function (campo) {
                        var attiva = function () {
                            var radio = document.getElementById(campo.dataset.attiva);
                            if (radio) { radio.checked = true; }
                        };
                        campo.addEventListener('change', attiva);
                        campo.addEventListener('input', attiva);
                    });
                </script>
            @else
                <div class="empty-state" style="margin-top:24px;">
                    <p>Nessun movimento registrato ancora su questo conto.<br>
                       L'estratto conto sarà disponibile dopo il primo movimento contabilizzato.</p>
                </div>
            @endif
        </section>
    </div>

    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Il documento</div>
            <h3 class="card-title" style="margin-top:12px;">Cosa contiene il PDF</h3>
            <ul style="margin-top:14px;line-height:1.9;color:var(--text-muted);font-size:14px;padding-left:18px;">
                <li>Intestatario con partita IVA e codice fiscale, numero di conto e valuta</li>
                <li>Saldo iniziale, totale entrate, totale uscite, variazione e saldo finale</li>
                <li>Andamento mese per mese, quando il periodo ne copre più di uno</li>
                <li>Composizione per tipologia di operazione</li>
                <li>Dettaglio dei movimenti con saldo progressivo riga per riga</li>
                <li>Intestazione ripetuta e numerazione «Pagina X di Y» su ogni foglio</li>
            </ul>
        </section>

        <section class="card light-card card-pad">
            <div class="k-tag">Per il commercialista</div>
            <h3 class="card-title" style="margin-top:12px;">Gli altri due formati</h3>
            <p style="margin-top:12px;color:var(--text-muted);font-size:14px;line-height:1.7;">
                <strong>CSV (Excel)</strong> — le stesse righe del PDF, con intestatario, conto,
                periodo e saldi scritti in testa al file: si apre e si rielabora.
            </p>
            <p style="margin-top:10px;color:var(--text-muted);font-size:14px;line-height:1.7;">
                <strong>Prima nota</strong> — le stesse operazioni già in partita doppia,
                con conto Dare e conto Avere, pronte da importare in contabilità.
            </p>
            <div class="table-muted" style="margin-top:14px;">
                I tre file coprono sempre lo stesso periodo e gli stessi movimenti: entrano solo
                quelli contabilizzati. Il documento non ha valore fiscale.
            </div>
        </section>
    </div>
</div>
@endsection
