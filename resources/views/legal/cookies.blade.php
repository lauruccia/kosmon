@extends('legal.layout')

@section('title', 'Cookie Policy')

@section('content')
<h1>Cookie Policy</h1>
<p class="legal-meta">Ultimo aggiornamento: 27 maggio 2026</p>

<h2>Cookie utilizzati</h2>
<p>KMoney utilizza esclusivamente cookie tecnici strettamente necessari al funzionamento della piattaforma:</p>
<ul>
    <li><strong>kmoney_session</strong> — Cookie di sessione, durata: sessione browser. Necessario per mantenere l'autenticazione.</li>
    <li><strong>XSRF-TOKEN</strong> — Cookie di sicurezza anti-CSRF, durata: sessione. Necessario per la protezione dei form.</li>
    <li><strong>km-theme</strong> — Preferenza tema chiaro/scuro, durata: persistente (localStorage). Non trasmesso al server.</li>
    <li><strong>km-cookie-consent</strong> — Registra l'avvenuta presa visione dell'informativa cookie (localStorage). Non trasmesso al server.</li>
</ul>

<h2>Cookie di terze parti</h2>
<p>La piattaforma KMoney non utilizza cookie di terze parti, cookie di tracciamento, cookie pubblicitari o sistemi di analisi comportamentale.</p>

<h2>Come disabilitare i cookie</h2>
<p>I cookie tecnici sono necessari per il funzionamento della piattaforma: disabilitarli impedirà l'accesso al servizio. Puoi gestire i cookie tecnici dalle impostazioni del tuo browser.</p>
@endsection
