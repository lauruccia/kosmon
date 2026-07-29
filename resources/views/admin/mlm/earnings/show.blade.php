@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ route('admin.mlm.earnings.index') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">← Torna al report guadagni</a>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:8px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">Guadagni — {{ $agent->name }}</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">{{ $agent->email }}</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="{{ route('admin.mlm.earnings.show-export', $agent) }}" class="btn btn-secondary">Esporta CSV</a>
            <a href="{{ route('admin.mlm.show', $agent) }}" class="btn btn-secondary">Scheda agente</a>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Commissioni (tot.)</span>
        <strong style="font-size:18px;">&euro; {{ number_format($totals['commissions_total_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Bonus (tot.)</span>
        <strong style="font-size:18px;">&euro; {{ number_format($totals['bonus_total_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Gia' pagato</span>
        <strong style="font-size:18px;color:#166534;">&euro; {{ number_format($totals['total_paid_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Da pagare</span>
        <strong style="font-size:18px;color:#b45309;">&euro; {{ number_format($totals['total_outstanding_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
</div>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Storico liquidazioni</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12px;">Le liquidazioni gia' "Pagate" qui sotto sono gia' state saldate: non riliquidare lo stesso periodo.</p>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Stato</th>
                <th style="text-align:right;">Totale</th>
                <th>Pagamento</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($payouts as $payout)
                <tr>
                    <td>{{ $payout->period_from->format('d/m/Y') }} — {{ $payout->period_to->format('d/m/Y') }}</td>
                    <td style="text-transform:capitalize;">{{ $payout->status }}</td>
                    <td style="text-align:right;">&euro; {{ number_format($payout->total_eur_cents / 100, 2, ',', '.') }}</td>
                    <td>
                        @if($payout->status === 'paid')
                            {{ $payout->paid_at?->format('d/m/Y') }} @if($payout->payment_reference)· rif. {{ $payout->payment_reference }}@endif
                        @else
                            —
                        @endif
                    </td>
                    <td><a href="{{ route('admin.mlm.payouts.show', $payout) }}" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;">Dettaglio</a></td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuna liquidazione ancora generata per questo agente.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px 0;">
        <h3 style="margin:0 0 4px;font-size:15px;">Commissioni</h3>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Tipo</th>
                <th>Cliente sorgente</th>
                <th>Agente sorgente</th>
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
                    <td>{{ $commission->sourceAgent->name ?? '—' }}</td>
                    <td style="text-align:right;">&euro; {{ number_format($commission->amount_eur_cents / 100, 2, ',', '.') }}</td>
                    <td style="text-transform:capitalize;">{{ $commission->status }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuna commissione.</td></tr>
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
                <tr><td colspan="4" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun bonus.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div style="padding:12px 16px;">{{ $bonuses->links() }}</div>
</section>
@endsection
