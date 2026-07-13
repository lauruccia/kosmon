@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ route('admin.mlm.index') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">← Torna agli agenti MLM</a>
    <h2 style="margin:8px 0 0;font-size:18px;">Liquidazioni EUR — MLM</h2>
</div>

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">In attesa</span>
        <strong style="font-size:22px;">{{ $kpis['pending'] }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Approvate (da pagare)</span>
        <strong style="font-size:22px;">{{ $kpis['approved'] }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Da liquidare (tot.)</span>
        <strong style="font-size:18px;">&euro; {{ number_format($kpis['pending_total_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Pagato (totale storico)</span>
        <strong style="font-size:18px;">&euro; {{ number_format($kpis['paid_total_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
</div>

<div class="card card-pad" style="margin-bottom:14px;">
    <h3 style="margin:0 0 10px;font-size:14px;">Calcola commissioni per mese</h3>
    <form method="POST" action="{{ route('admin.mlm.payouts.calculate-commissions') }}" style="display:flex;gap:10px;align-items:center;">
        @csrf
        <input type="month" name="month" required value="{{ now()->format('Y-m') }}" class="form-control" style="max-width:180px;">
        <button type="submit" class="btn btn-secondary">Calcola</button>
        <span style="font-size:11.5px;color:var(--ink-muted);">Esegue subito il calcolo mensile commissioni dirette/indirette (normalmente schedulato il 1° alle 02:00) — utile senza cron.</span>
    </form>
</div>

<div class="card card-pad" style="margin-bottom:14px;">
    <h3 style="margin:0 0 10px;font-size:14px;">Genera liquidazioni per mese</h3>
    <form method="POST" action="{{ route('admin.mlm.payouts.generate') }}" style="display:flex;gap:10px;align-items:center;">
        @csrf
        <input type="month" name="month" required value="{{ now()->format('Y-m') }}" class="form-control" style="max-width:180px;">
        <button type="submit" class="btn btn-primary">Genera</button>
        <span style="font-size:11.5px;color:var(--ink-muted);">Aggrega commissioni e bonus non ancora collegati a una liquidazione per il mese scelto.</span>
    </form>
</div>

<form method="GET" action="{{ route('admin.mlm.payouts.index') }}" style="margin-bottom:10px;">
    <input type="hidden" name="status" value="{{ $status }}">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca agente</label>
            <input type="text" name="q" value="{{ $search }}" placeholder="Nome o email"
                style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:220px;">
        </div>
        <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Filtra</button>
        @if($search)
            <a href="{{ route('admin.mlm.payouts.index', array_filter(['status' => $status])) }}" style="padding:8px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;">Azzera ricerca</a>
        @endif
    </div>
</form>

<section class="card light-card">
    <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3 style="margin:0;font-size:15px;">Liquidazioni</h3>
        <div style="display:flex;gap:6px;">
            <a href="{{ route('admin.mlm.payouts.index', array_filter(['q' => $search])) }}" class="pill {{ $status === '' ? 'active' : '' }}">Tutte</a>
            <a href="{{ route('admin.mlm.payouts.index', array_filter(['status' => 'pending', 'q' => $search])) }}" class="pill {{ $status === 'pending' ? 'active' : '' }}">In attesa</a>
            <a href="{{ route('admin.mlm.payouts.index', array_filter(['status' => 'approved', 'q' => $search])) }}" class="pill {{ $status === 'approved' ? 'active' : '' }}">Approvate</a>
            <a href="{{ route('admin.mlm.payouts.index', array_filter(['status' => 'paid', 'q' => $search])) }}" class="pill {{ $status === 'paid' ? 'active' : '' }}">Pagate</a>
            <a href="{{ route('admin.mlm.payouts.index', array_filter(['status' => 'rejected', 'q' => $search])) }}" class="pill {{ $status === 'rejected' ? 'active' : '' }}">Rifiutate</a>
        </div>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Agente</th>
                <th>Periodo</th>
                <th>Commissioni</th>
                <th>Bonus</th>
                <th>Totale</th>
                <th>Stato</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($payouts as $payout)
                <tr>
                    <td>
                        <strong style="display:block;">{{ $payout->agent->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $payout->agent->email }}</span>
                    </td>
                    <td>
                        {{ $payout->period_from->format('m/Y') }}
                        @if($payout->requested_at)
                            <span class="pill" style="background:rgba(12,74,134,.1);color:#0c4a86;font-size:11px;">Richiesta agente</span>
                        @endif
                    </td>
                    <td>&euro; {{ number_format($payout->commissions_total_eur_cents / 100, 2, ',', '.') }}</td>
                    <td>&euro; {{ number_format($payout->bonus_total_eur_cents / 100, 2, ',', '.') }}</td>
                    <td><strong>&euro; {{ number_format($payout->total_eur_cents / 100, 2, ',', '.') }}</strong></td>
                    <td style="text-transform:capitalize;">{{ $payout->status }}</td>
                    <td><a href="{{ route('admin.mlm.payouts.show', $payout) }}" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;">Dettaglio</a></td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuna liquidazione trovata.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div style="padding:12px 16px;">{{ $payouts->links() }}</div>
</section>
@endsection
