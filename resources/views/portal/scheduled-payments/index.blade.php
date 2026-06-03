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
@media(max-width:640px) {
    .sched-hide-mobile { display:none; }
}
</style>

<div class="card" style="width:100%;overflow-x:auto;">
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
