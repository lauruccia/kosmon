@extends('emails.layout')

@section('emailTitle', 'Reimposta la tua password')

@section('content')
    <p class="greeting">Ciao, {{ $user->name }}!</p>

    <p>Abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account KMoney.</p>

    <div class="cta-wrap">
        <a href="{{ $url }}" class="cta-btn">Reimposta la password →</a>
    </div>

    <div class="alert warning">
        <strong>Il link scade tra {{ $expiresInMinutes }} minuti.</strong><br>
        Dopo questa scadenza dovrai richiedere un nuovo link dal portale.
    </div>

    <hr class="divider">

    <p style="font-size:13px;color:#94a3b8;">
        Se non hai richiesto il ripristino della password, non è necessaria alcuna azione.
        Il tuo account è al sicuro.
    </p>

    <p style="font-size:13px;color:#94a3b8;">
        Se il pulsante non funziona, copia e incolla questo link nel browser:<br>
        <a href="{{ $url }}" style="color:#64748b;word-break:break-all;">{{ $url }}</a>
    </p>
@endsection
