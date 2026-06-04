@extends('emails.layout')

@section('emailTitle', 'Conferma il tuo indirizzo email')

@section('content')
    <p class="greeting">Ciao, {{ $user->name }}!</p>

    <p>Grazie per esserti registrato su KMoney. Per attivare il tuo account, conferma il tuo indirizzo email cliccando sul pulsante qui sotto.</p>

    <div class="cta-wrap">
        <a href="{{ $url }}" class="cta-btn">Conferma indirizzo email →</a>
    </div>

    <div class="alert warning">
        <strong>Il link scade tra {{ $expiresInMinutes }} minuti.</strong><br>
        Dopo questa scadenza potrai richiedere un nuovo link di conferma dal portale.
    </div>

    <hr class="divider">

    <p style="font-size:13px;color:#94a3b8;">
        Se non hai creato tu un account, non è necessaria alcuna azione.
    </p>

    <p style="font-size:13px;color:#94a3b8;">
        Se il pulsante non funziona, copia e incolla questo link nel browser:<br>
        <a href="{{ $url }}" style="color:#64748b;word-break:break-all;">{{ $url }}</a>
    </p>
@endsection
