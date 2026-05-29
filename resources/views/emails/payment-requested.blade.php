@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao, {{ $recipient->name }}!</p>

    <p>
        <strong>{{ $requesterName }}</strong> ti ha inviato una richiesta di pagamento tramite il circuito KMoney.
        Devi confermare o rifiutare questa richiesta dal tuo portale.
    </p>

    <div class="amount-block">
        <div class="amount-label">Importo richiesto</div>
        <div class="amount-value">
            {{ number_format($transfer->amount, 2, ',', '.') }}<span>KY</span>
        </div>
    </div>

    <table class="info-table">
        <tr>
            <td>Richiesta da</td>
            <td><strong>{{ $requesterName }}</strong></td>
        </tr>
        <tr>
            <td>Conto addebitato</td>
            <td>{{ $fromAccount->display_name }}</td>
        </tr>
        <tr>
            <td>Conto accreditato</td>
            <td>{{ $toAccount->display_name }}</td>
        </tr>
        <tr>
            <td>Riferimento richiesta</td>
            <td>{{ $transfer->reference }}</td>
        </tr>
        @if($transfer->description)
        <tr>
            <td>Causale</td>
            <td>{{ $transfer->description }}</td>
        </tr>
        @endif
        <tr>
            <td>Data richiesta</td>
            <td>{{ $transfer->created_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}</td>
        </tr>
    </table>

    <div class="alert warning">
        <strong>Azione richiesta:</strong> questa richiesta è in attesa di tua approvazione. Nessun addebito è stato ancora effettuato sul tuo conto.
    </div>

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/movimenti" class="cta-btn">Gestisci richiesta →</a>
    </div>

    <hr class="divider">
    <p style="font-size:13px;color:#94a3b8;">
        Se non riconosci questa richiesta o non vuoi procedere, puoi rifiutarla dalla sezione Movimenti del tuo portale. Il rifiuto non ha costi.
    </p>
@endsection
