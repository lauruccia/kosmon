@extends('layouts.portal')

@section('content')
<div class="form-split">
    {{-- ─── COLONNA SINISTRA: struttura conti ─────────────────────────── --}}
    <div class="stack">

        {{-- Stato inviti in attesa (model B) --}}
        @if ($pendingAssignments->isNotEmpty())
            <section class="card light-card" style="border-left: 4px solid #f59e0b;">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Richieste in attesa</span>
                        <h3 class="section-title">Sottoconti assegnati da accettare</h3>
                    </div>
                </div>
                @foreach ($pendingAssignments as $assignment)
                    <div class="timeline-item" style="margin-bottom:12px;">
                        <strong>{{ $assignment->account->account_name }}</strong>
                        <div class="table-muted">
                            Di: {{ $assignment->account->parentAccount?->company?->name ?? '—' }}
                        </div>
                        <div style="display:flex;gap:8px;margin-top:8px;">
                            <form method="POST" action="{{ route('subaccount.invitation.accept-existing', $assignment->account_id) }}">
                                @csrf
                                <button type="submit" class="cta" style="padding:6px 14px;font-size:13px;">Accetta</button>
                            </form>
                            <form method="POST" action="{{ route('portal.accounts.subaccounts.decline', $assignment->account_id) }}">
                                @csrf
                                <button type="submit" class="cta secondary" style="padding:6px 14px;font-size:13px;">Rifiuta</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- Conto radice --}}
        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Conto radice</span>
                    <h3 class="section-title">{{ $rootAccount->display_name }}</h3>
                </div>
                <span class="pill {{ $rootAccount->owner_type === 'private' ? 'success' : '' }}">
                    {{ $rootAccount->owner_type === 'private' ? 'Privato' : 'Azienda' }}
                </span>
            </div>
            <div class="hero-strip">
                <article class="stat-card">
                    <div class="section-icon">BL</div>
                    <div><div class="eyebrow">Saldo</div><strong>{{ number_format($rootAccount->available_balance, 2, ',', '.') }}</strong><div class="subtle">KY disponibili</div></div>
                </article>
                <article class="stat-card">
                    <div class="section-icon">SC</div>
                    <div><div class="eyebrow">Sottoconti</div><strong>{{ $subaccounts->count() }}</strong><div class="subtle">totale configurati</div></div>
                </article>
                <article class="stat-card">
                    <div class="section-icon">AT</div>
                    <div><div class="eyebrow">Attivi</div><strong>{{ $subaccounts->where('status', 'active')->count() }}</strong><div class="subtle">pronti a spendere</div></div>
                </article>
                <article class="stat-card">
                    <div class="section-icon">DG</div>
                    <div><div class="eyebrow">Delegati</div><strong>{{ $subaccounts->sum(fn($s) => $s->managers->count()) }}</strong><div class="subtle">utenti assegnati</div></div>
                </article>
            </div>
        </section>

        {{-- Selettore conto attivo --}}
        @if ($switchableAccounts->count() > 1)
            <section class="card light-card">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Accesso rapido</span>
                        <h3 class="section-title">Cambia conto attivo</h3>
                    </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    @foreach ($switchableAccounts as $switchable)
                        <form method="POST" action="{{ route('portal.switch-account') }}">
                            @csrf
                            <input type="hidden" name="account_id" value="{{ $switchable->id }}">
                            <button type="submit"
                                class="cta {{ ($activeAccountId ?? null) == $switchable->id ? '' : 'secondary' }}"
                                style="padding:7px 14px;font-size:13px;">
                                {{ $switchable->account_name ?? $switchable->display_name }}
                                @if ($switchable->isSubAccount())
                                    <span class="chip" style="margin-left:4px;font-size:11px;">sub</span>
                                @endif
                            </button>
                        </form>
                    @endforeach
                    @if ($activeAccountId)
                        <form method="POST" action="{{ route('portal.switch-account') }}">
                            @csrf
                            <input type="hidden" name="account_id" value="0">
                            <button type="submit" class="cta secondary" style="padding:7px 14px;font-size:13px;">Torna al conto principale</button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        {{-- Lista sottoconti --}}
        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Roster</span>
                    <h3 class="section-title">Sottoconti esistenti</h3>
                </div>
            </div>
            @if ($subaccounts->isEmpty())
                <div class="empty-state">
                    <strong>Nessun sottoconto creato.</strong>
                    <p>Il primo sottoconto puo rappresentare un figlio, un dipendente, un reparto o un delegato operativo.</p>
                </div>
            @else
                <div class="timeline-list">
                    @foreach ($subaccounts as $subaccount)
                        <article class="timeline-item lifecycle-card {{ $subaccount->status !== 'active' ? 'is-suspended' : '' }}">
                            <div class="entity-head">
                                <div>
                                    <strong>{{ $subaccount->display_name }}</strong>
                                    <div class="table-muted">
                                        Gestori attivi: {{ $subaccount->managers->pluck('name')->implode(', ') ?: 'Nessuno' }}
                                    </div>
                                </div>
                                <span class="chip {{ $subaccount->status === 'active' ? 'success' : 'pink' }}">
                                    {{ $subaccount->status === 'active' ? 'Attivo' : 'Sospeso' }}
                                </span>
                            </div>

                            <div class="entity-meta">
                                <span class="chip">Singolo: {{ $subaccount->spending_limit ? number_format($subaccount->spending_limit, 2, ',', '.') . ' KY' : 'illimitato' }}</span>
                                <span class="chip">Giorno: {{ $subaccount->daily_outgoing_limit ? number_format($subaccount->daily_outgoing_limit, 2, ',', '.') . ' KY' : 'illimitato' }}</span>
                                <span class="chip">Mese: {{ $subaccount->monthly_outgoing_limit ? number_format($subaccount->monthly_outgoing_limit, 2, ',', '.') . ' KY' : 'illimitato' }}</span>
                                <span class="chip">{{ $subaccount->managers->count() }} gestori</span>
                            </div>

                            {{-- Inviti pendenti --}}
                            @if ($subaccount->activeInvitations->isNotEmpty())
                                <div class="mini-list" style="margin-top:8px;">
                                    @foreach ($subaccount->activeInvitations as $inv)
                                        <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;">
                                            <small style="color:#f59e0b;">
                                                <strong>Invito pendente:</strong> {{ $inv->email }}
                                                (scade {{ $inv->expires_at->format('d/m') }})
                                            </small>
                                            @if ($canManageSubaccounts)
                                                <form method="POST" action="{{ route('portal.accounts.subaccounts.invite.cancel', [$subaccount, $inv]) }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="cta secondary" style="padding:4px 10px;font-size:12px;">Annulla</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($canManageSubaccounts)
                                {{-- Invita gestore --}}
                                <div class="tile-grid lifecycle-actions">
                                    <section class="section-panel">
                                        <span class="eyebrow">Invita gestore</span>
                                        <form method="POST"
                                              action="{{ route('portal.accounts.subaccounts.invite', $subaccount) }}"
                                              class="field-grid">
                                            @csrf
                                            <div class="field-inline">
                                                <div class="field">
                                                    <input name="email" type="email" placeholder="email@esempio.com"
                                                           required style="min-width:180px;">
                                                </div>
                                            </div>
                                            <div class="form-actions">
                                                <button type="submit" class="cta">Invia invito</button>
                                            </div>
                                        </form>
                                    </section>

                                    <section class="section-panel">
                                        <span class="eyebrow">Modifica limiti</span>
                                        <form method="POST"
                                              action="{{ route('portal.accounts.subaccounts.limits', $subaccount) }}"
                                              class="field-grid">
                                            @csrf
                                            <div class="field-inline">
                                                <div class="field">
                                                    <input name="spending_limit" type="number" min="1"
                                                           value="{{ $subaccount->spending_limit }}"
                                                           placeholder="Limite singolo KY">
                                                </div>
                                                <div class="field">
                                                    <input name="daily_outgoing_limit" type="number" min="1"
                                                           value="{{ $subaccount->daily_outgoing_limit }}"
                                                           placeholder="Limite giornaliero KY">
                                                </div>
                                                <div class="field">
                                                    <input name="monthly_outgoing_limit" type="number" min="1"
                                                           value="{{ $subaccount->monthly_outgoing_limit }}"
                                                           placeholder="Limite mensile KY">
                                                </div>
                                            </div>
                                            <div class="form-actions">
                                                <button type="submit" class="cta secondary">Salva limiti</button>
                                            </div>
                                        </form>
                                    </section>
                                </div>

                                {{-- Gestori attivi: mostra con opzione revoca --}}
                                @if ($subaccount->managers->isNotEmpty())
                                    <div class="mini-list" style="margin-top:8px;">
                                        @foreach ($subaccount->managers as $mgr)
                                            <div style="display:flex;align-items:center;gap:10px;justify-content:space-between;">
                                                <small>{{ $mgr->name }} — {{ $mgr->email }}</small>
                                                <form method="POST"
                                                      action="{{ route('portal.accounts.subaccounts.revoke', [$subaccount, $mgr]) }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="cta secondary"
                                                            style="padding:4px 10px;font-size:12px;"
                                                            onclick="return confirm('Revocare accesso a {{ $mgr->name }}?')">
                                                        Revoca
                                                    </button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="form-actions" style="justify-content:flex-end;margin-top:12px;">
                                    <form method="POST"
                                          action="{{ route('portal.accounts.subaccounts.status', $subaccount) }}">
                                        @csrf
                                        <input type="hidden" name="status"
                                               value="{{ $subaccount->status === 'active' ? 'suspended' : 'active' }}">
                                        <button type="submit"
                                                class="cta {{ $subaccount->status === 'active' ? 'secondary' : '' }}">
                                            {{ $subaccount->status === 'active' ? 'Sospendi accesso' : 'Riattiva sottoconto' }}
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    {{-- ─── COLONNA DESTRA: crea nuovo sottoconto ──────────────────────── --}}
    <div class="stack">
        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Nuovo sottoconto</span>
                    <h3 class="section-title">Crea e configura</h3>
                </div>
            </div>
            @if (! $canManageSubaccounts)
                <div class="empty-state">
                    <strong>Non puoi creare sottoconti da questo profilo.</strong>
                    <p>Serve il conto proprietario o un profilo con delega gestionale.</p>
                </div>
            @else
                <form method="POST" action="{{ route('portal.accounts.subaccounts.store') }}">
                    @csrf
                    <div class="field-grid">
                        <div class="field">
                            <label for="account_name">Nome sottoconto</label>
                            <input id="account_name" name="account_name" type="text"
                                   value="{{ old('account_name') }}"
                                   placeholder="Marta Vendite, Budget Figlio, Reparto Acquisti"
                                   required>
                        </div>

                        <div class="field-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;">
                            <div class="field">
                                <label for="spending_limit">Limite per operazione (KY)</label>
                                <input id="spending_limit" name="spending_limit" type="number"
                                       min="1" value="{{ old('spending_limit') }}"
                                       placeholder="es. 500">
                            </div>
                            <div class="field">
                                <label for="daily_outgoing_limit">Limite giornaliero (KY)</label>
                                <input id="daily_outgoing_limit" name="daily_outgoing_limit"
                                       type="number" min="1"
                                       value="{{ old('daily_outgoing_limit') }}"
                                       placeholder="es. 1000">
                            </div>
                        </div>

                        <div class="field">
                            <label for="monthly_outgoing_limit">Limite mensile (KY)</label>
                            <input id="monthly_outgoing_limit" name="monthly_outgoing_limit"
                                   type="number" min="1"
                                   value="{{ old('monthly_outgoing_limit') }}"
                                   placeholder="es. 5000 (lascia vuoto = nessun limite)">
                            <span class="subtle" style="font-size:12px;margin-top:4px;display:block;">
                                I pagamenti attingono direttamente dal saldo del conto principale.
                            </span>
                        </div>

                        <div class="field">
                            <label for="manager_email">Email gestore (opzionale)</label>
                            <input id="manager_email" name="manager_email" type="email"
                                   value="{{ old('manager_email') }}"
                                   placeholder="dipendente@esempio.com">
                            <span class="subtle" style="font-size:12px;margin-top:4px;display:block;">
                                Utente esistente: riceve notifica di accesso.
                                Nuovo utente: riceve email con link di registrazione (valido 7 giorni).
                            </span>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="cta">Crea sottoconto</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="card light-card">
            <div class="section-head">
                <div><span class="eyebrow">Come funziona</span><h3 class="section-title">Logica sottoconti</h3></div>
            </div>
            <div class="mini-list">
                <p class="subtle" style="font-size:14px;line-height:1.6;margin:0 0 10px;">
                    <strong>Saldo condiviso:</strong> ogni pagamento dal sottoconto scala direttamente dal saldo del conto principale.
                    Non devi ricaricare manualmente ogni mese.
                </p>
                <p class="subtle" style="font-size:14px;line-height:1.6;margin:0 0 10px;">
                    <strong>Limiti di spesa:</strong> imposta un tetto per singola operazione, giornaliero o mensile.
                    Il sistema rifiuta automaticamente i pagamenti che superano i limiti.
                </p>
                <p class="subtle" style="font-size:14px;line-height:1.6;margin:0;">
                    <strong>Gestori:</strong> un gestore vede solo il suo sottoconto e il saldo disponibile del conto principale.
                    Puoi assegnare piu gestori allo stesso sottoconto o revocare l'accesso in qualsiasi momento.
                </p>
            </div>
        </section>
    </div>
</div>
@endsection
