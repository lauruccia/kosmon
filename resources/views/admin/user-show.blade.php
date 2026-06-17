@extends('layouts.portal')

@push('head')
<style>
/* ── Admin user-update form ────────────────────────────────────── */
#user-update { padding: 0; }
.uu-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 24px 16px; border-bottom: 2px solid var(--line);
}
.uu-body { padding: 20px 24px 24px; display: flex; flex-direction: column; gap: 14px; }
.uu-group {
    border: 2px solid var(--line-strong); border-radius: 10px;
    overflow: hidden; background: var(--surface);
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.uu-group-head {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 14px; background: var(--surface-soft);
    border-bottom: 1px solid var(--line);
    font-size: 10px; font-weight: 800; letter-spacing: .09em;
    text-transform: uppercase; color: var(--ink-muted);
}
.uu-group-head svg { opacity: .55; flex-shrink: 0; }
.uu-group-body { padding: 14px 16px; display: grid; gap: 12px; }
.uu-group-body.cols-2 { grid-template-columns: repeat(2, minmax(0,1fr)); }
.uu-group-body.cols-3 { grid-template-columns: repeat(3, minmax(0,1fr)); }
.uu-group-body.cols-4 { grid-template-columns: repeat(4, minmax(0,1fr)); }
.uu-group-body.cols-5 { grid-template-columns: repeat(5, minmax(0,1fr)); }
@media (max-width: 960px) {
    .uu-group-body.cols-4,
    .uu-group-body.cols-5 { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
@media (max-width: 620px) {
    .uu-group-body.cols-2,
    .uu-group-body.cols-3,
    .uu-group-body.cols-4,
    .uu-group-body.cols-5 { grid-template-columns: 1fr; }
}
.uu-field { display: flex; flex-direction: column; gap: 4px; }
.uu-field > label {
    font-size: 10px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .07em; color: var(--ink-muted);
}
.uu-field input[type="text"],
.uu-field input[type="email"],
.uu-field input[type="number"],
.uu-field select {
    width: 100%; padding: 7px 10px;
    border: 1.5px solid var(--line); border-radius: 7px;
    background: var(--surface); color: var(--ink);
    font-size: 13.5px; line-height: 1.4;
    transition: border-color .15s, box-shadow .15s;
}
.uu-field input:focus, .uu-field select:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99,102,241,.15);
}
.uu-hint { font-size: 11px; color: var(--ink-muted); line-height: 1.4; }
.uu-toggle {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 12px; border: 1.5px solid var(--line);
    border-radius: 8px; background: var(--surface-soft);
    cursor: pointer; user-select: none;
}
.uu-toggle input[type="checkbox"] {
    width: 16px; height: 16px; accent-color: var(--primary);
    cursor: pointer; flex-shrink: 0; margin-top: 2px;
}
.uu-toggle.danger { border-color: #f5c6cb; background: #fff5f5; }
.uu-toggle.danger input[type="checkbox"] { accent-color: #c0392b; }
.uu-toggle-text strong { font-size: 13px; color: var(--ink); }
.uu-toggle-text span { display: block; font-size: 11px; color: var(--ink-muted); margin-top: 2px; line-height: 1.4; }
.uu-select-active  { border-color: #27ae6066 !important; background: #f0fff4 !important; }
.uu-select-inactive{ border-color: #e74c3c66 !important; background: #fff5f5 !important; }
</style>
@endpush

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
            <a class="cta secondary" href="#user-sessions">Sessioni</a>
            <a class="cta secondary" href="#user-password">Cambio password</a>
            <a class="cta secondary" href="#user-update">Aggiorna utente</a>
            <a class="cta" href="#user-movements">Movimenti filtrati</a>
        </div>
    </section>

    <section class="hero-strip" style="margin-bottom:22px;">
        <article class="stat-card">
            <div class="eyebrow">Saldo disponibile</div>
            <div class="section-title" style="font-size:34px;">{{ ky_format($balances['available']) }} {{ $currency }}</div>
            <div class="table-muted">Somma di tutti i conti collegati</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Saldo pending</div>
            <div class="section-title" style="font-size:34px;">{{ ky_format($balances['pending']) }} {{ $currency }}</div>
            <div class="table-muted">Transazioni non ancora consolidate</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Incassato filtrato</div>
            <div class="section-title" style="font-size:34px;">{{ ky_format($balances['incoming']) }} {{ $currency }}</div>
            <div class="table-muted">Entrate nel periodo selezionato</div>
        </article>
        <article class="stat-card">
            <div class="eyebrow">Speso filtrato</div>
            <div class="section-title" style="font-size:34px;">{{ ky_format($balances['outgoing']) }} {{ $currency }}</div>
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
                <div>
                    <div class="eyebrow">Email</div>
                    <strong>{{ $userRecord->email }}</strong>
                    @if ($userRecord->hasVerifiedEmail())
                        <span class="pill success" style="margin-left:.5rem;">Verificata</span>
                    @else
                        <span class="pill warn" style="margin-left:.5rem;">Non verificata</span>
                        <form method="post" action="{{ route('admin.users.verify-email', $userRecord) }}" style="margin-top:.5rem;"
                              onsubmit="return confirm('Verificare manualmente l\'email e attivare questo utente?');">
                            @csrf
                            <button type="submit" class="cta secondary users-compact-cta">Verifica email e attiva</button>
                        </form>
                    @endif
                </div>
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
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Disponibile</div><strong>{{ ky_format($primaryAccount->available_balance) }} {{ $primaryAccount->currency_code }}</strong></div>
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Pending</div><strong>{{ ky_format($primaryAccount->pending_balance) }} {{ $primaryAccount->currency_code }}</strong></div>
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Limite giornaliero conto</div><strong>{{ $primaryAccount->daily_outgoing_limit ? ky_format($primaryAccount->daily_outgoing_limit) . ' ' . $primaryAccount->currency_code : 'non impostato' }}</strong></div>
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
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['circuit_capacity_limit'] !== null ? ky_format($effectiveTransferLimits['circuit_capacity_limit']) . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['circuit_capacity_limit'] !== null ? ky_format($defaultTransferLimits['circuit_capacity_limit']) . ' ' . $currency : 'non impostato' }}</div>
            </div>
            <div class="timeline-item">
                <strong>Massimale</strong>
                <div class="table-muted">Linea di credito spendibile sul conto.</div>
                <div class="section-title" style="font-size:22px;">-{{ ky_format((int) ($effectiveTransferLimits['negative_balance_limit'] ?? 0)) }} {{ $currency }}</div>
                <div class="table-muted">Default admin: -{{ ky_format((int) ($defaultTransferLimits['negative_balance_limit'] ?? 0)) }} {{ $currency }}</div>
            </div>
            <div class="timeline-item">
                <strong>Limite giornaliero</strong>
                <div class="table-muted">Massimo spendibile nel giorno corrente.</div>
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['daily_transaction_limit'] !== null ? ky_format($effectiveTransferLimits['daily_transaction_limit']) . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['daily_transaction_limit'] !== null ? ky_format($defaultTransferLimits['daily_transaction_limit']) . ' ' . $currency : 'non impostato' }}</div>
            </div>
            <div class="timeline-item">
                <strong>Limite mensile</strong>
                <div class="table-muted">Massimo spendibile nel mese corrente.</div>
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['monthly_transaction_limit'] !== null ? ky_format($effectiveTransferLimits['monthly_transaction_limit']) . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['monthly_transaction_limit'] !== null ? ky_format($defaultTransferLimits['monthly_transaction_limit']) . ' ' . $currency : 'non impostato' }}</div>
            </div>
            <div class="timeline-item">
                <strong>Limite per movimento</strong>
                <div class="table-muted">Massimo spendibile per singola operazione.</div>
                <div class="section-title" style="font-size:22px;">{{ $effectiveTransferLimits['per_movement_limit'] !== null ? ky_format($effectiveTransferLimits['per_movement_limit']) . ' ' . $currency : 'Non impostato' }}</div>
                <div class="table-muted">Default admin: {{ $defaultTransferLimits['per_movement_limit'] !== null ? ky_format($defaultTransferLimits['per_movement_limit']) . ' ' . $currency : 'non impostato' }}</div>
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
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Saldo</div><strong>{{ ky_format($account->available_balance) }} {{ $account->currency_code }}</strong></div>
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Pending</div><strong>{{ ky_format($account->pending_balance) }} {{ $account->currency_code }}</strong></div>
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Limite singolo conto</div><strong>{{ $account->spending_limit ? ky_format($account->spending_limit) . ' ' . $account->currency_code : 'non impostato' }}</strong></div>
                            <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Limite giornaliero conto</div><strong>{{ $account->daily_outgoing_limit ? ky_format($account->daily_outgoing_limit) . ' ' . $account->currency_code : 'non impostato' }}</strong></div>
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

    {{-- ===== SESSIONI ATTIVE + STORICO ACCESSI ===== --}}
    <section class="card light-card" id="user-sessions" style="margin-bottom:22px;">
        <div class="section-head" style="margin-bottom:18px;">
            <div>
                <span class="eyebrow">Sicurezza</span>
                <h3 class="section-title">Sessioni attive</h3>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                @if($activeSessions->count() > 0)
                    <span class="pill {{ $activeSessions->count() > 1 ? 'warn' : 'success' }}">
                        {{ $activeSessions->count() }} {{ $activeSessions->count() === 1 ? 'sessione' : 'sessioni' }}
                    </span>
                @endif
                @if($activeSessions->count() > 0)
                <form method="POST" action="{{ route('admin.users.sessions.terminate-all', $userRecord) }}"
                      onsubmit="return confirm('Terminare TUTTE le sessioni attive di {{ $userRecord->name }}?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="cta danger" style="font-size:12px;padding:6px 14px;">
                        Disconnetti tutti
                    </button>
                </form>
                @endif
            </div>
        </div>

        @if($activeSessions->isEmpty())
            <p class="table-muted" style="padding:16px 0;">Nessuna sessione attiva per questo utente.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:24px;">
                @foreach($activeSessions as $session)
                @php $holderType = $userRecord->account_holder_type; @endphp

        <div class=”uu-header”>
            <div>
                <span class=”eyebrow”>Aggiornamento backoffice</span>
                <h3 class=”section-title” style=”margin:2px 0 0;”>Modifica utente</h3>
            </div>
            <span class=”pill”>{{ $roles->count() }} ruoli disponibili</span>
        </div>

        <div class=”uu-body”>
        <form method=”post” action=”{{ route('admin.users.update', $userRecord) }}”
              id=”form-aggiorna-utente”
              onsubmit=”return adminUpdateConfirm(this)”>
            @csrf

            {{-- ── 1. Anagrafica ────────────────────────────────────────────────── --}}
            <div class=”uu-group”>
                <div class=”uu-group-head”>
                    <svg width=”13” height=”13” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2.5”><circle cx=”12” cy=”8” r=”4”/><path d=”M4 20c0-4 3.6-7 8-7s8 3 8 7”/></svg>
                    Anagrafica
                </div>
                <div class=”uu-group-body cols-3”>
                    <div class=”uu-field”>
                        <label>Nome completo</label>
                        <input name=”name” type=”text” required
                               value=”{{ old('name', trim((string)($userRecord->name ?? ''), '"')) }}”
                               placeholder=”Nome e cognome”>
                    </div>
                    <div class=”uu-field”>
                        <label>Indirizzo email</label>
                        <input name=”email” type=”email” required
                               value=”{{ old('email', trim((string)($userRecord->email ?? ''), '"')) }}”
                               data-original-email=”{{ trim((string)($userRecord->email ?? ''), '"') }}”
                               placeholder=”email@esempio.it”>
                        <span class=”uu-hint”>Modificare reimposta la verifica email.</span>
                    </div>
                    <div class=”uu-field”>
                        <label>Telefono</label>
                        <input name=”phone” type=”text”
                               value=”{{ old('phone', trim((string)($userRecord->phone ?? ''), '"')) }}”
                               placeholder=”+39 000 0000000”>
                    </div>
                </div>
            </div>

            {{-- ── 2. Classificazione ───────────────────────────────────────────── --}}
            <div class=”uu-group”>
                <div class=”uu-group-head”>
                    <svg width=”13” height=”13” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2.5”><rect x=”2” y=”7” width=”20” height=”14” rx=”2”/><path d=”M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2”/></svg>
                    Classificazione
                </div>
                <div class=”uu-group-body cols-4”>
                    <div class=”uu-field”>
                        <label>Tipologia</label>
                        <select name=”account_holder_type” id=”tipologia-select”
                                onchange=”toggleAziendaField(this.value)”>
                            <option value=”company” @selected(old('account_holder_type', $holderType) === 'company')>Azienda</option>
                            <option value=”private” @selected(old('account_holder_type', $holderType) === 'private')>Privato</option>
                        </select>
                    </div>
                    <div class=”uu-field” id=”field-azienda-collegata”>
                        <label>Azienda collegata</label>
                        <select name=”company_id”>
                            <option value=””>— Nessuna —</option>
                            @foreach ($companies as $company)
                                <option value=”{{ $company->id }}”
                                        @selected((int) old('company_id', $userRecord->company_id) === $company->id)>
                                    {{ $company->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class=”uu-field”>
                        <label>Sottoconto gestito (ID)</label>
                        <input name=”managed_account_id” type=”number” min=”1”
                               value=”{{ old('managed_account_id', $userRecord->managed_account_id) }}”
                               placeholder=”Vuoto se non applicabile”>
                    </div>
                    <div class=”uu-field”>
                        <label>Etichetta interna</label>
                        <input name=”role_label” type=”text”
                               value=”{{ old('role_label', trim((string)($userRecord->role ?? ''), '"')) }}”
                               placeholder=”owner, manager, operatore…”>
                    </div>
                </div>
            </div>

            {{-- ── 3. Stato e accessi ───────────────────────────────────────────── --}}
            <div class=”uu-group”>
                <div class=”uu-group-head”>
                    <svg width=”13” height=”13” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2.5”><rect x=”3” y=”11” width=”18” height=”11” rx=”2”/><path d=”M7 11V7a5 5 0 0 1 10 0v4”/></svg>
                    Stato e accessi
                </div>
                <div class=”uu-group-body cols-2”>
                    <div class=”uu-field”>
                        <label>Stato account</label>
                        <select name=”is_active” id=”stato-select”
                                onchange=”updateStatusStyle(this)”>
                            <option value=”1” @selected((string) old('is_active', $userRecord->is_active ? '1' : '0') === '1')>Attivo — accesso consentito</option>
                            <option value=”0” @selected((string) old('is_active', $userRecord->is_active ? '1' : '0') === '0')>Disattivo — accesso bloccato</option>
                        </select>
                    </div>
                    <div class=”uu-field”>
                        <label>Privilegi speciali</label>
                        <label class=”uu-toggle danger”>
                            <input type=”checkbox” name=”is_super_admin” value=”1”
                                   @checked(old('is_super_admin', $userRecord->is_super_admin))>
                            <div class=”uu-toggle-text”>
                                <strong>Super Admin</strong>
                                <span>Accesso illimitato a tutto il backoffice — assegnare con cautela.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            {{-- ── 4. Limiti transazionali ──────────────────────────────────────── --}}
            <div class=”uu-group”>
                <div class=”uu-group-head”>
                    <svg width=”13” height=”13” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2.5”><line x1=”12” y1=”1” x2=”12” y2=”23”/><path d=”M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6”/></svg>
                    Limiti transazionali personalizzati (KY) &mdash; lascia vuoto per usare il default admin
                </div>
                <div class=”uu-group-body cols-5”>
                    <div class=”uu-field”>
                        <label>Disponibilità commerciale</label>
                        <input name=”circuit_capacity_limit” type=”number” min=”0” step=”0.01”
                               value=”{{ old('circuit_capacity_limit', ky_input($userRecord->circuit_capacity_limit)) }}”
                               placeholder=”Default admin”>
                        <span class=”uu-hint”>Tetto massimo acquistabile.</span>
                    </div>
                    <div class=”uu-field”>
                        <label>Massimale / fido</label>
                        <input name=”negative_balance_limit” type=”number” min=”0” step=”0.01”
                               value=”{{ old('negative_balance_limit', ky_input($userRecord->negative_balance_limit)) }}”
                               placeholder=”Default admin”>
                        <span class=”uu-hint”><code>0</code> = nessun fido.</span>
                    </div>
                    <div class=”uu-field”>
                        <label>Limite giornaliero</label>
                        <input name=”daily_transaction_limit” type=”number” min=”0” step=”0.01”
                               value=”{{ old('daily_transaction_limit', ky_input($userRecord->daily_transaction_limit)) }}”
                               placeholder=”Default admin”>
                    </div>
                    <div class=”uu-field”>
                        <label>Limite mensile</label>
                        <input name=”monthly_transaction_limit” type=”number” min=”0” step=”0.01”
                               value=”{{ old('monthly_transaction_limit', ky_input($userRecord->monthly_transaction_limit)) }}”
                               placeholder=”Default admin”>
                    </div>
                    <div class=”uu-field”>
                        <label>Limite per movimento</label>
                        <input name=”per_movement_limit” type=”number” min=”0” step=”0.01”
                               value=”{{ old('per_movement_limit', ky_input($userRecord->per_movement_limit)) }}”
                               placeholder=”Default admin”>
                    </div>
                </div>
            </div>

            {{-- ── 5. Conto principale ──────────────────────────────────────────── --}}
            @if ($primaryAccount)
            <div class=”uu-group”>
                <div class=”uu-group-head”>
                    <svg width=”13” height=”13” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2.5”><rect x=”2” y=”5” width=”20” height=”14” rx=”2”/><line x1=”2” y1=”10” x2=”22” y2=”10”/></svg>
                    Conto principale &mdash; {{ $primaryAccount->display_name ?? $primaryAccount->uuid }}
                </div>
                <div class=”uu-group-body cols-2”>
                    <div class=”uu-field”>
                        <label>Saldo massimo (KY)</label>
                        <input name=”primary_account_max_balance” type=”number” min=”0” step=”0.01”
                               value=”{{ old('primary_account_max_balance', ky_input($primaryAccount->max_balance)) }}”
                               placeholder=”Vuoto = nessun tetto”>
                        <span class=”uu-hint”>Tetto positivo del conto. Vive sul conto, non sull'utente.</span>
                    </div>
                    <div class=”uu-field”>
                        <label>Fido / saldo negativo</label>
                        <label class=”uu-toggle”>
                            <input type=”checkbox” name=”primary_account_allow_negative” value=”1”
                                   @checked(old('primary_account_allow_negative', $primaryAccount->allow_negative_balance))>
                            <div class=”uu-toggle-text”>
                                <strong>Consenti saldo negativo</strong>
                                <span>Il conto può scendere sotto zero (linea di credito attiva).</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            @endif

            {{-- ── 6. Ruoli ─────────────────────────────────────────────────────── --}}
            <div class=”uu-group”>
                <div class=”uu-group-head”>
                    <svg width=”13” height=”13” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2.5”><path d=”M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2”/><circle cx=”9” cy=”7” r=”4”/><path d=”M23 21v-2a4 4 0 0 0-3-3.87”/><path d=”M16 3.13a4 4 0 0 1 0 7.75”/></svg>
                    Ruoli assegnati
                </div>
                <div style=”padding:16px;”>
                    <div class=”role-grid”>
                        @foreach ($roles as $role)
                            <label class=”check-tile”>
                                <input class=”check-mark” type=”checkbox” name=”roles[]” value=”{{ $role->id }}”
                                       @checked(collect(old('roles', $userRecord->roles->pluck('id')->all()))->contains($role->id))>
                                <span class=”check-tile-copy”>
                                    <span class=”check-tile-head”>
                                        <strong>{{ $role->name }}</strong>
                                        <span class=”check-tile-meta”>{{ strtoupper($role->scope) }}</span>
                                    </span>
                                    <span class=”subtle”>{{ $role->description ?: 'Ruolo operativo senza descrizione estesa.' }}</span>
                                    <span class=”perm-badges”>
                                        @forelse ($role->permissions->take(4) as $permission)
                                            <span class=”perm-badge”>{{ str_replace('.', ' · ', $permission->slug) }}</span>
                                        @empty
                                            <span class=”perm-empty”>Nessun permesso collegato</span>
                                        @endforelse
                                        @if ($role->permissions->count() > 4)
                                            <span class=”perm-more”>+{{ $role->permissions->count() - 4 }} altri</span>
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class=”form-actions”>
                <button type=”submit” class=”cta”>Salva modifiche</button>
            </div>
        </form>
        </div>{{-- /.uu-body --}}

        <script>
        function toggleAziendaField(tipo) {
            var el = document.getElementById('field-azienda-collegata');
            if (!el) return;
            if (tipo === 'private') {
                el.style.visibility = 'hidden'; el.style.opacity = '0'; el.style.pointerEvents = 'none';
                var sel = el.querySelector('select');
                if (sel) sel.disabled = true;
            } else {
                el.style.visibility = ''; el.style.opacity = ''; el.style.pointerEvents = '';
                var sel = el.querySelector('select');
                if (sel) sel.disabled = false;
            }
        }
        function updateStatusStyle(sel) {
            sel.className = sel.value === '1' ? 'uu-status-active' : 'uu-status-inactive';
        }
        document.addEventListener('DOMContentLoaded', function () {
            var t = document.getElementById('tipologia-select');
            if (t) toggleAziendaField(t.value);
            var s = document.getElementById('stato-select');
            if (s) updateStatusStyle(s);
        });

        function adminUpdateConfirm(form) {
            var warnings = [];
            var emailInput = form.querySelector('[name=”email”]');
            if (emailInput && emailInput.value !== emailInput.dataset.originalEmail) {
                warnings.push('⚠️ Stai cambiando l\'email. La verifica email verrà reimpostata.');
            }
            var isActive = form.querySelector('[name=”is_active”]');
            if (isActive && isActive.value === '0') {
                warnings.push('⚠️ Stai DISATTIVANDO l\'account. L\'utente non potrà più accedere.');
            }
            var isSA = form.querySelector('input[type=”checkbox”][name=”is_super_admin”]');
            if (isSA && isSA.checked) {
                warnings.push('⚠️ Stai assegnando i privilegi SUPER ADMIN. Avrà accesso illimitato al backoffice.');
            }
            if (warnings.length > 0) {
                return confirm(warnings.join('\n\n') + '\n\nConfermi le modifiche?');
            }
            return true;
        }
        </script>
    </section>
@endsection
