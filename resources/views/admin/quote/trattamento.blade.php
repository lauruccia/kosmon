{{--
    IL TRATTAMENTO DI QUESTA PERSONA SU UNA QUOTA (04/09/2026).

    Uguale per tutte e tre: quanti KY le vengono restituiti pagando in euro, e
    se pagando in KY riceve il fido aggiuntivo. Scavalca i due default della
    pagina /admin/quote.

    Riceve tre cose: $servizio (il servizio della quota), $rotta (dove salva) e
    $utente. Tutto il resto — i nomi delle colonne di ripiego, le chiavi delle
    impostazioni, la colonna del saldo — lo dice la definition() della quota,
    che e' l'unico posto dove quei nomi stanno scritti.

    TRE VALORI, NON DUE, ed e' il senso di questo riquadro: campo vuoto e «come
    da impostazioni» vogliono dire «segui il pannello», mentre 0 e «no» sono
    decisioni prese per questa persona e restano ferme anche se domani il
    default cambia.
--}}
@php
    $d = $servizio->definition();
    $imp = $servizio->settings();

    $creditoSuo = $utente->{$d->kyCreditOverrideColumn};
    $fidoSuo    = $utente->{$d->kyAllowanceOverrideColumn};

    $creditoDefault = max(0, (int) ($imp->{$d->kyCreditSetting} ?? 0));
    $fidoDefault    = $imp->{$d->kyAllowanceSetting} === null || (bool) $imp->{$d->kyAllowanceSetting};

    $creditoOra = $servizio->kyCreditFor($utente);
    $fidoOra    = $servizio->kyAllowanceEnabledFor($utente);
    $saldata    = $utente->{$d->paidAtColumn} !== null;
@endphp

<div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
    <div style="font-size:13px;font-weight:700;margin-bottom:6px;">Cosa riceve in cambio</div>
    <p class="table-muted" style="margin-bottom:12px;">
        Oggi per questa persona vale:
        <strong>{{ $creditoOra > 0 ? ky_format($creditoOra) . ' KY' : 'nessun KY' }}</strong>
        pagando in euro, e <strong>{{ $fidoOra ? 'fido aggiuntivo' : 'nessun fido aggiuntivo' }}</strong>
        pagando in KY.
        @if($creditoSuo === null && $fidoSuo === null)
            Sono i valori del pannello (restituzione {{ ky_format($creditoDefault) }} KY,
            fido {{ $fidoDefault ? 'acceso' : 'spento' }}).
        @else
            Ha un trattamento suo: se domani cambi il pannello, questa persona non lo segue.
        @endif
        @if($saldata)
            <br>
            <strong>Ha gi&agrave; saldato:</strong> cambiare qui non tocca i KY gi&agrave; restituiti n&eacute; il fido
            gi&agrave; concesso &mdash; per disfare quelli si annulla il pagamento.
        @endif
    </p>

    <form method="post" action="{{ route($rotta, $utente) }}"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end;">
        @csrf
        <div class="field">
            <label>KY se paga in euro</label>
            <input type="number" min="0" step="0.01" name="ky_credit"
                   placeholder="come da impostazioni ({{ ky_input($creditoDefault) }})"
                   value="{{ old('ky_credit', $creditoSuo !== null ? ky_input((int) $creditoSuo) : '') }}">
            <small style="color:var(--text-muted);">Vuoto = segui il pannello. Zero = niente, deciso per lei.</small>
        </div>
        <div class="field">
            <label>Fido se paga in KY</label>
            <select name="ky_allowance" data-no-search>
                <option value="" @selected($fidoSuo === null)>Come da impostazioni ({{ $fidoDefault ? 'sì' : 'no' }})</option>
                <option value="1" @selected($fidoSuo === true)>Sì: il fido che ha resta intero</option>
                <option value="0" @selected($fidoSuo === false)>No: la quota mangia il fido che ha già</option>
            </select>
        </div>
        <div class="field">
            <button type="submit" class="cta secondary users-compact-cta">Salva trattamento</button>
        </div>
    </form>
</div>
