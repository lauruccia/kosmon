@extends('layouts.portal')

@section('page-actions')
{{-- Export CSV col periodo attuale --}}
@php
$csvParams = http_build_query(array_filter([
    'period'    => $filters['period'],
    'from_date' => $filters['from_date'],
    'to_date'   => $filters['to_date'],
]));
@endphp
<a class="cta" href="{{ route('admin.report.export-csv') }}?{{ $csvParams }}">⬇ Esporta CSV</a>
<a class="cta secondary" href="{{ route('admin.transfers.index') }}">Movimenti</a>
@endsection




@section('content')
{{-- Filtro periodo --}}
<section class="card light-card" style="margin-bottom:22px;">
    <form method="get" action="{{ route('admin.report') }}" class="field-grid">
        <div class="field-grid" style="grid-template-columns:220px 1fr 1fr auto;gap:14px;align-items:end;">
            <div class="field">
                <label>Periodo</label>
                <select name="period">
                    @foreach ($periodOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Da data</label>
                <input type="date" name="from_date" value="{{ $filters['from_date'] }}">
            </div>
            <div class="field">
                <label>A data</label>
                <input type="date" name="to_date" value="{{ $filters['to_date'] }}">
            </div>
            <div class="form-actions" style="margin-top:0;">
                <button type="submit" class="cta secondary">Applica</button>
            </div>
        </div>
    </form>
</section>

{{-- KPI del periodo --}}
<section class="grid-cards" style="margin-bottom:22px;">
    <article class="stat-card">
        <div class="eyebrow">Periodo</div>
        <div class="section-title" style="font-size:22px;">{{ $filters['label'] }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Transazioni</div>
        <div class="section-title" style="font-size:30px;">{{ ky_format($totals['bookedCount']) }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Volume totale</div>
        <div class="section-title" style="font-size:30px;">{{ ky_format($totals['volume']) }} <small style="font-size:14px;color:var(--text-muted);">KY</small></div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Media per transazione</div>
        <div class="section-title" style="font-size:30px;">
            {{ $totals['bookedCount'] > 0 ? ky_format($totals['volume'] / $totals['bookedCount']) : '—' }}
            <small style="font-size:14px;color:var(--text-muted);">KY</small>
        </div>
    </article>
</section>

{{-- Grafico volumi --}}
<section class="card card-pad" style="margin-bottom:22px;">
    <div class="section-head">
        <div>
            <span class="eyebrow">Andamento</span>
            <h3 class="section-title">Volumi per periodo</h3>
        </div>
        <span class="pill">
            {{ match($chartData['granularity'] ?? 'month') {
                'day'   => 'Granularità giornaliera',
                'week'  => 'Granularità settimanale',
                default => 'Granularità mensile',
            } }}
        </span>
    </div>
    <div style="position:relative;height:260px;margin-top:16px;">
        <canvas id="volumeChart"></canvas>
    </div>
</section>

{{-- Top 10 aziende --}}
<div class="portal-grid" style="--grid-cols:2;margin-bottom:22px;">
    {{-- Tabella --}}
    <section class="card card-pad">
        <div class="section-head">
            <div>
                <span class="eyebrow">Classifiche</span>
                <h3 class="section-title">Top 10 aziende per volume</h3>
            </div>
        </div>
        <table class="data-table" style="margin-top:16px;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Azienda</th>
                    <th style="text-align:right;">Volume KY</th>
                    <th style="text-align:right;">Transazioni</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($topCompanies as $i => $company)
                    <tr>
                        <td><span class="pill" style="min-width:28px;text-align:center;">{{ $i + 1 }}</span></td>
                        <td>
                            <a href="{{ route('admin.companies.show', $company->slug) }}" style="color:var(--accent);font-weight:600;">
                                {{ $company->name }}
                            </a>
                        </td>
                        <td style="text-align:right;font-weight:700;font-family:monospace;">
                            {{ ky_format($company->volume) }}
                        </td>
                        <td style="text-align:right;color:var(--text-muted);">
                            {{ ky_format($company->tx_count) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:24px;">Nessun dato per il periodo selezionato.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Bar chart top aziende --}}
    <section class="card card-pad">
        <div class="section-head">
            <div>
                <span class="eyebrow">Visualizzazione</span>
                <h3 class="section-title">Volume per azienda</h3>
            </div>
        </div>
        <div style="position:relative;height:280px;margin-top:16px;">
            <canvas id="companiesChart"></canvas>
        </div>
    </section>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    const isDark = document.documentElement.classList.contains('dark')
        || window.matchMedia('(prefers-color-scheme: dark)').matches;

    const gridColor  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)';
    const textColor  = isDark ? '#a0aec0' : '#6b7280';
    const accentMain = '#6d28d9';
    const accentSoft = 'rgba(109,40,217,0.18)';

    // ── Grafico volumi ───────────────────────────────────────────────────────
    const chartLabels  = @json($chartData['labels'] ?? []);
    const chartVolumes = @json($chartData['volumes'] ?? []);
    const chartCounts  = @json($chartData['counts'] ?? []);

    if (chartLabels.length > 0) {
        const ctx = document.getElementById('volumeChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Volume KY',
                        data: chartVolumes,
                        backgroundColor: accentMain,
                        borderRadius: 5,
                        yAxisID: 'y',
                    },
                    {
                        label: 'N° transazioni',
                        data: chartCounts,
                        type: 'line',
                        borderColor: '#f59e0b',
                        backgroundColor: 'transparent',
                        pointBackgroundColor: '#f59e0b',
                        borderWidth: 2,
                        tension: 0.4,
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: textColor } },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                if (ctx.datasetIndex === 0)
                                    return ' Volume: ' + ctx.raw.toLocaleString('it-IT') + ' KY';
                                return ' Transazioni: ' + ctx.raw.toLocaleString('it-IT');
                            }
                        }
                    }
                },
                scales: {
                    x:  { ticks: { color: textColor }, grid: { color: gridColor } },
                    y:  { ticks: { color: textColor }, grid: { color: gridColor }, position: 'left',
                          title: { display: true, text: 'Volume KY', color: textColor } },
                    y1: { ticks: { color: '#f59e0b' }, grid: { display: false }, position: 'right',
                          title: { display: true, text: 'N° transazioni', color: '#f59e0b' } },
                },
            },
        });
    } else {
        document.getElementById('volumeChart').parentElement.innerHTML =
            '<p style="text-align:center;color:var(--text-muted);padding:60px 0;">Nessun dato per il periodo selezionato.</p>';
    }

    // ── Barchart top aziende ─────────────────────────────────────────────────
    const companyNames   = @json($topCompanies->pluck('name')->all());
    const companyVolumes = @json($topCompanies->pluck('volume')->map(fn($v) => (int)$v)->all());

    if (companyNames.length > 0) {
        const ctx2 = document.getElementById('companiesChart').getContext('2d');

        // Palette sfumata viola→blu
        const palette = companyNames.map((_, i) => {
            const h = 260 + i * 8;
            return `hsl(${h}, 65%, ${isDark ? 55 : 45}%)`;
        });

        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: companyNames,
                datasets: [{
                    label: 'Volume KY',
                    data: companyVolumes,
                    backgroundColor: palette,
                    borderRadius: 5,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.raw.toLocaleString('it-IT') + ' KY'
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: textColor }, grid: { color: gridColor } },
                    y: { ticks: { color: textColor }, grid: { display: false } },
                },
            },
        });
    }
})();
</script>
@endsection
