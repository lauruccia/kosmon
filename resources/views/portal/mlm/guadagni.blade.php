@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">I miei guadagni</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">Dettaglio di ogni commissione e bonus maturato. Per richiedere un prelievo vai su <a href="{{ route('portal.mlm.prelievi') }}">Prelievi</a>.</p>
        </div>
        <a href="{{ route('portal.mlm.earnings.export') }}" class="btn btn-secondary">Esporta CSV</a>
    </div>
</div>

<div class="card card-pad" style="margin-bottom:14px;background:rgba(12,74,134,.05);border:1px solid rgba(12,74,134,.15);">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
        <div>
            <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Il tuo cassetto kmoney</span>
            <strong style="font-size:24px;">&euro; {{ number_format($walletBalance / 100, 2, ',', '.') }}</strong>
            <p style="margin:6px 0 0;font-size:12px;color:var(--ink-muted);max-width:520px;">
                Compensi diretti, indiretti, estesi e bonus vengono accreditati qui automaticamente (i bonus ogni mercoledi', le provvigioni il 1&deg; del mese) e sono gia' spendibili come kmoney nel tuo saldo. A differenza del resto del tuo kmoney, puoi anche convertirli in &euro; da <a href="{{ route('portal.mlm.prelievi') }}">Prelievi</a> — finche' non li spendi.
            </p>
        </div>
        <a href="{{ route('portal.mlm.prelievi') }}" class="btn btn-primary">Vai ai prelievi</a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px;">
        <div>
            <span style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--ink-muted);">Diretti</span>
            <strong style="font-size:15px;">&euro; {{ number_format($walletBreakdown['diretta'] / 100, 2, ',', '.') }}</strong>
        </div>
        <div>
            <span style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--ink-muted);">Indiretti</span>
            <strong style="font-size:15px;">&euro; {{ number_format($walletBreakdown['indiretta'] / 100, 2, ',', '.') }}</strong>
        </div>
        <div>
            <span style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--ink-muted);">Estesi</span>
            <strong style="font-size:15px;">&euro; {{ number_format($walletBreakdown['estesa'] / 100, 2, ',', '.') }}</strong>
        </div>
        <div>
            <span style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--ink-muted);">Bonus</span>
            <strong style="font-size:15px;">&euro; {{ number_format($walletBreakdown['bonus'] / 100, 2, ',', '.') }}</strong>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Maturato totale (storico)</span>
        <strong style="font-size:20px;">&euro; {{ number_format($totals['total_earned_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Gia' pagato</span>
        <strong style="font-size:20px;color:#166534;">&euro; {{ number_format($totals['total_paid_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Da pagare</span>
        <strong style="font-size:20px;color:#b45309;">&euro; {{ number_format($totals['total_outstanding_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
</div>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Commissioni</h3>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Tipo</th>
                <th>Cliente</th>
                <th style="text-align:right;">Importo</th>
                <th>Stato</th>
            </tr>
        </thead>
        <tbody>
            @forelse($commissions as $commission)
                <tr>
                    <td>{{ $commission->run?->period_month?->format('m/Y') ?? '—' }}</td>
                    <td style="text-transform:capitalize;">{{ $commission->type }}</td>
                    <td>{{ $commission->sourceClient->name ?? '—' }}</td>
                    <td style="text-align:right;">&euro; {{ number_format($commission->amount_eur_cents / 100, 2, ',', '.') }}</td>
                    <td style="text-transform:capitalize;">{{ $commission->status }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuna commissione ancora maturata.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div style="padding:12px 16px;">{{ $commissions->links() }}</div>
</section>

<section class="card light-card">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Bonus</h3>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Settimana</th>
                <th>Qualifica</th>
                <th style="text-align:right;">Importo</th>
                <th>Stato</th>
            </tr>
        </thead>
        <tbody>
            @forelse($bonuses as $bonus)
                <tr>
                    <td>{{ $bonus->week_ending?->format('d/m/Y') }}</td>
                    <td><span class="pill">{{ $bonus->kind === 'diretto' ? 'Bonus diretto' : ($bonus->kind === 'extra' ? 'Extra Bonus ' . ucfirst((string) $bonus->rank_at_time) : ucfirst((string) $bonus->rank_at_time)) }}</span></td>
                    <td style="text-align:right;">&euro; {{ number_format($bonus->amount_eur_cents / 100, 2, ',', '.') }}</td>
                    <td style="text-transform:capitalize;">{{ $bonus->status }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun bonus ancora maturato.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div style="padding:12px 16px;">{{ $bonuses->links() }}</div>
</section>
@endsection
