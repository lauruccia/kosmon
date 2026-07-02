@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao{{ $invitation->name ? ' ' . $invitation->name : '' }}!</p>

    <p><strong>{{ $agent->name }}</strong> ti ha invitato a entrare nel circuito KMoney, la piattaforma di pagamenti in valuta complementare KY.</p>

    <p>Registrandoti con il link qui sotto sarai collegato direttamente a chi ti ha invitato.</p>

    <div style="text-align:center;margin:28px 0;">
        <a href="{{ $registerUrl }}" style="display:inline-block;padding:14px 32px;background:#0c4a86;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;">
            Registrati su KMoney
        </a>
    </div>

    <p style="font-size:12px;color:#64748b;">
        Se il pulsante non funziona, copia e incolla questo link nel browser:<br>
        <a href="{{ $registerUrl }}">{{ $registerUrl }}</a>
    </p>

    <p style="font-size:12px;color:#64748b;">Se non conosci {{ $agent->name }} o pensi di aver ricevuto questa email per errore, puoi semplicemente ignorarla.</p>
@endsection
