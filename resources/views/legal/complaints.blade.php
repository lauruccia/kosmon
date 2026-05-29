@extends('layouts.legal')
@section('content')
<h1>Procedura di Reclamo</h1>
<p class="subtitle">Come gestiremo il tuo reclamo — Versione 1.0</p>

<h2>1. Come presentare un reclamo</h2>
<p>I reclami possono essere inviati tramite:</p>
<ul>
    <li><strong>Form online:</strong> <a href="{{ route('help.index') }}">Centro Assistenza → Contattaci</a></li>
    <li><strong>Email:</strong> @php $b = \App\Models\SystemSetting::branding(); @endphp {{ $b->contact_email ?? 'supporto@kmoney.it' }}</li>
</ul>
<p>Il reclamo deve contenere: nome e ragione sociale, numero conto KY, descrizione dettagliata del problema, data/ora dell'evento, eventuale transazione di riferimento (UUID o riferimento).</p>

<h2>2. Tempi di risposta</h2>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
    <thead>
        <tr style="background:#f1f5f9;">
            <th style="text-align:left;padding:10px 14px;font-size:13px;">Tipo di reclamo</th>
            <th style="text-align:right;padding:10px 14px;font-size:13px;">Tempo massimo di risposta</th>
        </tr>
    </thead>
    <tbody>
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 14px;">Richiesta informazioni</td>
            <td style="text-align:right;padding:10px 14px;">2 giorni lavorativi</td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 14px;">Reclamo su transazione</td>
            <td style="text-align:right;padding:10px 14px;">5 giorni lavorativi</td>
        </tr>
        <tr>
            <td style="padding:10px 14px;">Reclamo complesso</td>
            <td style="text-align:right;padding:10px 14px;">15 giorni lavorativi</td>
        </tr>
    </tbody>
</table>

<h2>3. Processo di gestione</h2>
<ol>
    <li><strong>Ricezione:</strong> Il reclamo viene registrato e ricevi conferma entro 24h.</li>
    <li><strong>Istruttoria:</strong> Il team analizza il reclamo raccogliendo dati e documentazione interna.</li>
    <li><strong>Risposta:</strong> Ricevi una risposta motivata entro i termini indicati sopra.</li>
    <li><strong>Rimedio:</strong> Se il reclamo è fondato, il Gestore propone un rimedio adeguato (storno, correzione saldo, ecc.).</li>
</ol>

<h2>4. Escalation</h2>
<p>Se non sei soddisfatto della risposta, puoi richiedere un secondo esame alla direzione del Gestore entro 10 giorni dalla risposta. In caso di mancata risoluzione, le parti faranno ricorso alle vie ordinarie di giustizia come previsto dal Contratto di Adesione.</p>
@endsection
