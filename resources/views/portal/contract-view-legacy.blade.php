@extends('layouts.portal')

@section('title', 'Il Mio Contratto di Adesione')

@section('content')
<div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="margin:0 0 4px;">&#x1F4DC; Contratto di Adesione Firmato</h1>
            <p class="subtitle" style="margin:0;">Il contratto cos&#xEC; come firmato.</p>
        </div>
        <a href="{{ route('portal.security') }}" class="btn btn-secondary btn-sm">&#x2190; Sicurezza account</a>
    </div>
</div>

<div class="card" style="margin-bottom:24px;border-left:4px solid #16a34a;">
    <div class="card-body">
        <div style="font-weight:700;font-size:1rem;color:#15803d;margin-bottom:4px;">&#x2705; Contratto firmato digitalmente</div>
        <div style="font-size:13px;color:#374151;display:flex;flex-wrap:wrap;gap:16px;">
            <span>&#x1F4C5; <strong>Data firma:</strong> {{ $signedAt->format('d/m/Y \a\l\l\e H:i') }}</span>
            <span>&#x1F4CB; <strong>Versione:</strong> v{{ $contractVer }}</span>
        </div>
        <div style="margin-top:8px;font-size:12px;color:#94a3b8;">
            Nota: il contratto visualizzato riflette il testo attuale del contratto di adesione, poich&#233; la firma &#232; avvenuta prima dell&#39;introduzione dell&#39;archivio storico.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:1rem;">&#x1F4C4; Testo del contratto &mdash; versione {{ $contractVer }}</h2>
        <button onclick="toggleExpand(this)" class="btn btn-secondary btn-sm">&#x29C2; Espandi</button>
    </div>
    <div class="card-body" style="padding:0;">
        <div id="contractBody" style="padding:28px 32px;max-height:600px;overflow-y:auto;font-size:14px;line-height:1.75;border-top:1px solid #e2e8f0;">
            {!! $contractHtml !!}
        </div>
    </div>
</div>

<script>
function toggleExpand(btn) {
    const body = document.getElementById('contractBody');
    if (body.style.maxHeight === 'none') {
        body.style.maxHeight = '600px';
        btn.textContent = '&#x29C2; Espandi';
    } else {
        body.style.maxHeight = 'none';
        btn.textContent = '&#x29C1; Comprimi';
    }
}
</script>
@endsection
