@extends('layouts.portal')

@section('content')

    @if($errors->any())
        <div class="notice error" style="margin-bottom:16px;">
            @foreach($errors->all() as $errore)<div>{{ $errore }}</div>@endforeach
        </div>
    @endif

    <section style="margin-bottom:20px;">
        <article class="card" style="padding:22px;">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Impostazioni</span>
                    <h3 class="section-title" style="font-size:15px;">Quota di apertura conto delle aziende</h3>
                </div>
                <span class="pill {{ $settings->companyAccountFeeEnabled() ? 'success' : 'warn' }}">
                    {{ $settings->companyAccountFeeEnabled() ? 'Attiva' : 'Non attiva' }}
                </span>
            </div>

            <div class="notice" style="margin-bottom:14px;">
                Si applica solo alle aziende che si registrano <strong>da quando la quota &egrave; attiva</strong>:
                le anagrafiche gi&agrave; presenti non devono niente, e chi si &egrave; registrato con un importo
                diverso continua a dovere quello. <strong>La quota non blocca il conto</strong>:
                l'azienda che non ha saldato continua a pagare, incassare e vendere, e riceve il banner e un
                sollecito per email. Cosa riceve in cambio lo decidi qui sotto, e vale per tutte le aziende
                salvo quelle a cui hai messo un trattamento diverso dalla loro scheda.
            </div>

            <form method="POST" action="{{ route('admin.company-account-fees.settings') }}">
                @csrf

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end;">
                    <div class="field">
                        <label>Importo (euro)</label>
                        <input type="number" min="0" step="0.01" name="company_account_fee_amount"
                               value="{{ old('company_account_fee_amount', ky_input($settings->companyAccountFeeAmount())) }}">
                    </div>
                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="company_account_fee_enabled" value="1"
                                   @checked(old('company_account_fee_enabled', $settings->company_account_fee_enabled))>
                            Quota attiva
                        </label>
                        <small style="color:var(--text-muted);">Da spenta, nessuna nuova azienda paga nulla.</small>
                    </div>
                </div>

                <div class="section-head" style="margin-top:18px;">
                    <div>
                        <span class="eyebrow">In cambio</span>
                        <h3 class="section-title" style="font-size:15px;">Cosa riceve l'azienda</h3>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;align-items:start;">
                    <div class="field">
                        <label>KY accreditati a chi paga in euro</label>
                        <input type="number" min="0" step="0.01" name="company_account_fee_ky_credit"
                               value="{{ old('company_account_fee_ky_credit', ky_input($settings->companyAccountFeeKyCredit())) }}">
                        <small style="color:var(--text-muted);">
                            Zero = nessun accredito, e la quota resta solo un incasso. Non &egrave; legato
                            all'importo della quota: puoi darne meno, altrettanti o di pi&ugrave;. Quello che dai
                            &egrave; <strong>moneta coniata dal circuito</strong> per ogni azienda che paga.
                        </small>
                    </div>
                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="company_account_fee_ky_allowance" value="1"
                                   @checked(old('company_account_fee_ky_allowance', $settings->companyAccountFeeKyAllowance()))>
                            Fido aggiuntivo a chi paga in KY
                        </label>
                        <small style="color:var(--text-muted);">
                            Acceso: il conto va sotto di {{ ky_format($settings->companyAccountFeeAmount()) }} KY e il
                            massimale sale dello stesso importo, cos&igrave; il fido che l'azienda aveva resta intero.
                            Spento: la quota se lo mangia, e chi non ha fido proprio non riesce a pagare in KY.
                        </small>
                    </div>
                </div>

                <div class="section-head" style="margin-top:18px;">
                    <div><span class="eyebrow">Metodi</span><h3 class="section-title" style="font-size:15px;">Come si pu&ograve; pagare</h3></div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                    @php
                        $metodi = [
                            'company_account_fee_stripe_enabled'        => ['Carta (Stripe)', config('services.stripe.secret')],
                            'company_account_fee_paypal_enabled'        => ['PayPal', config('services.paypal.client_id')],
                            'company_account_fee_bank_transfer_enabled' => ['Bonifico bancario', config('kmoney.bank_iban')],
                            'company_account_fee_ky_enabled'            => ['Saldo KY', true],
                        ];
                    @endphp

                    @foreach($metodi as $campo => [$etichetta, $configurato])
                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="{{ $campo }}" value="1" @checked(old($campo, $settings->{$campo}))>
                            {{ $etichetta }}
                        </label>
                        @unless($configurato)
                            <small style="color:#b45309;">Non configurato: resta nascosto agli utenti anche se acceso.</small>
                        @endunless
                        @if($campo === 'company_account_fee_ky_enabled')
                            <small style="color:var(--text-muted);">
                                Accendendolo accetti {{ ky_format($settings->companyAccountFeeAmount()) }} KY al posto degli euro:
                                il conto dell'azienda va sotto di quell'importo, ed &egrave; moneta creata dal circuito.
                                <br>
                                <strong>Da sapere:</strong> ogni conto nasce con un limite giornaliero di uscita di 500,00 KY.
                                Se la quota lo supera, l'azienda vede &laquo;limite giornaliero raggiunto&raquo; e non riesce a
                                saldare: il limite va alzato sul suo conto, dalla scheda dell'azienda.
                            </small>
                        @endif
                    </div>
                    @endforeach
                </div>

                <div class="form-actions" style="justify-content:flex-start;margin-top:14px;">
                    <button type="submit" class="cta">Salva impostazioni</button>
                </div>
            </form>

            <div class="table-muted" style="margin-top:12px;font-size:12px;">
                Il pagamento con carta non si apre?
                <a href="{{ route('admin.stripe-diagnostics') }}" style="color:var(--primary);font-weight:600;">Apri la diagnosi Stripe</a>
                &mdash; dice dal server qual &egrave; il motivo, senza toccare niente.
            </div>
        </article>
    </section>

    <section>
        <article class="card" style="padding:22px;">
            <div class="section-head">
                <div><span class="eyebrow">Pagamenti</span><h3 class="section-title" style="font-size:15px;">Quote registrate</h3></div>
                <form method="GET" action="{{ route('admin.company-account-fees.index') }}" style="display:flex;gap:8px;align-items:center;">
                    <select name="stato" data-no-search onchange="this.form.submit()">
                        <option value="">Tutti gli stati</option>
                        @foreach(['pending' => 'In corso', 'pending_bank_transfer' => 'Attesa bonifico', 'completed' => 'Saldata', 'failed' => 'Fallita', 'cancelled' => 'Annullata'] as $valore => $etichetta)
                            <option value="{{ $valore }}" @selected($stato === $valore)>{{ $etichetta }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="notice" style="margin-bottom:14px;">
                Una quota saldata si disfa <strong>solo da qui</strong>, con &laquo;Annulla quota&raquo;. In KY storna il
                movimento, rimette la quota fra quelle da pagare e toglie il fido aggiuntivo, tutto insieme;
                in euro non c'&egrave; nessun movimento da stornare &mdash; nessun KY era stato emesso &mdash; e il
                rimborso dei soldi veri resta da fare a mano.
            </div>

            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Azienda</th>
                            <th>Metodo</th>
                            <th>Importo</th>
                            <th>Stato</th>
                            <th>Data</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($payments as $payment)
                        <tr>
                            <td>
                                <div style="font-weight:700;">{{ $payment->user?->company?->name ?? $payment->user?->name ?? '—' }}</div>
                                <div class="table-muted">{{ $payment->user?->email }}</div>
                            </td>
                            <td>{{ \App\Models\CompanyAccountFeePayment::METHODS[$payment->payment_method] ?? $payment->payment_method }}</td>
                            <td>
                                {{ number_format($payment->amount_eur, 2, ',', '.') }} &euro;
                                @unless($payment->isPaidInEuro())
                                    <div class="table-muted">{{ ky_format($payment->ky_amount) }} KY</div>
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
                                        <form method="POST" action="{{ route('admin.company-account-fees.confirm', $payment) }}"
                                              onsubmit="return confirm('Confermi di aver ricevuto il bonifico di {{ number_format($payment->amount_eur, 2, ',', '.') }} euro? La quota risulterà saldata.');">
                                            @csrf
                                            <button type="submit" class="cta" style="padding:6px 10px;font-size:12px;">Conferma</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.company-account-fees.reject', $payment) }}"
                                              onsubmit="return confirm('Rifiutare questo pagamento?');">
                                            @csrf
                                            <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Rifiuta</button>
                                        </form>
                                    </div>
                                @elseif($payment->isCompleted())
                                    {{-- Due avvisi diversi, perche' le due strade non si disfano allo
                                         stesso modo: in KY lo storno rimette tutto a posto da solo, in
                                         euro i soldi veri restano incassati e il rimborso lo deve fare
                                         una persona. --}}
                                    <form method="POST" action="{{ route('admin.company-account-fees.cancel', $payment) }}"
                                          onsubmit="return confirm('@if($payment->isPaidInEuro())Annullare questa quota? Torna da pagare.\n\nATTENZIONE: i {{ number_format($payment->amount_eur, 2, ',', '.') }} euro incassati NON vengono rimborsati da qui, e nessun KY era stato emesso. Il rimborso va fatto a mano dal pannello {{ $payment->payment_method === \App\Models\CompanyAccountFeePayment::METHOD_BANK_TRANSFER ? 'della banca' : ucfirst($payment->payment_method) }}.@else Annullare questa quota? Il movimento viene stornato, la quota torna da pagare e il fido aggiuntivo di {{ ky_format($payment->ky_amount) }} KY viene tolto.\n\nSe l\'azienda quei KY li ha già spesi, lo storno porta il conto sotto il fido.@endif');">
                                        @csrf
                                        <input type="hidden" name="admin_notes" value="Quota annullata dal backoffice.">
                                        <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Annulla quota</button>
                                    </form>
                                @elseif($payment->status === \App\Models\CompanyAccountFeePayment::STATUS_FAILED && $payment->isPaidInEuro())
                                    {{-- Pagamento in euro finito male: puo' essere un tentativo mai
                                         arrivato in fondo (e allora qui non succede niente) oppure un
                                         incasso vero che non e' stato registrato. Il bottone non da'
                                         per pagato per fiducia: interroga Stripe/PayPal e procede solo
                                         se risultano aver incassato. --}}
                                    @if($payment->payment_method === \App\Models\CompanyAccountFeePayment::METHOD_BANK_TRANSFER)
                                        <form method="POST" action="{{ route('admin.company-account-fees.retry-credit', $payment) }}"
                                              onsubmit="return confirm('Il bonifico di {{ number_format($payment->amount_eur, 2, ',', '.') }} euro è arrivato davvero sul conto?\n\nQui non c\'è nessuna banca da interrogare: dando la quota per saldata stai mettendo la tua firma sul fatto di averlo visto.');">
                                            @csrf
                                            <input type="hidden" name="bonifico_ricevuto" value="1">
                                            <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Salda comunque</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.company-account-fees.retry-credit', $payment) }}"
                                              onsubmit="return confirm('Chiedo a {{ ucfirst($payment->payment_method) }} se questo pagamento è stato incassato davvero. Se sì la quota risulta saldata, se no non succede niente.');">
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
        </article>
    </section>
@endsection
