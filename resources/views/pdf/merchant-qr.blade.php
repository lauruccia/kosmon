<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: DejaVu Sans, sans-serif;
    background:#fff; color:#1a1a2e;
    display:flex; flex-direction:column; align-items:center;
    padding:32px 28px;
    min-height:100vh;
}
.brand {
    font-size:28px; font-weight:800; color:#3d5566;
    letter-spacing:-0.5px; margin-bottom:4px; text-align:center;
}
.brand-sub { font-size:12px; color:#7a9098; text-align:center; margin-bottom:28px; }

.qr-container {
    padding:20px; background:#fff;
    border:2px solid #e2e8f0; border-radius:16px;
    margin-bottom:20px;
    display:inline-block;
}

.company-name {
    font-size:22px; font-weight:800; color:#1a1a2e;
    text-align:center; margin-bottom:6px;
}
.ky-number {
    font-size:14px; font-family:monospace; color:#6b7280;
    text-align:center; margin-bottom:24px;
}

.instruction {
    font-size:13px; color:#374151; text-align:center;
    line-height:1.6; margin-bottom:20px;
    max-width:320px;
}

.steps {
    font-size:12px; color:#6b7280; text-align:left;
    margin-bottom:28px; padding:14px 18px;
    background:#f8fafc; border-radius:10px; width:100%; max-width:320px;
}
.steps div { margin-bottom:4px; }

.footer {
    font-size:10px; color:#9ca3af; text-align:center;
    border-top:1px solid #e5e7eb; padding-top:14px; width:100%; max-width:360px;
}
</style>
</head>
<body>

<div class="brand">KMoney</div>
<div class="brand-sub">Circuito monetario locale</div>

<div class="company-name">{{ $companyName }}</div>
<div class="ky-number">{{ $kyNumber }}</div>

<div class="qr-container">
    {!! $qrSvg !!}
</div>

<div class="instruction">
    Scansiona con il tuo smartphone per inviare KMoney a questo esercente.
</div>

<div class="steps">
    <div>1. Apri la camera o l'app KMoney</div>
    <div>2. Inquadra il QR code</div>
    <div>3. Inserisci l'importo e conferma</div>
</div>

<div class="footer">
    {{ $qrPayUrl }}<br>
    Genera con KMoney — circuito locale di credito reciproco
</div>

</body>
</html>
