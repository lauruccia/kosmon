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
                    <h3 class="section-title" style="font-size:15px;">Quota per il codice agente</h3>
                </div>
                <span class="pill {{ $settings->agentCodeFeeEnabled() ? 'success' : 'warn' }}">
                    {{ $settings->agentCodeFeeEnabled() ? 'Attiva' : 'Non attiva' }}
                </span>
            </div>

            <div class="notice" style="margin-bottom:14px;">
                Dovuta da chi ha la richiesta agente <strong>approvata da quando la quota &egrave; attiva</strong>, prima
                di poter firmare il contratto di nomina: gli agenti che ci sono gi&agrave; non devono niente, e chi &egrave;
                stato approvato con un importo diverso continua a dovere quello. A differenza della quota di
                iscrizione dei privati, <strong>chi paga in euro NON riceve KY</strong>: &egrave; il prezzo del codice,
                non una ricarica. Chi paga in KY invece va sotto di quell'importo.
            </div>
            <div class="notice error" style="margin-bottom:14px;">
                Attenzione al metodo <strong>Saldo KY</strong>: ogni agente che sceglie i KY porta il proprio conto
                a &minus;{{ ky_format($settings->agentCodeFeeAmount()) }} KY, e quel saldo negativo &egrave; moneta creata
                dal circuito. Puoi tenerlo acceso per i privati e spento qui.
            </div>

            <form method="POST" action="{{ route('admin.agent-code-fees.settings') }}">
                @csrf

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end;">
                    <div class="field">
                        <label>Importo del codice (euro / KY, alla pari)</label>
                        <input type="number" min="0" step="0.01" name="agent_code_fee_amount"
                               value="{{ old('agent_code_fee_amount', ky_input($settings->agentCodeFeeAmount())) }}">
                    </div>
                    <div class="field">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="agent_code_fee_enabled" value="1"
                                   @checked(old('agent_code_fee_enabled', $settings->agent_code_fee_enabled))>
                            Quota attiva
                        </label>
                        <small style="color:var(--text-muted);">Da spenta, nessun nuovo agente paga nulla.</small>
                    </div>
                </div>

                <div class="section-head" style="margin-top:18px;">
                    <div><span class="eyebrow">Metodi</span><h3 class="section-title" style="font-size:15px;">Come si pu&ograve; pagare</h3></div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                    @php
                        $metodi = [
                            'agent_code_fee_stripe_enabled'        => ['Carta (Stripe)', config('services.stripe.secret')],
                            'agent_code_fee_paypal_enabled'        => ['PayPal', config('services.paypal.client_id')],
                            'agent_code_fee_bank_transfer_enabled' => ['Bonifico bancario', config('kmoney.bank_iban')],
                            'agent_code_fee_ky_enabled'            => ['Saldo KY', true],
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
        </article>
    </section>

    <section>
        <article class="card" style="padding:22px;">
            <div class="section-head">
                <div><span class="eyebrow">Pagamenti</span><h3 class="section-title" style="font-size:15px;">Quote codice registrate</h3></div>
                <form method="GET" action="{{ route('admin.agent-code-fees.index') }}" style="display:flex;gap:8px;align-items:center;">
                    <select name="stato" data-no-search onchange="this.form.submit()">
                        <option value="">Tutti gli stati</option>
                        @foreach(['pending' => 'In corso', 'pending_bank_transfer' => 'Attesa bonifico', 'completed' => 'Saldata', 'failed' => 'Fallita'] as $valore => $etichetta)
                            <option value="{{ $valore }}" @selected($stato === $valore)>{{ $etichetta }}</option>
                        @endforeach
                    </select>
                </form>
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
                            <td>{{ \App\Models\AgentCodeFeePayment::METHODS[$payment->payment_method] ?? $payment->payment_method }}</td>
                            <td>
                                {{ number_format($payment->amount_eur, 2, ',', '.') }} &euro;
                                @if($payment->payment_method === 'ky')
                                    <div class="table-muted">pagata in KY</div>
                                @endif
                            </td>
                            <td>
                                <span class="pill {{ $payment->isCompleted() ? 'success' : 'warn' }}">
                                    {{ $payment->status }}
                                </span>
                                @if($payment->admin_notes)
                                    <div class="table-muted">{{ $payment->admin_notes }}</div>
                                @endif
                            </td>
                            <td class="table-muted">{{ $payment->created_at?->format('d/m/Y H:i') }}</td>
                            <td>
                                @if($payment->isPendingBankTransfer())
                                    <div style="display:flex;gap:6px;">
                                        <form method="POST" action="{{ route('admin.agent-code-fees.confirm', $payment) }}"
                                              onsubmit="return confirm('Confermi di aver ricevuto il bonifico? L\'agente potrà firmare il contratto di nomina.');">
                                            @csrf
                                            <button type="submit" class="cta" style="padding:6px 10px;font-size:12px;">Conferma</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.agent-code-fees.reject', $payment) }}"
                                              onsubmit="return confirm('Rifiutare questo pagamento?');">
                                            @csrf
                                            <button type="submit" class="cta secondary" style="padding:6px 10px;font-size:12px;">Rifiuta</button>
                                        </form>
                                    </div>
                                @elseif($payment->isCompleted())
                                    {{-- Due avvisi diversi, perche' le due strade non si disfano
                                         allo stesso modo: in KY lo storno rimette tutto a posto da
                                         solo, in euro i 480 restano incassati su Stripe o PayPal e
                                         il rimborso lo deve fare una persona. In piu' un terzo
                                         avviso se ha gia' firmato: li' il codice agente ce l'ha
                                         gia' in mano e la quota gli torna addosso da pagare. --}}
                                    <form method="POST" action="{{ route('admin.agent-code-fees.cancel', $payment) }}"
                                          onsubmit="return confirm('@if($payment->isPaidInEuro())Annullare questa quota? Torna da saldare e l\'agente non potra\' firmare finche\' non la paga.\n\nATTENZIONE: i {{ number_format($payment->amount_eur, 2, ',', '.') }} euro incassati NON vengono rimborsati da qui — in euro non era stato accreditato nessun KY, quindi non c\'e\' niente da stornare. Il rimborso va disposto a mano dal pannello {{ $payment->payment_method === \App\Models\AgentCodeFeePayment::METHOD_BANK_TRANSFER ? 'della banca' : ucfirst($payment->payment_method) }}.@else Annullare questa quota? Il movimento viene stornato: {{ ky_format($payment->ky_amount) }} KY tornano sul suo conto, la quota torna da saldare e il fido aggiuntivo viene tolto.\n\nSe quei KY li ha gia\' spesi, lo storno porta il suo conto sotto il fido.@endif@if($payment->user?->isMlmAgent())\n\nHA GIA\' FIRMATO LA NOMINA: resta agente, con il codice attivo, e la quota gli torna addosso da pagare.@endif');">
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
