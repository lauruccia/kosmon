{{--
  Pagina di generazione dell'estratto conto.

  PERCHE' NON USA `.portal-grid` (09/09/2026, dopo gli screenshot di Laura).
  Quella griglia ha la prima colonna FISSA a 306px. Questo modulo ci stava
  dentro, e il risultato era: le sette scelte rapide incolonnate una sotto
  l'altra su sette righe, i menu a tendina schiacciati a meta' parola
  («Sett 202», «3° trim 202»), i pulsanti di download spinti in fondo alla
  pagina — mentre la colonna di destra, larga il triplo, restava mezza vuota.
  Qui c'e' una griglia a due colonne uguali (`.ec-layout`): il modulo prende
  meta' pagina e le scelte rapide stanno su una riga sola.

  I CONTROLLI HANNO LO STILE ADDOSSO. Menu e date non sono dentro un wrapper
  `.field`, quindi non ereditavano NIENTE dal layout: uscivano come controlli
  nudi del browser in mezzo a una pagina disegnata. Le regole stanno qui sotto,
  copiate da `.field` ma legate alle classi di questa pagina.

  PERCHE' RADIO E NON PULSANTI CON JAVASCRIPT. Le scelte sono
  `<input type="radio">` dentro una `<label>`, colorate via `:checked`. Nessuno
  stato da tenere in JS, nessun modo di inviare il form con un periodo diverso
  da quello che si vede selezionato, e funziona anche se lo script non parte.

  IL FORMATO E' IL PULSANTE. Tre `<button name="formato">` nello stesso form:
  periodo e conto sono gia' li', il formato lo decide quale si preme.
--}}
@extends('layouts.portal')

