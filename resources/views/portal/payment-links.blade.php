@extends('layouts.portal')

@section('content')
<div class="portal-grid" style="--grid-cols:3;margin-bottom:24px;">
    <a href="{{ route('portal.payment-links.create') }}" class="card card-pad" style="text-decoration:none;display:flex;align-items:center;gap:16px;border:2px dashed var(--primary);background:var(--primary-soft,#eef3fc);">
        <div style="width:44px;height:44px;border-radius:12px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:var(--primary);">Nuovo link</div>
            <div style="font-size:12px;color:var(--ink-muted);">Genera e condividi</div>
        </div>
    </a>
</div>

@if(session('portal_success'))
    <div class="alert alert-success" style="margin-bottom:20px;">{{ session('portal_success') }}</div>
@endif

<section class="card light-card">
    <table class="transactions-table">
        <thead>
            <tr>
                <th>Creato</th>
                <th>Importo</th>
                <th>Causale</th>
                <th>Scade</th>
                <th>Stato</th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            @forelse($links as $link)
                @php
                    $chipClass = match($link->status) {
                        'paid'      => 'success',
                        'expired'   => '',
                        'cancelled' => 'pink',
                        default     => 'blue',
                    };
                    $chipLabel = match($link->status) {
                        'paid'      => 'Pagato',
                        'expired'   => 'Scaduto',
                        'cancelled' => 'Annullato',
                        default     => 'Attivo',
                    };
                @endphp
                <tr>
                    <td style="font-size:12px;white-space:nowrap;">
                        <div style="font-weight:600;">{{ $link->created_at->format('d/m/Y') }}</div>
                        <div style="color:var(--ink-muted);">{{ $link->created_at->format('H:i') }}</div>
                    </td>
                    <td>
                        <span style="font-weight:700;font-size:15px;">{{ ky_format($link->amount) }}</span>
                        <span style="font-size:11px;color:var(--ink-muted);font-weight:600;">KY</span>
                    </td>
                    <td style="font-size:13px;color:var(--ink-muted);max-width:200px;">
                        {{ $link->description ?? '—' }}
                    </td>
                    <td style="font-size:12px;white-space:nowrap;">
                        @if($link->status === 'pending')
                            <span style="color:{{ $link->expires_at->diffInHours() < 24 ? 'var(--danger)' : 'var(--ink-muted)' }};">
                                {{ $link->expires_at->format('d/m/Y') }}
                            </span>
                        @else
                            <span style="color:var(--ink-muted);">{{ $link->expires_at->format('d/m/Y') }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="chip {{ $chipClass }}" style="font-size:11px;">{{ $chipLabel }}</span>
                    </td>
                    <td style="white-space:nowrap;">
                        @if($link->status === 'pending')
                            <a href="{{ route('portal.payment-links.show', $link->token) }}"
                               style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;margin-right:12px;">
                                Condividi
                            </a>
                            <form method="POST" action="{{ route('portal.payment-links.cancel', $link->token) }}"
                                  style="display:inline;"
                                  onsubmit="return confirm('Annullare questo link?')">
                                @csrf
                                <button type="submit" style="font-size:12px;font-weight:600;color:var(--danger);background:none;border:none;cursor:pointer;padding:0;">
                                    Annulla
                                </button>
                            </form>
                        @elseif($link->status === 'paid')
                            <span style="font-size:12px;color:var(--ink-muted);">Pagato {{ $link->paid_at?->format('d/m H:i') }}</span>
                        @else
                            <span style="font-size:12px;color:var(--ink-muted);">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="subtle" style="text-align:center;padding:40px;">
                        Nessun link ancora. <a href="{{ route('portal.payment-links.create') }}" style="color:var(--primary);font-weight:600;">Crea il primo →</a>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($links->hasPages())
        <div style="padding:16px 0 4px;">
            {{ $links->links() }}
        </div>
    @endif
</section>
@endsection
