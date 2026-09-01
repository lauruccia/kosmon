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
                    <h3 class="section-title" style="font-size:15px;">Quota di iscrizione dei privati</h3>
                </div>
                <span class="pill {{ $settings->registrationFeeEnabled() ? 'success' : 'warn' }}">
                    {{ $settings->registrationFeeEnabled() ? 'Attiva' : 'Non attiva' }}
                </span>
            </div>

            <div class="notice" style="margin-bottom:14px;">
                Si applica solo ai privati che si registrano <strong>da quando la quota &egrave; attiva</strong>:
                chi &egrave; gi&agrave; iscritto non deve niente, e chi si &egrave; registrato con un importo diverso
                continua a dovere quello. Chi paga in euro riceve l'equivalente in KY sul proprio conto;
                chi paga in KY va sotto di quell'importo e i KY finiscono sul conto di sistema.
            </div>

            <form method="POST" action="{{ route('admin.registration-fees.settings') }}">
                @csrf

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end;">
                    <div class="field">
                        <label>Importo (euro / KY, alla pari)</label>
                        <input type="number" min="0" step="0.01" name="registration_fee_amount"
                               value="{{ old('registration_fee_amount', ky_input($settings->registrationFeeAmount())) }}">
                    </div>
                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="registration_fee_enabled" value="1"
                                   @checked(old('registration_fee_enabled', $settings->registration_fee_enabled))>
                            Quota attiva
                        </label>
                        <small style="color:var(--text-muted);">Da spenta, nessun nuovo iscritto paga nulla.</small>
                    </div>
                </div>

                <div class="section-head" style="margin-top:18px;">
                    <div><span class="eyebrow">Metodi</span><h3 class="section-title" style="font-size:15px;">Come si pu&ograve; pagare</h3></div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                    @php
                        $metodi = [
                            'registration_fee_stripe_enabled'        => ['Carta (Stripe)', config('services.stripe.secret')],
                            'registration_fee_paypal_enabled'        => ['PayPal', config('services.paypal.client_id')],
                            'registration_fee_bank_transfer_enabled' => ['Bonifico bancario', config('kmoney.bank_iban')],
                            'registration_fee_ky_enabled'            => ['Saldo KY', true],
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
                <form method="GET" action="{{ route('admin.registration-fees.index') }}" style="display:flex;gap:8px;align-items:center;">
                    <select name="stato" data-no-search onchange="this.form.submit()">
                        <option value="">Tutti gli stati</option>
                        @foreach(['pending' => 'In corso', 'pending_bank_transfer' => 'Attesa bonifico', 'completed' => 'Saldata', 'failed' => 'Fallita', 'cancelled' => 'Annullata'] as $valore => $etichetta)
                            <option value="{{ $valore }}" @selected($stato === $valore)>{{ $etichetta }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="notice" style="margin-bottom:14px;">
                Una quota saldata si disfa <strong>solo da qui</strong>, con &laquo;Annulla quota&raquo;: storna il movimento,
                rimette la quota fra quelle da pagare e toglie il fido aggiuntivo, tutto insieme. Eliminare il movimento
                da <em>Movimenti</em> non basta &mdash; restituirebbe i KY lasciando la quota segnata come pagata &mdash;
                e infatti da l&igrave; non &egrave; pi&ugrave; possibile.
            </div>

            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Utente</th>
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
                                <div style="font-weight:700;">{{ $payment->user?->name ?? '—' }}</div>
                                <div class="table-muted">{{ $payment->user?->email }}</div>
                            </td>
                            <td>{{ \App\Models\RegistrationFeePayment::METHODS[$payment->payment_method] ?? $payment->payment_method }}</td>
                            <td>
                                {{ number_format($payment->amount_eur, 2, ',', '.') }} &euro;
                                <div class="table-muted">{{ ky_format($payment->ky_amount) }} KY</div>
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
                                        <form method="POST" action="{{ route('admin.registration-fees.confirm', $payment) }}"
                                              onsubmit="return confirm('Confermi di aver ricevuto il bonifico? Verranno accreditati {{ ky_format($payment->ky_amount) }} KY.');">
                                            @csrf
                                            <button type="submit" class="cta" style="padding:6px 10px;font-size:12px;">Conferma</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.registration-fees.reject', $payment) }}"
                                              onsubmit="return confirm('Rifiutare questo pagamento?');">
                                            @csrf
                                            <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Rifiuta</button>
                                        </form>
                                    </div>
                                @elseif($payment->isCompleted())
                                    <form method="POST" action="{{ route('admin.registration-fees.cancel', $payment) }}"
                                          onsubmit="return confirm('Annullare questa quota? Il movimento viene stornato, la quota torna da pagare e il fido aggiuntivo di {{ ky_format($payment->ky_amount) }} KY viene tolto.');">
                                        @csrf
                                        <input type="hidden" name="admin_notes" value="Quota annullata dal backoffice.">
                                        <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Annulla quota</button>
                                    </form>
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
