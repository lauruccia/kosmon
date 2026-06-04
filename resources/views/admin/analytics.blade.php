@extends('layouts.portal')

@section('page-actions')
<form method="GET" action="{{ route('admin.analytics') }}" style="display:flex;gap:6px;align-items:center;">
<label style="font-size:12px;font-weight:600;color:var(--ink-soft);">Periodo:</label>
<select name="days" onchange="this.form.submit()" class="form-control" style="width:auto;min-height:34px;padding:5px 10px;font-size:13px;">
@foreach([7=>'7 giorni',30=>'30 giorni',90=>'90 giorni',180=>'6 mesi'] as $val=>$label)
<option value="{{ $val }}" {{ $days==$val?'selected':'' }}>{{ $label }}</option>
@endforeach
</select>
</form>
@endsection


@section('content')
<style>
.analytics-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:1rem; margin-bottom:2rem; }
.a-card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:1.1rem 1.25rem; }
.a-card .a-label { font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:var(--text-secondary); margin-bottom:.4rem; }
.a-card .a-value { font-size:2rem; font-weight:700; color:var(--text-primary); line-height:1; }
.a-card .a-sub { font-size:.8rem; color:var(--text-secondary); margin-top:.3rem; }
.a-card.accent-green { border-left:4px solid #4caf50; }
.a-card.accent-blue  { border-left:4px solid #2196f3; }
.a-card.accent-orange{ border-left:4px solid #ff9800; }
.a-card.accent-red   { border-left:4px solid #f44336; }
.a-card.accent-purple{ border-left:4px solid #9c27b0; }
.section-title { font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:2rem 0 1rem; padding-bottom:.5rem; border-bottom:2px solid var(--border-color); }
.chart-wrap { background:var(--card-bg); border:1px solid var(--border-color); border-radius:10px; padding:1.25rem; margin-bottom:1.5rem; }
.chart-wrap h3 { font-size:.9rem; font-weight:600; margin-bottom:1rem; color:var(--text-secondary); }
.event-row { display:flex; align-items:center; gap:.75rem; padding:.4rem 0; border-bottom:1px solid var(--border-color); font-size:.85rem; }
.event-row:last-child { border-bottom:none; }
.event-name { flex:1; color:var(--text-primary); }
.event-bar-wrap { width:120px; background:var(--border-color); border-radius:4px; height:8px; overflow:hidden; }
.event-bar { height:100%; background:var(--primary-color,#6c47ff); border-radius:4px; }
.event-cnt { font-weight:600; color:var(--text-primary); min-width:30px; text-align:right; }
</style>

{{-- ========== WEBHOOK ========== --}}
<p class="section-title">&#128279; Webhook</p>
<div class="analytics-grid">
    <div class="a-card accent-blue">
        <div class="a-label">Totale webhook</div>
        <div class="a-value">{{ $webhookTotal }}</div>
        <div class="a-sub">{{ $webhookActive }} attivi</div>
    </div>
    <div class="a-card accent-green">
        <div class="a-label">Consegne ({{ $days }}gg)</div>
        <div class="a-value">{{ number_format($webhookDeliveries) }}</div>
        <div class="a-sub">{{ $webhookDeliveries - $webhookFailed }} successi</div>
    </div>
    <div class="a-card accent-red">
        <div class="a-label">Consegne fallite ({{ $days }}gg)</div>
        <div class="a-value">{{ number_format($webhookFailed) }}</div>
        <div class="a-sub">
            @if($webhookDeliveries > 0)
                {{ number_format($webhookFailed / $webhookDeliveries * 100, 1) }}% tasso errore
            @else
                —
            @endif
        </div>
    </div>
</div>

@if($webhookByEvent->isNotEmpty())
<div class="chart-wrap">
    <h3>Top eventi (ultimi {{ $days }} giorni)</h3>
    @php $maxEvt = $webhookByEvent->max('cnt') ?: 1; @endphp
    @foreach($webhookByEvent as $row)
    <div class="event-row">
        <span class="event-name">{{ $row->event }}</span>
        <div class="event-bar-wrap"><div class="event-bar" style="width:{{ round($row->cnt/$maxEvt*100) }}%"></div></div>
        <span class="event-cnt">{{ $row->cnt }}</span>
    </div>
    @endforeach
</div>
@endif

{{-- ========== API TOKENS ========== --}}
<p class="section-title">&#128273; Token API</p>
<div class="analytics-grid">
    <div class="a-card accent-blue">
        <div class="a-label">Token totali</div>
        <div class="a-value">{{ $apiTokenTotal }}</div>
        <div class="a-sub">{{ $apiTokenActive }} non scaduti</div>
    </div>
    <div class="a-card accent-green">
        <div class="a-label">Usati ({{ $days }}gg)</div>
        <div class="a-value">{{ $apiTokenUsedRecent }}</div>
        <div class="a-sub">token con attività recente</div>
    </div>
    <div class="a-card accent-orange">
        <div class="a-label">Inattivi</div>
        <div class="a-value">{{ $apiTokenTotal - $apiTokenUsedRecent }}</div>
        <div class="a-sub">nessuna chiamata negli ultimi {{ $days }}gg</div>
    </div>
</div>

{{-- ========== PIANI RATEALI ========== --}}
<p class="section-title">&#128197; Piani rateali</p>
<div class="analytics-grid">
    <div class="a-card accent-blue">
        <div class="a-label">Totale piani</div>
        <div class="a-value">{{ $planTotal }}</div>
    </div>
    <div class="a-card accent-orange">
        <div class="a-label">In attesa approvazione</div>
        <div class="a-value">{{ $planPendingApproval }}</div>
        <div class="a-sub">richiedono azione</div>
    </div>
    <div class="a-card accent-green">
        <div class="a-label">Attivi</div>
        <div class="a-value">{{ $planActive }}</div>
    </div>
    <div class="a-card">
        <div class="a-label">Completati</div>
        <div class="a-value">{{ $planCompleted }}</div>
    </div>
    <div class="a-card accent-red">
        <div class="a-label">Cancellati</div>
        <div class="a-value">{{ $planCancelled }}</div>
    </div>
</div>

{{-- Piani attivi/pending con opzione annulla --}}
@php
    $cancelablePlans = \App\Models\PaymentPlan::with(['fromAccount.company','toAccount.company'])
        ->whereIn('status', ['pending_approval','active'])
        ->latest()->take(20)->get();
@endphp
@if($cancelablePlans->isNotEmpty())
<div class="card card-pad" style="margin-bottom:24px;">
    <div class="eyebrow" style="margin-bottom:12px;">Piani annullabili (ultimi 20)</div>
    <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <thead>
            <tr style="border-bottom:1px solid var(--border);">
                <th style="text-align:left;padding:6px 8px;">Da</th>
                <th style="text-align:left;padding:6px 8px;">A</th>
                <th style="text-align:right;padding:6px 8px;">Importo (KY)</th>
                <th style="text-align:center;padding:6px 8px;">Stato</th>
                <th style="text-align:center;padding:6px 8px;">Azione</th>
            </tr>
        </thead>
        <tbody>
        @foreach($cancelablePlans as $plan)
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:6px 8px;">{{ $plan->fromAccount?->company?->name ?? $plan->fromAccount?->display_name ?? '—' }}</td>
                <td style="padding:6px 8px;">{{ $plan->toAccount?->company?->name ?? $plan->toAccount?->display_name ?? '—' }}</td>
                <td style="text-align:right;padding:6px 8px;">{{ ky_format($plan->total_amount) }}</td>
                <td style="text-align:center;padding:6px 8px;"><span class="chip {{ $plan->status === 'active' ? 'success' : '' }}" style="font-size:11px;">{{ $plan->status }}</span></td>
                <td style="text-align:center;padding:6px 8px;">
                    <form method="POST" action="{{ route('admin.payment-plans.cancel', $plan) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="cta secondary" style="font-size:11px;padding:4px 10px;"
                            onclick="return confirm('Annullare questo piano rateale?')">Annulla</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- ========== PAGAMENTI PROGRAMMATI ========== --}}
<p class="section-title">&#9200; Pagamenti programmati</p>
<div class="analytics-grid">
    <div class="a-card accent-blue">
        <div class="a-label">Creati ({{ $days }}gg)</div>
        <div class="a-value">{{ $schedRecent }}</div>
    </div>
    <div class="a-card accent-orange">
        <div class="a-label">In attesa</div>
        <div class="a-value">{{ $schedPending }}</div>
        <div class="a-sub">da eseguire</div>
    </div>
    <div class="a-card accent-green">
        <div class="a-label">Eseguiti</div>
        <div class="a-value">{{ $schedExecuted }}</div>
    </div>
    <div class="a-card accent-red">
        <div class="a-label">Falliti</div>
        <div class="a-value">{{ $schedFailed }}</div>
    </div>
    <div class="a-card">
        <div class="a-label">Cancellati</div>
        <div class="a-value">{{ $schedCancelled }}</div>
    </div>
</div>

{{-- ========== RICHIESTE TESTUALI ========== --}}
<p class="section-title">&#128203; Richieste di pagamento</p>
<div class="analytics-grid">
    <div class="a-card accent-blue">
        <div class="a-label">Totale richieste</div>
        <div class="a-value">{{ $textTotal }}</div>
    </div>
    <div class="a-card accent-orange">
        <div class="a-label">In attesa</div>
        <div class="a-value">{{ $textPending }}</div>
    </div>
    <div class="a-card accent-green">
        <div class="a-label">Approvate</div>
        <div class="a-value">{{ $textApproved }}</div>
        @if($textTotal > 0)
        <div class="a-sub">{{ number_format($textApproved/$textTotal*100,1) }}% tasso approvazione</div>
        @endif
    </div>
    <div class="a-card accent-red">
        <div class="a-label">Rifiutate</div>
        <div class="a-value">{{ $textRejected }}</div>
    </div>
    <div class="a-card">
        <div class="a-label">Annullate</div>
        <div class="a-value">{{ $textCancelled }}</div>
    </div>
</div>

{{-- ========== NETTING ========== --}}
<p class="section-title">&#8652; Compensazioni (Netting)</p>
<div class="analytics-grid">
    <div class="a-card accent-blue">
        <div class="a-label">Proposte totali</div>
        <div class="a-value">{{ $nettingTotal }}</div>
    </div>
    <div class="a-card accent-orange">
        <div class="a-label">In attesa</div>
        <div class="a-value">{{ $nettingPending }}</div>
    </div>
    <div class="a-card accent-green">
        <div class="a-label">Accettate</div>
        <div class="a-value">{{ $nettingAccepted }}</div>
        @if($nettingTotal > 0)
        <div class="a-sub">{{ number_format($nettingAccepted/$nettingTotal*100,1) }}% tasso accettazione</div>
        @endif
    </div>
    <div class="a-card accent-red">
        <div class="a-label">Rifiutate</div>
        <div class="a-value">{{ $nettingRejected }}</div>
    </div>
    <div class="a-card accent-purple">
        <div class="a-label">Volume nettato (KY)</div>
        <div class="a-value" style="font-size:1.4rem;">{{ number_format($nettingVolume) }}</div>
        <div class="a-sub">crediti compensati totali</div>
    </div>
</div>

{{-- Proposte netting pending con opzione annulla --}}
@php
    $cancelableNetting = \App\Models\NettingProposal::with(['proposerAccount.company','counterpartyAccount.company'])
        ->where('status', 'pending')
        ->latest()->take(20)->get();
@endphp
@if($cancelableNetting->isNotEmpty())
<div class="card card-pad" style="margin-bottom:24px;">
    <div class="eyebrow" style="margin-bottom:12px;">Proposte netting in attesa</div>
    <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <thead>
            <tr style="border-bottom:1px solid var(--border);">
                <th style="text-align:left;padding:6px 8px;">Proponente</th>
                <th style="text-align:left;padding:6px 8px;">Controparte</th>
                <th style="text-align:right;padding:6px 8px;">Netto (KY)</th>
                <th style="text-align:center;padding:6px 8px;">Scade</th>
                <th style="text-align:center;padding:6px 8px;">Azione</th>
            </tr>
        </thead>
        <tbody>
        @foreach($cancelableNetting as $prop)
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:6px 8px;">{{ $prop->proposerAccount?->company?->name ?? '—' }}</td>
                <td style="padding:6px 8px;">{{ $prop->counterpartyAccount?->company?->name ?? '—' }}</td>
                <td style="text-align:right;padding:6px 8px;">{{ ky_format($prop->net_amount) }}</td>
                <td style="text-align:center;padding:6px 8px;font-size:11px;color:var(--text-muted);">
                    {{ $prop->expires_at?->format('d/m H:i') ?? '—' }}
                </td>
                <td style="text-align:center;padding:6px 8px;">
                    <form method="POST" action="{{ route('admin.netting.cancel', $prop) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="cta secondary" style="font-size:11px;padding:4px 10px;"
                            onclick="return confirm('Annullare questa proposta netting?')">Annulla</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- ========== GRAFICO TREND ========== --}}
@if($schedMonthly->isNotEmpty())
<div class="chart-wrap" style="margin-bottom:2rem;">
    <h3>Pagamenti programmati — trend ultimi 6 mesi</h3>
    <canvas id="schedChart" height="80"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
    const labels = {!! $schedMonthly->pluck('month')->toJson() !!};
    const data   = {!! $schedMonthly->pluck('cnt')->toJson() !!};
    const ctx = document.getElementById('schedChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Pagamenti programmati creati',
                data,
                backgroundColor: 'rgba(108,71,255,.7)',
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
})();
</script>
@endif

@endsection
