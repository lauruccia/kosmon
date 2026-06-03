@extends('layouts.portal')

@section('content')
    @php
        $currency = $primaryAccount?->currency_code ?? 'KY';
        $hasUserOverrides = $userRecord->hasCustomTransferLimits();
    @endphp

    <section class="page-intro--row page-intro">
        <div class="page-intro-body">
        <span class="eyebrow">Dettaglio utente</span>
        <h2>{{ $userRecord->name }}</h2>
        <p>Vista completa del profilo utente con conto collegato, saldo aggregato, ruoli assegnati, limiti effettivi e tutti i movimenti filtrabili sui conti associati.</p>
        </div>
        <div class="page-actions">
            <a class="cta secondary" href="{{ route('admin.users.index') }}">Torna all'elenco</a>
            <a class="cta secondary" href="#user-limits">Limiti utente</a>
            <a class="cta secondary" href="#user-password">Cambio password</a>
            <a class="cta secondary" href="#user-update">Aggiorna utente</a>
            <a class="cta" href="#user-movements">Movimenti filtrati</a>
        </div>
    </section>

    <section class="hero-strip" style="margin-bottom:22px;">
        <article class="stat-card">
            <div class="eyebrow">Saldo disponibile</div>
            <div class="section-title" style="font-size:34px;">{{ number_format($balances['available'], 2, ',', '.') }} {{ $currency }}</div>
            <div class="table-muted">Somma di tutti i conti collegati</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Saldo pending</div>
            <div class="section-title" style="font-size:34px;">{{ number_format($balances['pending'], 2, ',', '.') }} {{ $currency }}</div>
            <div class="table-muted">Transazioni non ancora consolidate</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Incassato filtrato</div>
            <div class="section-title" style="font-size:34px;">{{ number_format($balances['incoming'], 2, ',', '.') }} {{ $currency }}</div>
            <div class="table-muted">Entrate nel periodo selezionato</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Speso filtrato</div>
            <div class="section-title" style="font-size:34px;">{{ number_format($balances['outgoing'], 2, ',', '.') }} {{ $currency }}</div>
            <div class="table-muted">Uscite nel periodo selezionato</div>
        </article>
    </section>

    <section class="summary-grid" style="margin-bottom:22px;">
        <article class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Anagrafica</span>
                    <h3 class="section-title">Profilo utente</h3>
                </div>
                <span class="pill {{ $userRecord->is_active ? 'success' : 'warn' }}">{{ $userRecord->is_active ? 'Attivo' : 'Disattivo' }}</span>
            </div>

            <div class="info-grid">
                <div><div class="eyebrow">Email</div><strong>{{ $userRecord->email }}</strong></div>
                <div><div class="eyebrow">Telefono</div><strong>{{ $userRecord->phone ?: 'Non impostato' }}</strong></div>
                <div><div class="eyebrow">Ruolo interno</div><strong>{{ $userRecord->role }}</strong></div>
                <div><div class="eyebrow">Tipologia</div><strong>{{ $userRecord->account_holder_type }}</strong></div>
                <div><div class="eyebrow">Azienda</div><strong>{{ $userRecord->company?->name ?? 'Nessuna azienda' }}</strong></div>
                <div><div class="eyebrow">Creato il</div><strong>{{ $userRecord->created_at?->format('d/m/Y H:i') ?? 'N/D' }}</strong></div>
            </div>

            <div class="table-tags" style="margin-top:18px;">
                @if ($userRecord->is_super_admin)
                    <span class="chip pink">superadmin</span>
                @endif
                @if ($userRecord->managed_account_id)
                    <span class="chip">delegato su sottoconto</span>
                @endif
                @if ($hasUserOverrides)
                    <span class="chip success">override limiti attivo</span>
                @else
                    <span class="chip">usa default admin</span>
                @endif
                @forelse ($userRecord->roles as $role)
                    <span class="chip">{{ $role->name }}</span>
                @empty
                    <span class="table-muted">Nessun ruolo esplicito assegnato</span>
                @endforelse
            </div>
        </article>

        <article class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Conto principale</span>
                    <h3 class="section-title">Panoramica conto</h3>
                </div>
                <span class="pill">{{ $accounts->count() }} conti</span>
            </div>

            @if ($primaryAccount)
                <div class="timeline-item">
                    <div class="entity-head">
                        <div>
                            <strong>{{ $primaryAccount->display_name }}</strong>
                            <div class="table-muted">{{ strtoupper($primaryAccount->type) }} · {{ $primaryAccount->ownerLabel }}</div>
                            <div class="table-muted">Conto {{ $primaryAccount->account_number }}</div>
                        </div>
                        <div class="entity-meta">
                            <span class="chip {{ $primaryAccount->status === 'active' ? 'success' : 'pink' }}">{{ $primaryAccount->status === 'active' ? 'Attivo' : ($primaryAccount->status === 'suspended' ? 'Sospeso' : $primaryAccount->status) }}</span>
                            @if ($primaryAccount->allow_negative_balance)
                                <span class="chip">saldo negativo ok</span>
                            @endif
                        </div>
                    </div>
                    <div class="field-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;">
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Disponibile</div><strong>{{ number_format($primaryAccount->available_balance, 2, ',', '.') }} {{ $primaryAccount->currency_code }}</strong></div>
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Pending</div><strong>{{ number_format($primaryAccount->pending_balance, 2, ',', '.') }} {{ $primaryAccount->currency_code }}</strong></div>
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Limite giornaliero conto</div><strong>{{ $primaryAccount->daily_outgoing_limit ? number_format($primaryAccount->daily_outgoing_limit, 2, ',', '.') . ' ' . $primaryAccount->currency_code : 'non impostato' }}</strong></div>
                    </div>
                </div>
            @else
                <div class="empty-state">Questo utente non ha ancora un conto collegato.</div>
            @endif
        </article>
    </section>

    <section class="card light-card" id="user-limits" style="margin-bottom:22px;">
        <div class="section-head">
            <div>
                <span class="eyebrow">Guardrail utente</span>
                <h3 class="section-title">Massimale, disponibilita commerciale e override</h3>
            </div>
            <span class="pill {{ $hasUserOverrides ? 'success' : '' }}">{{ $hasUserOverrides ? 'override personalizzato' : 'eredita default admin' }}</span>
        </div>

        <div class="field-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;">
            <div class="timeline-item">
                <strong>Disponibilita commerciale</strong>
                <div class="table-muted">Valore di beni o servizi messi a disposizione nel circuito.</div>
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['circuit_capacity_limit'] !== null ? number_format($effectiveTransferLimits['circuit_capacity_limit'], 2, ',', '.') . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['circuit_capacity_limit'] !== null ? number_format($defaultTransferLimits['circuit_capacity_limit'], 2, ',', '.') . ' ' . $currency : 'non impostato' }}</div>
            </div>
            <div class="timeline-item">
                <strong>Massimale</strong>
                <div class="table-muted">Linea di credito spendibile sul conto.</div>
                <div class="section-title" style="font-size:22px;">-{{ number_format((int) ($effectiveTransferLimits['negative_balance_limit'] ?? 0), 2, ',', '.') }} {{ $currency }}</div>
                <div class="table-muted">Default admin: -{{ number_format((int) ($defaultTransferLimits['negative_balance_limit'] ?? 0), 2, ',', '.') }} {{ $currency }}</div>
            </div>
            <div class="timeline-item">
                <strong>Limite giornaliero</strong>
                <div class="table-muted">Massimo spendibile nel giorno corrente.</div>
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['daily_transaction_limit'] !== null ? number_format($effectiveTransferLimits['daily_transaction_limit'], 2, ',', '.') . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['daily_transaction_limit'] !== null ? number_format($defaultTransferLimits['daily_transaction_limit'], 2, ',', '.') . ' ' . $currency : 'non impostato' }}</div>
            </div>
            <div class="timeline-item">
                <strong>Limite mensile</strong>
                <div class="table-muted">Massimo spendibile nel mese corrente.</div>
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['monthly_transaction_limit'] !== null ? number_format($effectiveTransferLimits['monthly_transaction_limit'], 2, ',', '.') . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['monthly_transaction_limit'] !== null ? number_format($defaultTransferLimits['monthly_transaction_limit'], 2, ',', '.') . ' ' . $currency : 'non impostato' }}</div>
            </div>
            <div class="timeline-item">
                <strong>Limite per movimento</strong>
                <div class="table-muted">Massimo spendibile per singola operazione.</div>
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['per_movement_limit'] !== null ? number_format($effectiveTransferLimits['per_movement_limit'], 2, ',', '.') . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['per_movement_limit'] !== null ? number_format($defaultTransferLimits['per_movement_limit'], 2, ',', '.') . ' ' . $currency : 'non impostato' }}</div>
            </div>
        </div>
    </section>

    <section class="card light-card" style="margin-bottom:22px;">
        <div class="section-head">
            <div>
                <span class="eyebrow">Mappa conti</span>
                <h3 class="section-title">Conti collegati</h3>
            </div>
            <span class="pill">{{ $accounts->count() }}</span>
        </div>

        @if ($accounts->isEmpty())
            <div class="empty-state">Nessun conto collegato al profilo selezionato.</div>
        @else
            <div class="timeline-list">
                @foreach ($accounts as $account)
                    <article class="timeline-item">
                        <div class="entity-head">
                            <div>
                                <strong>{{ $account->display_name }}</strong>
                                <div class="table-muted">{{ $account->ownerLabel }}{{ $account->parentAccount?->display_name ? ' · padre ' . $account->parentAccount->display_name : '' }}</div>
                                <div class="table-muted">{{ strtoupper($account->type) }} · {{ $account->currency_code }}</div>
                            </div>
                            <div class="entity-meta">
                                <span class="chip {{ $account->status === 'active' ? 'success' : 'pink' }}">{{ $account->status === 'active' ? 'Attivo' : ($account->status === 'suspended' ? 'Sospeso' : $account->status) }}</span>
                                <span class="chip">{{ $account->owner_type }}</span>
                            </div>
                        </div>
                        <div class="field-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;">
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Saldo</div><strong>{{ number_format($account->available_balance, 2, ',', '.') }} {{ $account->currency_code }}</strong></div>
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Pending</div><strong>{{ number_format($account->pending_balance, 2, ',', '.') }} {{ $account->currency_code }}</strong></div>
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Limite singolo conto</div><strong>{{ $account->spending_limit ? number_format($account->spending_limit, 2, ',', '.') . ' ' . $account->currency_code : 'non impostato' }}</strong></div>
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Limite giornaliero conto</div><strong>{{ $account->daily_outgoing_limit ? number_format($account->daily_outgoing_limit, 2, ',', '.') . ' ' . $account->currency_code : 'non impostato' }}</strong></div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="card light-card" id="user-movements" style="margin-bottom:22px;">
        <div class="section-head">
            <div>
                <span class="eyebrow">Registro movimenti</span>
                <h3 class="section-title">Tutti i movimenti dell'utente</h3>
            </div>
            <span class="pill">{{ $movementFilters['label'] }}</span>
        </div>

        <form method="get" action="{{ route('admin.users.show', $userRecord) }}" class="field-grid" style="margin-bottom:18px;">
            <div class="field-grid" style="grid-template-columns:220px 1fr 1fr auto;gap:14px;align-items:end;">
                <div class="field">
                    <label>Periodo</label>
                    <select name="period">
                        @foreach ($movementPeriodOptions as $value => $label)
                            <option value="{{ $value }}" @selected($movementFilters['period'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Da data</label>
                    <input type="date" name="from_date" value="{{ $movementFilters['from_date'] }}">
                </div>
                <div class="field">
                    <label>A data</label>
                    <input type="date" name="to_date" value="{{ $movementFilters['to_date'] }}">
                </div>
                <div class="form-actions" style="margin-top:0;">
                    <button type="submit" class="cta secondary">Filtra</button>
                </div>
            </div>
        </form>

        @if ($transfers->isEmpty())
            <div class="empty-state">Nessun movimento rilevato sui conti associati per il periodo selezionato.</div>
        @else
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Movimento</th>
                            <th>Da</th>
                            <th>A</th>
                            <th>Importo</th>
                            <th>Operatore</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transfers as $transfer)
                            <tr>
                                <td>{{ $transfer->booked_at?->format('d/m/Y H:i') ?? 'N/D' }}</td>
                                <td>
                                    <strong>{{ $transfer->reference }}</strong>
                                    <div class="table-muted">{{ match ($transfer->kind) { 'portal_payment' => 'Pagamento da portale', 'portal_collection' => 'Incasso da portale', 'trade_payment' => 'Pagamento commerciale', 'admin_refund' => 'Storno amministrativo', default => $transfer->kind ? ucfirst(str_replace('_', ' ', $transfer->kind)) : 'Movimento', } }}</div>
                                    <div class="table-muted">{{ match ($transfer->status) { 'booked' => 'Contabilizzato', 'pending' => 'In elaborazione', 'rejected' => 'Respinto', default => ucfirst(str_replace('_', ' ', $transfer->status ?? 'N/D')), } }}</div>
                                </td>
                                <td>
                                    <strong>{{ $transfer->fromAccount?->display_name ?? 'N/D' }}</strong>
                                    <div class="table-muted">{{ $transfer->fromAccount?->ownerLabel ?? 'N/D' }}</div>
                                </td>
                                <td>
                                    <strong>{{ $transfer->toAccount?->display_name ?? 'N/D' }}</strong>
                                    <div class="table-muted">{{ $transfer->toAccount?->ownerLabel ?? 'N/D' }}</div>
                                </td>
                                <td>
                                    <strong>{{ number_format($transfer->amount, 2, ',', '.') }} {{ $transfer->currency_code }}</strong>
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

    <section class="card light-card" id="user-password" style="margin-bottom:22px;">
        <div class="section-head">
            <div>
                <span class="eyebrow">Sicurezza account</span>
                <h3 class="section-title">Cambia password</h3>
            </div>
        </div>
        <form method="post" action="{{ route('admin.users.password', $userRecord) }}" class="field-grid" style="max-width:480px;">
            @csrf
            <div class="field">
                <label>Nuova password</label>
                <div class="pw-wrap">
                    <input name="new_password" type="password" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="field">
                <label>Conferma nuova password</label>
                <div class="pw-wrap">
                    <input name="new_password_confirmation" type="password" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="cta" onclick="return confirm('Impostare la nuova password per {{ addslashes($userRecord->name) }}?')">Imposta password</button>
            </div>
        </form>
    </section>

    <section class="card light-card" id="user-update">
        <div class="section-head">
            <div>
                <span class="eyebrow">Aggiornamento backoffice</span>
                <h3 class="section-title">Aggiorna utente</h3>
            </div>
            <span class="pill">{{ $roles->count() }} ruoli</span>
        </div>

        <form method="post" action="{{ route('admin.users.update', $userRecord) }}" class="field-grid">
            @csrf
            <div class="field-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;">
                <div class="field"><label>Telefono</label><input name="phone" type="text" value="{{ old('phone', $userRecord->phone) }}"></div>
                <div class="field"><label>Azienda</label><select name="company_id"><option value="">Nessuna</option>@foreach ($companies as $company)<option value="{{ $company->id }}" @selected((int) old('company_id', $userRecord->company_id) === $company->id)>{{ $company->name }}</option>@endforeach</select></div>
                <div class="field"><label>Sottoconto gestito</label><input name="managed_account_id" type="number" min="1" value="{{ old('managed_account_id', $userRecord->managed_account_id) }}"></div>
                <div class="field"><label>Stato</label><select name="is_active"><option value="1" @selected((string) old('is_active', $userRecord->is_active ? '1' : '0') === '1')>Attivo</option><option value="0" @selected((string) old('is_active', $userRecord->is_active ? '1' : '0') === '0')>Disattivo</option></select></div>
            </div>
            <div class="field"><label>Etichetta interna</label><input name="role_label" type="text" value="{{ old('role_label', $userRecord->role) }}"></div>

            <div class="field-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;">
                <div class="field"><label>Disponibilita commerciale personalizzata</label><input name="circuit_capacity_limit" type="number" min="0" value="{{ old('circuit_capacity_limit', $userRecord->circuit_capacity_limit) }}"><div class="table-muted">Lascia vuoto per usare il default admin.</div></div>
                <div class="field"><label>Massimale / fido personalizzato</label><input name="negative_balance_limit" type="number" min="0" value="{{ old('negative_balance_limit', $userRecord->negative_balance_limit) }}"><div class="table-muted">Importo del fido. `0` blocca il negativo, vuoto usa il default.</div></div>
                @if ($primaryAccount)
                    <div class="field"><label>Saldo massimo conto principale</label><input name="primary_account_max_balance" type="number" min="0" value="{{ old('primary_account_max_balance', $primaryAccount->max_balance) }}" placeholder="Vuoto = nessun limite"><div class="table-muted">Tetto positivo del conto mostrato in dashboard come “Saldo massimo”. Questo valore vive sul conto, non sull'utente.</div></div>
                @endif
                <div class="field"><label>Limite giornaliero personalizzato</label><input name="daily_transaction_limit" type="number" min="0" value="{{ old('daily_transaction_limit', $userRecord->daily_transaction_limit) }}"></div>
                <div class="field"><label>Limite mensile personalizzato</label><input name="monthly_transaction_limit" type="number" min="0" value="{{ old('monthly_transaction_limit', $userRecord->monthly_transaction_limit) }}"></div>
                <div class="field"><label>Limite per movimento personalizzato</label><input name="per_movement_limit" type="number" min="0" value="{{ old('per_movement_limit', $userRecord->per_movement_limit) }}"></div>
            </div>

            <div class="field"><label>Ruoli</label><div class="role-grid">@foreach ($roles as $role)<label class="check-tile"><input class="check-mark" type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(collect(old('roles', $userRecord->roles->pluck('id')->all()))->contains($role->id))><span class="check-tile-copy"><span class="check-tile-head"><strong>{{ $role->name }}</strong><span class="check-tile-meta">{{ strtoupper($role->scope) }}</span></span><span class="subtle">{{ $role->description ?: 'Ruolo operativo senza descrizione estesa.' }}</span><span class="perm-badges">@forelse ($role->permissions->take(4) as $permission)<span class="perm-badge">{{ str_replace('.', ' · ', $permission->slug) }}</span>@empty<span class="perm-empty">Nessun permesso collegato</span>@endforelse @if ($role->permissions->count() > 4)<span class="perm-more">+{{ $role->permissions->count() - 4 }} altri</span>@endif</span></span></label>@endforeach</div></div>
            <div class="form-actions"><button type="submit" class="cta">Salva modifiche</button></div>
        </form>
    </section>
@endsection
