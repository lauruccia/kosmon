@extends('layouts.portal')

@section('content')
<x-shop.styles />
{{--
    "Ordini ricevuti" — fase B, 27/08/2026.

    Prima esisteva solo /admin/listings/ordini, che pero' e' BACKOFFICE: la
    vedeva l'admin del circuito, non l'azienda che deve spedire. Il venditore
    riceveva una email e finiva li'.

    L'ordine dell'elenco non e' cronologico: prima quello che c'e' da fare, e
    dentro quel gruppo i piu' VECCHI per primi. In una lista di cose da
    spedire l'urgente e' chi aspetta da piu' tempo, non l'ultimo arrivato.
--}}

{{-- L'admin gestisce gli ordini PER CONTO delle aziende (richiesta di Laura,
     27/08): stessa pagina, ma senza il filtro sulla propria azienda e con la
     possibilita' di sceglierne una. --}}
@if($eAdmin && $aziende->isNotEmpty())
<form method="GET" action="{{ route('portal.sales.index') }}" class="card light-card" style="margin-bottom:16px;">
    <label class="field-label" for="company">Negozio</label>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select class="field-input" id="company" name="company" data-no-search style="max-width:340px;"
                onchange="this.form.submit()">
            <option value="">Tutti i negozi</option>
            @foreach($aziende as $azienda)
                <option value="{{ $azienda->id }}" @selected($aziendaScelta === $azienda->id)>{{ $azienda->name }}</option>
            @endforeach
        </select>
        @if($aziendaScelta)
            <a href="{{ route('portal.sales.index') }}" class="subtle" style="font-size:12.5px;">✕ Vedi tutti</a>
        @endif
    </div>
</form>
@endif

@if(($resiDaRispondere ?? 0) > 0)
<section class="card light-card" style="margin-bottom:16px;border-color:var(--buy-line);background:var(--buy-soft);">
    <div style="font-size:14px;font-weight:700;color:var(--buy-strong);">
        {{ $resiDaRispondere }} {{ $resiDaRispondere === 1 ? 'richiesta di reso aspetta' : 'richieste di reso aspettano' }} una risposta
    </div>
    <p class="subtle" style="font-size:12.5px;margin:6px 0 0;color:var(--buy-strong);">
        Finché non rispondi, il cliente resta senza merce e senza KY.
    </p>
</section>
@endif

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
        <h2 style="font-size:20px;font-weight:700;color:var(--ink);margin:0 0 8px;">Nessun ordine ricevuto</h2>
        <p class="subtle" style="margin:0 0 20px;">
            {{ $eAdmin
                ? 'Quando i negozi del circuito riceveranno ordini, li troverai qui e potrai gestirli per loro conto.'
                : 'Quando qualcuno comprerà dal tuo negozio, lo troverai qui con l\'indirizzo a cui spedire.' }}
        </p>
        @unless($eAdmin)
        <a href="{{ route('portal.shop.mine') }}" class="cta" style="display:inline-block;">I miei prodotti</a>
        @endunless
    </section>

@else

    <div class="stack">
        @foreach($ordini as $order)
        <a href="{{ route('portal.sales.show', $order) }}"
           class="card light-card"
           style="display:block;text-decoration:none;color:inherit;{{ $order->isConcluso() ? 'opacity:.62;' : '' }}">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div style="min-width:0;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-muted);">
                        Ordine {{ $order->numero }}
                    </div>
                    <div style="font-size:16px;font-weight:700;color:var(--ink);margin:3px 0 2px;">
                        {{ $order->summary_title }}
                    </div>
                    <div class="subtle" style="font-size:12.5px;">
                        @if($eAdmin)
                            {{ $order->company?->name ?? 'Negozio non più nel circuito' }} ·
                        @endif
                        {{ $order->shipping_recipient_name ?: ($order->buyerUser?->name ?? 'Cliente del circuito') }}
                        · {{ $order->placed_at?->format('d/m/Y') }}
                    </div>
                </div>

                <div style="text-align:right;">
                    @include('portal.partials.order-status-badge', ['order' => $order])
                    <div style="font-size:15px;font-weight:700;color:var(--ink);margin-top:7px;white-space:nowrap;">
                        {{ ky_format($order->total_ky) }} KY
                    </div>
                </div>
            </div>

            {{-- Una pratica di reso aperta batte qualsiasi altro avviso: e' la
                 sola cosa in questa lista che il venditore deve fare OGGI, e
                 che se ignora finisce all'assistenza del circuito. --}}
            @if($order->resoInCorso())
            <div style="margin-top:12px;font-size:12.5px;color:var(--buy-strong);background:var(--buy-soft);
                        border:1px solid var(--buy-line);border-radius:8px;padding:9px 12px;">
                <strong>Richiesta di reso in attesa di risposta.</strong> Aprila per accettare o rifiutare.
            </div>
            @elseif($order->isInAttesaDiEuro())
            <div style="margin-top:12px;font-size:12.5px;color:var(--warning);background:var(--warning-soft);
                        border:1px solid var(--warning-line);border-radius:8px;padding:9px 12px;">
                Non spedire ancora: la quota in euro non è stata saldata.
            </div>
            @elseif($order->buyer_note)
            <div style="margin-top:12px;font-size:12.5px;color:var(--info);background:var(--info-soft);
                        border:1px solid var(--info-line);border-radius:8px;padding:9px 12px;">
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
