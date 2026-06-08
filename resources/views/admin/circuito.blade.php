@extends('layouts.portal')

@section('page-actions')
<form method="GET" action="{{ route('admin.circuito') }}" style="display:flex;gap:6px;align-items:center;">
    <label style="font-size:12px;font-weight:600;color:var(--ink-soft);">Periodo:</label>
    <select name="days" onchange="this.form.submit()" style="min-height:34px;padding:5px 10px;font-size:13px;border:1px solid var(--line);border-radius:6px;background:var(--surface);">
        @foreach([7=>'7 giorni',30=>'30 giorni',90=>'90 giorni',180=>'6 mesi'] as $val=>$label)
            <option value="{{ $val }}" {{ $days==$val?'selected':'' }}>{{ $label }}</option>
        @endforeach
    </select>
</form>
@endsection

@section('content')
<style>
/* ── layout ───────────────────────────────── */
.circ-grid   { display:grid; gap:14px; }
.circ-row    { display:grid; gap:14px; }
.circ-2col   { grid-template-columns: minmax(0,1fr) minmax(0,1fr); }
.circ-3col   { grid-template-columns: repeat(3,minmax(0,1fr)); }
.circ-panel  {
    background:var(--surface); border:1px solid var(--line);
    border-radius:12px; box-shadow:var(--shadow); padding:18px 20px;
}
.circ-panel-lg { padding:20px 22px; }

/* ── label / value ────────────────────────── */
.circ-label  {
    display:block; font-size:10px; font-weight:800; letter-spacing:.12em;
    text-transform:uppercase; color:var(--ink-muted); margin-bottom:3px;
}
.circ-value  { font-size:26px; font-weight:900; line-height:1; color:var(--ink); }
.circ-sub    { font-size:12px; color:var(--ink-soft); margin-top:5px; }
.circ-panel-title {
    margin:0 0 14px; font-size:18px; line-height:1.15;
    display:flex; align-items:center; gap:8px;
}
.circ-panel-title small { font-size:11px; font-weight:600; color:var(--ink-muted); }

/* ── KPI strip ────────────────────────────── */
.circ-kpis   {
    display:grid; gap:12px;
    grid-template-columns: repeat(auto-fill,minmax(160px,1fr));
}
.circ-kpi    {
    background:var(--surface-soft); border:1px solid var(--line);
    border-radius:10px; padding:14px 16px;
    display:grid; align-content:space-between; gap:8px;
}

