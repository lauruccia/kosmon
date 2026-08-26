@extends('layouts.portal')

@section('content')
{{--
    La pagina "grazie" (fase A, 26/08/2026).

    Prima, dopo la cassa, si tornava allo shop con un messaggio verde che
    spariva: il numero d'ordine non lo vedeva nessuno, e con due quote in euro
    da saldare bisognava andarsele a cercare nei movimenti. Adesso sono qui.

    Gli uuid arrivano in query string, quindi la pagina regge un F5; il
    controller filtra su buyer_account_id, quindi con l'uuid di un ordine
    altrui non si vede niente.
--}}
@php
    $totaleKy   = (int) $ordini->sum('total_ky');
    $totaleEuro = (int) $ordini->sum('total_eur');
    $daPagare   = $ordini->filter(fn ($o) => $o->payment !== null);
@endphp

<section class="card light-card" style="text-align:center;padding:40px 24px 32px;">
    <div style="font-size:46px;line-height:1;margin-bottom:10px;">✅</div>
    <h2 style="font-size:22px;font-weight:700;color:#10263d;margin:0 0 8px;">
        {{ $ordini->count() === 1 ? 'Ordine confermato' : 'Ordini confermati' }}
    </h2>
    <p class="subtle" style="margin:0;font-size:14px;">
        {{ ky_format($totaleKy) }} KY sono partiti dal tuo conto{{ $ordini->count() > 1 ? ', divisi fra ' . $ordini->count() . ' venditori' : '' }}.
    </p>
</section>

@if($daPagare->isNotEmpty())
<section class="card light-card" style="border:1px solid #fde68a;background:#fffbeb;">
    <h3 style="font-size:16px;font-weight:700;color:#92400e;margin:0 0 6px;">
        {{ $daPagare->count() === 1 ? 'Resta una quota in euro da saldare' : 'Restano ' . $daPagare->count() . ' quote in euro da saldare' }}
    </h3>
    <p style="font-size:13px;color:#92400e;margin:0 0 14px;">
        La parte in KY è già pagata. La quota in euro si salda fuori dal circuito, con carta o bonifico.
    </p>
    @foreach($daPagare as $ordine)
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 0;border-top:1px solid #fde68a;">
        <span style="font-size:13.5px;color:#78350f;">
            <strong>{{ $ordine->company->name }}</strong> — € {{ number_format($ordine->total_eur / 100, 2, ',', '.') }}
        </span>
        <a href="{{ route('portal.shop.orders.pay', $ordine->payment) }}" class="cta" style="padding:8px 16px;font-size:13px;">
            Paga la quota in euro
        </a>
    </div>
    @endforeach
</section>
@endif

@foreach($ordini as $ordine)
<section class="card light-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div>
            <span class="eyebrow">Ordine</span>
            <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.5px;">
                {{ strtoupper(substr($ordine->uuid, 0, 8)) }}
            </h3>
            <p class="subtle" style="font-size:12.5px;margin:4px 0 0;">
                {{ $ordine->company->name }} · {{ $ordine->placed_at?->format('d/m/Y H:i') }}
            </p>
        </div>
        <span style="font-weight:700;color:#10263d;font-size:16px;">
            {{ ky_format($ordine->total_ky) }} KY{{ $ordine->total_eur > 0 ? ' + € ' . number_format($ordine->total_eur / 100, 2, ',', '.') : '' }}
        </span>
    </div>

    @foreach($ordine->items as $item)
    <div style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;font-size:13.5px;color:#475569;border-top:1px solid #f1f5f9;">
        <span>
            {{ $item->quantity }} × {{ $item->title }}
            @if($item->variant_label)<span class="subtle">— {{ $item->variant_label }}</span>@endif
        </span>
        <span style="white-space:nowrap;color:#10263d;font-weight:600;">{{ ky_format($item->line_ky_amount) }} KY</span>
    </div>
    @endforeach

    @if($ordine->shipping_ky > 0 || $ordine->shipping_eur > 0)
    <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px;color:#475569;border-top:1px solid #f1f5f9;">
        <span>Spedizione</span>
        <span>{{ ky_format($ordine->shipping_ky) }} KY{{ $ordine->shipping_eur > 0 ? ' + € ' . number_format($ordine->shipping_eur / 100, 2, ',', '.') : '' }}</span>
    </div>
    @endif

    @if($ordine->shipping_address)
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid #eef2f7;">
        <span class="eyebrow">Spedizione a</span>
        <p style="font-size:13.5px;color:#334155;line-height:1.6;margin:6px 0 0;">
            {{ $ordine->shipping_recipient_name }}<br>
            {{ $ordine->shipping_address }}<br>
            {{ trim($ordine->shipping_postal_code . ' ' . $ordine->shipping_city . ($ordine->shipping_province ? ' (' . $ordine->shipping_province . ')' : '')) }}
            @if($ordine->shipping_phone)<br>Tel. {{ $ordine->shipping_phone }}@endif
        </p>
    </div>
    @endif

    @if($ordine->buyer_note)
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid #eef2f7;">
        <span class="eyebrow">La tua nota al venditore</span>
        <p style="font-size:13.5px;color:#334155;line-height:1.6;margin:6px 0 0;white-space:pre-line;">{{ $ordine->buyer_note }}</p>
    </div>
    @endif
</section>
@endforeach

<section class="card light-card">
    <h3 style="font-size:16px;font-weight:700;color:#10263d;margin:0 0 10px;">Che cosa succede adesso</h3>
    <ul style="margin:0;padding-left:20px;font-size:13.5px;color:#475569;line-height:1.75;">
        <li>{{ $ordini->count() === 1 ? 'Il venditore è stato avvisato' : 'I venditori sono stati avvisati' }} e prepara{{ $ordini->count() === 1 ? '' : 'no' }} l'ordine.</li>
        <li>Trovi il movimento in <a href="{{ route('portal.movements') }}">Movimenti</a>, con il dettaglio di quanto è stato addebitato.</li>
        @if($daPagare->isNotEmpty())
        <li>L'ordine si chiude quando la quota in euro risulta saldata.</li>
        @endif
    </ul>
    <div style="margin-top:18px;">
        <a href="{{ route('portal.shop') }}" class="cta" style="display:inline-block;">Continua a fare acquisti</a>
    </div>
</section>
@endsection
