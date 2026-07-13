@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">MLM — Agenti</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">Albero agenti, punti, qualifiche e bonus. Vedi <code>MLM_PROPOSAL.md</code> per i dettagli del piano.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="{{ route('admin.mlm.tree.roots') }}" class="btn btn-secondary">Albero agenti</a>
            <a href="{{ route('admin.mlm.payouts.index') }}" class="btn btn-secondary">Liquidazioni EUR</a>
            <a href="{{ route('admin.mlm.settings.edit') }}" class="btn btn-secondary">Impostazioni qualifiche</a>
            <span class="pill">{{ $agents->total() }} agenti</span>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Agenti totali</span>
        <strong style="font-size:22px;">{{ $agents->total() }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Clienti totali</span>
        <strong style="font-size:22px;">{{ $clientsCount }}</strong>
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Clienti senza agente</span>
        <strong style="font-size:22px;{{ $unattachedClientsCount > 0 ? 'color:#c9313e;' : '' }}">{{ $unattachedClientsCount }}</strong>
    </div>
</div>

<form method="GET" action="{{ route('admin.mlm.index') }}" style="margin-bottom:10px;">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca</label>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Nome o email"
                style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:200px;">
        </div>
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Qualifica</label>
            <select name="rank" style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
                <option value="">Tutte</option>
                @foreach($ranks as $rank)
                    <option value="{{ $rank }}" {{ $filters['rank'] === $rank ? 'selected' : '' }}>{{ ucfirst($rank) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Filtra</button>
        @if($filters['q'] || $filters['rank'])
            <a href="{{ route('admin.mlm.index') }}" style="padding:8px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;">Azzera</a>
        @endif
    </div>
</form>

<section class="card light-card">
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Agente</th>
                <th>Qualifica</th>
                <th>Punti attivi</th>
                <th>Clienti</th>
                <th>Attivato il</th>
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
                    <td><span class="pill">{{ ucfirst($agent->mlm_rank) }}</span></td>
                    <td>{{ $agent->mlmActivePoints() }}</td>
                    <td>{{ $agent->mlm_clients_count }}</td>
                    <td>{{ $agent->mlm_activated_at?->format('d/m/Y') ?? '—' }}</td>
                    <td style="text-align:right;">
                        <a href="{{ route('admin.mlm.show', $agent) }}" style="color:var(--primary);text-decoration:none;font-weight:600;font-size:13px;">Dettaglio →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun agente registrato.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<div style="margin-top:14px;">{{ $agents->links() }}</div>
@endsection
