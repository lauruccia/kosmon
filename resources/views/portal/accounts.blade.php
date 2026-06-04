@extends('layouts.portal')

@section('content')
<div class="form-split">
    {{-- ─── COLONNA SINISTRA: struttura conti ─────────────────────────── --}}
    <div class="stack">

        {{-- ═══ RICHIESTE LIMITE/SFORAMENTO IN ATTESA (per il titolare) ═══ --}}
        @if ($pendingLimitRequests->isNotEmpty())
            <section class="card light-card" style="border-left:4px solid #7c3aed;">
                <div class="section-head">
                    <div>
                        <span class="eyebrow" style="color:#7c3aed;">Richieste da approvare</span>
                        <h3 class="section-title">Richieste dai sottoconti</h3>
                    </div>
                    <span class="chip pink" style="font-size:12px;">{{ $pendingLimitRequests->count() }} in attesa</span>
                </div>
                <div class="timeline-list">
                    @foreach ($pendingLimitRequests as $lr)
                        <article class="timeline-item" style="border-left:3px solid #ede9fe;padding-left:12px;">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                                <div>
                                    <strong style="font-size:14px;">{{ $lr->subAccount->account_name }}</strong>
                                    <span class="chip" style="margin-left:6px;font-size:11px;">{{ $lr->typeLabel() }}</span>
                                    <div class="table-muted" style="margin-top:4px;">
                                        Da: <strong>{{ $lr->requestedBy->name }}</strong>
                                        · Importo: <strong>{{ ky_format($lr->requested_amount) }} KY</strong>
                                        · {{ $lr->created_at->diffForHumans() }}
                                    </div>
                                    <div style="margin-top:6px;font-size:13px;color:var(--ink);background:var(--surface-soft);padding:8px 10px;border-radius:6px;max-width:480px;">
                                        "{{ $lr->reason }}"
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:6px;min-width:160px;">
                                    <form method="POST"
                                          action="{{ route('portal.accounts.subaccounts.limit-request.approve', $lr) }}">
                                        @csrf
                                        <input type="hidden" name="decision_note" value="">
                                        <button type="submit" class="cta"
                                                style="width:100%;font-size:13px;padding:7px 14px;"
                                                onclick="return confirm('Approvare la richiesta di {{ $lr->typeLabel() }} da {{ $lr->subAccount->account_name }}?')">
                                            ✓ Approva
                                        </button>
                                    </form>
                                    <form method="POST"
                                          action="{{ route('portal.accounts.subaccounts.limit-request.reject', $lr) }}">
                                        @csrf
                                        <input type="hidden" name="decision_note" value="">
                                        <button type="submit" class="cta secondary"
                                                style="width:100%;font-size:13px;padding:7px 14px;"
                                                onclick="return confirm('Rifiutare la richiesta?')">
                                            ✗ Rifiuta
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ═══ FORM RICHIESTA LIMITE (per il gestore del sottoconto) ═══ --}}
        @if ($currentAccount->isSubAccount())
            <section class="card light-card" style="border-left:4px solid #7c3aed;">
                <div class="section-head">
                    <div>
                        <span class="eyebrow" style="color:#7c3aed;">Sottoconto: {{ $currentAccount->account_name }}</span>
                        <h3 class="section-title">Richiedi aumento o sforamento</h3>
                    </div>
                </div>
                <p class="subtle" style="font-size:13px;margin:0 0 16px;">
                    Invia una richiesta motivata al titolare del conto. Riceverai una notifica quando verrà approvata o rifiutata.
                </p>
                <form method="POST"
                      action="{{ route('portal.accounts.subaccounts.limit-request.store', $currentAccount) }}">
                    @csrf
                    <div class="field-grid">
                        <div class="field">
                            <label>Tipo di richiesta</label>
                            <select name="type" required
                                    style="border:1px solid var(--line);border-radius:8px;padding:9px 12px;font-size:13px;background:var(--surface-soft);color:var(--ink);width:100%;">
                                <option value="">Seleziona...</option>
                                <option value="spending_limit_increase">Aumento limite per singolo pagamento</option>
                                <option value="daily_limit_increase">Aumento limite giornaliero</option>
                                <option value="monthly_limit_increase">Aumento limite mensile</option>
                                <option value="temporary_overdraft">Sforamento una-tantum (spesa imprevista)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Importo richiesto (KY)</label>
                            <input name="requested_amount" type="number" min="0.01" step="0.01" required
                                   placeholder="es. 500,00">
                            <span class="subtle" style="font-size:12px;margin-top:4px;display:block;">
                                Stesso formato dei limiti: 50000 corrisponde a 500,00 KY.
                            </span>
                        </div>
                        <div class="field">
                            <label>Motivazione</label>
                            <textarea name="reason" rows="3" required minlength="10" maxlength="1000"
                                      placeholder="Descrivi il motivo della richiesta (min. 10 caratteri)..."
                                      style="resize:vertical;min-height:80px;"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="cta">Invia richiesta</button>
                    </div>
                </form>

                {{-- Storico ultime richieste --}}
                @if ($mySubAccountRequests->isNotEmpty())
                    <div style="margin-top:20px;border-top:1px solid var(--line);padding-top:16px;">
                        <span class="eyebrow" style="display:block;margin-bottom:10px;">Ultime richieste inviate</span>
                        <div class="timeline-list">
                            @foreach ($mySubAccountRequests as $req)
                                <div class="timeline-item" style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;">
                                    <div>
                                        <span style="font-size:13px;font-weight:600;">{{ $req->typeLabel() }}</span>
                                        <span class="table-muted" style="margin-left:8px;">{{ ky_format($req->requested_amount) }} KY</span>
                                        <div class="table-muted" style="font-size:12px;margin-top:2px;">{{ $req->created_at->format('d/m/Y H:i') }}</div>
                                    </div>
                                    <span class="chip {{ $req->status === 'approved' ? 'success' : ($req->status === 'rejected' ? 'pink' : '') }}"
                                          style="font-size:11px;">
                                        {{ $req->statusLabel() }}
                                        @if ($req->isApproved() && $req->isTemporaryOverdraft() && $req->isOverdraftUsable())
                                            · scade {{ $req->overdraft_expires_at->format('d/m H:i') }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        @endif

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
                    <div><div class="eyebrow">Saldo</div><strong>{{ ky_format($rootAccount->available_balance) }}</strong><div class="subtle">KY disponibili</div></div>
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
                                <span class="chip">Singolo: {{ $subaccount->spending_limit ? ky_format($subaccount->spending_limit) . ' KY' : 'illimitato' }}</span>
                                <span class="chip">Giorno: {{ $subaccount->daily_outgoing_limit ? ky_format($subaccount->daily_outgoing_limit) . ' KY' : 'illimitato' }}</span>
                                <span class="chip">Mese: {{ $subaccount->monthly_outgoing_limit ? ky_format($subaccount->monthly_outgoing_limit) . ' KY' : 'illimitato' }}</span>
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
                                                    <input name="spending_limit" type="number" min="0.01" step="0.01"
                                                           value="{{ ky_input($subaccount->spending_limit) }}"
                                                           placeholder="Limite singolo KY">
                                                </div>
                                                <div class="field">
                                                    <input name="daily_outgoing_limit" type="number" min="0.01" step="0.01"
                                                           value="{{ ky_input($subaccount->daily_outgoing_limit) }}"
                                                           placeholder="Limite giornaliero KY">
                                                </div>
                                                <div class="field">
                                                    <input name="monthly_outgoing_limit" type="number" min="0.01" step="0.01"
                                                           value="{{ ky_input($subaccount->monthly_outgoing_limit) }}"
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
                                       min="0.01" step="0.01" value="{{ old('spending_limit') }}"
                                       placeholder="es. 50,00">
                            </div>
                            <div class="field">
                                <label for="daily_outgoing_limit">Limite giornaliero (KY)</label>
                                <input id="daily_outgoing_limit" name="daily_outgoing_limit"
                                       type="number" min="0.01" step="0.01"
                                       value="{{ old('daily_outgoing_limit') }}"
                                       placeholder="es. 100,00">
                            </div>
                        </div>

                        <div class="field">
                            <label for="monthly_outgoing_limit">Limite mensile (KY)</label>
                            <input id="monthly_outgoing_limit" name="monthly_outgoing_limit"
                                   type="number" min="0.01" step="0.01"
                                   value="{{ old('monthly_outgoing_limit') }}"
                                   placeholder="es. 500,00 (lascia vuoto = nessun limite)">
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
                <p class="subtle" style="font-size:14px;line-height:1.6;margin:0 0 10px;">
                    <strong>Gestori:</strong> un gestore vede solo il suo sottoconto e il saldo disponibile del conto principale.
                    Puoi assegnare piu gestori allo stesso sottoconto o revocare l'accesso in qualsiasi momento.
                </p>
                <p class="subtle" style="font-size:14px;line-height:1.6;margin:0 0 10px;">
                    <strong>Notifiche:</strong> ricevi una notifica in-app e via email per ogni pagamento effettuato da qualsiasi sottoconto.
                    Nella sezione Movimenti puoi filtrare per singolo sottoconto o vedere tutto in un colpo.
                </p>
                <p class="subtle" style="font-size:14px;line-height:1.6;margin:0;">
                    <strong>Richieste limite:</strong> il gestore di un sottoconto può chiedere un aumento di limite o uno sforamento una-tantum (ad esempio per una spesa imprevista).
                    La richiesta arriva a te via notifica e deve essere approvata prima che il pagamento possa procedere.
                    Per gli sforamenti approvati, l'autorizzazione scade automaticamente dopo 24 ore.
                </p>
            </div>
        </section>
    </div>
</div>
@endsection
