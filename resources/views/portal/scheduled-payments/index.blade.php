@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('portal.scheduled-payments.create') }}" class="cta">+ Programma pagamento</a>
@endsection



@section('content')
<section class="card light-card">
    <table class="transactions-table">
        <thead>
            <tr>
                <th>Data programmata</th>
                <th>Destinatario / Mittente</th>
                <th>Descrizione</th>
                <th>Importo</th>
                <th>Stato</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $p)
                @php
                    $isSender = $p->from_account_id === $currentAccount->id;
                @endphp
                <tr>
                    <td style="white-space:nowrap;">
                        <div style="font-weight:600;">{{ $p->scheduled_at->format('d/m/Y') }}</div>
                        <div style="font-size:11px;color:var(--ink-muted);">{{ $p->scheduled_at->format('H:i') }}</div>
                    </td>
                    <td>
                        @if($isSender)
                            <div style="font-size:11px;color:var(--ink-muted);">A</div>
                            <div style="font-weight:600;">{{ $p->toAccount?->company?->name ?? $p->toAccount?->display_name ?? '—' }}</div>
                        @else
                            <div style="font-size:11px;color:var(--ink-muted);">Da</div>
                            <div style="font-weight:600;">{{ $p->fromAccount?->company?->name ?? $p->fromAccount?->display_name ?? '—' }}</div>
                        @endif
                    </td>
                    <td style="font-size:13px;color:var(--ink-muted);max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        {{ $p->description }}
                    </td>
                    <td>
                        <span style="font-weight:700;color:{{ $isSender ? 'var(--danger)' : 'var(--success-color,#16a34a)' }};">
                            {{ $isSender ? '−' : '+' }}{{ $p->formattedAmount() }}
                        </span>
                    </td>
                    <td>
                        <span class="chip {{ $p->statusChipClass() }}" style="font-size:11px;">{{ $p->statusLabel() }}</span>
                    </td>
                    <td>
                        <a href="{{ route('portal.scheduled-payments.show', $p) }}"
                           style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;">Vedi</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="subtle" style="text-align:center;padding:32px;">
                        Nessun pagamento programmato.
                        <a href="{{ route('portal.scheduled-payments.create') }}" style="color:var(--primary);font-weight:600;">Programmane uno ora &rarr;</a>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if($payments->hasPages())
        <div style="padding:12px 20px;">{{ $payments->links() }}</div>
    @endif
</section>
@endsection
