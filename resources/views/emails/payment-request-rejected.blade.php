@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao, {{ $recipient->name }}!</p>

    <p>La tua richiesta di pagamento è stata <strong>rifiutata</strong>. Nessun addebito è stato effettuato sul conto del destinatario.</p>

    <div class="amount-block">
        <div class="amount-label">Importo richiesto (non addebitato)</div>
        <div class="amount-value" style="color:#b64e62;">
            {{ ky_format($transfer->amount) }}<span>KY</span>
        </div>
    </div>

    <table class="info-table">
        <tr>
            <td>Riferimento</td>
            <td>{{ $transfer->reference }}</td>
        </tr>
        <tr>
            <td>Rifiutato da</td>
            <td>{{ $fromAccount->display_name }}</td>
        </tr>
        <tr>
            <td>Conto richiedente</td>
            <td>{{ $toAccount->display_name }}</td>
        </tr>
        @if($transfer->description)
        <tr>
            <td>Causale originale</td>
            <td>{{ $transfer->description }}</td>
        </tr>
        @endif
    </table>

    <div class="alert danger">
        La richiesta è stata rifiutata. Puoi inviare una nuova richiesta o contattare direttamente l'azienda per chiarire.
    </div>

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/incassa" class="cta-btn">Nuova richiesta →</a>
        <a href="{{ config('app.url') }}/aziende" class="cta-btn secondary">Directory aziende</a>
    </div>
@endsection
