@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ route('admin.mlm.index') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">← Torna agli agenti MLM</a>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:8px 0 0;font-size:18px;">Report guadagni — MLM</h2>
        <a href="{{ route('admin.mlm.earnings.export') }}" class="btn btn-secondary" style="margin-top:8px;">Esporta CSV</a>
    </div>
    <p style="margin:6px 0 0;color:var(--ink-muted);font-size:13px;">
        Maturato, pagato e da pagare per ogni agente — utile per avere contezza di quanto e' gia' stato liquidato ed evitare di pagare due volte lo stesso importo.
        Per approvare/pagare le richieste vai su <a href="{{ route('admin.mlm.payouts.index') }}">Liquidazioni EUR</a>.
    </p>
</div>

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Maturato totale (storico)</span>
        <strong style="font-size:20px;">&euro; {{ number_format($kpis['total_earned_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Gia' pagato</span>
        <strong style="font-size:20px;color:#166534;">&euro; {{ number_format($kpis['total_paid_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Da pagare</span>
        <strong style="font-size:20px;color:#b45309;">&euro; {{ number_format($kpis['total_outstanding_eur_cents'] / 100, 2, ',', '.') }}</strong>
    </div>
</div>

<form method="GET" action="{{ route('admin.mlm.earnings.index') }}" style="margin-bottom:10px;">
    <input type="hidden" name="sort" value="{{ $sort }}">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca agente</label>
            <input type="text" name="q" value="{{ $search }}" placeholder="Nome o email"
                style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:220px;">
        </div>
        <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Filtra</button>
        @if($search)
            <a href="{{ route('admin.mlm.earnings.index', ['sort' => $sort]) }}" style="padding:8px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;">Azzera ricerca</a>
        @endif
    </div>
</form>

<section class="card light-card">
    <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3 style="margin:0;font-size:15px;">Per agente</h3>
        <div style="display:flex;gap:6px;">
            <a href="{{ route('admin.mlm.earnings.index', array_filter(['q' => $search, 'sort' => 'outstanding'])) }}" class="pill {{ $sort === 'outstanding' ? 'active' : '' }}">Da pagare</a>
            <a href="{{ route('admin.mlm.earnings.index', array_filter(['q' => $search, 'sort' => 'earned'])) }}" class="pill {{ $sort === 'earned' ? 'active' : '' }}">Maturato</a>
            <a href="{{ route('admin.mlm.earnings.index', array_filter(['q' => $search, 'sort' => 'paid'])) }}" class="pill {{ $sort === 'paid' ? 'active' : '' }}">Pagato</a>
            <a href="{{ route('admin.mlm.earnings.index', array_filter(['q' => $search, 'sort' => 'name'])) }}" class="pill {{ $sort === 'name' ? 'active' : '' }}">Nome</a>
        </div>
    </div>
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Agente</th>
                <th style="text-align:right;">Maturato totale</th>
                <th style="text-align:right;">Pagato</th>
                <th style="text-align:right;">Da pagare</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($agents as $agent)
                <tr>
                    <td>
                        <strong style="display:block;">{{ $agent->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $agent->email }}</span>
                    </td>
                    <td style="text-align:right;">&euro; {{ number_format($agent->total_earned_eur_cents / 100, 2, ',', '.') }}</td>
                    <td style="text-align:right;color:#166534;">&euro; {{ number_format($agent->total_paid_eur_cents / 100, 2, ',', '.') }}</td>
                    <td style="text-align:right;">
                        <strong style="color:{{ $agent->total_outstanding_eur_cents > 0 ? '#b45309' : 'inherit' }};">
                            &euro; {{ number_format($agent->total_outstanding_eur_cents / 100, 2, ',', '.') }}
                        </strong>
                    </td>
                    <td><a href="{{ route('admin.mlm.earnings.show', $agent) }}" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;">Dettaglio</a></td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun agente trovato.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div style="padding:12px 16px;">{{ $agents->links() }}</div>
</section>
@endsection
