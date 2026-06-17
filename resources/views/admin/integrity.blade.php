@extends('layouts.portal')

@section('content')
<style>
.int-grid  { display:grid; gap:16px; }
.int-panel {
    background:var(--surface); border:1px solid var(--line);
    border-radius:12px; box-shadow:var(--shadow); padding:20px 22px;
}
.int-panel-title {
    margin:0 0 16px; font-size:17px; font-weight:800;
    display:flex; align-items:center; gap:8px;
}
/* banner stato */
.int-banner {
    border-radius:12px; padding:18px 22px;
    display:grid; grid-template-columns:auto 1fr auto; gap:16px; align-items:center;
}
.int-banner.ok   { background:rgba(34,197,94,.08);  border:1px solid rgba(34,197,94,.3); }
.int-banner.err  { background:rgba(239,68,68,.07);  border:1px solid rgba(239,68,68,.3); }
.int-banner.warn { background:rgba(245,158,11,.07); border:1px solid rgba(245,158,11,.3); }
.int-icon { font-size:32px; line-height:1; }
.int-banner-title { font-size:16px; font-weight:800; }
.int-banner-sub   { font-size:13px; color:var(--ink-soft); margin-top:3px; }
/* KPI mini */
.int-kpis { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; margin-bottom:18px; }
.int-kpi  {
    background:var(--surface-soft); border:1px solid var(--line);
    border-radius:10px; padding:12px 14px;
}
.int-kpi-label { font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:var(--ink-muted); }
.int-kpi-value { font-size:22px; font-weight:900; line-height:1.2; color:var(--ink); }
.int-kpi-sub   { font-size:11px; color:var(--ink-soft); margin-top:2px; }
/* tabelle */
.int-table { width:100%; border-collapse:collapse; font-size:13px; }
.int-table th {
    text-align:left; font-size:10px; font-weight:800; letter-spacing:.1em;
    text-transform:uppercase; color:var(--ink-muted); padding:6px 10px;
    border-bottom:2px solid var(--line);
}
.int-table td { padding:9px 10px; border-bottom:1px solid var(--line); vertical-align:middle; }
.int-table tr:last-child td { border-bottom:0; }
.int-table tr:hover td { background:var(--surface-soft); }
/* diff colori */
.diff-pos { color:var(--success,#22c55e); font-weight:700; }
.diff-neg { color:var(--danger,#ef4444); font-weight:700; }
/* badge */
.badge { display:inline-block; border-radius:6px; padding:2px 8px; font-size:11px; font-weight:700; }
.badge-ok   { background:rgba(34,197,94,.12);  color:#15803d; }
.badge-err  { background:rgba(239,68,68,.12);  color:#b91c1c; }
.badge-warn { background:rgba(245,158,11,.12); color:#b45309; }
/* form inline */
.fix-form { display:inline; }
@media(max-width:800px){
    .int-banner { grid-template-columns:auto 1fr; }
    .int-banner > :last-child { grid-column:1/-1; }
}
</style>

<div class="int-grid">

    {{-- ══ BANNER STATO GLOBALE ══ --}}
    @php
        $bannerClass = $allHealthy ? 'ok' : ($circuitHealthy ? 'warn' : 'err');
        $bannerIcon  = $allHealthy ? '✅' : ($circuitHealthy ? '⚠️' : '🚨');
    @endphp
    <div class="int-banner {{ $bannerClass }}">
        <div class="int-icon">{{ $bannerIcon }}</div>
        <div>
            @if($allHealthy)
                <div class="int-banner-title">Circuito in perfetto equilibrio</div>
                <div class="int-banner-sub">Tutti gli invarianti contabili sono verificati. Somma saldi = 0, nessun conto disallineato, nessun transfer sbilanciato.</div>
            @elseif($circuitHealthy)
                <div class="int-banner-title">Circuito bilanciato, ma ci sono anomalie di dettaglio</div>
                <div class="int-banner-sub">
                    La somma globale dei saldi è corretta (≈ 0), ma:
                    @if($mismatchCount > 0) {{ $mismatchCount }} conto/i con saldo disallineato dal ledger; @endif
                    @if($unbalancedCount > 0) {{ $unbalancedCount }} transfer booked senza ledger entry bilanciata. @endif
                </div>
            @else
                <div class="int-banner-title">⚡ Circuito non bilanciato — intervento richiesto</div>
                <div class="int-banner-sub">
                    Somma di tutti i saldi = <strong>{{ ky_format($totalBalance) }} KY</strong>
                    (deve essere 0).
                    @if($mismatchCount > 0) {{ $mismatchCount }} conti disallineati. @endif
                    @if($unbalancedCount > 0) {{ $unbalancedCount }} transfer sbilanciati. @endif
                </div>
            @endif
        </div>
        <div>
            <a href="{{ route('admin.integrity.index') }}" class="cta secondary" style="white-space:nowrap;">
                🔄 Ricarica
            </a>
        </div>
    </div>

    {{-- ══ KPI RAPIDI ══ --}}
    <div class="int-panel" style="padding:16px 20px;">
        <div class="int-kpis">
            <div class="int-kpi">
                <div class="int-kpi-label">KY in circolazione</div>
                <div class="int-kpi-value" style="font-size:18px;">{{ ky_format($kyInCirculation) }}</div>
                <div class="int-kpi-sub">Saldo negativo conto sistema</div>
            </div>
            <div class="int-kpi">
                <div class="int-kpi-label">Somma globale saldi</div>
                <div class="int-kpi-value" style="font-size:18px;color:{{ abs($totalBalance)<=1 ? 'var(--success,#22c55e)' : 'var(--danger,#ef4444)' }};">
                    {{ $totalBalance > 0 ? '+' : '' }}{{ ky_format($totalBalance) }}
                </div>
                <div class="int-kpi-sub">Deve essere 0,00</div>
            </div>
            <div class="int-kpi">
                <div class="int-kpi-label">Conti disallineati</div>
                <div class="int-kpi-value" style="color:{{ $mismatchCount > 0 ? 'var(--danger,#ef4444)' : 'var(--success,#22c55e)' }};">
                    {{ $mismatchCount }}
                </div>
                <div class="int-kpi-sub">su {{ $totalAccounts }} totali</div>
            </div>
            <div class="int-kpi">
                <div class="int-kpi-label">Transfer sbilanciati</div>
                <div class="int-kpi-value" style="color:{{ $unbalancedCount > 0 ? 'var(--danger,#ef4444)' : 'var(--success,#22c55e)' }};">
                    {{ $unbalancedCount }}
                </div>
                <div class="int-kpi-sub">con ledger incompleto</div>
            </div>
            @if($systemAccount)
            <div class="int-kpi">
                <div class="int-kpi-label">Saldo conto sistema</div>
                <div class="int-kpi-value" style="font-size:18px;">{{ ky_format($systemAccount->available_balance) }}</div>
                <div class="int-kpi-sub">{{ $systemAccount->account_name }}</div>
            </div>
            @endif
        </div>

        {{-- Indicatore visivo: barra saldo globale --}}
        @if(!$circuitHealthy)
        <div style="padding:12px 14px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:8px;font-size:13px;">
            <strong style="color:var(--danger,#ef4444);">⚠ Circuito non chiuso:</strong>
            la somma di tutti gli <code>available_balance</code> vale
            <strong>{{ ky_format($totalBalance) }} KY</strong> invece di 0,00 KY.
            Differenza: <strong>{{ $totalBalance > 0 ? '+' : '' }}{{ ky_format($totalBalance) }} KY</strong>.
            Possibile causa: saldo impostato manualmente senza ledger entry corrispondente,
            o emissione KY non registrata tramite Transfer.
        </div>
        @endif
    </div>

    {{-- ══ CONTI DISALLINEATI ══ --}}
    <div class="int-panel">
        <h2 class="int-panel-title">
            🔍 Conti con saldo disallineato dal ledger
            @if($mismatchCount > 0)
                <span class="badge badge-err">{{ $mismatchCount }}</span>
            @else
                <span class="badge badge-ok">✓ nessuno</span>
            @endif

            @if($mismatchCount > 0)
            <form method="POST" action="{{ route('admin.integrity.fix-all') }}" class="fix-form"
                  onsubmit="return confirm('Ricalcolare il saldo di tutti i {{ $mismatchCount }} conti disallineati dal ledger?\n\nL\'operazione è irreversibile ma verrà registrata nell\'Audit Log.');"
                  style="margin-left:auto;">
                @csrf
                <button type="submit" class="cta" style="font-size:12px;padding:6px 14px;background:var(--danger,#ef4444);border:none;color:#fff;border-radius:7px;cursor:pointer;font-weight:700;">
                    ⚡ Correggi tutti ({{ $mismatchCount }})
                </button>
            </form>
            @endif
        </h2>

        @if($mismatchCount === 0)
            <div style="padding:24px 0;text-align:center;color:var(--ink-muted);font-size:14px;">
                Tutti i saldi sono allineati alle rispettive ledger entry.
            </div>
        @else
            <div style="font-size:12px;color:var(--ink-muted);margin-bottom:12px;">
                Il saldo mostrato in <strong>available_balance</strong> differisce di oltre 1 centesimo
                dalla somma algebrica delle <strong>ledger_entries</strong> (crediti − debiti).
                La fonte di verità è il ledger. Usa "Correggi" per riallineare.
            </div>
            <div style="overflow-x:auto;">
            <table class="int-table">
                <thead>
                    <tr>
                        <th>Conto</th>
                        <th>Nome</th>
                        <th style="text-align:right">Saldo attuale</th>
                        <th style="text-align:right">Saldo ledger</th>
                        <th style="text-align:right">Differenza</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mismatchedAccounts as $row)
                    @php
                        $diff = (int)$row->ledger_balance - (int)$row->available_balance;
                        $diffClass = $diff >= 0 ? 'diff-pos' : 'diff-neg';
                    @endphp
                    <tr>
                        <td>
                            <code style="font-size:11px;">{{ $row->uuid }}</code>
                            @if($row->is_system_account)
                                <span class="badge badge-warn" style="margin-left:4px;">sistema</span>
                            @endif
                        </td>
                        <td>{{ $row->account_name ?: '—' }}</td>
                        <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">
                            {{ ky_format((int)$row->available_balance) }} KY
                        </td>
                        <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">
                            {{ ky_format((int)$row->ledger_balance) }} KY
                        </td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;" class="{{ $diffClass }}">
                            {{ $diff >= 0 ? '+' : '' }}{{ ky_format($diff) }} KY
                        </td>
                        <td style="text-align:right;">
                            <form method="POST" action="{{ route('admin.integrity.fix-account', $row->id) }}" class="fix-form"
                                  onsubmit="return confirm('Ricalcolare il saldo di questo conto dal ledger?\n\nSaldo attuale: {{ ky_format((int)$row->available_balance) }} KY\nSaldo ledger:  {{ ky_format((int)$row->ledger_balance) }} KY\n\nVerrà registrato in Audit Log.');">
                                @csrf
                                <button type="submit" class="cta secondary" style="font-size:11px;padding:4px 10px;border:1px solid var(--danger,#ef4444);color:var(--danger,#ef4444);background:transparent;border-radius:6px;cursor:pointer;font-weight:700;">
                                    Correggi
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>

            <div style="margin-top:12px;padding:10px 14px;background:var(--surface-soft);border-radius:8px;font-size:12px;color:var(--ink-muted);">
                <strong>Come funziona la correzione:</strong>
                ricalcola <code>available_balance = SUM(credit) − SUM(debit)</code>
                dalle <code>ledger_entries</code> del conto e aggiorna il record con lock pessimistico.
                L'operazione viene registrata nell'Audit Log.
            </div>
        @endif
    </div>

    {{-- ══ TRANSFER SBILANCIATI ══ --}}
    <div class="int-panel">
        <h2 class="int-panel-title">
            ⚖️ Transfer booked senza ledger bilanciato
            @if($unbalancedCount > 0)
                <span class="badge badge-err">{{ $unbalancedCount }}</span>
            @else
                <span class="badge badge-ok">✓ nessuno</span>
            @endif
        </h2>

        @if($unbalancedCount === 0)
            <div style="padding:24px 0;text-align:center;color:var(--ink-muted);font-size:14px;">
                Tutti i transfer booked hanno le proprie ledger entry bilanciate.
            </div>
        @else
            <div style="font-size:12px;color:var(--ink-muted);margin-bottom:12px;">
                Ogni transfer con <code>status = booked</code> deve avere esattamente
                <strong>2 ledger entry</strong>: 1 debit e 1 credit, entrambi pari ad <code>amount</code>.
                I seguenti transfer non rispettano questo invariante — richiedono verifica manuale
                (potrebbero indicare un errore nel <code>TransferBookingService</code>
                o una corruzione dei dati).
            </div>
            <div style="overflow-x:auto;">
            <table class="int-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>UUID</th>
                        <th>Da / A</th>
                        <th>Kind</th>
                        <th style="text-align:right">Amount</th>
                        <th style="text-align:right">Debit LE</th>
                        <th style="text-align:right">Credit LE</th>
                        <th style="text-align:right">Entry</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($unbalancedTransfers as $row)
                    @php
                        $debitOk  = (int)$row->total_debit  === (int)$row->amount;
                        $creditOk = (int)$row->total_credit === (int)$row->amount;
                        $cntOk    = (int)$row->entry_count  === 2;
                    @endphp
                    <tr>
                        <td style="color:var(--ink-muted);font-size:12px;">#{{ $row->id }}</td>
                        <td><code style="font-size:10px;">{{ substr($row->uuid, 0, 8) }}…</code></td>
                        <td style="font-size:12px;max-width:180px;">
                            <span title="{{ $row->from_name }}">{{ Str::limit($row->from_name ?? '?', 18) }}</span>
                            <br><span style="color:var(--ink-muted);">→ {{ Str::limit($row->to_name ?? '?', 18) }}</span>
                        </td>
                        <td><code style="font-size:11px;">{{ $row->kind }}</code></td>
                        <td style="text-align:right;font-weight:700;">{{ ky_format((int)$row->amount) }}</td>
                        <td style="text-align:right;" class="{{ $debitOk ? 'diff-pos' : 'diff-neg' }}">
                            {{ ky_format((int)$row->total_debit) }}
                            @if(!$debitOk) ⚠ @endif
                        </td>
                        <td style="text-align:right;" class="{{ $creditOk ? 'diff-pos' : 'diff-neg' }}">
                            {{ ky_format((int)$row->total_credit) }}
                            @if(!$creditOk) ⚠ @endif
                        </td>
                        <td style="text-align:right;" class="{{ $cntOk ? '' : 'diff-neg' }}">
                            {{ (int)$row->entry_count }}
                            @if(!$cntOk) ⚠ @endif
                        </td>
                        <td style="font-size:11px;white-space:nowrap;color:var(--ink-muted);">
                            {{ $row->booked_at ? \Carbon\Carbon::parse($row->booked_at)->format('d/m/y H:i') : '—' }}
                        </td>
                        <td>
                            <a href="{{ route('admin.transfers.index', ['search' => $row->uuid]) }}" class="cta secondary" style="font-size:11px;padding:4px 10px;">
                                Cerca →
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div style="margin-top:12px;padding:10px 14px;background:rgba(239,68,68,.05);border:1px solid rgba(239,68,68,.15);border-radius:8px;font-size:12px;color:var(--ink-soft);">
                <strong>Nota:</strong> i transfer sbilanciati non possono essere corretti automaticamente
                perché mancano le ledger entry da cui ricalcolare.
                Aprire ciascun transfer, verificare il contesto e applicare una correzione manuale
                (nota di credito o storno) tramite il pannello movimenti.
            </div>
        @endif
    </div>

    {{-- ══ LEGENDA INVARIANTI ══ --}}
    <div class="int-panel" style="padding:16px 20px;">
        <h2 class="int-panel-title" style="font-size:14px;margin-bottom:10px;">📋 Invarianti del circuito chiuso</h2>
        <div style="display:grid;gap:8px;font-size:13px;">
            <div style="display:grid;grid-template-columns:24px 1fr;gap:10px;align-items:start;">
                <span class="badge {{ $circuitHealthy ? 'badge-ok' : 'badge-err' }}" style="text-align:center;">{{ $circuitHealthy ? '✓' : '✗' }}</span>
                <span>
                    <strong>SUM(available_balance) = 0</strong> — in un circuito chiuso la somma algebrica
                    di tutti i saldi (incluso il conto sistema) deve essere zero: ogni KY emesso
                    è un debito del conto sistema e un credito di un conto utente.
                </span>
            </div>
            <div style="display:grid;grid-template-columns:24px 1fr;gap:10px;align-items:start;">
                <span class="badge {{ $mismatchCount === 0 ? 'badge-ok' : 'badge-err' }}" style="text-align:center;">{{ $mismatchCount === 0 ? '✓' : '✗' }}</span>
                <span>
                    <strong>available_balance = Σ ledger_entries</strong> — il saldo di ogni conto
                    deve corrispondere alla somma algebrica delle sue ledger entry (credit − debit).
                    Scostamenti indicano aggiornamenti diretti del saldo senza passare dal
                    <code>TransferBookingService</code>.
                </span>
            </div>
            <div style="display:grid;grid-template-columns:24px 1fr;gap:10px;align-items:start;">
                <span class="badge {{ $unbalancedCount === 0 ? 'badge-ok' : 'badge-err' }}" style="text-align:center;">{{ $unbalancedCount === 0 ? '✓' : '✗' }}</span>
                <span>
                    <strong>Ogni transfer booked ha 2 ledger entry bilanciate</strong> — il motore di booking
                    deve sempre generare 1 debit (conto mittente) e 1 credit (conto destinatario),
                    entrambi pari ad <code>amount</code>.
                </span>
            </div>
        </div>
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line);font-size:12px;color:var(--ink-muted);">
            Puoi eseguire la stessa verifica via CLI:
            <code style="background:var(--surface-soft);padding:2px 6px;border-radius:4px;">php artisan accounting:verify-integrity</code>
        </div>
    </div>

</div>
@endsection
