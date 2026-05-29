@extends('layouts.legal')
@section('content')
<h1>Contratto di Adesione al Circuito KMoney</h1>
<p class="subtitle">Versione 1.0 — in vigore dal 1 gennaio 2026</p>

<h2>1. Oggetto</h2>
<p>Il presente contratto disciplina l'accesso e l'utilizzo del circuito di compensazione privato KMoney, gestito dall'operatore del circuito (di seguito "Gestore"). Il circuito utilizza la valuta interna denominata KY (KiloYield), non convertibile in valuta legale se non tramite le modalità esplicitamente previste.</p>

<h2>2. Requisiti di adesione</h2>
<p>Possono aderire al circuito le persone giuridiche (società di capitali, cooperative, ditte individuali con P.IVA) regolarmente costituite e operative. Il completamento del processo KYC (Know Your Customer) è obbligatorio prima dell'attivazione del conto.</p>

<h2>3. Conto KY e saldo</h2>
<p>Ogni azienda aderente riceve un conto denominato in KY. Il saldo KY non è un deposito bancario né uno strumento di pagamento ai sensi del D.Lgs. 27 gennaio 2010, n. 11. Il Gestore non è una banca né un istituto di pagamento autorizzato. Il circuito opera come sistema di clearing privato tra aziende.</p>

<h2>4. Utilizzo del conto</h2>
<p>Il conto KY può essere utilizzato esclusivamente per transazioni commerciali tra aziende aderenti al circuito. È vietato l'utilizzo per finalità speculative, riciclaggio o finanziamento di attività illecite.</p>

<h2>5. Fido (massimale negativo)</h2>
<p>Il Gestore può concedere, a propria discrezione, un fido (saldo KY negativo fino a un massimale concordato). Il fido è revocabile in qualsiasi momento e non costituisce un finanziamento creditizio.</p>

<h2>6. Commissioni</h2>
<p>Le commissioni applicabili sono quelle in vigore al momento della transazione, consultabili nella pagina <a href="{{ route('legal.limits') }}">Limiti Transazionali</a> e nel pannello amministrativo del portale. Il Gestore si riserva il diritto di modificarle con 30 giorni di preavviso.</p>

<h2>7. Recesso e chiusura conto</h2>
<p>L'aderente può recedere dal circuito in qualsiasi momento con comunicazione scritta al Gestore. In caso di saldo KY positivo al momento del recesso, le modalità di liquidazione saranno concordate con il Gestore. Saldi negativi devono essere ripianati prima della chiusura.</p>

<h2>8. Responsabilità</h2>
<p>Il Gestore non è responsabile per danni indiretti derivanti da malfunzionamenti tecnici, salvo dolo o colpa grave. La responsabilità massima del Gestore è limitata al saldo KY del conto dell'aderente al momento dell'evento dannoso.</p>

<h2>9. Foro competente</h2>
<p>Per qualsiasi controversia derivante dal presente contratto è competente il Tribunale del luogo della sede del Gestore, salvo diverso accordo scritto.</p>

<div style="margin-top:32px;padding:16px;background:#f8fafc;border-radius:8px;font-size:13px;color:#64748b;">
    Per domande o chiarimenti: <a href="{{ route('help.index') }}">Centro Assistenza</a> — <a href="{{ route('legal.complaints') }}">Procedura Reclami</a>
</div>
@endsection
