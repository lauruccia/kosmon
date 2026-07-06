@extends('layouts.portal')

@section('title', 'Il Mio Contratto di Adesione')

@section('content')
<div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="margin:0 0 4px;">&#x1F4DC; Contratto di Adesione Firmato</h1>
            <p class="subtitle" style="margin:0;">Il contratto cos&#xEC; come lo hai firmato.</p>
        </div>
        <a href="{{ route('portal.security') }}" class="btn btn-secondary btn-sm">&#x2190; Sicurezza account</a>
    </div>
</div>

{{-- Certificato di firma --}}
<div class="card" style="margin-bottom:24px;border-left:4px solid #16a34a;">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div style="font-size:2.5rem;">&#x2705;</div>
        <div style="flex:1;min-width:200px;">
            <div style="font-weight:700;font-size:1rem;color:#15803d;margin-bottom:4px;">Contratto firmato digitalmente</div>
            <div style="font-size:13px;color:#374151;display:flex;flex-wrap:wrap;gap:16px;">
                <span>&#x1F4C5; <strong>Data firma:</strong> {{ $signature->signed_at->format('d/m/Y \a\l\l\e H:i') }}</span>
                <span>&#x1F4CB; <strong>Versione:</strong> v{{ $signature->contract_version }}</span>
                @if($signature->ip_address)
                <span>&#x1F310; <strong>IP:</strong> {{ $signature->ip_address }}</span>
                @endif
                <span>&#x1F194; <strong>Codice firma:</strong> <code style="font-family:monospace;background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:12px;">{{ strtoupper(substr(md5($signature->id . $signature->signed_at), 0, 12)) }}</code></span>
            </div>
        </div>
        <a href="{{ route('portal.contract.download') }}"
           class="btn btn-secondary btn-sm"
           style="white-space:nowrap;">
            &#x1F4E5; Scarica PDF
        </a>
    </div>
</div>

{{-- Contratto --}}
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:1rem;">&#x1F4C4; Testo del contratto &mdash; versione {{ $signature->contract_version }}</h2>
        <button onclick="toggleExpand(this)" class="btn btn-secondary btn-sm">&#x29C2; Espandi</button>
    </div>
    <div class="card-body" style="padding:0;">
        <div id="contractBody" style="padding:28px 32px;max-height:600px;overflow-y:auto;font-size:14px;line-height:1.75;border-top:1px solid #e2e8f0;">
            {!! sanitize_html($signature->contract_html_snapshot) !!}
        </div>
    </div>
</div>

<div style="margin-top:12px;font-size:12px;color:#94a3b8;text-align:center;">
    Il testo sopra riporta il contratto esattamente come era al momento della firma. Eventuali aggiornamenti successivi non modificano questo documento.
</div>

<script>
function toggleExpand(btn) {
    const body = document.getElementById('contractBody');
    if (body.style.maxHeight === 'none') {
        body.style.maxHeight = '600px';
        btn.textContent = '⧂ Espandi';
    } else {
        body.style.maxHeight = 'none';
        btn.textContent = '⧁ Comprimi';
    }
}
</script>
@endsection
