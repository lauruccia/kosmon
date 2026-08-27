@extends('layouts.portal')

@section('content')
{{--
    "Ordini ricevuti" — fase B, 27/08/2026.

    Prima esisteva solo /admin/listings/ordini, che pero' e' BACKOFFICE: la
    vedeva l'admin del circuito, non l'azienda che deve spedire. Il venditore
    riceveva una email e finiva li'.

    L'ordine dell'elenco non e' cronologico: prima quello che c'e' da fare, e
    dentro quel gruppo i piu' VECCHI per primi. In una lista di cose da
    spedire l'urgente e' chi aspetta da piu' tempo, non l'ultimo arrivato.
--}}

@if($daLavorare > 0)
<section class="card account-hero card-pad" style="margin-bottom:16px;">
    <div class="k-tag">Da spedire</div>
    <h3 style="font-size:19px;font-weight:700;margin:10px 0 0;color:#fff;">
        {{ $daLavorare }} {{ $daLavorare === 1 ? 'ordine aspetta' : 'ordini aspettano' }} di essere preparati
    </h3>
</section>
@endif

@if($ordini->isEmpty())

    <section class="card light-card" style="text-align:center;padding:48px 24px;">
        <div style="font-size:44px;line-height:1;margin-bottom:12px;">🧾</div>
        <h2 style="font-size:20px;font-weight:700;color:#10263d;margin:0 0 8px;">Nessun ordine ricevuto</h2>
        <p class="subtle" style="margin:0 0 20px;">Quando qualcuno comprerà dal tuo negozio, lo troverai qui con l'indirizzo a cui spedire.</p>
        <a href="{{ route('portal.shop.mine') }}" class="cta" style="display:inline-block;">I miei prodotti</a>
    </section>

@else

    <div class="stack">
        @foreach($ordini as $order)
        <a href="{{ route('portal.sales.show', $order) }}"
           class="card light-card"
           style="display:block;text-decoration:none;color:inherit;{{ $order->isConcluso() ? 'opacity:.62;' : '' }}">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div style="min-width:0;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">
                        Ordine {{ $order->numero }}
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#10263d;margin:3px 0 2px;">
                        {{ $order->summary_title }}
                    </div>
                    <div class="subtle" style="font-size:12.5px;">
                        {{ $order->shipping_recipient_name ?: ($order->buyerUser?->name ?? 'Cliente del circuito') }}
                        · {{ $order->placed_at?->format('d/m/Y') }}
                    </div>
                </div>

                <div style="text-align:right;">
                    @include('portal.partials.order-status-badge', ['order' => $order])
                    <div style="font-size:15px;font-weight:700;color:#10263d;margin-top:7px;white-space:nowrap;">
                        {{ ky_format($order->total_ky) }} KY
                    </div>
                </div>
            </div>

            @if($order->isInAttesaDiEuro())
            <div style="margin-top:12px;font-size:12.5px;color:#92400e;background:#fffbeb;
                        border:1px solid #fde68a;border-radius:8px;padding:9px 12px;">
                Non spedire ancora: la quota in euro non è stata saldata.
            </div>
            @elseif($order->buyer_note)
            <div style="margin-top:12px;font-size:12.5px;color:#0c4a86;background:#eff6ff;
                        border:1px solid #bfdbfe;border-radius:8px;padding:9px 12px;">
                Nota del cliente: {{ \Illuminate\Support\Str::limit($order->buyer_note, 140) }}
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
