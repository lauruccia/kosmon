{{--
    UN RIQUADRO DI IMPOSTAZIONI, uguale per tutte e tre le quote (04/09/2026).

    Riceve $q, la descrizione della quota costruita da QuoteAdminController.
    Il form salva sulla rotta della sua quota — quella che conosce le regole
    del caso e le ha gia' sotto test — e torna qui con back().

    ATTENZIONE ALLE DIRETTIVE BLADE ATTACCATE A UNA PAROLA. Una @if incollata
    al testo che la precede non viene compilata: resta scritta letterale,
    sbilancia il blocco e la pagina esplode solo quando qualcuno la apre. E'
    successo il 04/09 su due pagine. Davanti a una direttiva ci va uno spazio
    o un a capo.
--}}
@php
    $prefisso = $q['prefisso'];

    // Se il form e' tornato indietro con un errore, i valori giusti da
    // rimettere a schermo sono quelli che l'admin aveva scritto, non quelli
    // salvati. Ma old() e' uno solo per tutta la pagina e i form sono tre:
    // senza questo marcatore, un errore sulla quota dei privati farebbe
    // rileggere old() anche agli altri due riquadri, che non hanno inviato
    // niente — e una spunta tolta apparirebbe di nuovo accesa.
    $inviato = old($prefisso . '_form') !== null;

    $spunta = fn (string $campo, bool $salvato): bool => $inviato ? (bool) old($campo) : $salvato;
    $testo  = fn (string $campo, string $salvato): string => $inviato ? (string) old($campo, $salvato) : $salvato;

    $note = [
        'privati' => 'La pagano i privati che si registrano <strong>da quando la quota &egrave; attiva</strong>:
                      chi era gi&agrave; dentro non deve niente. <strong>Blocca il conto</strong> &mdash; finch&eacute;
                      non salda non pu&ograve; pagare, incassare n&eacute; acquistare; vedere il conto e ricaricare s&igrave;.
                      Chi entra nel percorso agente se la vede sospendere e al suo posto paga il codice agente.',
        'agenti'  => 'La paga chi &egrave; stato approvato come agente, <strong>fra l\'approvazione e la firma</strong>
                      del contratto di nomina: finch&eacute; non salda non pu&ograve; firmare. Non ferma il conto di chi
                      nel circuito ha gi&agrave; pagato un ingresso. L\'esonero per una singola persona si d&agrave;
                      dalla sua scheda, non da qui.',
        'aziende' => 'La pagano le aziende che si registrano <strong>da quando la quota &egrave; attiva</strong>:
                      le anagrafiche gi&agrave; presenti non devono niente e l\'admin pu&ograve; chiederla a una alla
                      volta dalla scheda. <strong>Non blocca il conto</strong>: l\'azienda che non ha saldato continua
                      a pagare, incassare e vendere, e riceve solo il banner e un sollecito per email.',
    ];
@endphp

