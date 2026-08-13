@extends('layouts.portal')

@section('content')
<style>
    .aca-bulk-bar {
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        margin:0 0 12px; padding:10px 14px;
        background:var(--surface); border:1px solid var(--line);
        border-radius:var(--radius-sm); box-shadow:var(--shadow);
    }
    .aca-bulk-info { font-size:13px; color:var(--ink-muted); margin-right:auto; }
    .aca-bulk-info strong { color:var(--ink); font-variant-numeric:tabular-nums; }
    .aca-bulk-bar select {
        padding:7px 10px; border:1.5px solid var(--line); border-radius:8px;
        font-size:13px; font-weight:700; background:var(--surface); color:var(--ink);
    }
    .aca-bulk-bar button:disabled { opacity:.45; cursor:not-allowed; }
    .aca-agent-badge {
        display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px;
        font-size:11.5px; font-weight:700; border:1.5px solid;
        background:#dbeafe; border-color:#93c5fd; color:#1e3a8a;
    }
    .aca-agent-none {
        display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px;
        font-size:11.5px; font-weight:700; border:1.5px solid #fecdd3;
        background:var(--danger-soft); color:var(--danger);
    }
</style>

<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <a href="{{ route('admin.mlm.index') }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">&larr; Torna a MLM — Agenti</a>
            <h2 style="margin:8px 0 4px;font-size:18px;">Assegnazione clienti agli agenti</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;max-width:720px;">
                Seleziona uno o piu' clienti e assegnali in blocco a un agente. Utile soprattutto dopo un'importazione
                (i clienti importati non hanno un agente finche' non li assegni qui). L'operazione e' puramente
                strutturale: non tocca punti, commissioni o bonus gia' generati — vale solo per le valutazioni future.
            </p>
        </div>
        <span class="pill" style="{{ $unattachedCount > 0 ? 'background:rgba(217,119,6,.15);color:#b45309;' : '' }}">
            {{ $unattachedCount }} {{ $unattachedCount === 1 ? 'cliente non assegnato' : 'clienti non assegnati' }}
        </span>
    </div>
</div>

@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- Filtri --}}
<form method="GET" action="{{ route('admin.mlm.clients.assign-form') }}" style="margin-bottom:10px;">
    <div class="card card-pad" style="padding:10px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Cerca</label>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Nome, email o azienda"
                style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:240px;">
        </div>
        <div>
            <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Agente attuale</label>
            <select name="agent" style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:200px;">
                <option value="" @selected($filters['agent'] === '')>Tutti i clienti</option>
                <option value="none" @selected($filters['agent'] === 'none')>Solo non assegnati</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected($filters['agent'] === (string) $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Filtra</button>
        @if($filters['q'] !== '' || $filters['agent'] !== '')
            <a href="{{ route('admin.mlm.clients.assign-form') }}" style="padding:8px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;">Azzera</a>
        @endif
    </div>
</form>

<p style="font-size:13px;color:var(--ink-muted);margin:0 0 10px;">
    <strong style="color:var(--ink);">{{ number_format($clients->total()) }}</strong>
    {{ $clients->total() === 1 ? 'cliente trovato' : 'clienti trovati' }}
    @if($clients->lastPage() > 1)
        — pagina {{ $clients->currentPage() }} / {{ $clients->lastPage() }}
    @endif
</p>

@if($clients->isEmpty())
    <div class="empty-state">
        <strong>Nessun cliente trovato.</strong>
        <p>Prova a modificare i filtri di ricerca.</p>
    </div>
@else
<form id="bulkForm" method="POST"
      action="{{ route('admin.mlm.clients.assign') }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}"
      class="aca-bulk-bar">
    @csrf
    <input type="hidden" name="scope" id="bulk-scope" value="selected">

    <select name="new_agent_id" id="bulk-agent" required>
        <option value="">Assegna a…</option>
        @foreach($agents as $a)
            <option value="{{ $a->id }}">{{ $a->name }} ({{ $a->email }})</option>
        @endforeach
    </select>

    <span class="aca-bulk-info"><strong id="bulk-count">0</strong> selezionati</span>

    <button type="submit" id="btn-apply-selected" class="cta" disabled>Assegna selezionati</button>
    <button type="submit" id="btn-apply-all" class="cta secondary">Assegna tutti i filtrati ({{ number_format($clients->total()) }})</button>
</form>

<section class="card light-card">
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th style="width:38px;text-align:center;">
                    <input type="checkbox" id="cb-all" title="Seleziona tutti (in pagina)">
                </th>
                <th>Cliente</th>
                <th>Azienda</th>
                <th>Agente attuale</th>
                <th>Iscritto il</th>
            </tr>
        </thead>
        <tbody>
            @foreach($clients as $client)
            <tr>
                <td style="text-align:center;">
                    <input type="checkbox" class="bulk-cb" value="{{ $client->id }}"
                           aria-label="Seleziona {{ $client->name }}">
                </td>
                <td>
                    <strong style="display:block;">{{ $client->name }}</strong>
                    <span style="color:var(--ink-muted);font-size:12px;">{{ $client->email }}</span>
                </td>
                <td style="color:var(--ink-muted);font-size:13px;">{{ $client->company?->name ?? '—' }}</td>
                <td>
                    @if($client->mlmClientAgent)
                        <span class="aca-agent-badge">{{ $client->mlmClientAgent->name }}</span>
                    @else
                        <span class="aca-agent-none">Non assegnato</span>
                    @endif
                </td>
                <td style="color:var(--ink-muted);font-size:13px;">{{ $client->created_at?->format('d/m/Y') ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>

@if($clients->hasPages())
    <div style="margin-top:18px;display:flex;justify-content:center;">
        {{ $clients->links() }}
    </div>
@endif
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bulkForm');
    if (!form) return;

    var master  = document.getElementById('cb-all');
    var counter = document.getElementById('bulk-count');
    var scope   = document.getElementById('bulk-scope');
    var agentEl = document.getElementById('bulk-agent');
    var btnSel  = document.getElementById('btn-apply-selected');
    var btnAll  = document.getElementById('btn-apply-all');
    var boxes   = Array.prototype.slice.call(document.querySelectorAll('.bulk-cb'));

    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        counter.textContent = n;
        btnSel.disabled = n === 0;
        if (master) {
            master.checked = n > 0 && n === boxes.length;
            master.indeterminate = n > 0 && n < boxes.length;
        }
    }

    if (master) {
        master.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = master.checked; });
            refresh();
        });
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });

    function clearIds() {
        form.querySelectorAll('input[name="client_ids[]"]').forEach(function (i) { i.remove(); });
    }

    function agentLabel() {
        var opt = agentEl.options[agentEl.selectedIndex];
        return opt ? opt.text : '';
    }

    btnSel.addEventListener('click', function (e) {
        var selected = boxes.filter(function (b) { return b.checked; });
        if (selected.length === 0 || !agentEl.value) { e.preventDefault(); return; }
        if (!confirm('Assegnare ' + selected.length + ' clienti selezionati a ' + agentLabel() + '?')) {
            e.preventDefault(); return;
        }
        scope.value = 'selected';
        clearIds();
        selected.forEach(function (b) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'client_ids[]'; h.value = b.value;
            form.appendChild(h);
        });
    });

    btnAll.addEventListener('click', function (e) {
        if (!agentEl.value) { e.preventDefault(); alert('Scegli prima un agente.'); return; }
        if (!confirm('Assegnare TUTTI i clienti che rispettano i filtri correnti a ' + agentLabel() + '? Operazione potenzialmente estesa.')) {
            e.preventDefault(); return;
        }
        scope.value = 'all_filtered';
        clearIds();
    });

    refresh();
});
</script>
@endsection
