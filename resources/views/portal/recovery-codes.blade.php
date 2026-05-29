@extends('layouts.portal')

@section('content')

<div style="max-width:560px; margin:0 auto; padding:8px 0 40px;">

    {{-- Header --}}
    <div style="margin-bottom:28px;">
        <h1 style="font-size:22px; font-weight:700; color:var(--ink); margin:0 0 6px;">Codici di recupero</h1>
        <p style="font-size:14px; color:var(--ink-soft); margin:0;">Salva questi codici in un posto sicuro. Potrai usarli per accedere se perdi l'accesso alla tua app di autenticazione.</p>
    </div>

    {{-- Warning banner --}}
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:24px;display:flex;gap:12px;align-items:flex-start;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;">
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <div style="font-size:13px;color:#78350f;line-height:1.5;">
            <strong>Questi codici vengono mostrati una sola volta.</strong>
            Annotali, salvali in un gestore di password o stampali. Non potrai rivederli.
            Ogni codice e monouso: viene eliminato dopo l'utilizzo.
        </div>
    </div>

    {{-- Codes grid --}}
    <section class="card card-pad" style="margin-bottom:24px;">
        <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:14px;display:flex;align-items:center;gap:8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            I tuoi 8 codici di recupero
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;">
            @foreach($codes as $code)
                <div style="font-family:monospace;font-size:15px;font-weight:700;letter-spacing:2px;color:var(--ink);background:var(--surface-soft);border:1px solid var(--line);border-radius:8px;padding:10px 14px;text-align:center;">
                    {{ $code }}
                </div>
            @endforeach
        </div>

        {{-- Copy all button --}}
        <button
            onclick="copyAllCodes()"
            id="copy-btn"
            style="width:100%;padding:10px;background:var(--surface-soft);border:1px solid var(--line);border-radius:8px;font-size:14px;font-weight:600;color:var(--ink);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;"
        >
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
            </svg>
            <span id="copy-label">Copia tutti i codici</span>
        </button>
    </section>

    {{-- CTA --}}
    <div style="text-align:center;">
        <a
            href="{{ route('portal.security') }}"
            style="display:inline-block;padding:12px 28px;background:var(--primary);color:#fff;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;"
        >
            Ho salvato i codici &rarr;
        </a>
        <div style="margin-top:12px;font-size:12px;color:var(--ink-muted);">
            Puoi rigenerare i codici in qualsiasi momento dalla pagina Sicurezza.
        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
function copyAllCodes() {
    const codes = @json($codes);
    const text = codes.join('\n');
    navigator.clipboard.writeText(text).then(() => {
        const label = document.getElementById('copy-label');
        label.textContent = 'Copiati!';
        setTimeout(() => { label.textContent = 'Copia tutti i codici'; }, 2000);
    }).catch(() => {
        // Fallback: prompt
        window.prompt('Copia questi codici:', text);
    });
}
</script>
@endpush