<article class="card" style="padding:22px;margin-bottom:16px;">
    <div class="section-head">
        <div>
            <span class="eyebrow">Impostazioni</span>
            <h3 class="section-title" style="font-size:15px;">{{ $q['titolo'] }}</h3>
        </div>
        <span class="pill {{ $q['attiva'] ? 'success' : 'warn' }}">
            {{ $q['attiva'] ? 'Attiva' : 'Non attiva' }}
        </span>
    </div>

    <div class="notice" style="margin-bottom:14px;">{!! $note[$q['chiave']] !!}</div>

    @if($q['accesa'] && ! $q['attiva'])
        {{-- L'interruttore e' acceso ma la quota non sta funzionando: manca
             l'importo o mancano i metodi eseguibili. Senza questo avviso
             sembra accesa e non chiede niente a nessuno. --}}
        <div class="notice error" style="margin-bottom:14px;">
            L'interruttore &egrave; acceso ma la quota <strong>non sta chiedendo niente a nessuno</strong>:
            serve un importo maggiore di zero e almeno un metodo di pagamento davvero eseguibile
            (con le sue chiavi configurate).
        </div>
    @endif

    <form method="POST" action="{{ route($q['rotta_salva']) }}">
        @csrf
        <input type="hidden" name="{{ $prefisso }}_form" value="1">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end;">
            <div class="field">
                <label>Importo (euro)</label>
                <input type="number" min="0" step="0.01" name="{{ $q['campo_importo'] }}"
                       value="{{ $testo($q['campo_importo'], ky_input($q['importo'])) }}">
                <small style="color:var(--text-muted);">In KY vale lo stesso numero, alla pari.</small>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="{{ $prefisso }}_enabled" value="1"
                           @checked($spunta($prefisso . '_enabled', $q['accesa']))>
                    Quota attiva
                </label>
                <small style="color:var(--text-muted);">Da spenta nessun nuovo iscritto la deve; chi ce l'ha gi&agrave; in carico la deve ancora.</small>
            </div>
        </div>

        <div class="section-head" style="margin-top:18px;">
            <div>
                <span class="eyebrow">In cambio</span>
                <h3 class="section-title" style="font-size:15px;">Cosa riceve chi paga</h3>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;align-items:start;">
            <div class="field">
                <label>KY restituiti a chi paga in euro</label>
                <input type="number" min="0" step="0.01" name="{{ $q['campo_credito'] }}"
                       value="{{ $testo($q['campo_credito'], ky_input($q['credito'])) }}">
                <small style="color:var(--text-muted);">
                    Zero = nessuna restituzione, e la quota resta solo un incasso. Non &egrave; legato
                    all'importo: puoi darne meno, altrettanti o di pi&ugrave;. Quello che dai
                    &egrave; <strong>moneta coniata dal circuito</strong> per ogni persona che paga, e
                    <strong>non segue l'importo</strong>: cambiando la quota, questo numero resta dov'&egrave;.
                </small>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="{{ $prefisso }}_ky_allowance" value="1"
                           @checked($spunta($prefisso . '_ky_allowance', $q['fido']))>
                    Fido aggiuntivo a chi paga in KY
                </label>
                <small style="color:var(--text-muted);">
                    Acceso: il conto va sotto di {{ ky_format($q['importo']) }} KY e il massimale sale
                    dello stesso importo, cos&igrave; il fido che aveva resta intero.
                    Spento: la quota se lo mangia, e chi non ha fido proprio non riesce a pagare in KY.
                </small>
            </div>
        </div>

        <div class="section-head" style="margin-top:18px;">
            <div><span class="eyebrow">Metodi</span><h3 class="section-title" style="font-size:15px;">Come si pu&ograve; pagare</h3></div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
            @foreach($q['metodi'] as $campo => [$etichetta, $configurato])
                <div class="field">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="{{ $campo }}" value="1"
                               @checked($spunta($campo, (bool) $settings->{$campo}))>
                        {{ $etichetta }}
                    </label>
                    @unless($configurato)
                        <small style="color:#b45309;">Non configurato: resta nascosto agli utenti anche se acceso.</small>
                    @endunless
                    @if($campo === $prefisso . '_ky_enabled')
                        <small style="color:var(--text-muted);">
                            Accendendolo accetti {{ ky_format($q['importo']) }} KY al posto degli euro:
                            il conto va sotto di quell'importo, ed &egrave; moneta creata dal circuito.
                        </small>
                        @if($q['oltre_il_limite'])
                            <small style="color:#b45309;display:block;margin-top:4px;">
                                <strong>Attenzione:</strong> ogni conto nasce con un limite giornaliero di uscita di
                                {{ ky_format($q['limite_giornaliero']) }} KY, e questa quota lo supera.
                                Chi prova a pagare in KY legge &laquo;limite giornaliero raggiunto&raquo; e non ci riesce:
                                il limite va alzato sul suo conto, dalla sua scheda.
                            </small>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        <div class="form-actions" style="justify-content:flex-start;margin-top:14px;">
            <button type="submit" class="cta">Salva {{ mb_strtolower($q['titolo']) }}</button>
        </div>
    </form>

    <div class="table-muted" style="margin-top:12px;font-size:12px;">
        Il pagamento con carta non si apre?
        <a href="{{ route('admin.stripe-diagnostics') }}" style="color:var(--primary);font-weight:600;">Apri la diagnosi Stripe</a>
        &mdash; dice dal server qual &egrave; il motivo, senza toccare niente.
    </div>
</article>
