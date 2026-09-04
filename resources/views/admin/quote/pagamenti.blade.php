{{--
    L'ELENCO DEI PAGAMENTI DI UNA QUOTA, uguale per tutte e tre (04/09/2026).

    Riceve $q (la descrizione della quota), $payments e $stato. Le azioni
    puntano alle rotte della quota: sono rimaste quelle di prima, con i loro
    controlli e i loro test.

    I TESTI DELLE CONFERME SI CALCOLANO IN PHP, sopra la riga, e non dentro
    l'attributo onsubmit. Il motivo e' la trappola del 04/09: una direttiva
    Blade attaccata a una parola non viene compilata, resta scritta letterale e
    fa esplodere la pagina solo quando qualcuno la apre. Dentro un attributo,
    per giunta, gli apostrofi vanno scritti \' o chiudono la stringa
    JavaScript: qui si scrivono una volta sola, in stringhe con le virgolette
    doppie, invece che sparsi in mezzo all'HTML.
--}}
@php
    $classe   = $q['classe'];
    $inEuro   = fn ($p): bool => $p->isPaidInEuro();
    $eur      = fn (int|float $v): string => number_format($v, 2, ',', '.');
@endphp

<div class="notice" style="margin-bottom:14px;">
    Una quota saldata si disfa <strong>solo da qui</strong>, con &laquo;Annulla quota&raquo;: storna il
    movimento se ce n'era uno, rimette la quota fra quelle da pagare e toglie il fido aggiuntivo,
    tutto insieme. Eliminare il movimento da <em>Movimenti</em> non basta &mdash; restituirebbe i KY
    lasciando la quota segnata come pagata &mdash; e infatti da l&igrave; non &egrave; pi&ugrave; possibile.
    <br>
    I <strong>soldi veri incassati</strong> con carta, PayPal o bonifico non tornano indietro da qui:
    il rimborso si dispone a mano dal pannello di chi li ha incassati.
</div>

<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>{{ $q['chi'] }}</th>
                <th>Metodo</th>
                <th>Importo</th>
                <th>Stato</th>
                <th>Data</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse($payments as $payment)
            @php
                $importoEur = $eur($payment->amount_eur);
                $importoKy  = ky_format($payment->ky_amount);
                $pagatore   = $payment->payment_method === $classe::METHOD_BANK_TRANSFER
                    ? 'della banca'
                    : ucfirst((string) $payment->payment_method);

                $confermaBonifico = "Confermi di aver ricevuto il bonifico di {$importoEur} euro? La quota risultera saldata.";

                $confermaAnnulla = $inEuro($payment)
                    ? "Annullare questa quota? Torna da pagare, e i KY eventualmente restituiti tornano al conto di sistema."
                        . "\\n\\nATTENZIONE: i {$importoEur} euro incassati NON vengono rimborsati da qui: il rimborso va fatto a mano dal pannello {$pagatore}."
                        . "\\n\\nSe quei KY sono gia stati spesi, lo storno porta il conto sotto il fido."
                    : "Annullare questa quota? Il movimento viene stornato, la quota torna da pagare e il fido aggiuntivo di {$importoKy} KY viene tolto."
                        . "\\n\\nSe quei KY sono gia stati spesi, lo storno porta il conto sotto il fido.";

                $confermaRipescaBonifico = "Il bonifico di {$importoEur} euro e arrivato davvero sul conto?"
                    . "\\n\\nQui non c'e nessuna banca da interrogare: dando la quota per saldata stai mettendo la tua firma sul fatto di averlo visto.";

                $confermaRipescaOnline = "Chiedo a {$pagatore} se questo pagamento e stato incassato davvero. Se si la quota risulta saldata, se no non succede niente.";
            @endphp
            <tr>
                <td>
                    <div style="font-weight:700;">{{ $payment->user?->company?->name ?? $payment->user?->name ?? '—' }}</div>
                    <div class="table-muted">{{ $payment->user?->email }}</div>
                </td>
                <td>{{ $classe::METHODS[$payment->payment_method] ?? $payment->payment_method }}</td>
                <td>
                    {{ $importoEur }} &euro;
                    @unless($inEuro($payment))
                        <div class="table-muted">{{ $importoKy }} KY</div>
                    @endunless
                </td>
                <td>
                    <span class="pill {{ $payment->isCompleted() ? 'success' : ($payment->isCancelled() ? 'danger' : 'warn') }}">
                        {{ $payment->isCancelled() ? 'annullata' : $payment->status }}
                    </span>
                    @if($payment->admin_notes)
                        <div class="table-muted">{{ $payment->admin_notes }}</div>
                    @endif
                </td>
                <td class="table-muted">{{ $payment->created_at?->format('d/m/Y H:i') }}</td>
                <td>
                    @if($payment->isPendingBankTransfer())
                        <div style="display:flex;gap:6px;">
                            <form method="POST" action="{{ route($q['rotta_conferma'], $payment) }}"
                                  onsubmit="return confirm('{{ $confermaBonifico }}');">
                                @csrf
                                <button type="submit" class="cta" style="padding:6px 10px;font-size:12px;">Conferma</button>
                            </form>
                            <form method="POST" action="{{ route($q['rotta_rifiuta'], $payment) }}"
                                  onsubmit="return confirm('Rifiutare questo pagamento?');">
                                @csrf
                                <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Rifiuta</button>
                            </form>
                        </div>
                    @elseif($payment->isCompleted())
                        <form method="POST" action="{{ route($q['rotta_annulla'], $payment) }}"
                              onsubmit="return confirm('{{ $confermaAnnulla }}');">
                            @csrf
                            <input type="hidden" name="admin_notes" value="Quota annullata dal backoffice.">
                            <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Annulla quota</button>
                        </form>
                    @elseif($payment->status === $classe::STATUS_FAILED && $inEuro($payment))
                        {{-- Pagamento in euro finito male: puo' essere un tentativo mai arrivato in
                             fondo (e allora qui non succede niente) oppure un incasso vero che non e'
                             stato registrato. Il bottone non da' per pagato per fiducia: interroga
                             Stripe o PayPal e procede solo se risultano aver incassato. Il bonifico
                             e' l'eccezione, perche' non c'e' nessuno da interrogare. --}}
                        @if($payment->payment_method === $classe::METHOD_BANK_TRANSFER)
                            <form method="POST" action="{{ route($q['rotta_ripesca'], $payment) }}"
                                  onsubmit="return confirm('{{ $confermaRipescaBonifico }}');">
                                @csrf
                                <input type="hidden" name="bonifico_ricevuto" value="1">
                                <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Salda comunque</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route($q['rotta_ripesca'], $payment) }}"
                                  onsubmit="return confirm('{{ $confermaRipescaOnline }}');">
                                @csrf
                                <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Verifica e salda</button>
                            </form>
                        @endif
                    @else
                        <span class="table-muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="table-muted">Nessun pagamento registrato.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:14px;">{{ $payments->links() }}</div>
