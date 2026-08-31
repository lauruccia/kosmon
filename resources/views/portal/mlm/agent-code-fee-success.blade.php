@extends('layouts.portal')

@section('content')
<div style="max-width:560px;">
    <div class="card" style="padding:28px;text-align:center;">

        @if($payment->isCompleted())
            <div style="font-size:44px;margin-bottom:10px;">&#9989;</div>
            <div style="font-size:19px;font-weight:800;color:var(--ink);">Quota saldata</div>

            @if($payment->isPaidInEuro())
                <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                    Hai pagato {{ number_format($payment->amount_eur, 2, ',', '.') }} &euro; per il codice agente.
                </p>
            @else
                <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                    Sono stati addebitati {{ ky_format($payment->ky_amount) }} KY: il tuo conto &egrave; ora in negativo
                    di quell'importo. Lo recuperi invitando altre persone, agenti o attivit&agrave;.
                </p>
            @endif

            <p style="font-size:14px;color:var(--ink);margin-top:14px;font-weight:600;">
                Ora puoi firmare il contratto di nomina e diventare agente KNM.
            </p>

            <a class="cta" href="{{ route('portal.mlm.agent-contract.show') }}" style="margin-top:18px;display:inline-block;">Vai alla firma del contratto</a>

        @elseif($payment->isPendingBankTransfer())
            <div style="font-size:44px;margin-bottom:10px;">&#8987;</div>
            <div style="font-size:19px;font-weight:800;color:var(--ink);">In attesa del bonifico</div>
            <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                Appena risulter&agrave; ricevuto potrai firmare il contratto di nomina.
            </p>
            <a class="cta secondary" href="{{ route('portal.mlm.agent-code-fee.show') }}" style="margin-top:18px;display:inline-block;">Torna ai metodi di pagamento</a>

        @else
            <div style="font-size:44px;margin-bottom:10px;">&#9888;</div>
            <div style="font-size:19px;font-weight:800;color:var(--ink);">Pagamento non completato</div>
            <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                Il pagamento non risulta incassato. Puoi riprovare o scegliere un altro metodo.
            </p>
            <a class="cta" href="{{ route('portal.mlm.agent-code-fee.show') }}" style="margin-top:18px;display:inline-block;">Riprova</a>
        @endif

    </div>
</div>
@endsection
