@extends('layouts.portal')

@section('content')
{{--
    Il dettaglio dell'ordine — fase B, 27/08/2026.

    UNA view per due punti di vista (`$lato` = 'compratore' | 'venditore'):
    l'ordine e' lo stesso oggetto, e tenere due file separati vorrebbe dire
    che fra un mese mostrano cose diverse per sbaglio. Cambiano il link di
    ritorno, di chi si legge il nome, e i bottoni: quelli li ha solo chi vende.
--}}
@php
    $eVenditore = $lato === 'venditore';
    $passaggi   = $eVenditore ? $order->passaggiDisponibili() : [];
@endphp

<div style="margin-bottom:16px;">
    <a href="{{ $eVenditore ? route('portal.sales.index') : route('portal.orders.index') }}" class="shop-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        {{ $eVenditore ? 'Torna agli ordini ricevuti' : 'Torna ai miei ordini' }}
    </a>
</div>

<div class="cart-grid" style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

    <div class="stack">

        {{-- ── Che cosa è stato comprato ─────────────────────────────────── --}}
        <section class="card light-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div>
                    <span class="eyebrow">Ordine {{ $order->numero }}</span>
                    <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0;">
                        {{ $eVenditore ? 'Che cosa devi preparare' : 'Che cosa hai comprato' }}
                    </h3>
                </div>
                @include('portal.partials.order-status-badge', ['order' => $order])
            </div>

            <div style="margin-top:14px;">
                @foreach($order->items as $riga)
                <div style="display:flex;justify-content:space-between;gap:12px;padding:11px 0;
                            border-bottom:1px solid #f1f5f9;font-size:14px;">
                    <div style="min-width:0;">
                        {{ $riga->quantity }} × {{ $riga->title }}
                        @if($riga->variant_label)
                            <span class="subtle">— {{ $riga->variant_label }}</span>
                        @endif
                    </div>
                    <span style="white-space:nowrap;color:#10263d;font-weight:600;">
                        {{ ky_format($riga->line_ky_amount) }} KY
                    </span>
                </div>
                @endforeach
            </div>

            @if($order->buyer_note)
            <div style="margin-top:14px;font-size:13px;color:#0c4a86;background:#eff6ff;
                        border:1px solid #bfdbfe;border-radius:9px;padding:11px 13px;">
                <strong>Nota {{ $eVenditore ? 'del cliente' : 'che hai lasciato' }}:</strong>
                {{ $order->buyer_note }}
            </div>
            @endif
        </section>

        {{-- ── Dove va ───────────────────────────────────────────────────── --}}
        @if($order->richiedeSpedizione())
        <section class="card light-card">
            <span class="eyebrow">Spedizione</span>
            <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 12px;">
                {{ $eVenditore ? 'Dove spedire' : 'Dove arriva' }}
            </h3>
            <div style="font-size:14px;line-height:1.6;color:#10263d;">
                {{ $order->shipping_recipient_name }}<br>
                {{ $order->shipping_address }}<br>
                {{ $order->shipping_postal_code }} {{ $order->shipping_city }}
                {{ $order->shipping_province ? '(' . $order->shipping_province . ')' : '' }}
                @if($order->shipping_phone)
                    <br>{{ $order->shipping_phone }}
                @endif
            </div>
            {{-- Snapshot: se il compratore cambia indirizzo in rubrica domani,
                 questo resta quello a cui il pacco e' partito davvero. --}}
            <p class="subtle" style="font-size:11.5px;margin:12px 0 0;">
                È l'indirizzo scelto al momento dell'ordine e non cambia più.
            </p>
        </section>
        @endif

        {{-- ── I bottoni: solo chi vende ─────────────────────────────────── --}}
        @if($eVenditore && ! empty($passaggi))
        <section class="card light-card">
            <span class="eyebrow">Aggiorna</span>
            <h3 style="font-size:17px;font-weight:700;color:#10263d;margin:4px 0 4px;">A che punto sei</h3>
            <p class="subtle" style="font-size:12.5px;margin:0 0 14px;">
                Il cliente vede questo stato dalla sua pagina ordini. Cambiarlo non muove denaro:
                l'addebito è già avvenuto alla cassa.
            </p>

            <form method="POST" action="{{ route('portal.sales.status', $order) }}">
                @csrf

                @if(array_key_exists(\App\Models\Order::STATUS_SHIPPED, $passaggi))
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                    <div>
                        <label class="field-label" for="carrier">Corriere <span class="subtle">(facoltativo)</span></label>
                        <input class="field-input" type="text" id="carrier" name="carrier" maxlength="60"
                               value="{{ old('carrier', $order->carrier) }}" placeholder="es. BRT">
                    </div>
                    <div>
                        <label class="field-label" for="tracking_code">Codice di tracciamento <span class="subtle">(facoltativo)</span></label>
                        <input class="field-input" type="text" id="tracking_code" name="tracking_code" maxlength="100"
                               value="{{ old('tracking_code', $order->tracking_code) }}">
                    </div>
                </div>
                @endif

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    @foreach($passaggi as $stato => $etichetta)
                        <button type="submit" name="stato" value="{{ $stato }}"
                                class="{{ $loop->first ? 'cta' : 'cta-outline' }}">
                            Segna «{{ $etichetta }}»
                        </button>
                    @endforeach
                </div>
            </form>
        </section>
        @elseif($eVenditore && $order->isInAttesaDiEuro())
        <section class="card light-card">
            <p style="font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;
                      border-radius:9px;padding:11px 13px;margin:0;">
                Questo ordine aspetta ancora la quota in euro. Quando il pagamento risulterà incassato
                potrai segnarlo come preparato e spedito.
            </p>
        </section>
        @endif
    </div>

    {{-- ── La colonna di destra ──────────────────────────────────────────── --}}
    <div class="stack">
        <section class="card account-hero card-pad">
            <div class="k-tag">Totale</div>
            <div class="metric" style="margin-top:12px;">
                <div class="metric-label">In KY</div>
                <div class="metric-value">{{ ky_format($order->total_ky) }} KY</div>
            </div>
            @if($order->total_eur > 0)
            <div class="metric">
                <div class="metric-label">Quota in euro</div>
                <div class="metric-value">€ {{ number_format($order->total_eur / 100, 2, ',', '.') }}</div>
            </div>
            @endif
            @if($order->shipping_ky > 0)
            <div class="metric">
                <div class="metric-label">Di cui spedizione</div>
                <div class="metric-value">{{ ky_format($order->shipping_ky) }} KY</div>
            </div>
            @endif
        </section>

        {{-- Il compratore che deve ancora saldare gli euro ha qui la sua via
             d'uscita: senza, l'ordine resta fermo e non si capisce perche'. --}}
        @if(! $eVenditore && $order->isInAttesaDiEuro() && $order->payment)
        <section class="card light-card">
            <p style="font-size:13px;margin:0 0 12px;color:#92400e;">
                Il venditore aspetta la quota in euro per poter spedire.
            </p>
            <a href="{{ route('portal.shop.orders.pay', $order->payment) }}" class="cta"
               style="width:100%;text-align:center;display:block;">
                Paga € {{ number_format($order->total_eur / 100, 2, ',', '.') }}
            </a>
        </section>
        @endif

        <section class="card light-card">
            <span class="eyebrow">Cronologia</span>
            <div style="margin-top:12px;font-size:13px;line-height:1.9;color:#10263d;">
                <div>Ordinato · <strong>{{ $order->placed_at?->format('d/m/Y H:i') }}</strong></div>
                @if($order->shipped_at)
                    <div>Spedito · <strong>{{ $order->shipped_at->format('d/m/Y H:i') }}</strong></div>
                @endif
                @if($order->delivered_at)
                    <div>Consegnato · <strong>{{ $order->delivered_at->format('d/m/Y H:i') }}</strong></div>
                @endif
                @if($order->cancelled_at)
                    <div>Annullato · <strong>{{ $order->cancelled_at->format('d/m/Y H:i') }}</strong></div>
                @endif
            </div>

            @if($order->tracking_code)
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">
                    Tracciamento
                </div>
                <div style="font-size:14px;font-weight:600;color:#10263d;margin-top:4px;word-break:break-all;">
                    {{ $order->carrier ? $order->carrier . ' · ' : '' }}{{ $order->tracking_code }}
                </div>
            </div>
            @endif

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">
                    {{ $eVenditore ? 'Cliente' : 'Venditore' }}
                </div>
                <div style="font-size:14px;font-weight:600;color:#10263d;margin-top:4px;">
                    @if($eVenditore)
                        {{ $order->shipping_recipient_name ?: ($order->buyerUser?->name ?? 'Cliente del circuito') }}
                    @else
                        {{ $order->company?->name ?? 'Non più nel circuito' }}
                    @endif
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
