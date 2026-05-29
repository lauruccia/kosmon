@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('portal.text-requests.create') }}" class="cta">+ Nuova richiesta</a>
@endsection



@section('content')
{{-- Tab: Ricevute --}}
<section class="card light-card" style="margin-bottom:24px;">
    <div style="padding:16px 20px 0;display:flex;align-items:center;gap:10px;">
        <h3 style="font-size:15px;font-weight:700;margin:0;">Ricevute</h3>
        @if($pendingCount > 0)
            <span class="chip" style="background:var(--warning-soft,#fef9c3);color:#92400e;border:1px solid #fde68a;font-size:11px;">
                {{ $pendingCount }} in attesa
            </span>
        @endif
    </div>

    <table class="transactions-table" style="margin-top:8px;">
        <thead>
            <tr>
                <th>Da</th>
                <th>Importo</th>
                <th>Causale</th>
                <th>Scadenza</th>
                <th>Stato</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($received as $req)
                <tr>
                    <td style="font-weight:600;">
                        {{ $req->fromAccount?->company?->name ?? $req->fromAccount?->display_name ?? '—' }}
                    </td>
                    <td style="font-weight:700;">{{ $req->formattedAmount() }}</td>
                    <td style="font-size:13px;color:var(--ink-muted);max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        {{ $req->causale }}
                    </td>
                    <td style="font-size:12px;">
                        @if($req->due_date)
                            <span style="{{ $req->due_date->isPast() && $req->isPending() ? 'color:var(--danger);font-weight:600;' : '' }}">
                                {{ $req->due_date->format('d/m/Y') }}
                            </span>
                        @else
                            <span style="color:var(--ink-muted);">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="chip {{ $req->statusChipClass() }}" style="font-size:11px;">
                            {{ $req->statusLabel() }}
                        </span>
                    </td>
                    <td>
                        <a href="{{ route('portal.text-requests.show', $req) }}"
                           style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;">
                            @if($req->isActionable()) Approva / Rifiuta @else Vedi @endif
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="subtle" style="text-align:center;padding:24px;">Nessuna richiesta ricevuta.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($received->hasPages())
        <div style="padding:12px 20px;">{{ $received->links() }}</div>
    @endif
</section>

{{-- Tab: Inviate --}}
<section class="card light-card">
    <div style="padding:16px 20px 0;">
        <h3 style="font-size:15px;font-weight:700;margin:0;">Inviate</h3>
    </div>

    <table class="transactions-table" style="margin-top:8px;">
        <thead>
            <tr>
                <th>A</th>
                <th>Importo</th>
                <th>Causale</th>
                <th>Scadenza</th>
                <th>Stato</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($sent as $req)
                <tr>
                    <td style="font-weight:600;">
                        {{ $req->toAccount?->company?->name ?? $req->toAccount?->display_name ?? '—' }}
                    </td>
                    <td style="font-weight:700;">{{ $req->formattedAmount() }}</td>
                    <td style="font-size:13px;color:var(--ink-muted);max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        {{ $req->causale }}
                    </td>
                    <td style="font-size:12px;">
                        {{ $req->due_date?->format('d/m/Y') ?? '—' }}
                    </td>
                    <td>
                        <span class="chip {{ $req->statusChipClass() }}" style="font-size:11px;">
                            {{ $req->statusLabel() }}
                        </span>
                    </td>
                    <td>
                        <a href="{{ route('portal.text-requests.show', $req) }}"
                           style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;">
                            Vedi
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="subtle" style="text-align:center;padding:24px;">Nessuna richiesta inviata.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($sent->hasPages())
        <div style="padding:12px 20px;">{{ $sent->links() }}</div>
    @endif
</section>
@endsection