/* ── velocity bar ─────────────────────────── */
.vel-bar-wrap { height:10px; background:var(--surface-hover); border-radius:999px; overflow:hidden; margin:8px 0; }
.vel-bar      { height:100%; border-radius:inherit; background:linear-gradient(90deg,var(--primary),var(--teal,#20c997)); }

/* ── trend chart ──────────────────────────── */
.trend-row   {
    display:grid; grid-template-columns:48px minmax(0,1fr) 100px 64px;
    gap:8px; align-items:center; font-size:12px; padding:4px 0;
    border-bottom:1px solid var(--line);
}
.trend-row:last-child { border-bottom:0; }
.trend-track { height:8px; background:var(--surface-hover); border-radius:999px; overflow:hidden; }
.trend-fill  { height:100%; border-radius:inherit; }

/* ── anomaly ──────────────────────────────── */
.anom-list   { display:grid; gap:10px; }
.anom-item   {
    display:grid; grid-template-columns:8px minmax(0,1fr); gap:14px;
    align-items:start; padding:12px 14px; border-radius:10px;
    border:1px solid var(--line); background:var(--surface-soft);
}
.anom-item.high   { border-color:var(--danger,#ef4444); background:rgba(239,68,68,.04); }
.anom-item.medium { border-color:var(--warning,#f59e0b); background:rgba(245,158,11,.04); }
.anom-dot    { width:8px; height:8px; border-radius:50%; margin-top:5px; }
.anom-dot.high   { background:var(--danger,#ef4444); }
.anom-dot.medium { background:var(--warning,#f59e0b); }
.anom-dot.low    { background:var(--ink-muted); }
.anom-title  { font-weight:700; font-size:13px; }
.anom-detail { font-size:12px; color:var(--ink-soft); margin-top:2px; line-height:1.4; }
.anom-at     { font-size:11px; color:var(--ink-muted); margin-top:3px; }

/* ── network ──────────────────────────────── */
#network-svg { width:100%; height:460px; }

@media(max-width:960px) {
    .circ-2col,.circ-3col { grid-template-columns:1fr; }
}
</style>

<div class="circ-grid">

    {{-- ══ SECTION 1: VELOCITY ══ --}}
    <section>
        <div class="circ-panel" style="border-left:4px solid var(--primary);">
            <h2 class="circ-panel-title">
                📊 Circolazione monetaria KY
                <small>— ultimi {{ $days }} giorni</small>
            </h2>

            <div class="circ-kpis">
                <div class="circ-kpi">
                    <span class="circ-label">KY in circolazione</span>
                    <span class="circ-value" style="font-size:20px;">{{ ky_format($kyInCirculation) }} KY</span>
                    <span class="circ-sub">Somma saldi conti attivi</span>
                </div>
                <div class="circ-kpi">
                    <span class="circ-label">Volume periodo</span>
                    <span class="circ-value" style="font-size:20px;">{{ ky_format($volumePeriod) }} KY</span>
                    <span class="circ-sub">{{ number_format($transactionCount) }} transazioni</span>
                </div>
                <div class="circ-kpi">
                    <span class="circ-label">Velocity monetaria (30d)</span>
                    <span class="circ-value">{{ $velocity30d > 0 ? number_format($velocity30d, 2) : '—' }}</span>
                    <span class="circ-sub">Volte che 1 KY cambia mano/mese</span>
                </div>
                <div class="circ-kpi">
                    <span class="circ-label">Giorni di rotazione</span>
                    <span class="circ-value">{{ $rotationDays !== null ? $rotationDays : '—' }}</span>
                    <span class="circ-sub">Giorni medi per ricircolazione</span>
                </div>
                <div class="circ-kpi">
                    <span class="circ-label">Partecipazione</span>
                    <span class="circ-value">{{ $participationRate }}%</span>
                    <span class="circ-sub">{{ $activeParticipants }} attivi su {{ $totalAccounts }} conti</span>
                </div>
                <div class="circ-kpi">
                    <span class="circ-label">Importo medio transazione</span>
                    <span class="circ-value" style="font-size:20px;">{{ ky_format($avgAmount) }} KY</span>
                    <span class="circ-sub">Saldo medio conto: {{ ky_format($avgBalance) }} KY</span>
                </div>
            </div>

            @if($velocity30d > 0)
            <div style="margin-top:18px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ink-muted);margin-bottom:4px;">
                    <span>Intensità circolazione</span>
                    <span>{{ number_format($velocity30d, 2) }}× / mese</span>
                </div>
                <div class="vel-bar-wrap">
                    @php $velPct = min(100, round($velocity30d / 2 * 100)); @endphp
                    <div class="vel-bar" style="width:{{ $velPct }}%;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--ink-muted);">
                    <span>Bassa (&lt;0.5×)</span><span>Media (1×)</span><span>Alta (&gt;2×)</span>
                </div>
            </div>
            @endif
        </div>
    </section>

    {{-- ══ TREND MENSILE ══ --}}
    @if($velocityTrend->isNotEmpty())
    <section class="circ-panel">
        <h2 class="circ-panel-title">📈 Trend transazionale — ultimi 6 mesi</h2>
        @php
            $maxVol = $velocityTrend->max('volume') ?: 1;
            $maxCnt = $velocityTrend->max('cnt') ?: 1;
        @endphp
        <div>
            @foreach($velocityTrend as $row)
            @php
                $volPct = max(2, round($row->volume / $maxVol * 100));
                $cntPct = max(2, round($row->cnt / $maxCnt * 100));
            @endphp
            <div class="trend-row">
                <strong>{{ \Carbon\Carbon::createFromFormat('Y-m', $row->ym)->locale('it')->isoFormat('MMM YY') }}</strong>
                <div>
                    <div class="trend-track">
                        <div class="trend-fill" style="width:{{ $volPct }}%;background:var(--primary);"></div>
                    </div>
                    <div class="trend-track" style="height:4px;margin-top:3px;">
                        <div class="trend-fill" style="width:{{ $cntPct }}%;background:var(--success,#22c55e);"></div>
                    </div>
                </div>
                <span style="text-align:right;font-weight:800;font-size:11px;white-space:nowrap;">{{ ky_format((int)$row->volume) }} KY</span>
                <span style="text-align:right;color:var(--ink-muted);font-size:11px;">{{ $row->cnt }} txn</span>
            </div>
            @endforeach
        </div>
        <div style="margin-top:10px;display:flex;gap:16px;font-size:11px;color:var(--ink-muted);">
            <span style="display:flex;align-items:center;gap:4px;">
                <span style="width:12px;height:4px;border-radius:2px;background:var(--primary);display:inline-block;"></span> Volume KY
            </span>
            <span style="display:flex;align-items:center;gap:4px;">
                <span style="width:12px;height:4px;border-radius:2px;background:var(--success,#22c55e);display:inline-block;"></span> N° transazioni
            </span>
        </div>
    </section>
    @endif

    {{-- ══ SECTION 2: RETE ══ --}}
    <section class="circ-panel circ-panel-lg">
        <h2 class="circ-panel-title">
            🕸 Rete transazioni — flussi tra conti
            <small>— top {{ $networkLinks->count() }} coppie per volume</small>
        </h2>

        @if($networkNodes->isEmpty())
            <div class="empty-state">Nessuna transazione nel periodo selezionato.</div>
        @else
            <div style="margin-bottom:10px;font-size:12px;color:var(--ink-muted);">
                {{ $networkNodes->count() }} nodi · {{ $networkLinks->count() }} flussi —
                Dimensione nodo = saldo, spessore arco = volume. Trascina i nodi per esplorare.
            </div>
            <svg id="network-svg"></svg>

            <script>
            (function() {
                const nodes = @json($networkNodes->toArray());
                const links = @json($networkLinks->toArray());

                const svg = document.getElementById('network-svg');
                const W = svg.clientWidth || 800;
                const H = 460;

                // Simple force simulation via vanilla JS (no external lib required)
                // Use D3 from CDN
                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/d3/7.9.0/d3.min.js';
                script.onload = () => buildGraph(nodes, links, W, H);
                document.head.appendChild(script);

                function buildGraph(nodes, links, W, H) {
                    const maxVol = Math.max(...links.map(l => l.volume), 1);
                    const maxBal = Math.max(...nodes.map(n => Math.abs(n.balance)), 1);

                    const color = d3.scaleOrdinal(d3.schemeTableau10);

                    const sim = d3.forceSimulation(nodes)
                        .force('link', d3.forceLink(links)
                            .id(d => d.id)
                            .distance(d => 80 + (1 - d.volume / maxVol) * 120)
                            .strength(0.6))
                        .force('charge', d3.forceManyBody().strength(-180))
                        .force('center', d3.forceCenter(W / 2, H / 2))
                        .force('collision', d3.forceCollide(28));

                    const svgEl = d3.select('#network-svg')
                        .attr('viewBox', `0 0 ${W} ${H}`)
                        .style('background', 'var(--surface-soft)')
                        .style('border-radius', '10px');

                    // Arrowhead def
                    const defs = svgEl.append('defs');
                    defs.append('marker')
                        .attr('id', 'arrow')
                        .attr('viewBox', '0 -4 8 8')
                        .attr('refX', 22).attr('refY', 0)
                        .attr('markerWidth', 5).attr('markerHeight', 5)
                        .attr('orient', 'auto')
                        .append('path').attr('d', 'M0,-4L8,0L0,4').attr('fill', '#aaa');

                    const link = svgEl.append('g').selectAll('line')
                        .data(links).enter().append('line')
                        .attr('stroke', '#aaa')
                        .attr('stroke-opacity', 0.55)
                        .attr('stroke-width', d => Math.max(1, Math.round(d.volume / maxVol * 8)))
                        .attr('marker-end', 'url(#arrow)');

                    const node = svgEl.append('g').selectAll('g')
                        .data(nodes).enter().append('g')
                        .call(d3.drag()
                            .on('start', (ev, d) => { if (!ev.active) sim.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; })
                            .on('drag',  (ev, d) => { d.fx = ev.x; d.fy = ev.y; })
                            .on('end',   (ev, d) => { if (!ev.active) sim.alphaTarget(0); d.fx = null; d.fy = null; }));

                    node.append('circle')
                        .attr('r', d => Math.max(8, Math.min(22, 8 + Math.abs(d.balance) / maxBal * 14)))
                        .attr('fill', (d, i) => color(i))
                        .attr('fill-opacity', 0.85)
                        .attr('stroke', '#fff')
                        .attr('stroke-width', 1.5);

                    node.append('text')
                        .attr('text-anchor', 'middle')
                        .attr('dy', '0.32em')
                        .attr('font-size', '9px')
                        .attr('fill', '#fff')
                        .attr('pointer-events', 'none')
                        .text(d => d.label.substring(0, 4));

                    node.append('title')
                        .text(d => `${d.label}\nSaldo: ${(d.balance/100).toLocaleString('it-IT',{minimumFractionDigits:2})} KY`);

                    sim.on('tick', () => {
                        link
                            .attr('x1', d => d.source.x).attr('y1', d => d.source.y)
                            .attr('x2', d => d.target.x).attr('y2', d => d.target.y);
                        node.attr('transform', d => `translate(${Math.max(20,Math.min(W-20,d.x))},${Math.max(20,Math.min(H-20,d.y))})`);
                    });
                }
            })();
            </script>

            {{-- Top 10 coppie per volume --}}
            <div style="margin-top:18px;">
                <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:var(--ink-muted);">TOP 10 FLUSSI PER VOLUME</div>
                @php $top10 = $networkLinks->sortByDesc('volume')->take(10); $maxLinkVol = $top10->max('volume') ?: 1; @endphp
                @foreach($top10 as $link)
                @php
                    $src = $networkNodes->firstWhere('id', $link['source']);
                    $tgt = $networkNodes->firstWhere('id', $link['target']);
                    $pct = round($link['volume'] / $maxLinkVol * 100);
                @endphp
                <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 100px 64px;gap:8px;align-items:center;font-size:11px;padding:4px 0;border-bottom:1px solid var(--line);">
                    <span style="font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $src['label'] ?? '?' }}</span>
                    <span style="color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">→ {{ $tgt['label'] ?? '?' }}</span>
                    <div style="height:6px;background:var(--surface-hover);border-radius:999px;overflow:hidden;">
                        <div style="height:100%;width:{{ $pct }}%;background:var(--primary);border-radius:inherit;"></div>
                    </div>
                    <span style="text-align:right;font-weight:800;white-space:nowrap;">{{ ky_format((int)$link['volume']) }}</span>
                </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ══ SECTION 3: ANOMALIE ══ --}}
    <section class="circ-panel">
        <h2 class="circ-panel-title">
            🚨 Alert automatici — comportamenti anomali
            @if($anomalies->isNotEmpty())
                <span class="pill {{ $anomalies->where('severity','high')->isNotEmpty() ? 'pink' : '' }}" style="font-size:11px;">
                    {{ $anomalies->count() }} alert{{ $anomalies->where('severity','high')->isNotEmpty() ? ' — ⚠ HIGH' : '' }}
                </span>
            @endif
        </h2>

        @if($anomalies->isEmpty())
            <div style="padding:24px 0;text-align:center;color:var(--ink-muted);">
                <div style="font-size:28px;margin-bottom:8px;">✅</div>
                <div style="font-size:14px;font-weight:600;">Nessuna anomalia rilevata nel periodo</div>
                <div style="font-size:12px;margin-top:4px;">Il circuito opera nei parametri attesi.</div>
            </div>
        @else
            <div class="anom-list">
                @foreach($anomalies as $a)
                <div class="anom-item {{ $a['severity'] }}">
                    <div class="anom-dot {{ $a['severity'] }}"></div>
                    <div>
                        <div class="anom-title">
                            {{ match($a['type']) {
                                'large_transaction' => '💸',
                                'burst_activity'    => '⚡',
                                'near_credit_limit' => '🔴',
                                'kyc_missing'       => '📋',
                                default             => '⚠️',
                            } }}
                            {{ $a['title'] }}
                            <span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:4px;margin-left:4px;
                                background:{{ $a['severity']==='high' ? 'rgba(239,68,68,.12)' : 'rgba(245,158,11,.12)' }};
                                color:{{ $a['severity']==='high' ? 'var(--danger,#ef4444)' : 'var(--warning,#f59e0b)' }};">
                                {{ strtoupper($a['severity']) }}
                            </span>
                        </div>
                        <div class="anom-detail">{{ $a['detail'] }}</div>
                        @if($a['at'])
                            <div class="anom-at">{{ $a['at'] }}</div>
                        @endif
                        @if($a['link'])
                            <a href="{{ $a['link'] }}" class="cta secondary" style="margin-top:8px;font-size:11px;padding:4px 10px;">
                                Esamina →
                            </a>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Legenda --}}
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);font-size:11px;color:var(--ink-muted);display:flex;flex-wrap:wrap;gap:12px;">
                <span><span style="color:var(--danger,#ef4444);font-weight:900;">● HIGH</span> — richiede attenzione immediata</span>
                <span><span style="color:var(--warning,#f59e0b);font-weight:900;">● MEDIUM</span> — monitorare nelle prossime 24h</span>
            </div>
            <div style="margin-top:8px;font-size:11px;color:var(--ink-muted);">
                Soglie: transazione anomala = 5× media periodo · burst = 3× media giornaliera in 24h · fido al 90%
            </div>
        @endif
    </section>

</div>
@endsection
