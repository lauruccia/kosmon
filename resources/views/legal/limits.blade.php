@extends('layouts.legal')
@section('content')
<h1>Limiti Transazionali e Commissioni</h1>
<p class="subtitle">Valori standard del circuito — soggetti a modifica con preavviso</p>

<h2>Limiti operativi standard</h2>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
    <thead>
        <tr style="background:#f1f5f9;">
            <th style="text-align:left;padding:10px 14px;font-size:13px;">Parametro</th>
            <th style="text-align:right;padding:10px 14px;font-size:13px;">Valore</th>
            <th style="padding:10px 14px;font-size:13px;">Note</th>
        </tr>
    </thead>
    <tbody>
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 14px;">Limite per singolo movimento</td>
            <td style="text-align:right;padding:10px 14px;">Configurabile</td>
            <td style="padding:10px 14px;font-size:13px;color:#64748b;">Impostato dall'admin per ogni azienda</td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 14px;">Limite giornaliero uscite</td>
            <td style="text-align:right;padding:10px 14px;">Configurabile</td>
            <td style="padding:10px 14px;font-size:13px;color:#64748b;">Default: nessun limite</td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 14px;">Limite mensile uscite</td>
            <td style="text-align:right;padding:10px 14px;">Configurabile</td>
            <td style="padding:10px 14px;font-size:13px;color:#64748b;">Default: nessun limite</td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:10px 14px;">Saldo massimo (tetto)</td>
            <td style="text-align:right;padding:10px 14px;">Configurabile</td>
            <td style="padding:10px 14px;font-size:13px;color:#64748b;">Blocca vendite se raggiunto</td>
        </tr>
        <tr>
            <td style="padding:10px 14px;">Scadenza QR dinamico</td>
            <td style="text-align:right;padding:10px 14px;">10 minuti</td>
            <td style="padding:10px 14px;font-size:13px;color:#64748b;">Dopo la scadenza il QR va rigenerato</td>
        </tr>
    </tbody>
</table>

<h2>Commissioni</h2>
<p>Le commissioni di transazione sono configurate dall'amministratore del circuito e visibili nel pannello di amministrazione. In assenza di commissioni configurate, non viene applicata alcuna fee. Le commissioni in vigore sono sempre consultabili nel portale alla sezione "Conto".</p>

<h2>Cashback</h2>
<p>Il Gestore può attivare programmi di cashback in KY per specifiche categorie di transazioni. Le regole cashback attive sono visibili nel portale. Il cashback viene accreditato automaticamente al momento del pagamento.</p>

<h2>Valuta KYCard</h2>
<p>Le KYCard permettono di acquistare KY con pagamento in EUR. I tassi di conversione (EUR → KY) e i bonus applicabili sono visibili nella pagina di ricarica del portale.</p>
@endsection
