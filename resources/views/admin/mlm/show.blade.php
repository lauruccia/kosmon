@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ route('admin.mlm.index') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">← Torna agli agenti</a>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:8px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">{{ $agent->name }}</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">{{ $agent->email }} · Sponsor: {{ $sponsor?->name ?? '— (radice albero)' }}</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="{{ route('admin.mlm.tree', $agent) }}" class="btn btn-secondary">Albero</a>
            <a href="{{ route('admin.mlm.tree.move-form', $agent) }}" class="btn btn-secondary">Sposta sponsor</a>
            <a href="{{ route('admin.mlm.promote-form', $agent) }}" class="btn btn-secondary">Promuovi agente</a>
            <span class="pill">{{ ucfirst($agent->mlm_rank) }}</span>
            @php
                // Stato BasiQ (finestra di 30 giorni da mlm_activated_at,
                // stessa regola di mlm:recalculate-points).
                $basiqDeadline = $agent->mlm_activated_at?->copy()->addDays(30);
            @endphp
            @if($agent->mlm_basiq_at)
                <span class="pill" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;" title="Ha raggiunto 12 punti entro 30 giorni dall'attivazione: genera bonus di struttura per l'upline.">BasiQ ✓ {{ $agent->mlm_basiq_at->format('d/m/Y') }}</span>
            @elseif(!$agent->mlm_activated_at)
                <span class="pill" style="color:var(--ink-muted);" title="Diventa candidato BasiQ solo dopo l'attivazione (firma contratto agente).">Non BasiQ — non attivato</span>
            @elseif($basiqDeadline->isPast())
                <span class="pill" style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;" title="Non ha raggiunto 12 punti entro 30 giorni dall'attivazione: non diventera' mai BasiQ (nessun bonus di struttura per l'upline). Puo' comunque salire di qualifica.">Non BasiQ — finestra scaduta il {{ $basiqDeadline->format('d/m/Y') }}</span>
            @else
                <span class="pill" style="background:#fef3c7;color:#b45309;border:1px solid #fde68a;" title="Diventa BasiQ raggiungendo 12 punti attivi (omaggio inclusi) entro questa data. Rilevato dal job notturno o dal ricalcolo immediato dopo un grant.">Non ancora BasiQ — entro il {{ $basiqDeadline->format('d/m/Y') }}</span>
            @endif
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Punti attivi</span>
        <strong style="font-size:22px;">{{ $agent->mlmActivePoints() }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Clienti diretti</span>
        <strong style="font-size:22px;">{{ $clients->total() }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Colonne (1° livello)</span>
        <strong style="font-size:22px;">{{ $branches->count() }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Attivato il</span>
        <strong style="font-size:16px;">{{ $agent->mlm_activated_at?->format('d/m/Y') ?? '—' }}</strong>
    </div>
</div>

@if($nextRank)
<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px;">
        <h3 style="margin:0 0 4px;font-size:15px;">Requisiti prossima qualifica: {{ ucfirst($nextRank['rank']) }}</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">Calcolato in tempo reale da MlmRankEngine. La promozione effettiva avviene nel job notturno <code>mlm:recalculate-points</code>.</p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            @foreach($nextRank['items'] as $item)
                <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:{{ $item['met'] ? 'rgba(26,122,74,0.08)' : 'var(--surface)' }};">
                    <span style="font-weight:700;font-size:13px;color:{{ $item['met'] ? '#1a7a4a' : '#c9313e' }};">{{ $item['met'] ? '✓' : '✗' }}</span>
                    <span style="font-size:12.5px;">{{ $item['label'] }}: {{ mlm_points_format($item['current']) }} / {{ $item['required'] }}</span>
                    @php $itemGranted = $item['granted'] ?? 0; $itemReal = $item['current'] - $itemGranted; @endphp
                    @if($itemGranted > 0 && $itemReal >= 0)
                        <span style="font-size:11.5px;color:var(--ink-muted);">({{ mlm_points_format($itemReal) }} {{ $itemReal == 1 ? 'reale' : 'reali' }} + {{ $itemGranted }} omaggio)</span>
                    @elseif($itemGranted < 0)
                        <span style="font-size:11.5px;color:var(--ink-muted);">(incl. {{ $itemGranted }} omaggio)</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if($retention ?? null)
<section class="card light-card" style="margin-bottom:14px;border-left:4px solid #dc2626;">
    <div style="padding:14px 16px;">
        <h3 style="margin:0 0 4px;font-size:15px;">⚠ A rischio retrocessione — per restare {{ ucfirst($retention['rank']) }}</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">L'agente non soddisfa più tutti i requisiti della sua qualifica attuale: al prossimo ricalcolo (notturno o "Ricalcola ora") verrà retrocesso, a meno che le voci in rosso non vengano coperte (clienti/punti reali o regali omaggio).</p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            @foreach($retention['items'] as $item)
                <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:{{ $item['met'] ? 'rgba(26,122,74,0.08)' : 'rgba(220,38,38,0.06)' }};">
                    <span style="font-weight:700;font-size:13px;color:{{ $item['met'] ? '#1a7a4a' : '#c9313e' }};">{{ $item['met'] ? '✓' : '✗' }}</span>
                    <span style="font-size:12.5px;">{{ $item['label'] }}: {{ mlm_points_format($item['current']) }} / {{ $item['required'] }}</span>
                    @php $itemGranted = $item['granted'] ?? 0; $itemReal = $item['current'] - $itemGranted; @endphp
                    @if($itemGranted > 0 && $itemReal >= 0)
                        <span style="font-size:11.5px;color:var(--ink-muted);">({{ mlm_points_format($itemReal) }} {{ $itemReal == 1 ? 'reale' : 'reali' }} + {{ $itemGranted }} omaggio)</span>
                    @elseif($itemGranted < 0)
                        <span style="font-size:11.5px;color:var(--ink-muted);">(incl. {{ $itemGranted }} omaggio)</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Colonne / rami</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">Ogni riga è il sotto-albero radicato in un invitato diretto di 1° livello. Usato per verificare i requisiti "N colonne diverse" delle qualifiche.</p>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Colonna (1° livello)</th>
                <th>Qualifica colonna</th>
                <th>Agenti nel ramo</th>
                <th>Punti attivi ramo</th>
                <th title="Avanzamento della colonna verso i 300 punti attivi richiesti dal requisito 'colonne da 300 punti' (es. qualifica Top).">Verso i 300 pt</th>
                <th>Distribuzione rank</th>
            </tr>
        </thead>
        <tbody>
            @forelse($branches as $branch)
                @php
                    // Soglia del requisito "colonne da 300 punti attivi"
                    // (MlmRankEngine::evaluate, metrica branches_300pt).
                    $branch300Missing = max(0, 300 - $branch['active_points']);
                    $branch300Pct = min(100, round($branch['active_points'] / 300 * 100, 1));
                @endphp
                <tr>
                    <td>
                        <strong style="display:block;">{{ $branch['branch_root']->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $branch['branch_root']->email }}</span>
                    </td>
                    <td><span class="pill">{{ ucfirst($branch['branch_root']->mlm_rank) }}</span></td>
                    <td>{{ $branch['agent_count'] }}</td>
                    <td>{{ mlm_points_format($branch['active_points']) }}</td>
                    <td>
                        <div style="min-width:130px;max-width:170px;">
                            <div style="height:6px;border-radius:999px;background:var(--surface-soft,#e2e8f0);overflow:hidden;">
                                <div style="height:100%;width:{{ number_format($branch300Pct, 1, '.', '') }}%;background:{{ $branch300Missing <= 0 ? '#16a34a' : 'var(--primary,#0c4a86)' }};border-radius:999px;"></div>
                            </div>
                            @if($branch300Missing <= 0)
                                <span style="display:block;margin-top:3px;font-size:11.5px;font-weight:700;color:#1a7a4a;">✓ 300 raggiunti</span>
                            @else
                                <span style="display:block;margin-top:3px;font-size:11.5px;color:var(--ink-muted);">{{ mlm_points_format($branch['active_points']) }} / 300 — ne mancano {{ mlm_points_format($branch300Missing) }}</span>
                            @endif
                        </div>
                    </td>
                    <td style="font-size:12px;color:var(--ink-muted);">
                        @forelse($branch['rank_counts'] as $rank => $count)
                            {{ ucfirst($rank) }}: {{ $count }}@if(!$loop->last), @endif
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun agente in downline.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Ultimi movimenti ledger punti</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">Ogni riga e' un punto assegnato con la sua finestra di validita' (smoothing). "Attivo" = oggi e' dentro la finestra.</p>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Origine</th>
                <th>Punti</th>
                <th>Valido dal</th>
                <th>Valido al</th>
                <th>Stato</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pointLedger as $entry)
                <tr>
                    <td>{{ $entry->client->name ?? '—' }}</td>
                    <td>{{ $entry->source_type === 'registration' ? 'Registrazione' : 'Deposito' }}</td>
                    <td>{{ mlm_points_format($entry->points) }}</td>
                    <td>{{ $entry->valid_from->format('d/m/Y') }}</td>
                    <td>{{ $entry->valid_until->format('d/m/Y') }}</td>
                    <td>
                        @if($entry->valid_until->isPast())
                            <span style="color:var(--ink-muted);font-size:12px;">Scaduto</span>
                        @else
                            <span style="color:#1a7a4a;font-size:12px;font-weight:600;">Attivo</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun punto assegnato finora.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

@if(session('portal_success'))
    <div class="card card-pad" style="margin-bottom:14px;background:rgba(26,122,74,0.08);border:1px solid #bfe3cf;color:#1a7a4a;font-size:13px;">{{ session('portal_success') }}</div>
@endif

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Punti/agenti omaggio assegnati da admin</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">Assegnabili in blocco da <a href="{{ route('admin.mlm.index') }}" style="color:var(--primary);">MLM — Agenti</a>. Non scadono mai finché non vengono revocati esplicitamente.</p>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Quantità</th>
                <th>Motivo</th>
                <th>Assegnato da</th>
                <th>Data</th>
                <th>Stato</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($metricGrants as $grant)
                <tr>
                    <td>{{ \App\Models\MlmMetricGrant::metricLabel($grant->metric) }}</td>
                    <td style="{{ $grant->amount < 0 ? 'color:#c9313e;font-weight:600;' : '' }}">{{ sprintf('%+d', $grant->amount) }}</td>
                    <td style="color:var(--ink-muted);font-size:12px;">{{ $grant->reason ?? '—' }}</td>
                    <td>{{ $grant->grantedBy?->name ?? '—' }}</td>
                    <td>{{ $grant->created_at->format('d/m/Y H:i') }}</td>
                    <td>
                        @if($grant->revoked_at)
                            <span style="color:var(--ink-muted);font-size:12px;">Revocato {{ $grant->revoked_at->format('d/m/Y') }}</span>
                        @else
                            <span style="color:#1a7a4a;font-size:12px;font-weight:600;">Attivo</span>
                        @endif
                    </td>
                    <td style="text-align:right;">
                        @if(!$grant->revoked_at)
                            <form method="POST" action="{{ route('admin.mlm.metric-grants.destroy', $grant) }}" onsubmit="return confirm('Revocare questo regalo? L\'agente potrebbe essere retrocesso al ricalcolo.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="background:none;border:none;color:var(--danger);font-size:12px;font-weight:600;cursor:pointer;padding:0;">Revoca</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun regalo assegnato finora.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Bonus di struttura ricevuti</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">Generati istantaneamente quando un agente in downline diventa BasiQ, accredito EUR previsto il mercoledi' della settimana indicata.</p>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Generato da (BasiQ)</th>
                <th>Qualifica al momento</th>
                <th>Importo</th>
                <th>Settimana (mercoledi')</th>
                <th>Stato</th>
            </tr>
        </thead>
        <tbody>
            @forelse($bonusPayouts as $payout)
                <tr>
                    <td>{{ $payout->event->basiqUser->name ?? '—' }}</td>
                    <td><span class="pill">{{ $payout->kind === 'diretto' ? 'Bonus diretto' : ($payout->kind === 'extra' ? 'Extra Bonus ' . ucfirst((string) $payout->rank_at_time) : ucfirst((string) $payout->rank_at_time)) }}</span></td>
                    <td>&euro; {{ number_format($payout->amount_eur_cents / 100, 2, ',', '.') }}</td>
                    <td>{{ $payout->week_ending->format('d/m/Y') }}</td>
                    <td style="text-transform:capitalize;">{{ $payout->status }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun bonus generato finora.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<section class="card light-card">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Clienti diretti</h3>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Registrato il</th>
            </tr>
        </thead>
        <tbody>
            @forelse($clients as $client)
                <tr>
                    <td>
                        <strong style="display:block;">{{ $client->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $client->email }}</span>
                    </td>
                    <td>{{ $client->created_at?->format('d/m/Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="2" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun cliente diretto.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div style="padding:12px 16px;">{{ $clients->links() }}</div>
</section>

<section class="card light-card" style="margin-top:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Storico qualifiche</h3>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Qualifica</th>
                <th>Raggiunta il</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rankHistory as $history)
                <tr>
                    <td><span class="pill">{{ ucfirst($history->rank) }}</span></td>
                    <td>{{ $history->achieved_at->format('d/m/Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="2" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun avanzamento registrato finora (resta "Start" finche' il job notturno non lo promuove).</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
@endsection
