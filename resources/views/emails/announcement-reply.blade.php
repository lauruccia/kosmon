@extends('emails.layout')

@section('content')
    <p class="greeting">Ciao, {{ $recipient->name }}!</p>

    <p>Un'azienda del circuito ha risposto al tuo annuncio <strong>«{{ $announcement->title }}»</strong>.</p>

    <table class="info-table">
        <tr>
            <td>Azienda</td>
            <td>{{ $reply->company->name ?? '—' }}</td>
        </tr>
        <tr>
            <td>Utente</td>
            <td>{{ $reply->user->name ?? '—' }}</td>
        </tr>
        <tr>
            <td>Data</td>
            <td>{{ $reply->created_at->locale('it')->isoFormat('D MMMM YYYY, HH:mm') }}</td>
        </tr>
    </table>

    <div class="alert info" style="background:#eff6ff;border-left:4px solid #0c4a86;border-radius:8px;padding:16px 18px;margin:20px 0;font-size:15px;line-height:1.6;color:#1e3a5f;white-space:pre-line;">{{ $reply->message }}</div>

    <p style="font-size:14px;color:#64748b;">Puoi vedere tutte le risposte ricevute direttamente nella pagina dell'annuncio.</p>

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/annunci/{{ $announcement->id }}" class="cta-btn">Vedi l'annuncio →</a>
    </div>
@endsection
