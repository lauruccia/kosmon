@extends('layouts.portal')

@section('content')
<style>
.rep-grid { display:grid; gap:20px; max-width:1000px; }

/* Header */
.rep-header {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;
}
.rep-title { font-size:22px; font-weight:800; color:var(--ink); margin:0 0 4px; }
.rep-sub { font-size:13px; color:var(--ink-muted); margin:0; }

/* Period selector */
.rep-period-bar {
    display:flex; gap:6px; flex-wrap:wrap;
    background:#fff; border:1px solid var(--line); border-radius:10px; padding:4px;
}
.rep-period-btn {
    padding:7px 14px; border-radius:8px; border:none; background:transparent;
    font-size:13px; font-weight:600; color:var(--ink-muted); cursor:pointer; transition:all .15s;
}
.rep-period-btn.active, .rep-period-btn:hover {
    background:var(--ink); color:#fff;
}

/* KPI grid */
.rep-kpis { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; }
@media(max-width:900px){ .rep-kpis { grid-template-columns:repeat(3,1fr); } }
@media(max-width:600px){ .rep-kpis { grid-template-columns:repeat(2,1fr); } }
.rep-kpi {
    background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 18px;
}
.rep-kpi-val { font-size:22px; font-weight:800; color:var(--ink); letter-spacing:-.03em; margin-bottom:4px; }
.rep-kpi-lbl { font-size:11px; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.07em; }
.rep-kpi-val.green { color:#059669; }
.rep-kpi-val.red   { color:#dc2626; }

/* Card */
.rep-card {
    background:#fff; border:1px solid var(--line); border-radius:14px; padding:20px 22px;
}
.rep-card-title { font-size:15px; font-weight:700; color:var(--ink); margin:0 0 16px; }

/* Chart */
.rep-chart-wrap { overflow-x:auto; }
canvas#rep-chart { max-height:220px; }

/* Top payers */
.rep-payer-list { display:flex; flex-direction:column; gap:8px; }
.rep-payer {
    display:flex; align-items:center; gap:12px;
    padding:10px 14px; border:1px solid var(--line); border-radius:10px;
}
.rep-payer-rank { width:24px; height:24px; border-radius:50%; background:var(--ink); color:#fff; font-size:11px; font-weight:800; display:grid; place-items:center; flex-shrink:0; }
.rep-payer-name { flex:1; font-size:13px; font-weight:600; color:var(--ink); }
.rep-payer-val { font-size:14px; font-weight:800; color:#059669; }
.rep-payer-sub { font-size:11px; color:var(--ink-muted); }

/* Movements table */
.rep-table { width:100%; border-collapse:collapse; font-size:13px; }
.rep-table th { text-align:left; font-size:11px; font-weight:700; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.07em; padding:0 0 8px; border-bottom:1px solid var(--line); }
.rep-table td { padding:10px 0; border-bottom:1px solid var(--line); color:var(--ink); vertical-align:middle; }
.rep-table tr:last-child td { border-bottom:none; }
.rep-amount { font-weight:700; }
.rep-amount.in  { color:#059669; }
.rep-amount.out { color:#dc2626; }
.rep-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; background:#f3f4f6; color:#374151; }
</style>

<div class="rep-grid">

    {{-- Header + period --}}
    <div class="rep-header">
        <div>
            <h1 class="rep-title">📊 Report merchant</h1>
            <p class="rep-sub">{{ $currentAccount->company?->name ?? $currentUser->name }} · {{ $currentAccount->account_number }}</p>
        </div>
        <div class="rep-period-bar">
            @foreach(['mese' => 'Questo mese', 'trimestre' => 'Trimestre', '6mesi' => '6 mesi', 'anno' => 'Anno'] as $val => $label)
                <button class="rep-period-btn {{ $period === $val ? 'active' : '' }}"
                    onclick="setPeriodo('{{ $val }}')">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- KPIs --}}
    <div class="rep-kpis">
        <div class="rep-kpi">
            <div class="rep-kpi-val green">+{{ ky_format($incomeTotal) }}</div>
            <div class="rep-kpi-lbl">Incassato KY</div>
        </div>
        <div class="rep-kpi">
            <div class="rep-kpi-val red">−{{ ky_format($expenseTotal) }}</div>
            <div class="rep-kpi-lbl">Speso KY</div>
        </div>
        <div class="rep-kpi">
            <div class="rep-kpi-val green">+{{ ky_format($cashbackTotal) }}</div>
            <div class="rep-kpi-lbl">Cashback ricevuto</div>
        </div>
        <div class="rep-kpi">
            <div class="rep-kpi-val red">−{{ ky_format($feeTotal) }}</div>
            <div class="rep-kpi-lbl">Commissioni pagate</div>
        </div>
        <div class="rep-kpi">
            <div class="rep-kpi-val">{{ $txCount }}</div>
            <div class="rep-kpi-lbl">Transazioni</div>
        </div>
    </div>

    {{-- Grafico trend --}}
    <div class="rep-card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <div class="rep-card-title" style="margin:0;">Trend ultimi 12 mesi</div>
            <a href="{{ route('portal.merchant-report.csv', ['periodo' => $period]) }}"
               class="cta secondary" style="padding:6px 14px;font-size:12px;">⬇ CSV</a>
        </div>
        <div class="rep-chart-wrap">
            <canvas id="rep-chart"></canvas>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

        {{-- Top payers --}}
        <div class="rep-card">
            <div class="rep-card-title">Top 5 pagatori</div>
            @if($topPayers->isEmpty())
                <p style="color:var(--ink-muted);font-size:13px;margin:0;">Nessun pagamento nel periodo.</p>
            @else
            <div class="rep-payer-list">
                @foreach($topPayers as $i => $payer)
                    @php
                        $name = $payer->fromAccount?->company?->name
                             ?? $payer->fromAccount?->ownerUser?->name
                             ?? '—';
                    @endphp
                    <div class="rep-payer">
                        <div class="rep-payer-rank">{{ $i + 1 }}</div>
                        <div>
                            <div class="rep-payer-name">{{ $name }}</div>
                            <div class="rep-payer-sub">{{ $payer->tx_count }} transazioni</div>
                        </div>
                        <div>
                            <div class="rep-payer-val">{{ ky_format($payer->total) }} KY</div>
                        </div>
                    </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Ultimi movimenti --}}
        <div class="rep-card" style="overflow-x:auto;">
            <div class="rep-card-title">Ultimi movimenti</div>
            <table class="rep-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Controparte</th>
                        <th>Importo</th>
                        <th>Tipo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransfers as $t)
                        @php
                            $isIn = (int)$t->to_account_id === $currentAccount->id;
                            $other = $isIn
                                ? ($t->fromAccount?->company?->name ?? $t->fromAccount?->ownerUser?->name ?? '—')
                                : ($t->toAccount?->company?->name  ?? $t->toAccount?->ownerUser?->name  ?? '—');
                        @endphp
                        <tr>
                            <td style="white-space:nowrap;">{{ $t->booked_at?->format('d/m/y H:i') }}</td>
                            <td>{{ $other }}</td>
                            <td class="rep-amount {{ $isIn ? 'in' : 'out' }}">
                                {{ $isIn ? '+' : '−' }}{{ ky_format($t->amount) }}
                            </td>
                            <td><span class="rep-badge">{{ $t->kind }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="color:var(--ink-muted);padding:16px 0;">Nessun movimento.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const months = @json($months->pluck('label'));
const income  = @json($months->pluck('income')->map(fn($v) => round($v / 100, 2)));
const expense = @json($months->pluck('expense')->map(fn($v) => round($v / 100, 2)));

new Chart(document.getElementById('rep-chart'), {
    type: 'bar',
    data: {
        labels: months,
        datasets: [
            {
                label: 'Incassato KY',
                data: income,
                backgroundColor: 'rgba(5,150,105,.7)',
                borderRadius: 5,
            },
            {
                label: 'Speso KY',
                data: expense,
                backgroundColor: 'rgba(220,38,38,.55)',
                borderRadius: 5,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { ticks: { callback: v => v.toLocaleString('it-IT') + ' KY' } },
        },
    },
});

function setPeriodo(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('periodo', val);
    window.location.href = url.toString();
}
</script>
@endsection
