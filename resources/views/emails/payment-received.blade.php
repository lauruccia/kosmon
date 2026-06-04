@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao, {{ $recipient->name }}!</p>

    <p>Hai ricevuto un pagamento sul tuo conto KMoney. Il saldo è stato aggiornato automaticamente.</p>

    <div class="amount-block">
        <div class="amount-label">Importo ricevuto</div>
        <div class="amount-value">
            {{ ky_format($transfer->amount) }}<span>KY</span>
        </div>
    </div>

    <table class="info-table">
        <tr>
            <td>Riferimento</td>
            <td>{{ $transfer->reference }}</td>
        </tr>
        <tr>
            <td>Da</td>
            <td>{{ $fromAccount->display_name }}</td>
        </tr>
        <tr>
            <td>A</td>
            <td>{{ $toAccount->display_name }}</td>
        </tr>
        <tr>
            <td>Data e ora</td>
            <td>{{ $transfer->booked_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}</td>
        </tr>
        @if($transfer->description)
        <tr>
            <td>Causale</td>
            <td>{{ $transfer->description }}</td>
        </tr>
        @endif
        <tr>
            <td>Saldo dopo operazione</td>
            <td style="color:#166534;"><strong>{{ ky_format($balanceAfter) }} KY</strong></td>
        </tr>
    </table>

    <div class="alert success">
        Il pagamento è stato registrato nel libro mastro del circuito. Puoi visualizzare il dettaglio completo nella sezione Movimenti del tuo portale.
    </div>

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/movimenti" class="cta-btn">Vedi movimenti →</a>
    </div>
@endsection
