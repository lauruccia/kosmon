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
                @php
                    $ua       = $session->user_agent ?? '';
                    $isMobile = stripos($ua, 'mobile') !== false || stripos($ua, 'android') !== false;
                    $isTablet = stripos($ua, 'tablet') !== false || stripos($ua, 'ipad') !== false;
                    $icon     = $isMobile ? '📱' : ($isTablet ? '📟' : '🖥️');
                    $lastSeen = \Carbon\Carbon::createFromTimestamp($session->last_activity);
                @endphp
                <div style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span style="font-size:22px;">{{ $icon }}</span>
                        <div>
                            <div style="font-size:13px;font-weight:700;color:var(--ink);">
                                {{ $session->ip_address ?? 'IP sconosciuto' }}
                            </div>
                            <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">
                                Attiva {{ $lastSeen->diffForHumans() }} &middot; {{ $lastSeen->format('d/m/Y H:i') }}
                            </div>
                            @if($ua)
                            <div style="font-size:11px;color:var(--ink-muted);margin-top:1px;max-width:480px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $ua }}">
                                {{ Str::limit($ua, 80) }}
                            </div>
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.users.sessions.terminate', [$userRecord, $session->id]) }}"
                          onsubmit="return confirm('Terminare questa sessione?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                style="font-size:12px;font-weight:600;color:var(--danger);background:transparent;border:1.5px solid var(--danger);padding:5px 12px;border-radius:7px;cursor:pointer;white-space:nowrap;">
                            Disconnetti
                        </button>
                    </form>
                </div>
                @endforeach
            </div>
        @endif

        {{-- Storico accessi --}}
        @if($loginLogs->isNotEmpty())
        <div style="border-top:1px solid var(--line);padding-top:18px;">
            <div class="eyebrow" style="margin-bottom:10px;">Ultimi accessi registrati</div>
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead>
                    <tr style="border-bottom:1px solid var(--line);">
                        <th style="padding:6px 10px;text-align:left;color:var(--ink-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;font-size:10px;">Data</th>
                        <th style="padding:6px 10px;text-align:left;color:var(--ink-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;font-size:10px;">IP</th>
                        <th style="padding:6px 10px;text-align:left;color:var(--ink-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;font-size:10px;">Posizione</th>
                        <th style="padding:6px 10px;text-align:left;color:var(--ink-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;font-size:10px;">Dispositivo</th>
                        <th style="padding:6px 10px;text-align:left;color:var(--ink-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;font-size:10px;">Browser</th>
                        <th style="padding:6px 10px;text-align:left;color:var(--ink-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;font-size:10px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($loginLogs as $log)
                    <tr style="border-bottom:1px solid var(--line-soft);">
                        <td style="padding:8px 10px;white-space:nowrap;">
                            <div style="font-weight:600;color:var(--ink);">{{ $log->logged_in_at?->format('d/m/Y') }}</div>
                            <div style="color:var(--ink-muted);font-size:10px;">{{ $log->logged_in_at?->format('H:i:s') }}</div>
                        </td>
                        <td style="padding:8px 10px;">
                            <code style="font-size:11px;background:var(--surface-soft);padding:2px 6px;border-radius:4px;border:1px solid var(--line);">{{ $log->ip_address ?? '—' }}</code>
                        </td>
                        <td style="padding:8px 10px;color:var(--ink);">
                            {{ implode(', ', array_filter([$log->city, $log->country])) ?: '—' }}
                        </td>
                        <td style="padding:8px 10px;">
                            @php $dIcon = match($log->device_type) { 'mobile' => '📱', 'tablet' => '📟', 'desktop' => '🖥️', default => '💻' }; @endphp
                            {{ $dIcon }} {{ ucfirst($log->device_type ?? '—') }}
                        </td>
                        <td style="padding:8px 10px;">
                            <div style="color:var(--ink);">{{ $log->browser ?? '—' }}</div>
                            <div style="color:var(--ink-muted);font-size:10px;">{{ $log->os ?? '' }}</div>
                        </td>
                        <td style="padding:8px 10px;text-align:right;">
                            @if($loop->first)
                                <span style="background:#dbeafe;color:#1d4ed8;border-radius:5px;padding:2px 7px;font-size:9px;font-weight:700;text-transform:uppercase;">Più recente</span>
                            @elseif($log->is_new_ip)
                                <span style="background:#fef3c7;color:#92400e;border-radius:5px;padding:2px 7px;font-size:9px;font-weight:700;text-transform:uppercase;" title="Primo accesso da questo IP">⚠ Nuovo IP</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
            <p class="table-muted" style="padding-top:12px;">Nessun accesso registrato nella cronologia.</p>
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

        {{-- hidden inputs FUORI dal form-grid per evitare che il CSS li renda visibili --}}
        <form method=”post” action=”{{ route('admin.users.update', $userRecord) }}” class=”field-grid”
              id=”form-aggiorna-utente”
              onsubmit=”return adminUpdateConfirm(this)”>
            @csrf
            {{-- hidden fallback per i checkbox booleani: devono stare subito dopo @csrf, non dentro .field --}}
            <div style=”display:none !important; visibility:hidden; position:absolute; pointer-events:none;”>
                <input type=”text” name=”is_super_admin” value=”0”>
                <input type=”text” name=”primary_account_allow_negative” value=”0”>
            </div>

            {{-- ── Anagrafica ──────────────────────────────────────────────────────── --}}
            <p class=”eyebrow” style=”margin:0 0 6px;”>Anagrafica</p>
            <div class=”field-grid” style=”grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;”>
                <div class=”field”>
                    <label>Nome completo</label>
                    <input name=”name” type=”text” required
                           value=”{{ old('name', $userRecord->name) }}”>
                </div>
                <div class=”field”>
                    <label>Email</label>
                    <input name=”email” type=”email” required
                           value=”{{ old('email', $userRecord->email) }}”
                           data-original-email=”{{ $userRecord->email }}”>
                    <div class=”table-muted” style=”margin-top:4px;”>Modificare reimposta la verifica.</div>
                </div>
                <div class=”field”>
                    <label>Telefono</label>
                    <input name=”phone” type=”text”
                           value=”{{ old('phone', $userRecord->phone) }}”>
                </div>
            </div>

            <div class=”field-grid” style=”grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;”>
                <div class=”field”>
                    <label>Tipologia</label>
                    <select name=”account_holder_type”>
                        <option value=”company” @selected(old('account_holder_type', $userRecord->account_holder_type) === 'company')>Azienda</option>
                        <option value=”private” @selected(old('account_holder_type', $userRecord->account_holder_type) === 'private')>Privato</option>
                    </select>
                </div>
                <div class=”field”>
                    <label>Azienda collegata</label>
                    <select name=”company_id”>
                        <option value=””>Nessuna</option>
                        @foreach ($companies as $company)
                            <option value=”{{ $company->id }}”
                                    @selected((int) old('company_id', $userRecord->company_id) === $company->id)>
                                {{ $company->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class=”field”>
                    <label>Sottoconto gestito (ID)</label>
                    <input name=”managed_account_id” type=”number” min=”1”
                           value=”{{ old('managed_account_id', $userRecord->managed_account_id) }}”
                           placeholder=”Lascia vuoto se non applicabile”>
                </div>
                <div class=”field”>
                    <label>Etichetta interna</label>
                    <input name=”role_label” type=”text”
                           value=”{{ old('role_label', $userRecord->role) }}”
                           placeholder=”es. owner, manager…”>
                </div>
            </div>

            {{-- ── Stato e accessi speciali ────────────────────────────────────────── --}}
            <p class=”eyebrow” style=”margin:8px 0 6px;”>Stato e accessi</p>
            <div class=”field-grid” style=”grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;”>
                <div class=”field”>
                    <label>Stato account</label>
                    <select name=”is_active”>
                        <option value=”1” @selected((string) old('is_active', $userRecord->is_active ? '1' : '0') === '1')>✅ Attivo</option>
                        <option value=”0” @selected((string) old('is_active', $userRecord->is_active ? '1' : '0') === '0')>🚫 Disattivo — accesso bloccato</option>
                    </select>
                </div>
                <div class=”field”>
                    <label>Privilegi Super Admin</label>
                    <div style=”display:flex;align-items:center;gap:12px;padding:10px 0;”>
                        <label class=”admin-toggle” style=”display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:normal;”>
                            <input type=”checkbox” name=”is_super_admin” value=”1”
                                   @checked(old('is_super_admin', $userRecord->is_super_admin))
                                   style=”width:20px;height:20px;accent-color:var(--primary);cursor:pointer;flex-shrink:0;”>
                            <span>
                                <strong>Super Admin</strong>
                                <span class=”table-muted” style=”display:block;font-size:11px;margin-top:2px;”>Accesso illimitato a tutto il backoffice.</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- ── Limiti transazionali ────────────────────────────────────────────── --}}
            <p class=”eyebrow” style=”margin:8px 0 6px;”>Limiti transazionali personalizzati (KY) — vuoto = usa il default admin</p>
            <div class=”field-grid” style=”grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;”>
                <div class=”field”>
                    <label>Disponibilità commerciale</label>
                    <input name=”circuit_capacity_limit” type=”number” min=”0” step=”0.01”
                           value=”{{ old('circuit_capacity_limit', ky_input($userRecord->circuit_capacity_limit)) }}”
                           placeholder=”Vuoto = default”>
                    <div class=”table-muted” style=”margin-top:4px;”>Tetto massimo acquistabile nel circuito.</div>
                </div>
                <div class=”field”>
                    <label>Massimale / fido</label>
                    <input name=”negative_balance_limit” type=”number” min=”0” step=”0.01”
                           value=”{{ old('negative_balance_limit', ky_input($userRecord->negative_balance_limit)) }}”
                           placeholder=”Vuoto = default”>
                    <div class=”table-muted” style=”margin-top:4px;”><code>0</code> = nessun fido; vuoto = default.</div>
                </div>
                <div class=”field”>
                    <label>Limite giornaliero</label>
                    <input name=”daily_transaction_limit” type=”number” min=”0” step=”0.01”
                           value=”{{ old('daily_transaction_limit', ky_input($userRecord->daily_transaction_limit)) }}”
                           placeholder=”Vuoto = default”>
                </div>
                <div class=”field”>
                    <label>Limite mensile</label>
                    <input name=”monthly_transaction_limit” type=”number” min=”0” step=”0.01”
                           value=”{{ old('monthly_transaction_limit', ky_input($userRecord->monthly_transaction_limit)) }}”
                           placeholder=”Vuoto = default”>
                </div>
                <div class=”field”>
                    <label>Limite per singolo movimento</label>
                    <input name=”per_movement_limit” type=”number” min=”0” step=”0.01”
                           value=”{{ old('per_movement_limit', ky_input($userRecord->per_movement_limit)) }}”
                           placeholder=”Vuoto = default”>
                </div>
            </div>

            {{-- ── Conto principale ────────────────────────────────────────────────── --}}
            @if ($primaryAccount)
                <p class=”eyebrow” style=”margin:8px 0 6px;”>
                    Conto principale &mdash; {{ $primaryAccount->display_name ?? $primaryAccount->uuid }}
                </p>
                <div class=”field-grid” style=”grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;”>
                    <div class=”field”>
                        <label>Saldo massimo (KY)</label>
                        <input name=”primary_account_max_balance” type=”number” min=”0” step=”0.01”
                               value=”{{ old('primary_account_max_balance', ky_input($primaryAccount->max_balance)) }}”
                               placeholder=”Vuoto = nessun tetto”>
                        <div class=”table-muted” style=”margin-top:4px;”>Valore vive sul conto, non sull'utente.</div>
                    </div>
                    <div class=”field”>
                        <label>Saldo negativo</label>
                        <div style=”display:flex;align-items:center;gap:10px;padding:10px 0;”>
                            <label style=”display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:normal;”>
                                <input type=”checkbox” name=”primary_account_allow_negative” value=”1”
                                       @checked(old('primary_account_allow_negative', $primaryAccount->allow_negative_balance))
                                       style=”width:20px;height:20px;accent-color:var(--primary);cursor:pointer;flex-shrink:0;”>
                                <span>
                                    <strong>Consenti saldo negativo</strong>
                                    <span class=”table-muted” style=”display:block;font-size:11px;margin-top:2px;”>Il conto può scendere sotto zero (fido).</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Ruoli ───────────────────────────────────────────────────────────── --}}
            <p class=”eyebrow” style=”margin:8px 0 6px;”>Ruoli assegnati</p>
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

            <div class=”form-actions” style=”margin-top:12px;”>
                <button type=”submit” class=”cta”>Salva modifiche utente</button>
            </div>
        </form>

        <script>
        function adminUpdateConfirm(form) {
            // Sincronizza i fallback hidden con i checkbox prima del submit
            ['is_super_admin', 'primary_account_allow_negative'].forEach(function(name) {
                var cb  = form.querySelector('input[type=”checkbox”][name=”' + name + '”]');
                var hid = form.querySelector('div[style*=”display:none”] input[name=”' + name + '”]');
                if (cb && hid) hid.disabled = cb.checked; // se checked, il hidden non invia
            });

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
