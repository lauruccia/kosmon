@extends('layouts.portal')

@section('content')
    @php
        $fmtKy = fn ($value) => number_format((int) $value, 2, ',', '.') . ' KY';
        $maxMonthlyVolume = max($monthlyChart['volumes'] ?? [0]) ?: 1;
        $maxMonthlyCount = max($monthlyChart['counts'] ?? [0]) ?: 1;
        $liquidityRatio = $circuitKpis['kyInCirculation'] > 0
            ? min(100, round(($circuitKpis['volumeThisMonth'] / max(1, $circuitKpis['kyInCirculation'])) * 100))
            : 0;
        $bookedRatio = $dashboardMovementTotals['count'] > 0
            ? round(($dashboardMovementTotals['bookedCount'] / $dashboardMovementTotals['count']) * 100)
            : 0;
    @endphp

    <style>
        .bank-admin {
            display: grid;
            gap: 14px;
        }
        .bank-sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .bank-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr);
            gap: 14px;
            align-items: stretch;
        }
        .bank-command,
        .bank-panel,
        .bank-table-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .bank-command {
            padding: 18px 20px;
            border-left: 4px solid var(--primary);
        }
        .bank-title {
            margin: 0;
            font-size: 24px;
            line-height: 1.1;
            letter-spacing: 0;
        }
        .bank-copy {
            margin: 7px 0 0;
            color: var(--ink-soft);
            font-size: 13px;
        }
        .bank-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .bank-status {
            padding: 16px;
            display: grid;
            align-content: space-between;
            gap: 16px;
        }
        .bank-status-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }
        .bank-status-row:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }
        .bank-label {
            display: block;
            color: var(--ink-muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .bank-value {
            display: block;
            margin-top: 2px;
            color: var(--ink);
            font-size: 18px;
            font-weight: 800;
            line-height: 1.1;
        }
        .bank-note {
            color: var(--ink-soft);
            font-size: 12px;
        }
        .bank-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }
        .bank-metric {
            min-height: 104px;
            padding: 15px 16px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow-xs);
            display: grid;
            align-content: space-between;
            gap: 10px;
        }
        .bank-metric strong {
            display: block;
            font-size: 24px;
            line-height: 1;
            letter-spacing: 0;
        }
        .bank-metric .bank-note {
            margin-top: 4px;
        }
        .bank-positive {
            color: var(--success);
            font-weight: 800;
        }
        .bank-negative {
            color: var(--danger);
            font-weight: 800;
        }
        .bank-grid {
            display: grid;
            grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr);
            gap: 14px;
        }
        .bank-panel {
            padding: 16px;
        }
        .bank-panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .bank-panel-title {
            margin: 3px 0 0;
            font-size: 18px;
            line-height: 1.15;
        }
        .bank-ratio {
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--surface-hover);
        }
        .bank-ratio span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: var(--primary);
        }
        .bank-list {
            display: grid;
            gap: 9px;
        }
        .bank-module {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--surface-soft);
        }
        .bank-module-code {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: var(--navy);
            color: #fff;
            font-size: 11px;
            font-weight: 900;
        }
        .bank-module h3 {
            margin: 0;
            font-size: 14px;
            line-height: 1.15;
        }
        .bank-module p {
            margin: 4px 0 0;
            color: var(--ink-soft);
            font-size: 12px;
            line-height: 1.35;
        }
        .bank-chart {
            display: grid;
            gap: 11px;
        }
        .bank-chart-row {
            display: grid;
            grid-template-columns: 48px minmax(0, 1fr) 96px;
            gap: 10px;
            align-items: center;
            font-size: 12px;
        }
        .bank-chart-track {
            position: relative;
            height: 9px;
            border-radius: 999px;
            background: var(--surface-hover);
            overflow: hidden;
        }
        .bank-chart-track span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--primary), var(--teal));
        }
        .bank-chart-value {
            text-align: right;
            font-weight: 800;
            white-space: nowrap;
        }
        .bank-dual {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .bank-table-panel {
            padding: 0;
            overflow: hidden;
        }
        .bank-table-toolbar {
            padding: 15px 16px;
            border-bottom: 1px solid var(--line);
        }
        .bank-filter-grid {
            display: grid;
            grid-template-columns: 220px 1fr 1fr auto;
            gap: 10px;
            align-items: end;
            margin-top: 14px;
        }
        .bank-table-wrap {
            overflow-x: auto;
        }
        .bank-table-wrap .admin-table {
            margin: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .bank-table-wrap .admin-table th {
            background: var(--surface-soft);
            color: var(--ink-muted);
            font-size: 10px;
            letter-spacing: .09em;
            text-transform: uppercase;
        }
        .bank-money {
            font-weight: 900;
            white-space: nowrap;
        }
        @media (max-width: 1180px) {
            .bank-hero,
            .bank-grid,
            .bank-dual {
                grid-template-columns: 1fr;
            }
            .bank-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 760px) {
            .bank-metrics,
            .bank-filter-grid {
                grid-template-columns: 1fr;
            }
            .bank-module {
                grid-template-columns: 38px minmax(0, 1fr);
            }
            .bank-module .cta {
                grid-column: 1 / -1;
                width: 100%;
            }
            .bank-chart-row {
                grid-template-columns: 42px minmax(0, 1fr);
            }
            .bank-chart-value {
                grid-column: 2;
                text-align: left;
            }
        }
    </style>

    <div class="bank-admin">
        <section class="bank-hero">
            <div class="bank-command">
                <span class="bank-label">Control room circuito</span>
                <h2 class="bank-title">Dashboard operativa Kmoney</h2>
                <span class="bank-sr-only">Backoffice centrale KMoney</span>
                <p class="bank-copy">Presidio centralizzato di liquidita KY, anagrafiche, conti, autorizzazioni e movimenti contabilizzati.</p>
                <div class="bank-actions">
                    <a class="cta" href="{{ route('admin.transfers.index') }}">Monitor movimenti</a>
                    <a class="cta secondary" href="{{ route('admin.accounts.index') }}">Libro conti</a>
                    <a class="cta secondary" href="{{ route('admin.report') }}">Report circuito</a>
                </div>
            </div>

            <aside class="bank-status">
                <div class="bank-status-row">
                    <div>
                        <span class="bank-label">Stato operativo</span>
                        <span class="bank-value">Circuito attivo</span>
                    </div>
                    <span class="pill success">Online</span>
                </div>
                <div class="bank-status-row">
                    <div>
                        <span class="bank-label">Finestra storni</span>
                        <span class="bank-value">{{ $refundWindowDays }} giorni</span>
                    </div>
                    <span class="bank-note">Controllo amministrativo</span>
                </div>
                <div>
                    <div class="bank-status-row" style="border-bottom:0;padding-bottom:8px;">
                        <div>
                            <span class="bank-label">Rotazione liquidita</span>
                            <span class="bank-value">{{ $liquidityRatio }}%</span>
                        </div>
                        <span class="bank-note">Volume mese / KY attivi</span>
                    </div>
                    <div class="bank-ratio"><span style="width:{{ $liquidityRatio }}%;"></span></div>
                </div>
            </aside>
        </section>

        <section class="bank-metrics" aria-label="Indicatori principali">
            <article class="bank-metric">
                <div>
                    <span class="bank-label">KY in circolazione</span>
                    <strong>{{ $fmtKy($circuitKpis['kyInCirculation']) }}</strong>
                </div>
                <span class="bank-note">Somma saldi conti attivi</span>
            </article>
            <article class="bank-metric">
                <div>
                    <span class="bank-label">Volume mese corrente</span>
                    <strong>{{ $fmtKy($circuitKpis['volumeThisMonth']) }}</strong>
                </div>
                <span class="bank-note">
                    @if($circuitKpis['volumeChange'] !== null)
                        <span class="{{ $circuitKpis['volumeChange'] >= 0 ? 'bank-positive' : 'bank-negative' }}">
                            {{ $circuitKpis['volumeChange'] >= 0 ? '+' : '-' }}{{ abs($circuitKpis['volumeChange']) }}%
                        </span>
                        vs mese precedente
                    @else
                        Nessun confronto disponibile
                    @endif
                </span>
            </article>
            <article class="bank-metric">
                <div>
                    <span class="bank-label">Movimento medio</span>
                    <strong>{{ $fmtKy($circuitKpis['avgMovement']) }}</strong>
                </div>
                <span class="bank-note">Media transazioni contabilizzate nel mese</span>
            </article>
            <article class="bank-metric">
                <div>
                    <span class="bank-label">Movimenti oggi</span>
                    <strong>{{ $circuitKpis['transfersToday'] }}</strong>
                </div>
                <span class="bank-note">{{ $circuitKpis['newUsers30d'] }} nuovi utenti negli ultimi 30 giorni</span>
            </article>
        </section>

        <section class="bank-grid">
            <div class="bank-panel">
                <div class="bank-panel-head">
                    <div>
                        <span class="bank-label">Assetto operativo</span>
                        <h2 class="bank-panel-title">Presidio backoffice</h2>
                    </div>
                    <span class="pill">{{ $stats['permissions'] }} policy</span>
                </div>
                <div class="bank-list">
                    <a class="bank-module" href="{{ route('admin.users.index') }}">
                        <span class="bank-module-code">ID</span>
                        <span>
                            <h3>Anagrafiche e utenti</h3>
                            <p>{{ $stats['users'] }} utenti, {{ $stats['companyUsers'] }} profili aziendali, {{ $stats['superAdmins'] }} superadmin.</p>
                        </span>
                        <span class="cta secondary">Apri</span>
                    </a>
                    <a class="bank-module" href="{{ route('admin.roles.index') }}">
                        <span class="bank-module-code">ACL</span>
                        <span>
                            <h3>Ruoli e autorizzazioni</h3>
                            <p>{{ $stats['roles'] }} ruoli governano accessi, operativita e segregazione funzioni.</p>
                        </span>
                        <span class="cta secondary">Apri</span>
                    </a>
                    <a class="bank-module" href="{{ route('admin.accounts.index') }}">
                        <span class="bank-module-code">IB</span>
                        <span>
                            <h3>Conti, massimali e sottoconti</h3>
                            <p>{{ $stats['accounts'] }} conti tra aziende, privati, deleghe e conti operativi.</p>
                        </span>
                        <span class="cta secondary">Apri</span>
                    </a>
                    <a class="bank-module" href="{{ route('admin.kyc.index') }}">
                        <span class="bank-module-code">KYC</span>
                        <span>
                            <h3>Verifiche e conformita</h3>
                            <p>Controllo documentale, stato aziende, sospensioni e riattivazioni circuito.</p>
                        </span>
                        <span class="cta secondary">Apri</span>
                    </a>
                </div>
            </div>

            <div class="bank-panel">
                <div class="bank-panel-head">
                    <div>
                        <span class="bank-label">Ultimi 6 mesi</span>
                        <h2 class="bank-panel-title">Andamento transazionale</h2>
                    </div>
                    <span class="pill">{{ $stats['transfers'] }} movimenti</span>
                </div>
                <div class="bank-chart">
                    @foreach($monthlyChart['labels'] as $index => $label)
                        @php
                            $volume = (int) ($monthlyChart['volumes'][$index] ?? 0);
                            $count = (int) ($monthlyChart['counts'][$index] ?? 0);
                            $volumePct = max(2, round(($volume / $maxMonthlyVolume) * 100));
                            $countPct = max(2, round(($count / $maxMonthlyCount) * 100));
                        @endphp
                        <div class="bank-chart-row">
                            <strong>{{ $label }}</strong>
                            <div>
                                <div class="bank-chart-track"><span style="width:{{ $volumePct }}%;"></span></div>
                                <div class="bank-ratio" style="height:4px;margin-top:4px;"><span style="width:{{ $countPct }}%;background:var(--success);"></span></div>
                            </div>
                            <span class="bank-chart-value">{{ $fmtKy($volume) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bank-dual">
            <div class="bank-panel">
                <div class="bank-panel-head">
                    <div>
                        <span class="bank-label">Qualita operativa</span>
                        <h2 class="bank-panel-title">Movimenti filtrati</h2>
                    </div>
                    <span class="pill">{{ $movementFilters['label'] }}</span>
                </div>
                <div class="bank-metrics" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                    <article class="bank-metric">
                        <div>
                            <span class="bank-label">Contabilizzati</span>
                            <strong>{{ $bookedRatio }}%</strong>
                        </div>
                        <span class="bank-note">{{ $dashboardMovementTotals['bookedCount'] }} su {{ $dashboardMovementTotals['count'] }} movimenti</span>
                    </article>
                    <article class="bank-metric">
                        <div>
                            <span class="bank-label">Totale movimentato</span>
                            <strong>{{ $fmtKy($dashboardMovementTotals['volume']) }}</strong>
                        </div>
                        <span class="bank-note">{{ $dashboardMovementTotals['refunds'] }} storni nel perimetro</span>
                    </article>
                </div>
            </div>

            <div class="bank-panel">
                <div class="bank-panel-head">
                    <div>
                        <span class="bank-label">Top aziende</span>
                        <h2 class="bank-panel-title">Volume ultimi 90 giorni</h2>
                    </div>
                    <a class="cta secondary" href="{{ route('admin.companies.index') }}">Aziende</a>
                </div>
                @if($topCompanies->isEmpty())
                    <div class="empty-state">Nessun volume aziendale disponibile.</div>
                @else
                    <div class="bank-list">
                        @foreach($topCompanies->take(5) as $i => $row)
                            @php
                                $maxVol = max(1, (int) $topCompanies->first()->volume);
                                $pct = round(((int) $row->volume / $maxVol) * 100);
                            @endphp
                            <div class="bank-chart-row" style="grid-template-columns:26px minmax(0,1fr) 108px;">
                                <strong>{{ $i + 1 }}</strong>
                                <div>
                                    <div style="font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $row->name }}</div>
                                    <div class="bank-chart-track" style="margin-top:5px;"><span style="width:{{ $pct }}%;"></span></div>
                                </div>
                                <span class="bank-chart-value">{{ $fmtKy($row->volume) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="bank-table-panel">
            <div class="bank-table-toolbar">
                <div class="bank-panel-head" style="margin-bottom:0;">
                    <div>
                        <span class="bank-label">Libro movimenti</span>
                        <h2 class="bank-panel-title">Ultime scritture e correzioni</h2>
                    </div>
                    <a class="cta" href="{{ route('admin.transfers.index') }}">Vista completa</a>
                </div>

                <form method="get" action="{{ route('admin.dashboard') }}" class="bank-filter-grid">
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
                    <button type="submit" class="cta secondary">Applica filtri</button>
                </form>
            </div>

            @if ($dashboardTransfers->isEmpty())
                <div class="empty-state" style="margin:16px;">Nessun movimento trovato per il periodo selezionato.</div>
            @else
                <div class="bank-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Data valuta</th>
                                <th>Riferimento</th>
                                <th>Ordinante</th>
                                <th>Beneficiario</th>
                                <th>Importo</th>
                                <th>Operatore</th>
                                <th>Esito</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dashboardTransfers as $transfer)
                                @php
                                    $stato = match ($transfer->status) {
                                        'booked' => 'Contabilizzato',
                                        'pending' => 'In lavorazione',
                                        'rejected' => 'Respinto',
                                        'cancelled' => 'Annullato',
                                        default => ucfirst(str_replace('_', ' ', $transfer->status ?? 'N/D')),
                                    };
                                    $causale = match ($transfer->kind) {
                                        'portal_payment' => 'Pagamento portale',
                                        'portal_collection_request' => 'Richiesta incasso',
                                        'trade_payment' => 'Pagamento commerciale',
                                        'admin_refund', 'portal_refund' => 'Storno',
                                        'kycard_topup' => 'Ricarica KYCard',
                                        default => $transfer->kind ? ucfirst(str_replace('_', ' ', $transfer->kind)) : 'Movimento',
                                    };
                                @endphp
                                <tr>
                                    <td>{{ $transfer->booked_at?->format('d/m/Y H:i') ?? $transfer->created_at?->format('d/m/Y H:i') ?? 'N/D' }}</td>
                                    <td>
                                        <strong>{{ $transfer->reference }}</strong>
                                        <div class="table-muted">{{ $causale }}</div>
                                    </td>
                                    <td>
                                        <strong>{{ $transfer->fromAccount?->display_name ?? 'N/D' }}</strong>
                                        <div class="table-muted">{{ $transfer->fromAccount?->account_number ?? $transfer->fromAccount?->ownerLabel ?? 'N/D' }}</div>
                                    </td>
                                    <td>
                                        <strong>{{ $transfer->toAccount?->display_name ?? 'N/D' }}</strong>
                                        <div class="table-muted">{{ $transfer->toAccount?->account_number ?? $transfer->toAccount?->ownerLabel ?? 'N/D' }}</div>
                                    </td>
                                    <td class="bank-money">{{ $fmtKy($transfer->amount) }}</td>
                                    <td>{{ $transfer->initiator?->name ?? 'Sistema' }}</td>
                                    <td><span class="chip {{ $transfer->status === 'booked' ? 'success' : 'pink' }}">{{ $stato }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
