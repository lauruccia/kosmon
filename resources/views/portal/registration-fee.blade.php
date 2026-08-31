@extends('layouts.portal')

@section('content')
<div style="max-width:640px;">

    <div class="card" style="padding:28px;">

        <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#e0f2fe;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;">&#127915;</div>
            <div>
                <div style="font-size:18px;font-weight:800;color:var(--ink);">Quota di iscrizione</div>
                <div style="font-size:13px;color:var(--ink-soft);margin-top:2px;">Una tantum, per entrare nel circuito.</div>
            </div>
        </div>

        <div style="background:#f8fafc;border-radius:10px;padding:18px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:15px;font-weight:700;">Importo</span>
            <span style="font-size:22px;font-weight:800;color:var(--ink);">{{ number_format($amountCents / 100, 2, ',', '.') }} &euro;</span>
        </div>

        <div class="notice" style="margin-bottom:22px;">
            Puoi pagarla in <strong>euro</strong> &mdash; e in quel caso ricevi
            <strong>{{ ky_format($amountCents) }} KY</strong> sul tuo conto, quindi non perdi niente: hai
            comprato KY &mdash; oppure con il <strong>saldo KY</strong>, e allora il conto va sotto di
            {{ ky_format($amountCents) }} KY. Quel saldo negativo lo recuperi invitando qualcuno: ogni
            persona, agente o attivit&agrave; che entra grazie a te ti fa incassare un bonus in KY.
        </div>

        @if(empty($metodi))
            <div class="notice error">
                Nessun metodo di pagamento &egrave; al momento disponibile. Contatta l'assistenza.
            </div>
        @endif

        <div style="display:grid;gap:12px;">

            @isset($metodi['stripe'])
            <form method="POST" action="{{ route('portal.registration-fee.stripe') }}">
                @csrf
                <button type="submit" class="cta" style="width:100%;">Paga {{ number_format($amountCents / 100, 2, ',', '.') }} &euro; con carta</button>
            </form>
            @endisset

            @isset($metodi['paypal'])
            <div id="paypal-button-container" data-create-url="{{ route('portal.registration-fee.paypal-create-order') }}"></div>
            @endisset

            @isset($metodi['bank_transfer'])
            <form method="POST" action="{{ route('portal.registration-fee.bank-transfer') }}">
                @csrf
                <button type="submit" class="cta secondary" style="width:100%;">Paga con bonifico bancario</button>
            </form>
            @endisset

            @isset($metodi['ky'])
            <form method="POST" action="{{ route('portal.registration-fee.ky') }}"
                  onsubmit="return confirm('Il tuo conto andrà sotto di {{ ky_format($amountCents) }} KY. Confermi?');">
                @csrf
                <button type="submit" class="cta secondary" style="width:100%;">
                    Paga con il saldo KY (il conto andr&agrave; a &minus;{{ ky_format($amountCents) }} KY)
                </button>
                <div style="font-size:12px;color:var(--ink-muted);margin-top:8px;text-align:center;">
                    Saldo attuale: {{ ky_format($saldo) }} KY
                </div>
            </form>
            @endisset

        </div>

        <div style="margin-top:24px;padding-top:18px;border-top:1px solid var(--border);font-size:13px;color:var(--ink-muted);">
            Fino al pagamento puoi entrare e vedere il tuo conto, ma non puoi inviare KY, incassare o
            acquistare nel negozio. Puoi comunque <a href="{{ route('portal.ky-cards.index') }}">ricaricare il conto</a>.
        </div>

    </div>
</div>

@isset($metodi['paypal'])
@push('scripts')
<script src="https://www.paypal.com/sdk/js?client-id={{ config('services.paypal.client_id') }}&currency=EUR"></script>
<script>
(function () {
    var box = document.getElementById('paypal-button-container');
    if (!box || typeof paypal === 'undefined') { return; }

    paypal.Buttons({
        createOrder: function () {
            return fetch(box.dataset.createUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.id) { throw new Error(data.error || 'Errore PayPal'); }
                box.dataset.paymentUuid = data.payment_uuid;
                return data.id;
            });
        },
        onApprove: function () {
            // La cattura la fa il server: il browser non deve mai essere
            // l'unico a sapere che un pagamento e' andato a buon fine.
            window.location = '{{ url('/quota-iscrizione/paypal/capture') }}/' + box.dataset.paymentUuid;
        }
    }).render('#paypal-button-container');
})();
</script>
@endpush
@endisset
@endsection
