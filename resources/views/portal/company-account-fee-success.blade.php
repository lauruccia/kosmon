@extends('layouts.portal')

@section('content')
<div style="max-width:560px;">
    <div class="card" style="padding:28px;text-align:center;">

        @if($payment->isCompleted())
            <div style="font-size:44px;margin-bottom:10px;">&#9989;</div>
            <div style="font-size:19px;font-weight:800;color:var(--ink);">Quota di apertura conto saldata</div>

            @if($payment->isPaidInEuro())
                <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                    Hai pagato {{ number_format($payment->amount_eur, 2, ',', '.') }} &euro;.
                    @if($payment->transfer)
                        Sul conto sono stati accreditati <strong>{{ ky_format((int) $payment->transfer->amount) }} KY</strong>.
                    @else
                        Il saldo KY del conto non &egrave; stato toccato: la quota di apertura &egrave; il prezzo del
                        conto, non una ricarica.
                    @endif
                </p>
            @else
                @php
                    $fidoAggiuntivo = (int) ($currentUser->company_account_fee_ky_allowance_cents ?? 0) > 0;
                @endphp
                <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                    Sono stati addebitati {{ ky_format($payment->ky_amount) }} KY: il conto &egrave; ora in negativo di
                    quell'importo{!! $fidoAggiuntivo
                        ? ', e il massimale &egrave; stato aumentato dello stesso valore, cos&igrave; il fido che avevi resta intero.'
                        : ', a valere sul fido che avevi gi&agrave;.' !!}
                </p>
            @endif

            <a class="cta" href="{{ route('portal.dashboard') }}" style="margin-top:18px;display:inline-block;">Vai alla dashboard</a>

        @elseif($payment->isPendingBankTransfer())
            <div style="font-size:44px;margin-bottom:10px;">&#8987;</div>
            <div style="font-size:19px;font-weight:800;color:var(--ink);">In attesa del bonifico</div>
            <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                Appena il bonifico risulter&agrave; ricevuto, la quota sar&agrave; saldata.
            </p>
            <a class="cta secondary" href="{{ route('portal.company-account-fee.show') }}" style="margin-top:18px;display:inline-block;">Torna ai metodi di pagamento</a>

        @else
            <div style="font-size:44px;margin-bottom:10px;">&#9888;</div>
            <div style="font-size:19px;font-weight:800;color:var(--ink);">Pagamento non completato</div>
            <p style="font-size:14px;color:var(--ink-soft);margin-top:10px;">
                Il pagamento non risulta incassato. Puoi riprovare o scegliere un altro metodo.
            </p>
            <a class="cta" href="{{ route('portal.company-account-fee.show') }}" style="margin-top:18px;display:inline-block;">Riprova</a>
        @endif

    </div>
</div>
@endsection
