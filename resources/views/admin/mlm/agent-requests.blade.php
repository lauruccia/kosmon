@extends('layouts.portal')

@section('content')
@if(session('portal_success'))
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(22,163,74,.09);border:1px solid rgba(22,163,74,.3);color:#166534;font-size:13px;font-weight:600;">
        {{ session('portal_success') }}
    </div>
@endif
@if($errors->any())
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);color:#b91c1c;font-size:13px;font-weight:600;">
        {{ $errors->first() }}
    </div>
@endif

<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">MLM — Richieste agente</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">Richieste "voglio diventare agente KNM" inviate dagli utenti. Approva o rifiuta: l'utente firmerà poi il contratto di nomina.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="{{ route('admin.mlm.index') }}" class="btn btn-secondary">Torna agli agenti</a>
            @if($pendingCount > 0)
                <span class="pill" style="background:rgba(217,119,6,.12);color:#b45309;">{{ $pendingCount }} in attesa</span>
            @endif
        </div>
    </div>
</div>

<form method="GET" action="{{ route('admin.mlm.requests.index') }}" style="margin-bottom:10px;">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Stato</label>
            <select name="status" style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
                <option value="pending" {{ $selectedStatus === 'pending' ? 'selected' : '' }}>In attesa</option>
                <option value="approved" {{ $selectedStatus === 'approved' ? 'selected' : '' }}>Approvate (contratto non firmato)</option>
                <option value="rejected" {{ $selectedStatus === 'rejected' ? 'selected' : '' }}>Rifiutate</option>
                <option value="" {{ $selectedStatus === '' ? 'selected' : '' }}>Tutte</option>
            </select>
        </div>
        <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Filtra</button>
    </div>
</form>

<section class="card light-card">
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Utente</th>
                <th>Richiesta il</th>
                <th>Messaggio</th>
                <th>Stato</th>
                <th>Revisionata da</th>
                <th style="text-align:right;">Azioni</th>
            </tr>
        </thead>
        <tbody>
            @forelse($requests as $reqUser)
                @php
                    [$label, $bg, $fg] = match($reqUser->mlm_agent_request_status) {
                        'approved' => ['Approvata — attesa firma', 'rgba(12,74,134,.1)', '#0c4a86'],
                        'rejected' => ['Rifiutata', 'rgba(220,38,38,.1)', '#b91c1c'],
                        default    => ['In attesa', 'rgba(217,119,6,.12)', '#b45309'],
                    };
                @endphp
                <tr>
                    <td>
                        <strong style="display:block;">{{ $reqUser->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $reqUser->email }}</span>
                    </td>
                    <td>{{ $reqUser->mlm_agent_requested_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td style="max-width:260px;font-size:12px;color:var(--ink-muted);">{{ \Illuminate\Support\Str::limit($reqUser->mlm_agent_request_note, 120) ?: '—' }}</td>
                    <td><span class="pill" style="background:{{ $bg }};color:{{ $fg }};">{{ $label }}</span></td>
                    <td style="font-size:12px;color:var(--ink-muted);">{{ $reqUser->mlmAgentReviewedBy?->name ?? '—' }}</td>
                    <td style="text-align:right;">
                        <a href="{{ route('admin.users.show', $reqUser) }}" style="color:var(--primary);text-decoration:none;font-weight:600;font-size:13px;margin-right:10px;">Profilo →</a>
                        @if($reqUser->mlm_agent_request_status === 'pending')
                            <form method="POST" action="{{ route('admin.mlm.requests.approve', $reqUser) }}" style="display:inline-block;"
                                  onsubmit="return confirm('Approvare la richiesta di {{ $reqUser->name }}?');">
                                @csrf
                                <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">Approva</button>
                            </form>
                            <button type="button" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="document.getElementById('reject-{{ $reqUser->id }}').style.display='flex'">Rifiuta</button>
                            <form method="POST" action="{{ route('admin.mlm.requests.reject', $reqUser) }}" id="reject-{{ $reqUser->id }}" style="display:none;gap:6px;margin-top:8px;">
                                @csrf
                                <input type="text" name="reason" placeholder="Motivo del rifiuto" required minlength="5" maxlength="1000"
                                    style="border:1px solid var(--line);border-radius:8px;padding:6px 10px;font-size:12px;min-width:200px;">
                                <button type="submit" class="btn" style="padding:6px 12px;font-size:12px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;">Conferma rifiuto</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuna richiesta trovata.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<div style="margin-top:14px;">{{ $requests->links() }}</div>
@endsection
