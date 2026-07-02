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
            <span class="pill">{{ ucfirst($agent->mlm_rank) }}</span>
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
                    <span style="font-size:12.5px;">{{ $item['label'] }}: {{ $item['current'] }} / {{ $item['required'] }}</span>
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
                <th>Distribuzione rank</th>
            </tr>
        </thead>
        <tbody>
            @forelse($branches as $branch)
                <tr>
                    <td>
                        <strong style="display:block;">{{ $branch['branch_root']->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $branch['branch_root']->email }}</span>
                    </td>
                    <td><span class="pill">{{ ucfirst($branch['branch_root']->mlm_rank) }}</span></td>
                    <td>{{ $branch['agent_count'] }}</td>
                    <td>{{ $branch['active_points'] }}</td>
                    <td style="font-size:12px;color:var(--ink-muted);">
                        @forelse($branch['rank_counts'] as $rank => $count)
                            {{ ucfirst($rank) }}: {{ $count }}@if(!$loop->last), @endif
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun agente in downline.</td></tr>
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
                    <td>{{ $entry->points }}</td>
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
                    <td><span class="pill">{{ ucfirst($payout->rank_at_time) }}</span></td>
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
