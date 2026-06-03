@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('portal.scheduled-payments.create') }}" class="cta">+ Programma pagamento</a>
@endsection

@section('content')

<style>
.sched-table { width:100%; border-collapse:collapse; }
.sched-table th { font-size:11px; font-weight:700; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.05em; padding:10px 14px; border-bottom:2px solid var(--border); text-align:left; white-space:nowrap; }
.sched-table td { padding:11px 14px; border-bottom:1px solid var(--border); vertical-align:middle; font-size:13px; }
.sched-table tr:last-child td { border-bottom:none; }
.sched-table tr:hover td { background:var(--surface-soft); }
.sched-dir { font-size:10px; color:var(--ink-muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
.sched-name { font-weight:700; font-size:13px; }
.sched-badge-rata { display:inline-flex; align-items:center; font-size:10px; font-weight:700; padding:1px 6px; border-radius:4px; background:var(--primary-soft,#ede9fe); color:var(--primary); margin-left:6px; vertical-align:middle; white-space:nowrap; }
.sched-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; padding:14px 16px; border-bottom:1px solid var(--border); background:var(--surface-soft); }
.sched-filters label { display:flex; flex-direction:column; gap:3px; font-size:11px; font-weight:700; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.05em; }
.sched-filters input, .sched-filters select { font-size:13px; padding:5px 8px; border:1px solid var(--border); border-radius:6px; background:var(--surface); color:var(--ink); height:32px; }
.sched-filters .filter-actions { display:flex; gap:6px; align-items:flex-end; margin-left:auto; }
.sched-active-filters { display:flex; flex-wrap:wrap; gap:6px; padding:10px 16px; border-bottom:1px solid var(--border); }
.sched-filter-chip { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 8px; border-radius:20px; background:var(--primary-soft,#ede9fe); color:var(--primary); }
.sched-filter-chip a { color:inherit; text-decoration:none; opacity:.7; font-size:13px; line-height:1; }
.sched-filter-chip a:hover { opacity:1; }
@media(max-width:640px) {
    .sched-hide-mobile { display:none; }
    .sched-filters { flex-direction:column; }
    .sched-filters .filter-actions { margin-left:0; width:100%; }
}
</style>

{{-- Barra filtri --}}
<div class="card" style="width:100%;overflow-x:auto;margin-bottom:0;">
    <form method="GET" action="{{ route('portal.scheduled-payments.index') }}" class="sched-filters">
        <label>
            Cerca controparte
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Nome azienda…" style="min-width:160px;">
        </label>
        <label>
            Direzione
            <select name="direction">
                <option value="">Tutte</option>
                <option value="out" @selected($filters['direction']==='out')>Pagamenti inviati</option>
                <option value="in"  @selected($filters['direction']==='in')>Pagamenti ricevuti</option>
            </select>
        </label>
        <label>
            Stato
            <select name="status">
                <option value="">Tutti</option>
                <option value="pending"   @selected($filters['status']==='pending')>In attesa</option>
                <option value="executed"  @selected($filters['status']==='executed')>Eseguiti</option>
                <option value="failed"    @selected($filters['status']==='failed')>Falliti</option>
                <option value="cancelled" @selected($filters['status']==='cancelled')>Annullati</option>
            </select>
        </label>
        <label>
            Data da
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}">
        </label>
        <label>
            Data a
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}">
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn" style="height:32px;padding:0 14px;font-size:13px;">Filtra</button>
            @if(array_filter($filters))
                <a href="{{ route('portal.scheduled-payments.index') }}" class="btn btn-ghost" style="height:32px;padding:0 10px;font-size:13px;">✕ Reset</a>
            @endif
        </div>
    </form>

    {{-- Chip filtri attivi --}}
    @if(array_filter($filters))
    <div class="sched-active-filters">
        @if($filters['search'])
            <span class="sched-filter-chip">
                Controparte: {{ $filters['search'] }}
                <a href="{{ route('portal.scheduled-payments.index', array_merge(array_filter($filters), ['search'=>''])) }}">✕</a>
            </span>
        @endif
        @if($filters['direction'])
            <span class="sched-filter-chip">
                {{ $filters['direction']==='out' ? 'Inviati' : 'Ricevuti' }}
                <a href="{{ route('portal.scheduled-payments.index', array_merge(array_filter($filters), ['direction'=>''])) }}">✕</a>
            </span>
        @endif
        @if($filters['status'])
            <span class="sched-filter-chip">
                {{ ['pending'=>'In attesa','executed'=>'Eseguiti','failed'=>'Falliti','cancelled'=>'Annullati'][$filters['status']] }}
                <a href="{{ route('portal.scheduled-payments.index', array_merge(array_filter($filters), ['status'=>''])) }}">✕</a>
            </span>
        @endif
        @if($filters['date_from'])
            <span class="sched-filter-chip">
                Dal {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}
                <a href="{{ route('portal.scheduled-payments.index', array_merge(array_filter($filters), ['date_from'=>''])) }}">✕</a>
            </span>
        @endif
        @if($filters['date_to'])
            <span class="sched-filter-chip">
                Al {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
                <a href="{{ route('portal.scheduled-payments.index', array_merge(array_filter($filters), ['date_to'=>''])) }}">✕</a>
            </span>
        @endif
        <span style="font-size:12px;color:var(--ink-muted);margin-left:4px;">{{ $payments->total() }} risultati</span>
    </div>
    @endif
</div>

<div class="card" style="width:100%;overflow-x:auto;margin-top:12px;">
    <table class="sched-table">
        <thead>
            <tr>
                <th>Data</th>
                <th>Controparte</th>
                <th class="sched-hide-mobile">Descrizione</th>
                <th>Importo</th>
                <th>Stato</th>
                <th style="width:50px;"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $p)
                @php $isSender = $p->from_account_id === $currentAccount->id; @endphp
                <tr>
                    <td style="white-space:nowrap;">
                        <div style="font-weight:700;">{{ $p->scheduled_at->format('d/m/Y') }}</div>
                        <div style="font-size:11px;color:var(--ink-muted);">{{ $p->scheduled_at->format('H:i') }}</div>
                    </td>
                    <td>
                        <div class="sched-dir">{{ $isSender ? 'A' : 'Da' }}</div>
                        <div class="sched-name">
                            {{ $isSender
                                ? ($p->toAccount?->company?->name ?? $p->toAccount?->display_name ?? '—')
                                : ($p->fromAccount?->company?->name ?? $p->fromAccount?->display_name ?? '—') }}
                        </div>
                    </td>
                    <td class="sched-hide-mobile" style="max-width:260px;">
                        <span style="color:var(--ink-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:260px;">
                            {{ $p->description }}
                        </span>
                        @if($p->isRecurring())
                            <span class="sched-badge-rata">{{ $p->recurrence_index }}/{{ $p->recurrence_total }}</span>
                        @endif
                    </td>
                    <td style="white-space:nowrap;">
                        <span style="font-weight:700;font-size:14px;color:{{ $isSender ? 'var(--danger)' : 'var(--success-color,#16a34a)' }};">
                            {{ $isSender ? '−' : '+' }}{{ $p->formattedAmount() }}
                        </span>
                    </td>
                    <td>
                        <span class="chip {{ $p->statusChipClass() }}" style="font-size:11px;">{{ $p->statusLabel() }}</span>
                        @if($p->isRecurring() && $p->isPending())
                            {{-- badge rata visibile su mobile dove la colonna descrizione è nascosta --}}
                            <span class="sched-badge-rata" style="display:none;" id="mobile-badge-{{ $p->id }}">{{ $p->recurrence_index }}/{{ $p->recurrence_total }}</span>
                        @endif
                    </td>
                    <td style="text-align:right;">
                        <a href="{{ route('portal.scheduled-payments.show', $p) }}"
                           style="font-size:12px;font-weight:700;color:var(--primary);text-decoration:none;white-space:nowrap;">
                            Vedi &rarr;
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align:center;padding:40px;color:var(--ink-muted);">
                        Nessun pagamento programmato.
                        <a href="{{ route('portal.scheduled-payments.create') }}" style="color:var(--primary);font-weight:700;margin-left:4px;">Programmane uno ora &rarr;</a>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($payments->hasPages())
        <div style="padding:12px 16px;border-top:1px solid var(--border);">{{ $payments->links() }}</div>
    @endif
</div>

<script>
// Su mobile mostra il badge rata nella colonna stato
if (window.innerWidth <= 640) {
    document.querySelectorAll('[id^="mobile-badge-"]').forEach(el => el.style.display = 'inline-flex');
}
</script>
@endsection
