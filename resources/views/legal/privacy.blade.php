@extends('legal.layout')

@section('title', 'Privacy Policy')

@section('content')
<h1>Informativa sulla Privacy</h1>
<p class="legal-meta">Ultimo aggiornamento: 27 maggio 2026 &mdash; Versione 1.0</p>

<h2>1. Titolare del trattamento</h2>
<p>Il titolare del trattamento dei dati personali raccolti tramite la piattaforma KMoney è la società che gestisce il circuito business KY, raggiungibile all'indirizzo email indicato nel contratto di adesione al circuito.</p>

<h2>2. Dati raccolti</h2>
<p>In fase di registrazione e utilizzo della piattaforma raccogliamo:</p>
<ul>
    <li>Dati anagrafici e di contatto (nome, email, numero di telefono)</li>
    <li>Dati aziendali (ragione sociale, partita IVA, codice fiscale)</li>
    <li>Documenti KYC (carte d'identità, visure camerali, atti costitutivi)</li>
    <li>Dati di utilizzo (movimenti, transazioni, saldi nel circuito KY)</li>
    <li>Dati tecnici (indirizzo IP, log di accesso, user agent)</li>
</ul>

<h2>3. Finalità e base giuridica</h2>
<p>I dati vengono trattati per: esecuzione del contratto di partecipazione al circuito, adempimento di obblighi legali (antiriciclaggio, KYC), prevenzione delle frodi, miglioramento del servizio. La base giuridica è l'esecuzione contrattuale (art. 6.1.b GDPR) e l'adempimento di obblighi legali (art. 6.1.c GDPR).</p>

<h2>4. Conservazione dei dati</h2>
<p>I dati contabili e di transazione sono conservati per 10 anni ai sensi della normativa fiscale e antiriciclaggio. I dati di accesso sono conservati per 12 mesi. I documenti KYC sono conservati per la durata del rapporto più 5 anni.</p>

<h2>5. Destinatari</h2>
<p>I dati non vengono venduti a terzi. Possono essere comunicati a: fornitori di servizi tecnici (hosting, email transazionale), autorità competenti su richiesta di legge, broker autorizzati dal circuito nei limiti strettamente necessari.</p>

<h2>6. Diritti dell'interessato</h2>
<p>Hai diritto di accesso, rettifica, cancellazione (nei limiti legali), portabilità, opposizione e limitazione del trattamento. Per esercitare i tuoi diritti scrivi all'indirizzo email del titolare.</p>

<h2>7. Cookie</h2>
<p>La piattaforma utilizza esclusivamente cookie tecnici necessari al funzionamento (sessione, preferenze tema). Non utilizziamo cookie di profilazione o di terze parti a fini pubblicitari. Maggiori informazioni nella nostra <a href="{{ route('legal.cookies') }}">Informativa sui Cookie</a>.</p>

<h2>8. Contatti</h2>
<p>Per qualsiasi domanda relativa al trattamento dei tuoi dati personali contatta il titolare tramite i canali indicati nel contratto di adesione al circuito KMoney.</p>
@endsection
