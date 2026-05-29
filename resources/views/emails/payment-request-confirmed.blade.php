@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao, {{ $recipient->name }}!</p>

    <p>La tua richiesta di pagamento è stata <strong>confermata</strong>. Il pagamento è stato registrato e il tuo saldo aggiornato.</p>

    <div class="amount-block">
        <div class="amount-label">Importo incassato</div>
        <div class="amount-value">
            {{ number_format($transfer->amount, 2, ',', '.') }}<span>KY</span>
        </div>
    </div>

    <table class="info-table">
        <tr>
            <td>Riferimento</td>
            <td>{{ $transfer->reference }}</td>
        </tr>
        <tr>
            <td>Confermato da</td>
            <td>{{ $fromAccount->display_name }}</td>
        </tr>
        <tr>
            <td>Accreditato su</td>
            <td>{{ $toAccount->display_name }}</td>
        </tr>
        <tr>
            <td>Data conferma</td>
            <td>{{ $transfer->booked_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}</td>
        </tr>
        @if($transfer->description)
        <tr>
            <td>Causale</td>
            <td>{{ $transfer->description }}</td>
        </tr>
        @endif
    </table>

    <div class="alert success">
        Il pagamento è stato registrato nel libro mastro del circuito e il tuo saldo KY è stato aggiornato.
    </div>

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/movimenti" class="cta-btn">Vedi movimenti →</a>
    </div>
@endsection
