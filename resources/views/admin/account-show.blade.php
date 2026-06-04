@extends('layouts.portal')

@section('content')
    @php
        $massimale = $accountRecord->massimale();
        $saldoDisponibile = $accountRecord->saldoDisponibile();
        $disponibilitaCommerciale = $accountRecord->disponibilitaCommerciale();
        $disponibilitaCommercialeUsata = $accountRecord->disponibilitaCommercialeUsata();
        $disponibilitaCommercialeResidua = $accountRecord->disponibilitaCommercialeResidua();
        $disponibilitaCommercialePercentuale = $accountRecord->disponibilitaCommercialePercentualeUtilizzo();
    @endphp

    <section class="page-intro--row page-intro">
        <div class="page-intro-body">
        <span class="eyebrow">Dettaglio conto</span>
        <h2>{{ $accountRecord->display_name }}</h2>
        <p>Vista ordinata per leggere saldo attuale, saldo disponibile, massimale e disponibilita commerciale senza mischiare credito e capacita commerciale.</p>
        </div>
        <div class="page-actions">
            <a class="cta secondary" href="{{ route('admin.accounts.index') }}">Torna ai conti</a>
            <a class="cta secondary" href="#account-settings">Imposta limiti conto</a>
            @if ($accountRecord->ownerUser)
                <a class="cta secondary" href="#owner-user-limits">Massimale e disponibilita commerciale</a>
            @endif
            <a class="cta" href="{{ route('admin.transfers.index') }}">Vai ai movimenti</a>
        </div>
    </section>

    <section class="hero-strip" style="margin-bottom:22px;">
        <article class="stat-card">
            <div class="eyebrow">Numero conto</div>
            <div class="section-title" style="font-size:24px;word-break:break-word;">{{ $accountRecord->account_number }}</div>
            <div class="table-muted">Formato compatto KY a 16 caratteri</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Saldo attuale</div>
            <div class="section-title" style="font-size:34px;">{{ ky_format($accountRecord->available_balance) }} {{ $accountRecord->currency_code }}</div>
            <div class="table-muted">Saldo contabile del conto</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Saldo disponibile</div>
            <div class="section-title" style="font-size:34px;">{{ ky_format($saldoDisponibile) }} {{ $accountRecord->currency_code }}</div>
            <div class="table-muted">Saldo attuale + massimale</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Massimale</div>
            <div class="section-title" style="font-size:34px;">{{ ky_format($massimale) }} {{ $accountRecord->currency_code }}</div>
            <div class="table-muted">Linea di credito spendibile sul conto</div>
        </article>
    </section>

    <section class="summary-grid" style="margin-bottom:22px;">
        <article class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Anagrafica</span>
                    <h3 class="section-title">Profilo conto</h3>
                </div>
                <span class="pill {{ $accountRecord->status === 'active' ? 'success' : 'warn' }}">{{ $accountRecord->status === 'active' ? 'Attivo' : 'Sospeso' }}</span>
            </div>

            <div class="info-grid">
                <div><div class="eyebrow">Intestatario</div><strong>{{ $accountRecord->ownerLabel }}</strong></div>
                <div><div class="eyebrow">Tipo conto</div><strong>{{ strtoupper($accountRecord->type) }}</strong></div>
                <div><div class="eyebrow">Profilo</div><strong>{{ $accountRecord->owner_type }}</strong></div>
                <div><div class="eyebrow">Azienda</div><strong>{{ $accountRecord->company?->name ?? 'Nessuna azienda' }}</strong></div>
                <div><div class="eyebrow">Utente proprietario</div><strong>{{ $accountRecord->ownerUser?->name ?? 'N/D' }}</strong></div>
                <div><div class="eyebrow">Creato il</div><strong>{{ $accountRecord->created_at?->format('d/m/Y H:i') ?? 'N/D' }}</strong></div>
            </div>

            <div class="table-tags" style="margin-top:18px;">
                @if ($accountRecord->parentAccount)
                    <span class="chip">padre: {{ $accountRecord->parentAccount->display_name }}</span>
                @endif
                @if ($accountRecord->childAccounts->isNotEmpty())
                    <span class="chip">{{ $accountRecord->childAccounts->count() }} sottoconti figli</span>
                @endif
                <span class="chip">{{ $accountRecord->allow_negative_balance ? 'saldo negativo conto consentito' : 'saldo negativo conto bloccato' }}</span>
                <span class="chip">{{ $accountRecord->max_balance !== null ? 'saldo massimo ' . ky_format($accountRecord->max_balance) . ' ' . $accountRecord->currency_code : 'nessun saldo massimo' }}</span>
            </div>
        </article>

        <article class="card light-card" id="account-settings">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Governance</span>
                    <h3 class="section-title">Stato e limiti conto</h3>
                </div>
                <span class="pill">Aggiornamento rapido</span>
            </div>

            <form method="post" action="{{ route('admin.accounts.update', $accountRecord) }}" class="field-grid">
                @csrf
                <div class="field"><label>Stato</label><select name="status"><option value="active" @selected($accountRecord->status === 'active')>Attivo</option><option value="suspended" @selected($accountRecord->status === 'suspended')>Sospeso</option></select></div>
                <div class="field"><label>Saldo massimo conto (KY)</label><input name="max_balance" type="number" min="0" step="0.01" value="{{ old('max_balance', ky_input($accountRecord->max_balance)) }}" placeholder="Vuoto = nessun limite"><div class="table-muted">Tetto massimo positivo mostrato in dashboard come “Saldo massimo”. Quando il saldo raggiunge questo valore il conto entra in modalità commerciale limitata.</div></div>
                <div class="field"><label>Limite singolo conto (KY)</label><input name="spending_limit" type="number" min="0.01" step="0.01" value="{{ old('spending_limit', ky_input($accountRecord->spending_limit)) }}"></div>
                <div class="field"><label>Limite giornaliero conto (KY)</label><input name="daily_outgoing_limit" type="number" min="0.01" step="0.01" value="{{ old('daily_outgoing_limit', ky_input($accountRecord->daily_outgoing_limit)) }}"></div>
                <div class="field"><label>Saldo negativo conto</label><select name="allow_negative_balance"><option value="0" @selected((string) old('allow_negative_balance', $accountRecord->allow_negative_balance ? '1' : '0') === '0')>Non consentito</option><option value="1" @selected((string) old('allow_negative_balance', $accountRecord->allow_negative_balance ? '1' : '0') === '1')>Consentito</option></select><div class="table-muted">Questo flag abilita il conto all'uso del massimale. Il valore del massimale si configura sotto sul proprietario.</div></div>
                <div class="form-actions"><button type="submit" class="cta">Salva impostazioni</button></div>
            </form>
        </article>
    </section>

    <section class="summary-grid" id="owner-user-limits" style="margin-bottom:22px;">
        <article class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Disponibilita commerciale</span>
                    <h3 class="section-title">Capacita del conto nel circuito</h3>
                </div>
                <span class="pill">vendita nel circuito</span>
            </div>
            <div class="field-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;">
                <div class="timeline-item"><strong>Disponibilita commerciale</strong><div class="table-muted">Valore dei beni o servizi che il conto mette a disposizione nel circuito.</div><div class="section-title" style="font-size:22px;">{{ ky_format($disponibilitaCommerciale) }} {{ $accountRecord->currency_code }}</div></div>
                <div class="timeline-item"><strong>Percentuale di utilizzo</strong><div class="table-muted">Entrate contabilizzate nell'anno in corso rispetto alla disponibilita commerciale.</div><div class="section-title" style="font-size:22px;">{{ number_format($disponibilitaCommercialePercentuale, 2, ',', '.') }}%</div></div>
                <div class="timeline-item"><strong>Utilizzata</strong><div class="table-muted">Totale entrate contabilizzate dall'inizio dell'anno.</div><div class="section-title" style="font-size:22px;">{{ ky_format($disponibilitaCommercialeUsata) }} {{ $accountRecord->currency_code }}</div></div>
                <div class="timeline-item"><strong>Residua</strong><div class="table-muted">Disponibilita commerciale ancora spendibile come capacita di vendita.</div><div class="section-title" style="font-size:22px;">{{ ky_format($disponibilitaCommercialeResidua) }} {{ $accountRecord->currency_code }}</div></div>
            </div>
        </article>

        <article class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Massimale proprietario</span>
                    <h3 class="section-title">Credito e limiti del conto</h3>
                </div>
                @if ($accountRecord->ownerUser)
                    <a class="cta secondary" href="{{ route('admin.users.show', $accountRecord->ownerUser) }}">Apri scheda utente</a>
                @endif
            </div>

            @if ($accountRecord->ownerUser)
                <form method="post" action="{{ route('admin.users.update', $accountRecord->ownerUser) }}" class="field-grid">
                    @csrf
                    <input type="hidden" name="company_id" value="{{ old('company_id', $accountRecord->ownerUser->company_id) }}">
                    <input type="hidden" name="managed_account_id" value="{{ old('managed_account_id', $accountRecord->ownerUser->managed_account_id) }}">
                    <input type="hidden" name="phone" value="{{ old('phone', $accountRecord->ownerUser->phone) }}">
                    <input type="hidden" name="role_label" value="{{ old('role_label', $accountRecord->ownerUser->role) }}">
                    <input type="hidden" name="is_active" value="{{ old('is_active', $accountRecord->ownerUser->is_active ? '1' : '0') }}">
                    @foreach ($accountRecord->ownerUser->roles as $role)
                        <input type="hidden" name="roles[]" value="{{ $role->id }}">
                    @endforeach
                    <input type="hidden" name="daily_transaction_limit" value="{{ old('daily_transaction_limit', ky_input($accountRecord->ownerUser->daily_transaction_limit)) }}">
                    <input type="hidden" name="monthly_transaction_limit" value="{{ old('monthly_transaction_limit', ky_input($accountRecord->ownerUser->monthly_transaction_limit)) }}">
                    <input type="hidden" name="per_movement_limit" value="{{ old('per_movement_limit', ky_input($accountRecord->ownerUser->per_movement_limit)) }}">

                    <div class="field"><label>Massimale / fido (KY)</label><input name="negative_balance_limit" type="number" min="0" step="0.01" value="{{ old('negative_balance_limit', ky_input($accountRecord->ownerUser->negative_balance_limit)) }}"><div class="table-muted">`100,00` consente un saldo minimo di `-100,00 KY` e rende il saldo disponibile pari a saldo attuale + 100,00 KY.</div></div>
                    <div class="field"><label>Disponibilita commerciale (KY)</label><input name="circuit_capacity_limit" type="number" min="0" step="0.01" value="{{ old('circuit_capacity_limit', ky_input($accountRecord->ownerUser->circuit_capacity_limit)) }}"><div class="table-muted">Valore di beni o servizi messi a disposizione nel circuito. Non aumenta il saldo disponibile.</div></div>
                    <div class="form-actions"><button type="submit" class="cta">Salva massimale e disponibilita commerciale</button></div>
                </form>
            @else
                <div class="empty-state">Questo conto non ha un utente proprietario collegato. Collega prima il proprietario per impostare massimale e disponibilita commerciale.</div>
            @endif
        </article>
    </section>

    <section class="card light-card" style="margin-bottom:22px;">
        <div class="section-head">
            <div>
                <span class="eyebrow">Accessi collegati</span>
                <h3 class="section-title">Delegati e sottoconti</h3>
            </div>
            <span class="pill">{{ $accountRecord->managedUsers->count() + $accountRecord->childAccounts->count() }}</span>
        </div>

        <div class="timeline-list">
            @forelse ($accountRecord->managedUsers as $managedUser)
                <article class="timeline-item">
                    <div class="entity-head">
                        <div>
                            <strong>{{ $managedUser->name }}</strong>
                            <div class="table-muted">{{ $managedUser->email }}</div>
                            <div class="table-muted">Massimale utente: {{ ky_format((int) ($managedUser->effectiveTransferLimits()['negative_balance_limit'] ?? 0)) }} {{ $accountRecord->currency_code }}</div>
                        </div>
                        <div class="entity-meta">
                            <span class="chip {{ $managedUser->is_active ? 'success' : 'pink' }}">{{ $managedUser->is_active ? 'Attivo' : 'Disattivo' }}</span>
                            <a class="cta secondary" href="{{ route('admin.users.show', $managedUser) }}">Dettaglio utente</a>
                        </div>
                    </div>
                </article>
            @empty
                <div class="empty-state">Nessun utente delegato collegato a questo conto.</div>
            @endforelse

            @foreach ($accountRecord->childAccounts as $childAccount)
                <article class="timeline-item">
                    <div class="entity-head">
                        <div>
                            <strong>{{ $childAccount->display_name }}</strong>
                            <div class="table-muted">{{ $childAccount->account_number }}</div>
                        </div>
                        <div class="entity-meta">
                            <span class="chip">{{ $childAccount->type }}</span>
                            <a class="cta secondary" href="{{ route('admin.accounts.show', $childAccount) }}">Dettagli</a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Registro sintetico</span>
                <h3 class="section-title">Ultimi movimenti sul conto</h3>
            </div>
            <span class="pill">{{ $recentTransfers->count() }}</span>
        </div>

        @if ($recentTransfers->isEmpty())
            <div class="empty-state">Nessun movimento rilevato su questo conto.</div>
        @else
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Movimento</th>
                            <th>Contropartita</th>
                            <th>Importo</th>
                            <th>Operatore</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentTransfers as $transfer)
                            @php
                                $isOutgoing = (int) $transfer->from_account_id === (int) $accountRecord->id;
                                $counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount;
                            @endphp
                            <tr>
                                <td>{{ $transfer->booked_at?->format('d/m/Y H:i') ?? 'N/D' }}</td>
                                <td>
                                    <strong>{{ $transfer->reference }}</strong>
                                    <div class="table-muted">{{ $isOutgoing ? 'Uscita' : 'Entrata' }}</div>
                                </td>
                                <td>
                                    <strong>{{ $counterparty?->display_name ?? 'N/D' }}</strong>
                                    <div class="table-muted">{{ $counterparty?->ownerLabel ?? 'N/D' }}</div>
                                </td>
                                <td>
                                    <strong>{{ ky_format($transfer->amount) }} {{ $transfer->currency_code }}</strong>
                                    <div class="table-muted">{{ $transfer->description ?: 'nessuna causale' }}</div>
                                </td>
                                <td>{{ $transfer->initiator?->name ?? 'sistema' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
