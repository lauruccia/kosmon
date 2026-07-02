@extends('layouts.portal')

@section('content')
@php
    $detail = $payout->agent->mlmPaymentDetail;
    $statusColor = match($payout->status) {
        'paid' => '#1a7a4a',
        'approved' => '#2563eb',
        'rejected' => '#dc2626',
        default => '#d97706',
    };
@endphp

<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ route('admin.mlm.payouts.index') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">← Torna alle liquidazioni</a>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:8px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">Liquidazione #{{ $payout->id }} — {{ $payout->agent->name }}</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">{{ $payout->agent->email }} · Periodo {{ $payout->period_from->format('d/m/Y') }} – {{ $payout->period_to->format('d/m/Y') }}</p>
            @if($payout->requested_at)
                <p style="margin:4px 0 0;font-size:12px;color:#0c4a86;font-weight:600;">Prelievo richiesto dall'agente il {{ $payout->requested_at->format('d/m/Y H:i') }}</p>
            @endif
        </div>
        <span class="pill" style="color:{{ $statusColor }};border-color:{{ $statusColor }};">{{ ucfirst($payout->status) }}</span>
    </div>
</div>

@if(session('portal_error'))
<div class="card card-pad" style="margin-bottom:14px;border-left:3px solid #dc2626;">{{ session('portal_error') }}</div>
@endif

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Commissioni</span>
        <strong style="font-size:20px;">&euro; {{ number_format($payout->commissions_total_eur_cents / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Bonus</span>
        <strong style="font-size:20px;">&euro; {{ number_format($payout->bonus_total_eur_cents / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Totale</span>
        <strong style="font-size:20px;">&euro; {{ number_format($payout->total_eur_cents / 100, 2, ',', '.') }}</strong>
    </div>
</div>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px;">
        <h3 style="margin:0 0 10px;font-size:15px;">Dati bancari agente</h3>
        @if($detail)
            @php
                $verifColor = match($detail->verification_status) {
                    'verified' => '#1a7a4a',
                    'rejected' => '#dc2626',
                    default => '#d97706',
                };
            @endphp
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;font-size:13px;">
                <div><span style="color:var(--ink-muted);">Intestatario:</span> {{ $detail->account_holder_name }}</div>
                <div><span style="color:var(--ink-muted);">IBAN:</span> <code>{{ $detail->iban }}</code></div>
                <div><span style="color:var(--ink-muted);">BIC/SWIFT:</span> {{ $detail->bic_swift ?? '—' }}</div>
                <div><span style="color:var(--ink-muted);">Banca:</span> {{ $detail->bank_name ?? '—' }}</div>
                <div><span style="color:var(--ink-muted);">Verifica:</span> <span style="color:{{ $verifColor }};font-weight:600;">{{ ucfirst($detail->verification_status) }}</span></div>
            </div>
            @if($detail->verification_status !== 'verified')
                <p style="margin:10px 0 0;font-size:12px;color:#d97706;">⚠ Dati bancari non ancora verificati — verificare prima di segnare la liquidazione come pagata.</p>
            @endif
        @else
            <p style="margin:0;color:#dc2626;font-size:13px;">⚠ L'agente non ha ancora inserito i dati bancari. Impossibile pagare finché non li fornisce dal portale.</p>
        @endif
    </div>
</section>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px;">
        <h3 style="margin:0 0 10px;font-size:15px;">Azioni</h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            @if($payout->status === 'pending')
                <form method="POST" action="{{ route('admin.mlm.payouts.approve', $payout) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Approva</button>
                </form>
                <form method="POST" action="{{ route('admin.mlm.payouts.reject', $payout) }}" onsubmit="return confirm('Rifiutare questa liquidazione? Le righe collegate torneranno disponibili per una futura generazione.');">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Rifiuta</button>
                </form>
            @elseif($payout->status === 'approved')
                <form method="POST" action="{{ route('admin.mlm.payouts.mark-paid', $payout) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    @csrf
                    <input type="text" name="payment_reference" required placeholder="Riferimento bonifico" class="form-control" style="max-width:220px;">
                    <button type="submit" class="btn btn-primary">Segna come pagata</button>
                </form>
                <form method="POST" action="{{ route('admin.mlm.payouts.reject', $payout) }}" onsubmit="return confirm('Rifiutare questa liquidazione già approvata?');">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Rifiuta</button>
                </form>
            @elseif($payout->status === 'paid')
                <p style="margin:0;font-size:13px;color:var(--ink-muted);">Pagata il {{ $payout->paid_at?->format('d/m/Y H:i') }} — rif. <strong>{{ $payout->payment_reference }}</strong>@if($payout->approvedBy) · approvata da {{ $payout->approvedBy->name }}@endif</p>
            @else
                <p style="margin:0;font-size:13px;color:var(--ink-muted);">Liquidazione rifiutata.@if($payout->admin_notes) Motivo: {{ $payout->admin_notes }}@endif</p>
            @endif
        </div>
    </div>
</section>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Commissioni incluse</h3>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Cliente sorgente</th>
                <th>Agente sorgente (indiretta)</th>
                <th>Livello</th>
                <th>Base</th>
                <th>%</th>
                <th>Importo</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payout->commissions as $commission)
                <tr>
                    <td style="text-transform:capitalize;">{{ $commission->type }}</td>
                    <td>{{ $commission->sourceClient->name ?? '—' }}</td>
                    <td>{{ $commission->sourceAgent->name ?? '—' }}</td>
                    <td>{{ $commission->level ?? '—' }}</td>
                    <td>&euro; {{ number_format($commission->base_amount_eur_cents / 100, 2, ',', '.') }}</td>
                    <td>{{ number_format($commission->percentage, 2, ',', '.') }}%</td>
                    <td>&euro; {{ number_format($commission->amount_eur_cents / 100, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuna commissione in questa liquidazione.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<section class="card light-card">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Bonus inclusi</h3>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Qualifica al momento</th>
                <th>Settimana</th>
                <th>Importo</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payout->bonusPayouts as $bonus)
                <tr>
                    <td><span class="pill">{{ ucfirst($bonus->rank_at_time) }}</span></td>
                    <td>{{ $bonus->week_ending->format('d/m/Y') }}</td>
                    <td>&euro; {{ number_format($bonus->amount_eur_cents / 100, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun bonus in questa liquidazione.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
@endsection
