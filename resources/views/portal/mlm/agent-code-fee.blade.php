@extends('layouts.portal')

@section('content')
<div style="max-width:640px;">

    <div class="card" style="padding:28px;">

        @include('portal.mlm._passi', ['passo' => 1])

        <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#ede9fe;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;">&#128273;</div>
            <div>
                <div style="font-size:18px;font-weight:800;color:var(--ink);">Quota per il codice agente</div>
                <div style="font-size:13px;color:var(--ink-soft);margin-top:2px;">Richiesta approvata. Restano due passi: questa quota, poi la firma della nomina.</div>
            </div>
        </div>

        <div style="background:#f8fafc;border-radius:10px;padding:18px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:15px;font-weight:700;">Importo</span>
            <span style="font-size:22px;font-weight:800;color:var(--ink);">{{ number_format($amountCents / 100, 2, ',', '.') }} &euro;</span>
        </div>

        <div class="notice" style="margin-bottom:22px;">
            Una volta saldata potrai firmare il contratto di nomina e diventare agente KNM a tutti gli effetti.
            Puoi pagare in <strong>euro</strong>, oppure con il tuo <strong>saldo KY</strong>: in quel caso il conto
            va sotto di {{ ky_format($amountCents) }} KY, che recuperi invitando altre persone, agenti o attivit&agrave;.
        </div>

        @if(empty($metodi))
            <div class="notice error">
                Nessun metodo di pagamento &egrave; al momento disponibile. Contatta l'assistenza.
            </div>
        @endif

        {{-- Il bonifico gia' chiesto (02/09/2026). Chi torna dopo essere
             andato in banca non deve ritrovare i quattro bottoni come la
             prima volta: farne un altro vorrebbe dire una causale diversa da
             quella scritta sul bonifico vero, e nessuno dei due piu'
             ricollegabile. --}}
        @isset($bonifico)
        @if($bonifico)
            <div style="border:1px solid #fcd34d;background:#fffbeb;border-radius:12px;padding:18px;margin-bottom:18px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                    <span style="font-size:20px;">&#127968;</span>
                    <div style="font-size:15px;font-weight:800;color:var(--ink);">Hai scelto il bonifico bancario</div>
                </div>

                <div style="font-size:13px;color:var(--ink-soft);line-height:1.6;margin-bottom:14px;">
                    Stiamo aspettando l'accredito. La quota risulter&agrave; saldata quando il bonifico
                    sar&agrave; verificato, di norma entro 1-2 giorni lavorativi, e in quel momento
                    potrai firmare il contratto di nomina.
                    <br>
                    Causale da indicare: <strong style="font-family:monospace;color:#7c3aed;">{{ $bonifico->bank_transfer_reference }}</strong>
                </div>

                <div style="display:grid;gap:10px;">
                    <form method="POST" action="{{ route('portal.mlm.agent-code-fee.bank-transfer') }}">
                        @csrf
                        <button type="submit" class="cta" style="width:100%;">Procedi con il bonifico (rivedi i dati)</button>
                    </form>

                    <form method="POST" action="{{ route('portal.mlm.agent-code-fee.bank-transfer.abandon') }}"
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
            <form method="POST" action="{{ route('portal.mlm.agent-code-fee.stripe') }}">
                @csrf
                <button type="submit" class="cta" style="width:100%;">Paga {{ number_format($amountCents / 100, 2, ',', '.') }} &euro; con carta</button>
            </form>
            @endisset

            @isset($metodi['paypal'])
            <div id="paypal-button-container" data-create-url="{{ route('portal.mlm.agent-code-fee.paypal-create-order') }}"></div>
            @endisset

            @isset($metodi['bank_transfer'])
            <form method="POST" action="{{ route('portal.mlm.agent-code-fee.bank-transfer') }}">
                @csrf
                <button type="submit" class="cta secondary" style="width:100%;">Paga con bonifico bancario</button>
            </form>
            @endisset

            @isset($metodi['ky'])
            <form method="POST" action="{{ route('portal.mlm.agent-code-fee.ky') }}"
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

        {{-- Due situazioni diverse, e dirle uguali sarebbe una bugia in una
             delle due (02/09/2026): chi nel circuito non e' ancora entrato
             pagando ha il conto fermo, chi c'era gia' no. --}}
        <div style="margin-top:24px;padding-top:18px;border-top:1px solid var(--border);font-size:13px;color:var(--ink-muted);">
            @if(app(\App\Services\RegistrationFeeService::class)->isSuspendedFor($currentUser))
                Questa quota &egrave; anche il tuo ingresso nel circuito: fino al pagamento puoi entrare e vedere il
                conto, ma non puoi inviare KY, incassare o acquistare nel negozio. Puoi
                <a href="{{ route('portal.ky-cards.index') }}">ricaricare il conto</a> quando vuoi.
            @else
                Il tuo conto resta pienamente operativo: puoi inviare KY, incassare e acquistare come sempre.
                Questa quota serve solo a sbloccare la firma della nomina.
            @endif
        </div>

        {{-- La via d'uscita: nessuno resta intrappolato in un percorso che non
             vuole piu' fare. Torna cliente normale e potra' ricandidarsi. --}}
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
            <form method="POST" action="{{ route('portal.mlm.agent-code-fee.give-up') }}"
                  onsubmit="return confirm('Rinunci a diventare agente KNM? Il tuo conto tornerà pienamente operativo e potrai ricandidarti quando vorrai.');">
                @csrf
                <button type="submit" style="background:none;border:none;padding:0;font-size:13px;color:var(--ink-muted);text-decoration:underline;cursor:pointer;">
                    Ho cambiato idea: non voglio diventare agente
                </button>
            </form>
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
            window.location = '{{ url('/mlm/quota-codice/paypal/capture') }}/' + box.dataset.paymentUuid;
        }
    }).render('#paypal-button-container');
})();
</script>
@endpush
@endisset
@endsection
