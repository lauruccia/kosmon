@extends('layouts.portal')

@section('content')
{{-- $isSeller arriva dal controller (PaymentController::show), stessa regola
     usata per autorizzare la conferma del bonifico — non ricalcolarla qui
     solo sul company_id, altrimenti un utente aziendale senza permesso
     marketplace vedrebbe il bottone "Conferma ricezione bonifico" e otterrebbe
     un 403 al click. --}}
<div style="max-width:640px;">

    <div style="margin-bottom:20px;">
        @if($payment->listing)
            <a href="{{ route('portal.shop.show', $payment->listing) }}" style="font-size:13px;color:var(--primary,#0c4a86);text-decoration:none;">&larr; Torna al prodotto</a>
        @else
            <a href="{{ route('portal.shop') }}" style="font-size:13px;color:var(--primary,#0c4a86);text-decoration:none;">&larr; Torna allo shop</a>
        @endif
    </div>

    @if(session('portal_success'))
        <div class="alert alert-success" style="margin-bottom:20px;">{{ session('portal_success') }}</div>
    @endif
    @if(session('portal_error'))
        <div class="alert alert-error" style="margin-bottom:20px;">{{ session('portal_error') }}</div>
    @endif

    {{-- Riepilogo ordine --}}
    <div class="card" style="padding:20px 22px;margin-bottom:18px;">
        <div style="font-size:11px;font-weight:700;color:var(--ink-muted,#7a95aa);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">Riepilogo ordine</div>
        <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px;">
            <span>{{ $payment->listing?->title ?? 'Prodotto shop' }}</span>
            <span style="font-weight:700;">{{ number_format($payment->amount / 100, 2, ',', '.') }} &euro;</span>
        </div>
        <div style="font-size:12.5px;color:var(--ink-muted,#7a95aa);">Venduto da {{ $payment->company->name }}</div>
    </div>

    @if($payment->status === \App\Models\MarketplaceOrderPayment::STATUS_PAID)
        {{-- ── Pagato ── --}}
        <div class="card" style="padding:24px;text-align:center;">
            <div style="font-size:40px;margin-bottom:10px;">✅</div>
            <div style="font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px;">Pagamento completato</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:20px;">
                {{ number_format($payment->amount / 100, 2, ',', '.') }} € pagati a {{ $payment->company->name }}.
            </div>
            @if($payment->listing)
                <a href="{{ route('portal.shop.show', $payment->listing) }}" class="cta" style="justify-content:center;">Torna al prodotto</a>
            @else
                <a href="{{ route('portal.shop') }}" class="cta" style="justify-content:center;">Torna allo shop</a>
            @endif
        </div>

    @elseif($payment->status === \App\Models\MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION && $payment->provider === \App\Models\PaymentGateway::PROVIDER_BANK_TRANSFER)
        {{-- ── Istruzioni bonifico ── --}}
        @php $bankCreds = $payment->paymentGateway?->credentials ?? []; @endphp
        <div class="card" style="padding:24px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
                <div style="width:44px;height:44px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">🏦</div>
                <div>
                    <div style="font-size:16px;font-weight:800;color:var(--ink);">Istruzioni per il bonifico</div>
                    <div style="font-size:12.5px;color:var(--ink-soft);">Il venditore confermerà la ricezione appena visibile sul suo conto.</div>
                </div>
            </div>

            @php
                $bankFields = [
                    'Beneficiario' => $bankCreds['intestatario'] ?? null,
                    'Banca'        => $bankCreds['banca'] ?? null,
                    'IBAN'         => $bankCreds['iban'] ?? null,
                    'Importo'      => number_format($payment->amount / 100, 2, ',', '.') . ' EUR',
                    'Causale'      => 'ORDINE-' . strtoupper(substr($payment->uuid, 0, 8)),
                ];
            @endphp
            <div style="margin-bottom:18px;">
                @foreach($bankFields as $label => $value)
                    @continue(! $value)
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #f1f5f9;">
                        <span style="font-size:13px;color:var(--ink-muted,#7a95aa);min-width:110px;">{{ $label }}</span>
                        <span style="font-size:13.5px;font-weight:700;font-family:{{ in_array($label, ['IBAN','Causale']) ? 'monospace' : 'inherit' }};">{{ $value }}</span>
                    </div>
                @endforeach
            </div>

            @if($bankCreds['note'] ?? null)
                <div style="background:var(--surface-soft,#f8fafc);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12.5px;color:var(--ink-soft);">
                    Nota del venditore: {{ $bankCreds['note'] }}
                </div>
            @endif

            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#92400e;">
                ⏳ In attesa di conferma da parte del venditore.
            </div>

            @if($isSeller)
                <form method="POST" action="{{ route('portal.shop.orders.confirm-bank', $payment) }}"
                      onsubmit="return confirm('Confermi di aver ricevuto questo bonifico sul tuo conto?');">
                    @csrf
                    <button type="submit" class="cta" style="width:100%;justify-content:center;">Conferma ricezione bonifico</button>
                </form>
            @endif
        </div>

    @elseif($payment->status === \App\Models\MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION)
        {{-- ── Stripe/PayPal in attesa di verifica ── --}}
        <div class="card" style="padding:24px;text-align:center;">
            <div style="font-size:36px;margin-bottom:10px;">⏳</div>
            <div style="font-size:15px;font-weight:800;color:var(--ink);margin-bottom:6px;">Verifica del pagamento in corso</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:20px;">
                Se hai già completato il pagamento, ricarica questa pagina tra qualche istante.
            </div>
            <a href="{{ route('portal.shop.orders.pay', $payment) }}" class="cta secondary" style="justify-content:center;">Ricarica</a>
        </div>

    @elseif($isSeller)
        {{-- ── Venditore: nessun metodo ancora scelto dall'acquirente, nulla da fare qui ── --}}
        <div class="card" style="padding:24px;text-align:center;">
            <div style="font-size:14px;color:var(--ink-soft);">
                In attesa che l'acquirente scelga un metodo di pagamento per la quota in euro.
            </div>
        </div>

    @else
        {{-- ── Scelta metodo (pending / failed / cancelled) ── --}}
        @if($activeGateways->isEmpty())
            <div class="card" style="padding:24px;text-align:center;">
                <div style="font-size:14px;color:var(--ink-soft);">
                    Questo venditore non ha al momento nessun metodo di pagamento attivo. Riprova più tardi o contattalo direttamente.
                </div>
            </div>
        @else
            <div style="font-size:15px;font-weight:800;color:var(--ink);margin-bottom:14px;">Scegli il metodo di pagamento</div>

            @if($activeGateways->has(\App\Models\PaymentGateway::PROVIDER_STRIPE))
                <div class="card" style="padding:20px;margin-bottom:14px;">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">💳 Carta di credito (Stripe)</div>
                    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">Verrai reindirizzato su Stripe per completare il pagamento in sicurezza.</div>
                    <form method="POST" action="{{ route('portal.shop.orders.pay.stripe', $payment) }}">
                        @csrf
                        <button type="submit" class="cta" style="width:100%;justify-content:center;">
                            Paga {{ number_format($payment->amount / 100, 2, ',', '.') }} € con carta →
                        </button>
                    </form>
                </div>
            @endif

            @if($activeGateways->has(\App\Models\PaymentGateway::PROVIDER_PAYPAL))
                <div class="card" style="padding:20px;margin-bottom:14px;">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">🅿 PayPal</div>
                    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">Verrai reindirizzato su PayPal per completare il pagamento.</div>
                    <form method="POST" action="{{ route('portal.shop.orders.pay.paypal', $payment) }}">
                        @csrf
                        <button type="submit" class="cta" style="width:100%;justify-content:center;">
                            Paga {{ number_format($payment->amount / 100, 2, ',', '.') }} € con PayPal →
                        </button>
                    </form>
                </div>
            @endif

            @if($activeGateways->has(\App\Models\PaymentGateway::PROVIDER_BANK_TRANSFER))
                <div class="card" style="padding:20px;margin-bottom:14px;">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">🏦 Bonifico bancario</div>
                    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">Ricevi subito le coordinate bancarie del venditore. Il venditore confermerà la ricezione.</div>
                    <form method="POST" action="{{ route('portal.shop.orders.pay.bank', $payment) }}">
                        @csrf
                        <button type="submit" class="cta secondary" style="width:100%;justify-content:center;">Ricevi coordinate per il bonifico →</button>
                    </form>
                </div>
            @endif
        @endif
    @endif

</div>
@endsection
