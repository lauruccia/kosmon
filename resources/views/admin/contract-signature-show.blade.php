@extends('layouts.portal')

@section('title', 'Contratto Firmato — ' . ($signature->company?->name ?? $signature->user?->name))

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="margin:0 0 4px;">&#x1F4DC; Contratto Firmato</h1>
        <p class="subtitle" style="margin:0;">{{ $signature->company?->name ?? $signature->user?->name }}</p>
    </div>
    <a href="{{ route('admin.contract-signatures') }}" class="btn btn-secondary btn-sm">&#x2190; Log firme</a>
</div>

{{-- Log di firma --}}
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2 style="margin:0;font-size:1rem;">&#x1F512; Dati di firma certificati</h2>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px 24px;">
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">Azienda</div>
                <div style="font-weight:600;">{{ $signature->company?->name ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">Utente firmatario</div>
                <div style="font-weight:600;">{{ $signature->user?->name }}</div>
                <div style="font-size:12px;color:#64748b;">{{ $signature->user?->email }}</div>
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">Data e ora firma</div>
                <div style="font-weight:600;">{{ $signature->signed_at->format('d/m/Y') }}</div>
                <div style="font-size:12px;color:#64748b;">{{ $signature->signed_at->format('H:i:s') }} UTC</div>
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">Versione contratto</div>
                <div><span style="background:#ede9fe;color:#6d28d9;padding:3px 10px;border-radius:12px;font-size:13px;font-weight:700;">v{{ $signature->contract_version }}</span></div>
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">Indirizzo IP</div>
                <div style="font-family:monospace;font-size:13px;">{{ $signature->ip_address ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">Codice firma</div>
                <div style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:3px 8px;border-radius:6px;display:inline-block;">{{ strtoupper(substr(md5($signature->id . $signature->signed_at), 0, 12)) }}</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">User Agent</div>
                <div style="font-size:12px;color:#374151;word-break:break-all;">{{ $signature->user_agent ?? '—' }}</div>
            </div>
            @if($signature->company?->vat_number)
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px;">P.IVA</div>
                <div style="font-family:monospace;">{{ $signature->company->vat_number }}</div>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Snapshot contratto --}}
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:1rem;">&#x1F4C4; Contratto come firmato &mdash; versione {{ $signature->contract_version }}</h2>
        <div style="display:flex;gap:8px;">
            <button onclick="toggleExpand(this)" class="btn btn-secondary btn-sm">&#x29C2; Espandi</button>
            <a href="{{ route('admin.contract-signatures.export-single', $signature) }}" class="btn btn-secondary btn-sm">&#x1F4E5; PDF</a>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 20px;font-size:12px;color:#92400e;">
            &#x26A0;&#xFE0F; Questo documento mostra il testo esatto del contratto al momento della firma. Non modificabile.
        </div>
        <div id="contractBody"
             style="padding:32px 40px;max-height:700px;overflow-y:auto;font-size:14px;line-height:1.8;font-family:Georgia,serif;">
            {!! sanitize_html($signature->contract_html_snapshot) !!}
        </div>
    </div>
</div>

<style>
#contractBody h2 { font-size:.95rem;font-weight:700;margin:20px 0 8px;color:#0f766e; }
#contractBody p  { margin:0 0 12px; }
#contractBody hr { border:none;border-top:1px solid #e2e8f0;margin:20px 0; }
#contractBody ul,#contractBody ol { padding-left:20px; }
#contractBody li { margin-bottom:6px; }
#contractBody strong { font-weight:700; }
</style>

<script>
function toggleExpand(btn) {
    const body = document.getElementById('contractBody');
    if (body.style.maxHeight === 'none') {
        body.style.maxHeight = '700px';
        btn.textContent = '⧂ Espandi';
    } else {
        body.style.maxHeight = 'none';
        btn.textContent = '⧁ Comprimi';
    }
}
</script>
@endsection
