@extends('layouts.portal')

@section('content')
<div>

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
            {{-- Quanti KY tornano indietro pagando in euro lo decide l'admin
                 (04/09/2026): prima erano sempre pari all'importo e questa
                 pagina lo dava per scontato. Con zero il testo non deve
                 promettere niente. --}}
            @if(($kyCredit ?? 0) > 0)
                Puoi pagarla in <strong>euro</strong> &mdash; e in quel caso ricevi
                <strong>{{ ky_format($kyCredit) }} KY</strong> sul tuo conto &mdash; oppure con il
                <strong>saldo KY</strong>, e allora il conto va sotto di
                {{ ky_format($amountCents) }} KY.
            @else
                Puoi pagarla in <strong>euro</strong> oppure con il <strong>saldo KY</strong>, e in
                quel caso il conto va sotto di {{ ky_format($amountCents) }} KY.
            @endif
            Un saldo negativo lo recuperi invitando qualcuno: ogni
            persona, agente o attivit&agrave; che entra grazie a te ti fa incassare un bonus in KY.
        </div>

        @if(empty($metodi))
            <div class="notice error">
                Nessun metodo di pagamento &egrave; al momento disponibile. Contatta l'assistenza.
            </div>
        @endif

        {{-- Bonifico gia' chiesto: niente quattro bottoni come la prima volta.
             L'utente ha in mano una causale e sta aspettando; qui deve poter
             solo rivedere i dati o cambiare idea. --}}
        @isset($bonifico)
        @if($bonifico)
            <div style="border:1px solid #fcd34d;background:#fffbeb;border-radius:12px;padding:18px;margin-bottom:18px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                    <span style="font-size:20px;">&#127968;</span>
                    <div style="font-size:15px;font-weight:800;color:var(--ink);">Hai scelto il bonifico bancario</div>
                </div>

                <div style="font-size:13px;color:var(--ink-soft);line-height:1.6;margin-bottom:14px;">
                    Stiamo aspettando l'accredito. La quota risulter&agrave; saldata quando il bonifico
                    sar&agrave; verificato, di norma entro 1-2 giorni lavorativi.
                    @if(($kyCredit ?? 0) > 0)
                        In quel momento riceverai {{ ky_format($kyCredit) }} KY.
                    @endif
                    <br>
                    Causale da indicare: <strong style="font-family:monospace;color:#7c3aed;">{{ $bonifico->bank_transfer_reference }}</strong>
                </div>

                <div style="display:grid;gap:10px;">
                    <form method="POST" action="{{ route('portal.registration-fee.bank-transfer') }}">
                        @csrf
                        <button type="submit" class="cta" style="width:100%;">Procedi con il bonifico (rivedi i dati)</button>
                    </form>

                    <form method="POST" action="{{ route('portal.registration-fee.bank-transfer.abandon') }}"
                          onsubmit="return confirm('Annullo la richiesta di bonifico e torni a scegliere il metodo. Se il bonifico lo hai già fatto, NON annullare: arriverà comunque.');">
                        @csrf
                        <button type="submit" class="cta secondary" style="width:100%;">Cambia metodo di pagamento</button>
                    </form>
                </div>
            </div>
        @endif
        @endisset

        @if(empty($bonifico))
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
        @endif

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
