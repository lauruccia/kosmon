@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao, {{ $recipient->name }}!</p>

    @if($newStatus === 'under_review')
    <p>Abbiamo ricevuto i documenti caricati per <strong>{{ $company->name }}</strong>. Il nostro team di verifica li esaminerà entro 2-3 giorni lavorativi e riceverai una notifica non appena la revisione sarà completata.</p>

    @elseif($newStatus === 'approved')
    <p>Ottima notizia! L'azienda <strong>{{ $company->name }}</strong> ha superato con successo la verifica KYC. Il tuo profilo aziendale è ora completamente operativo nel circuito KMoney.</p>

    <div class="alert success">
        Il tuo conto è attivo e puoi iniziare a operare nel circuito: acquistare e vendere prodotti nello shop, pubblicare annunci e partecipare alle transazioni del network.
    </div>

    @elseif($newStatus === 'rejected')
    <p>Siamo spiacenti, la verifica KYC per <strong>{{ $company->name }}</strong> non è stata completata. Puoi caricare nuovamente i documenti corretti accedendo alla sezione Verifica aziendale del portale.</p>

    @if($adminNotes)
    <div class="alert" style="background:#fef2f2;border-left:4px solid #dc2626;border-radius:8px;padding:16px 18px;margin:20px 0;font-size:14px;color:#991b1b;">
        <strong>Note del team di verifica:</strong><br>{{ $adminNotes }}
    </div>
    @endif
    @endif

    <table class="info-table">
        <tr>
            <td>Azienda</td>
            <td>{{ $company->name }}</td>
        </tr>
        <tr>
            <td>Stato KYC</td>
            <td><strong>{{ $statusLabel }}</strong></td>
        </tr>
        <tr>
            <td>Data aggiornamento</td>
            <td>{{ now()->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}</td>
        </tr>
    </table>

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/kyc" class="cta-btn">Vai alla verifica aziendale →</a>
    </div>
@endsection
