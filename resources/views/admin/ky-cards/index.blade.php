@extends('layouts.portal')

@section('content')
<div style="width:100%;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0;">KYCard</h1>
            <p style="margin:4px 0 0;color:var(--ink-soft);font-size:14px;">Pacchetti di ricarica KMoney acquistabili dalle aziende</p>
        </div>
        <a href="{{ route('admin.ky-cards.create') }}" class="btn btn-primary">+ Nuova KYCard</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:10px;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger" style="margin-bottom:10px;">{{ session('error') }}</div>
    @endif

    @if($cards->isEmpty())
        <div class="card" style="padding:40px;text-align:center;color:var(--ink-muted);">
            Nessuna KYCard configurata. <a href="{{ route('admin.ky-cards.create') }}">Creane una</a>.
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($cards as $card)
            <div class="card" style="padding:12px 16px;display:flex;align-items:center;gap:14px;{{ !$card->is_active ? 'opacity:.55;' : '' }}">

                {{-- Badge tipo --}}
                <div style="width:38px;height:38px;border-radius:8px;background:{{ $card->bonus_type === 'percentage' ? '#ede9fe' : '#dcfce7' }};display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;font-weight:700;color:{{ $card->bonus_type === 'percentage' ? '#6d28d9' : '#166534' }};">
                    {{ $card->bonus_type === 'percentage' ? '%' : 'KY' }}
                </div>

                {{-- Info principali --}}
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);">{{ $card->name }}
                        @if(!$card->is_active)<span style="font-size:11px;font-weight:400;color:var(--ink-muted);"> · non attiva</span>@endif
                    </div>
                    <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;">
                        <span style="font-size:11px;background:#f0fdf4;color:#166534;padding:2px 7px;border-radius:20px;font-weight:600;">{{ number_format($card->price_eur, 2, ',', '.') }} €</span>
                        <span style="font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:20px;font-weight:600;">{{ number_format($card->ky_total, 0, ',', '.') }} KY</span>
                        <span style="font-size:11px;background:{{ $card->bonus_type === 'percentage' ? '#ede9fe' : '#fef9c3' }};color:{{ $card->bonus_type === 'percentage' ? '#6d28d9' : '#854d0e' }};padding:2px 7px;border-radius:20px;font-weight:600;">{{ $card->bonus_label }}</span>
                        @if(!$card->stripe_price_id)
                            <span style="font-size:11px;background:#fef2f2;color:#991b1b;padding:2px 7px;border-radius:20px;">Stripe non conf.</span>
                        @endif
                        @if($card->description)
                            <span style="font-size:11px;color:var(--ink-muted);">{{ Str::limit($card->description, 60) }}</span>
                        @endif
                    </div>
                </div>

                {{-- Azioni --}}
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <form method="POST" action="{{ route('admin.ky-cards.toggle', $card) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-sm {{ $card->is_active ? 'btn-secondary' : 'btn-primary' }}">
                            {{ $card->is_active ? 'Disattiva' : 'Attiva' }}
                        </button>
                    </form>
                    <a href="{{ route('admin.ky-cards.edit', $card) }}" class="btn btn-sm btn-secondary">Modifica</a>
                    <form method="POST" action="{{ route('admin.ky-cards.destroy', $card) }}" onsubmit="return confirm('Eliminare questa KYCard?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                    </form>
                </div>
            </div>
        @endforeach
        </div>
    @endif

</div>
@endsection
