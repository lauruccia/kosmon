@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ route('admin.mlm.settings.edit') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">&larr; Torna a Impostazioni MLM</a>
    <div style="margin-top:8px;">
        <h2 style="margin:0 0 4px;font-size:18px;">Agente radice del sistema</h2>
        <p style="margin:0;color:var(--ink-muted);max-width:760px;font-size:13px;">
            Il sistema MLM ha un'unica radice per tutto l'albero, scelta qui. Ogni nuovo agente senza uno sponsor valido (nessun referral a monte)
            viene agganciato automaticamente sotto questa radice invece di creare un albero indipendente. Se scegli o cambi la radice qui sotto,
            eventuali alberi indipendenti già esistenti vengono ricollegati automaticamente sotto la nuova radice — nessuna azione manuale ulteriore richiesta.
        </p>
    </div>
</div>

@if(session('portal_success'))
<div class="card card-pad" style="margin-bottom:14px;border:1px solid #bbf7d0;background:#f0fdf4;">
    <p style="margin:0;color:#166534;font-size:13px;">{{ session('portal_success') }}</p>
</div>
@endif

@if ($errors->any())
<div class="card card-pad" style="margin-bottom:14px;border:1px solid #fecdd3;background:var(--danger-soft);">
    @foreach ($errors->all() as $error)
        <p style="margin:0;color:var(--danger);font-size:13px;">{{ $error }}</p>
    @endforeach
</div>
@endif

<div class="card card-pad" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
        <strong style="display:block;font-size:14px;">Radice attuale</strong>
        @if($currentRoot)
            <span style="color:var(--ink);font-size:13px;">{{ $currentRoot->name }} — {{ $currentRoot->email }}</span>
        @else
            <span style="color:var(--ink-muted);font-size:13px;">Nessuna radice ancora designata.</span>
        @endif
    </div>
    @if($orphanCount > 0)
        <span style="padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;background:#fffbeb;color:#92400e;border:1px solid #fde68a;">
            ⚠ {{ $orphanCount }} {{ $orphanCount === 1 ? 'albero indipendente' : 'alberi indipendenti' }} da consolidare
        </span>
    @endif
</div>

<form method="GET" action="{{ route('admin.mlm.settings.root-agent') }}" style="margin-bottom:10px;">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca agente</label>
            <input type="text" name="q" value="{{ $search }}" placeholder="Nome o email"
                style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:240px;">
        </div>
        <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Cerca</button>
        @if($search)
            <a href="{{ route('admin.mlm.settings.root-agent') }}" style="padding:8px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;">Azzera</a>
        @endif
    </div>
</form>

<form method="POST" action="{{ route('admin.mlm.settings.root-agent.update') }}" onsubmit="return confirm('Confermi la scelta di questo agente come unica radice del sistema? Eventuali alberi indipendenti verranno consolidati automaticamente sotto di lui.');">
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
                @forelse($candidates as $candidate)
                <tr>
                    <td><input type="radio" name="root_agent_id" value="{{ $candidate->id }}" {{ $currentRoot?->id === $candidate->id ? 'checked' : '' }}></td>
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
        <button type="submit" style="padding:10px 20px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:700;cursor:pointer;">Imposta come radice unica</button>
    </div>
</form>
@endsection
