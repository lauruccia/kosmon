@extends('layouts.portal')

@php
    $eur = fn (int $cents) => number_format($cents / 100, 2, ',', '.') . ' €';
@endphp

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">MLM — Simulatore compensi</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">
                Calcola in anteprima punti, commissioni e bonus di struttura usando gli <strong>stessi motori di calcolo di produzione</strong>.
                Nessuna simulazione scrive nulla nel database (rollback automatico): puoi provare quante volte vuoi, anche sui dati reali.
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="{{ route('admin.mlm.index') }}" class="btn btn-secondary">← Torna agli agenti</a>
            <a href="{{ route('admin.mlm.settings.edit') }}" class="btn btn-secondary">Impostazioni qualifiche</a>
        </div>
    </div>
</div>

{{-- ── Ricerca utenti ── --}}
<form method="GET" action="{{ route('admin.mlm.simulator.show') }}" style="margin-bottom:10px;">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca utente</label>
            <input type="text" name="q" value="{{ $search }}" placeholder="Nome o email (filtra entrambe le tendine)"
                style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:260px;">
        </div>
        <button type="submit" class="btn btn-secondary">Filtra</button>
        @if($search !== '')
            <a href="{{ route('admin.mlm.simulator.show') }}" class="btn btn-secondary">Azzera</a>
        @endif
    </div>
</form>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px;">

    {{-- ── Scenario 1: ricarica cliente ── --}}
    <section class="card card-pad">
        <h3 style="margin:0 0 6px;font-size:15px;">1 · Simula una ricarica cliente</h3>
        <p style="margin:0 0 12px;color:var(--ink-muted);font-size:13px;">
            Un cliente fa una ricarica di X €: quanti punti prende il suo agente (tabella "Punti per evento" delle impostazioni MLM)
            e quali commissioni — dirette e indirette — produrrà nella struttura, una tantum al run del mese successivo.
        </p>

        @if($depositError)
            <p style="margin:0 0 10px;font-size:13px;color:var(--danger);font-weight:600;">{{ $depositError }}</p>
        @endif
        @error('client_id')<p style="margin:0 0 10px;font-size:13px;color:var(--danger);font-weight:600;">{{ $message }}</p>@enderror
        @error('amount_eur')<p style="margin:0 0 10px;font-size:13px;color:var(--danger);font-weight:600;">{{ $message }}</p>@enderror

        <form method="POST" action="{{ route('admin.mlm.simulator.deposit') }}">
            @csrf
            <input type="hidden" name="q" value="{{ $search }}">
            <div style="margin-bottom:10px;">
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cliente</label>
                <select name="client_id" required
                    style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);outline:none;width:100%;max-width:420px;">
                    <option value="">— scegli un cliente MLM —</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->id }}" @selected((string) old('client_id') === (string) $c->id)>{{ $c->name }} ({{ $c->email }})</option>
                    @endforeach
                </select>
                @if($clientsTruncated)
                    <span style="display:block;margin-top:4px;font-size:12px;color:var(--ink-muted);">Elenco limitato: usa la ricerca sopra per trovare altri clienti.</span>
                @endif
            </div>
            <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Importo ricarica (€)</label>
                    <input type="number" id="sim_amount_eur" name="amount_eur" min="0.01" max="10000000" step="0.01" required
                        value="{{ old('amount_eur') }}" placeholder="es. 1200"
                        style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);outline:none;width:160px;">
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;padding-bottom:2px;">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('sim_amount_eur').value=120">120 €</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('sim_amount_eur').value=1200">1.200 €</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('sim_amount_eur').value=2400">2.400 €</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('sim_amount_eur').value=3600">3.600 €</button>
                </div>
            </div>
            <button type="submit" style="padding:9px 18px;border-radius:8px;font-size:14px;background:var(--primary);color:#fff;border:none;font-weight:700;cursor:pointer;">Simula ricarica</button>
        </form>
    </section>

    {{-- ── Scenario 2: evento BasiQ ── --}}
    <section class="card card-pad">
        <h3 style="margin:0 0 6px;font-size:15px;">2 · Simula un evento BasiQ</h3>
        <p style="margin:0 0 12px;color:var(--ink-muted);font-size:13px;">
            Un agente diventa BasiQ (12 punti entro 30 giorni dall'attivazione): chi nella sua upline incassa il bonus
            di struttura e perché, con la regola "per posizione" e la regola speciale del Key (3° BasiQ).
        </p>

        @if($basiqError)
            <p style="margin:0 0 10px;font-size:13px;color:var(--danger);font-weight:600;">{{ $basiqError }}</p>
        @endif
        @error('agent_id')<p style="margin:0 0 10px;font-size:13px;color:var(--danger);font-weight:600;">{{ $message }}</p>@enderror

        <form method="POST" action="{{ route('admin.mlm.simulator.basiq') }}">
            @csrf
            <input type="hidden" name="q" value="{{ $search }}">
            <div style="margin-bottom:12px;">
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Agente che diventa BasiQ</label>
                <select name="agent_id" required
                    style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);outline:none;width:100%;max-width:420px;">
                    <option value="">— scegli un agente —</option>
                    @foreach($agents as $a)
                        <option value="{{ $a->id }}" @selected((string) old('agent_id') === (string) $a->id)>{{ $a->name }} ({{ ucfirst($a->mlm_rank) }} — {{ $a->email }})</option>
                    @endforeach
                </select>
                @if($agentsTruncated)
                    <span style="display:block;margin-top:4px;font-size:12px;color:var(--ink-muted);">Elenco limitato: usa la ricerca sopra per trovare altri agenti.</span>
                @endif
            </div>
            <button type="submit" style="padding:9px 18px;border-radius:8px;font-size:14px;background:var(--primary);color:#fff;border:none;font-weight:700;cursor:pointer;">Simula BasiQ</button>
        </form>
    </section>
