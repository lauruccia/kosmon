@extends('layouts.portal')

@section('content')
{{--
    "I miei ordini" — fase B, 27/08/2026.

    Prima di questa pagina, chi comprava ritrovava l'acquisto solo come riga in
    *Movimenti*, in mezzo a bonifici e ricariche: senza stato, senza l'indirizzo
    a cui era partito, senza un numero da citare al venditore.
--}}

@if($ordini->isEmpty())

    <section class="card light-card" style="text-align:center;padding:48px 24px;">
        <div style="font-size:44px;line-height:1;margin-bottom:12px;">📦</div>
        <h2 style="font-size:20px;font-weight:700;color:#10263d;margin:0 0 8px;">Non hai ancora ordini</h2>
        <p class="subtle" style="margin:0 0 20px;">Quando comprerai qualcosa nel circuito, lo ritroverai qui con il suo stato.</p>
        <a href="{{ route('portal.shop') }}" class="cta" style="display:inline-block;">Vai allo shop</a>
    </section>

@else

    <div class="stack">
        @foreach($ordini as $order)
        <a href="{{ route('portal.orders.show', $order) }}"
           class="card light-card"
           style="display:block;text-decoration:none;color:inherit;">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div style="min-width:0;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">
                        Ordine {{ $order->numero }}
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#10263d;margin:3px 0 2px;">
                        {{ $order->summary_title }}
                    </div>
                    <div class="subtle" style="font-size:12.5px;">
                        {{ $order->company?->name ?? 'Venditore non più nel circuito' }}
                        · {{ $order->placed_at?->format('d/m/Y') }}
                    </div>
                </div>

                <div style="text-align:right;">
                    @include('portal.partials.order-status-badge', ['order' => $order])
                    <div style="font-size:15px;font-weight:700;color:#10263d;margin-top:7px;white-space:nowrap;">
                        {{ ky_format($order->total_ky) }} KY
                    </div>
                    @if($order->total_eur > 0)
                    <div class="subtle" style="font-size:12px;white-space:nowrap;">
                        + € {{ number_format($order->total_eur / 100, 2, ',', '.') }}
                    </div>
                    @endif
                </div>
            </div>

            {{-- La cosa che chi guarda un ordine vuole sapere per prima: devo
                 fare ancora qualcosa io? --}}
            @if($order->resoInCorso())
            <div style="margin-top:12px;font-size:12.5px;color:#7c2d12;background:#fff7ed;
                        border:1px solid #fdba74;border-radius:8px;padding:9px 12px;">
                Reso richiesto: stai aspettando la risposta del venditore.
            </div>
            @elseif($order->isInAttesaDiEuro())
            <div style="margin-top:12px;font-size:12.5px;color:#92400e;background:#fffbeb;
                        border:1px solid #fde68a;border-radius:8px;padding:9px 12px;">
                Manca il pagamento della quota in euro perché il venditore possa spedire.
            </div>
            @elseif($order->isSpedito() && $order->tracking_code)
            <div style="margin-top:12px;font-size:12.5px;color:#5b21b6;background:#f5f3ff;
                        border:1px solid #ddd6fe;border-radius:8px;padding:9px 12px;">
                {{ $order->carrier ? $order->carrier . ' · ' : '' }}{{ $order->tracking_code }}
            </div>
            @endif
        </a>
        @endforeach
    </div>

    <div style="margin-top:18px;">
        {{ $ordini->links() }}
    </div>

@endif
@endsection
