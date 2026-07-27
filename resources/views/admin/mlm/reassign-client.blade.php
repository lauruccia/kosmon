@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ $currentAgent ? route('admin.mlm.show', $currentAgent) : route('admin.mlm.index') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">&larr; Torna {{ $currentAgent ? 'a ' . $currentAgent->name : "all'elenco agenti" }}</a>
    <div style="margin-top:8px;">
        <h2 style="margin:0 0 4px;font-size:18px;">Riassegna {{ $client->name }} a un altro agente</h2>
        <p style="margin:0;color:var(--ink-muted);font-size:13px;max-width:720px;">
            Agente di riferimento attuale: <strong>{{ $currentAgent?->name ?? '— (nessuno, cliente non attribuito)' }}</strong>.
            La riassegnazione e' strutturale: cambia solo a quale agente il cliente e' collegato d'ora in poi.
            Punti, commissioni e bonus gia' generati restano storici — solo le valutazioni future useranno il nuovo agente.
        </p>
    </div>
</div>

@if ($errors->any())
<div class="card card-pad" style="margin-bottom:14px;border:1px solid #fecdd3;background:var(--danger-soft);">
    @foreach ($errors->all() as $error)
        <p style="margin:0;color:var(--danger);font-size:13px;">{{ $error }}</p>
    @endforeach
</div>
@endif

<form method="GET" action="{{ route('admin.mlm.clients.reassign-form', $client) }}" style="margin-bottom:10px;">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca nuovo agente</label>
            <input type="text" name="q" value="{{ $search }}" placeholder="Nome o email"
                style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:240px;">
        </div>
        <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Cerca</button>
        @if($search)
            <a href="{{ route('admin.mlm.clients.reassign-form', $client) }}" style="padding:8px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;">Azzera</a>
        @endif
    </div>
</form>

<form method="POST" action="{{ route('admin.mlm.clients.reassign', $client) }}" onsubmit="return confirm('Confermi la riassegnazione di {{ $client->name }} al nuovo agente selezionato?');">
    @csrf
    <section class="card light-card">
        <table class="admin-table transactions-table">
            <thead>
                <tr>
                    <th style="width:40px;"></th>
                    <th>Agente</th>
                    <th>Qualifica</th>
                    <th>Punti attivi</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><input type="radio" name="new_agent_id" value="" {{ !$currentAgent ? 'checked' : '' }}></td>
                    <td colspan="3">
                        <strong>Nessun agente</strong> — lascia {{ $client->name }} non attribuito
                    </td>
                </tr>
                @forelse($candidates as $candidate)
                <tr>
                    <td><input type="radio" name="new_agent_id" value="{{ $candidate->id }}" {{ $currentAgent?->id === $candidate->id ? 'checked' : '' }}></td>
                    <td>
                        <strong style="display:block;">{{ $candidate->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $candidate->email }}</span>
                    </td>
                    <td><span class="pill">{{ ucfirst($candidate->mlm_rank ?: 'start') }}</span></td>
                    <td>{{ $candidate->mlmActivePoints() }}</td>
                </tr>
                @empty
                <tr><td colspan="4" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun agente trovato.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div style="margin-top:14px;">{{ $candidates->links() }}</div>

    <div class="card card-pad" style="margin-top:14px;display:flex;align-items:center;justify-content:flex-end;">
        <button type="submit" style="padding:10px 20px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:700;cursor:pointer;">Conferma riassegnazione</button>
    </div>
</form>
@endsection