@section('content')
<style>
    /* Una colonna sola finche' non c'e' spazio VERO per due. Con
       `auto-fit, minmax(340px, 1fr)` la seconda colonna compariva appena due
       card ci stavano, e a 1280px il modulo si ritrovava con 500px scarsi: le
       scelte rapide andavano a capo a meta' parola e i due campi data
       finivano tagliati. Sopra i 1120px le colonne sono ASIMMETRICHE — il
       modulo si tiene una volta e mezzo la colonna informativa, perche' e'
       quello che deve restare comodo. */
    .ec-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
        align-items: start;
    }
    @media (min-width: 1120px) {
        .ec-layout { grid-template-columns: minmax(0, 1.55fr) minmax(300px, 1fr); }
    }

    /* ── Scelte rapide ─────────────────────────────────────────────────── */
    /* QUATTRO COLONNE FISSE, NON `auto-fit`. Le scelte rapide sono SETTE: con le
       colonne libere ne uscivano tre per riga e la settima restava sola in
       fondo, mezza riga vuota accanto — lo stesso orfano che Laura aveva gia'
       bocciato sulla griglia dei prodotti. Con quattro colonne le righe sono
       4 + 3, e l'ultima scelta occupa due celle per chiudere la riga: mesi e
       trimestri sopra, anni sotto. */
    .ec-periodi {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-top: 14px;
    }
    .ec-periodi .ec-chip:last-child { grid-column: span 2; }
    /* Il riquadro riempie tutta la cella: se un'etichetta va a capo ("Trimestre
       corrente" nelle larghezze intermedie) le altre della stessa riga si
       alzano con lei, invece di lasciare un pulsante piu' alto in mezzo a tre
       piu' bassi. */
    /* SELETTORE DISCENDENTE OBBLIGATORIO. Il chip e' una <label>, e il layout del
       portale ha `.card label, form label { display: block }` — specificita' 0,1,1
       contro lo 0,1,0 di `.ec-chip`: il `display: flex` veniva scartato e i
       riquadri non si allungavano, cosi' un'etichetta andata a capo lasciava un
       pulsante piu' alto in mezzo a tre piu' bassi. Con `.ec-periodi .ec-chip`
       (0,2,0) la regola vince. */
    .ec-periodi .ec-chip { position: relative; display: flex; cursor: pointer; margin: 0; }
    .ec-periodi .ec-chip input { position: absolute; opacity: 0; width: 0; height: 0; }
    .ec-periodi .ec-chip span {
        flex: 1; display: flex; align-items: center; justify-content: center;
        text-align: center; padding: 12px 10px;
        border: 1px solid var(--line); border-radius: var(--radius-sm);
        background: var(--surface-soft); color: var(--ink-soft);
        font-size: 13px; font-weight: 700; line-height: 1.25;
        transition: border-color .12s, background .12s, color .12s;
    }
    .ec-periodi .ec-chip:hover span { background: var(--surface-hover); }
    .ec-periodi .ec-chip input:checked + span {
        border-color: var(--accent); background: var(--accent-soft);
        color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent);
    }
    .ec-periodi .ec-chip input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 2px; }

    /* ── Periodo preciso ───────────────────────────────────────────────── */
    .ec-avanzate { margin-top: 22px; border-top: 1px solid var(--line); padding-top: 16px; }
    .ec-titoletto {
        font-size: 11px; text-transform: uppercase; letter-spacing: .7px;
        font-weight: 800; color: var(--ink-muted); margin-bottom: 8px;
    }
    .ec-riga {
        display: grid;
        grid-template-columns: 20px minmax(120px, 165px) minmax(0, 1fr);
        gap: 12px; align-items: center; padding: 9px 0;
    }
    .ec-riga + .ec-riga { border-top: 1px dashed var(--line); }
    .ec-riga input[type="radio"] { width: 17px; height: 17px; margin: 0; accent-color: var(--accent); cursor: pointer; }
    .ec-riga .ec-nome { font-size: 13.5px; font-weight: 700; color: var(--ink); cursor: pointer; }

    .ec-riga select,
    .ec-riga input[type="date"] {
        width: 100%; min-height: 42px; padding: 9px 12px;
        border-radius: 9px; border: 1px solid var(--line-strong);
        background: var(--surface); color: var(--ink);
        font: inherit; font-size: 14px;
        transition: border-color .16s, box-shadow .16s;
    }
    .ec-riga select:focus,
    .ec-riga input[type="date"]:focus {
        outline: none; border-color: var(--accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
    }
    /* Flex con `wrap`, non una griglia a tre colonne: quando lo spazio finisce
       il secondo campo va a capo invece di stringersi fino a mostrare
       "mm/gg/aa" tagliato. Si aggiusta da solo a ogni larghezza. */
    .ec-date { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .ec-date input[type="date"] { flex: 1 1 150px; min-width: 132px; }
    .ec-date .ec-al { font-size: 13px; color: var(--ink-muted); flex: 0 0 auto; }

    /* ── Riepilogo del periodo scelto ──────────────────────────────────── */
    .ec-riepilogo {
        margin-top: 18px; padding: 12px 14px;
        border: 1px solid var(--accent-line, var(--line));
        background: var(--accent-soft); border-radius: var(--radius-sm);
        font-size: 13.5px; color: var(--ink);
    }
    .ec-riepilogo strong { color: var(--accent); }

    /* ── Azioni ────────────────────────────────────────────────────────── */
    .ec-azioni {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px;
        margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--line);
    }
    .ec-azioni .cta { justify-content: center; width: 100%; }
    .ec-azioni .ec-primario { grid-column: 1 / -1; }

    .ec-conto { margin-bottom: 4px; }

    @media (max-width: 640px) {
        .ec-periodi { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 620px) {
        .ec-riga { grid-template-columns: 20px minmax(0, 1fr); row-gap: 8px; }
        .ec-riga > *:last-child { grid-column: 2 / -1; }
        .ec-date .ec-al { display: none; }
        .ec-date input[type="date"] { flex: 1 1 100%; }
        .ec-azioni { grid-template-columns: 1fr; }
    }
</style>

<div class="ec-layout">
    <section class="card light-card card-pad">
        <div class="k-tag">Estratto conto</div>
        <h3 class="card-title" style="margin-top:12px;">Scegli il periodo</h3>

        @if($months)
            <form method="get" action="{{ $downloadRoute }}" id="ec-form">

                @if($accounts->count() > 1)
                    <div class="field ec-conto" style="margin-top:14px;">
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
                    @foreach($quickPresets as $valore => $scelta)
                        <label class="ec-chip">
                            <input type="radio" name="periodo" value="{{ $valore }}"
                                   data-dal="{{ $scelta['dal'] }}" data-al="{{ $scelta['al'] }}"
                                   @checked($period->preset === $valore)>
                            <span>{{ $scelta['label'] }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="ec-avanzate">
                    <div class="ec-titoletto">Oppure un periodo preciso</div>

                    <div class="ec-riga">
                        <input type="radio" name="periodo" id="p-mese" value="mese" @checked($period->preset === 'mese')>
                        <label class="ec-nome" for="p-mese">Un mese</label>
                        <select name="mese" data-attiva="p-mese" aria-label="Mese">
                            @foreach($months as $m)
                                <option value="{{ $m['value'] }}" data-dal="{{ $m['dal'] }}" data-al="{{ $m['al'] }}"
                                        @selected($period->preset === 'mese' && $period->start->format('Y-m') === $m['value'])>{{ $m['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if($quarters)
                    <div class="ec-riga">
                        <input type="radio" name="periodo" id="p-trim" value="trimestre" @checked($period->preset === 'trimestre')>
                        <label class="ec-nome" for="p-trim">Un trimestre</label>
                        <select name="trimestre" data-attiva="p-trim" aria-label="Trimestre">
                            @foreach($quarters as $t)
                                <option value="{{ $t['value'] }}" data-dal="{{ $t['dal'] }}" data-al="{{ $t['al'] }}"
                                        @selected($period->preset === 'trimestre' && ($period->start->format('Y') . '-T' . $period->start->quarter) === $t['value'])>{{ $t['label'] }}</option>
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
                                <option value="{{ $a['value'] }}" data-dal="{{ $a['dal'] }}" data-al="{{ $a['al'] }}"
                                        @selected($period->preset === 'anno' && $period->start->format('Y') === $a['value'])>{{ $a['label'] }}</option>
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

                {{-- Le date esatte del periodo selezionato, sempre in vista: "mese
                     scorso" da solo non dice se il mese in corso ci sia dentro o no. --}}
                <div class="ec-riepilogo" id="ec-riepilogo">
                    Periodo selezionato: <strong>{{ $period->label }}</strong> —
                    dal {{ $period->start->format('d/m/Y') }} al {{ $period->end->format('d/m/Y') }}
                </div>

                <div class="ec-azioni">
                    <button type="submit" name="formato" value="pdf" class="cta ec-primario">Scarica PDF</button>
                    <button type="submit" name="formato" value="csv" class="cta secondary">CSV (Excel)</button>
                    <button type="submit" name="formato" value="prima-nota" class="cta secondary">Prima nota</button>
                </div>
            </form>

            <script>
                (function () {
                    var form = document.getElementById('ec-form');
                    var box  = document.getElementById('ec-riepilogo');
                    if (!form || !box) { return; }

                    function scelto() {
                        return form.querySelector('input[name="periodo"]:checked');
                    }

                    // Le date di ogni scelta arrivano dal server (data-dal/data-al):
                    // qui non si calcola nulla, si legge e basta — cosi' il
                    // riepilogo non puo' dire un periodo diverso da quello che parte.
                    function aggiorna() {
                        var radio = scelto();
                        if (!radio) { return; }

                        var dal = radio.dataset.dal, al = radio.dataset.al, nome = '';
                        var riga = radio.closest('.ec-riga');

                        if (riga) {
                            var campo = riga.querySelector('select, input[type="date"]');
                            if (campo && campo.tagName === 'SELECT') {
                                var opt = campo.options[campo.selectedIndex];
                                dal = opt.dataset.dal; al = opt.dataset.al; nome = opt.textContent.trim();
                            } else if (campo) {
                                var d = riga.querySelector('input[name="dal"]').value;
                                var a = riga.querySelector('input[name="al"]').value;
                                if (!d || !a) { box.textContent = 'Scegli le due date del periodo.'; return; }
                                dal = d.split('-').reverse().join('/');
                                al  = a.split('-').reverse().join('/');
                                nome = 'Periodo personalizzato';
                            }
                        } else {
                            nome = radio.parentNode.querySelector('span').textContent.trim();
                        }

                        if (!dal || !al) { return; }
                        box.innerHTML = 'Periodo selezionato: <strong></strong> — dal ' + dal + ' al ' + al;
                        box.querySelector('strong').textContent = nome;
                    }

                    form.querySelectorAll('[data-attiva]').forEach(function (campo) {
                        var attiva = function () {
                            var radio = document.getElementById(campo.dataset.attiva);
                            if (radio) { radio.checked = true; }
                            aggiorna();
                        };
                        campo.addEventListener('change', attiva);
                        campo.addEventListener('input', attiva);
                    });

                    form.querySelectorAll('input[name="periodo"]').forEach(function (r) {
                        r.addEventListener('change', aggiorna);
                    });

                    aggiorna();
                })();
            </script>
        @else
            <div class="empty-state" style="margin-top:24px;">
                <p>Nessun movimento registrato ancora su questo conto.<br>
                   L'estratto conto sarà disponibile dopo il primo movimento contabilizzato.</p>
            </div>
        @endif
    </section>

    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Il documento</div>
            <h3 class="card-title" style="margin-top:12px;">Cosa contiene il PDF</h3>
            <ul style="margin-top:14px;line-height:1.8;color:var(--text-muted);font-size:14px;padding-left:18px;">
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