</div>

{{-- ══ Risultati: ricarica ══ --}}
@if($depositResult)
    <section class="card card-pad" style="margin-bottom:14px;" id="risultato-ricarica">
        <h3 style="margin:0 0 4px;font-size:16px;">
            Risultato — ricarica di {{ $eur($depositResult['amount_eur_cents']) }} di {{ $depositResult['client']->name }}
        </h3>
        <p style="margin:0 0 14px;color:var(--ink-muted);font-size:13px;">
            Agente diretto: <strong>{{ $depositResult['agent']?->name ?? '—' }}</strong> ·
            Simulazione eseguita coi motori reali e annullata: <strong>nessun dato è stato salvato</strong>.
        </p>

        {{-- Punti --}}
        <h4 style="margin:0 0 6px;font-size:14px;">Punti cliente assegnati (tabella "Punti per evento")</h4>
        @if(count($depositResult['ledger_entries']) === 0)
            <p style="margin:0 0 14px;font-size:13px;color:var(--danger);font-weight:600;">
                Nessun punto assegnato: la ricarica è sotto il taglio minimo configurato nella tabella "Punti per evento" (cliente non "attivo"),
                oppure il cliente non ha un agente risolto. Sotto il taglio minimo non nasce nemmeno base commissionabile.
            </p>
        @else
            <div style="overflow-x:auto;margin-bottom:14px;">
                <table class="admin-table transactions-table">
                    <thead><tr><th>Agente beneficiario</th><th>Punti mensili</th><th>Validi dal</th><th>Fino al</th></tr></thead>
                    <tbody>
                        @foreach($depositResult['ledger_entries'] as $entry)
                            <tr>
                                <td><strong>{{ $entry['agent_name'] }}</strong></td>
                                <td>{{ mlm_points_format($entry['points']) }} pt</td>
                                <td>{{ $entry['valid_from'] }}</td>
                                <td>{{ $entry['valid_until'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Base commissionabile --}}
        @if(count($depositResult['base_entries']) > 0)
            <h4 style="margin:0 0 6px;font-size:14px;">Base commissionabile ("Prov K")</h4>
            <div style="overflow-x:auto;margin-bottom:14px;">
                <table class="admin-table transactions-table">
                    <thead><tr><th>Importo ricarica (una tantum)</th><th>Margine KNM</th><th>Prov K (base di tutte le %)</th></tr></thead>
                    <tbody>
                        @foreach($depositResult['base_entries'] as $entry)
                            <tr>
                                <td>{{ $eur($entry['monthly_amount_eur_cents']) }}</td>
                                <td>{{ $entry['knm_margin_percent'] }}%</td>
                                <td><strong>{{ $eur($entry['prov_k_eur_cents']) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Commissioni mensili --}}
        <h4 style="margin:0 0 6px;font-size:14px;">Commissioni generate dalla ricarica (run del {{ $depositResult['month']->format('d/m/Y') }})</h4>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12px;">
            Il motore commissioni gira il 1° di ogni mese: qui sotto c'è la <strong>differenza</strong> fra il run del
            {{ $depositResult['month']->format('d/m/Y') }} con e senza questa ricarica — cioè l'effetto della sola ricarica simulata,
            al netto di tutto il resto già attivo. La ricarica paga <strong>una sola volta</strong>, a quel run: nessuna ripetizione nei mesi successivi (decisione del 22/07/2026).
        </p>
        @if(count($depositResult['commissions']) === 0)
            <p style="margin:0;font-size:13px;color:var(--ink-muted);">
                Nessuna commissione: o la ricarica è sotto soglia, oppure nessun agente della struttura supera il gating
                (percentuale diretta 0% sotto i 6 punti attivi; livelli indiretti con requisiti minimi di punti e Basic).
            </p>
        @else
            <div style="overflow-x:auto;">
                <table class="admin-table transactions-table">
                    <thead><tr><th>Beneficiario</th><th>Tipo</th><th>Livello</th><th>Base (Prov K)</th><th>%</th><th>Importo</th></tr></thead>
                    <tbody>
                        @foreach($depositResult['commissions'] as $row)
                            <tr>
                                <td><strong>{{ $row['agent_name'] }}</strong></td>
                                <td>{{ ucfirst($row['type']) }}</td>
                                <td>{{ $row['level'] !== null ? $row['level'] . '°' : '—' }}</td>
                                <td>{{ $eur($row['base_amount_eur_cents']) }}</td>
                                <td>{{ rtrim(rtrim(number_format($row['percentage'], 3, ',', '.'), '0'), ',') }}%</td>
                                <td><strong>{{ $eur($row['amount_eur_cents']) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="text-align:right;font-weight:700;">Totale al mese</td>
                            <td><strong>{{ $eur($depositResult['total_commissions_eur_cents']) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>
@endif

{{-- ══ Risultati: BasiQ ══ --}}
@if($basiqResult)
    <section class="card card-pad" style="margin-bottom:14px;" id="risultato-basiq">
        <h3 style="margin:0 0 4px;font-size:16px;">
            Risultato — {{ $basiqResult['agent']->name }} diventa BasiQ
        </h3>
        <p style="margin:0 0 14px;color:var(--ink-muted);font-size:13px;">
            Cascata dei bonus di struttura sulla upline (regola "per posizione": ognuno incassa il bonus della propria
            qualifica meno il bonus più alto già presente sotto di lui).
            @if($basiqResult['week_ending'])
                In produzione i bonus verrebbero liquidati nel batch settimanale del <strong>{{ $basiqResult['week_ending'] }}</strong>.
            @endif
            Simulazione annullata: <strong>nessun dato è stato salvato</strong>.
        </p>

        @if(count($basiqResult['chain']) === 0)
            <p style="margin:0;font-size:13px;color:var(--ink-muted);">
                Questo agente non ha nessuno sopra di sé nell'albero (è una radice): nessun bonus da distribuire.
            </p>
        @else
            <div style="overflow-x:auto;">
                <table class="admin-table transactions-table">
                    <thead><tr><th>Liv. sopra</th><th>Agente</th><th>Qualifica</th><th>Bonus qualifica</th><th>Bonus percepito</th><th>Perché</th></tr></thead>
                    <tbody>
                        @foreach($basiqResult['chain'] as $row)
                            <tr>
                                <td>{{ $row['position'] }}°</td>
                                <td><strong>{{ $row['name'] }}</strong></td>
                                <td><span class="pill">{{ ucfirst($row['rank']) }}</span></td>
                                <td>{{ $row['tier_eur_cents'] !== null ? $eur($row['tier_eur_cents']) : '—' }}</td>
                                <td>
                                    @if($row['payout_eur_cents'] > 0)
                                        <strong style="color:var(--success, #1a7f37);">{{ $eur($row['payout_eur_cents']) }}</strong>
                                    @else
                                        <span style="color:var(--ink-muted);">0,00 €</span>
                                    @endif
                                </td>
                                <td style="font-size:12px;color:var(--ink-muted);max-width:340px;">{{ $row['note'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align:right;font-weight:700;">Totale distribuito</td>
                            <td><strong>{{ $eur($basiqResult['total_eur_cents']) }}</strong></td>
                            <td style="font-size:12px;color:var(--ink-muted);">= sempre il bonus della qualifica più alta presente in catena</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>
@endif
@endsection
