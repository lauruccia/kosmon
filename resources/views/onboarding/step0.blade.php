@extends('onboarding.layout')

@section('content')

{{-- Stepper --}}
<div class="ob-stepper">
    <div class="ob-step">
        <div class="ob-step-dot active">&#10003;</div>
        <div class="ob-step-label active">Benvenuto</div>
    </div>
    <div class="ob-step-connector"></div>
    <div class="ob-step">
        <div class="ob-step-dot pending">1</div>
        <div class="ob-step-label">Profilo</div>
    </div>
    <div class="ob-step-connector"></div>
    <div class="ob-step">
        <div class="ob-step-dot pending">2</div>
        <div class="ob-step-label">Documenti</div>
    </div>
    <div class="ob-step-connector"></div>
    <div class="ob-step">
        <div class="ob-step-dot pending">3</div>
        <div class="ob-step-label">Verifica</div>
    </div>
</div>

<div class="ob-card" style="text-align:center;">

    {{-- Hero icon --}}
    <div style="width:80px;height:80px;background:var(--primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2.2rem;">
        &#127881;
    </div>

    <h1 style="font-size:1.6rem;font-weight:800;color:var(--ink);margin:0 0 .5rem;">
        Benvenuto in KMoney, {{ $company->name }}!
    </h1>
    <p style="color:var(--ink-soft);max-width:460px;margin:0 auto 2rem;line-height:1.7;">
        Stai per entrare nel circuito <strong>B2B KY</strong> — la rete di scambio commerciale
        riservata alle imprese. In pochi minuti configurerai il tuo profilo e potrai
        iniziare a transare con le altre aziende del circuito.
    </p>

    {{-- Feature pillars --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:2rem;text-align:left;">
        <div style="background:var(--primary-light);border-radius:12px;padding:1rem;">
            <div style="font-size:1.5rem;margin-bottom:.4rem;">&#128176;</div>
            <div style="font-weight:700;font-size:.9rem;color:var(--ink);">Valuta KY</div>
            <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.2rem;">Paga e incassa in KY all'interno del circuito</div>
        </div>
        <div style="background:#f0fdf4;border-radius:12px;padding:1rem;">
            <div style="font-size:1.5rem;margin-bottom:.4rem;">&#128203;</div>
            <div style="font-weight:700;font-size:.9rem;color:var(--ink);">Pagamenti flessibili</div>
            <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.2rem;">Rate, QR, NFC, programmati e API</div>
        </div>
        <div style="background:#fdf4ff;border-radius:12px;padding:1rem;">
            <div style="font-size:1.5rem;margin-bottom:.4rem;">&#128272;</div>
            <div style="font-weight:700;font-size:.9rem;color:var(--ink);">Sicuro e verificato</div>
            <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.2rem;">KYC obbligatorio, 2FA, audit trail completo</div>
        </div>
        <div style="background:#fff7ed;border-radius:12px;padding:1rem;">
            <div style="font-size:1.5rem;margin-bottom:.4rem;">&#128101;</div>
            <div style="font-weight:700;font-size:.9rem;color:var(--ink);">Shop & Annunci</div>
            <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.2rem;">Pubblica prodotti e servizi per le altre aziende</div>
        </div>
    </div>

    {{-- Steps overview --}}
    <div style="background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:1.25rem;margin-bottom:2rem;text-align:left;">
        <p style="font-weight:700;font-size:.9rem;color:var(--ink);margin:0 0 .75rem;">Cosa ti chiediamo in questa procedura:</p>
        <div style="display:flex;flex-direction:column;gap:.5rem;">
            <div style="display:flex;align-items:center;gap:.75rem;font-size:.875rem;">
                <span style="width:22px;height:22px;background:var(--primary);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;flex-shrink:0;">1</span>
                <span><strong>Profilo azienda</strong> — settore, descrizione attività, contatti (3 minuti)</span>
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;font-size:.875rem;">
                <span style="width:22px;height:22px;background:var(--primary);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;flex-shrink:0;">2</span>
                <span><strong>Documenti KYC</strong> — visura camerale o documento identificativo (upload PDF/immagine)</span>
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;font-size:.875rem;">
                <span style="width:22px;height:22px;background:var(--primary);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;flex-shrink:0;">3</span>
                <span><strong>Verifica</strong> — il team KMoney revisionerà i documenti entro 1 giorno lavorativo</span>
            </div>
        </div>
    </div>

    <a href="{{ route('onboarding.step1') }}" class="ob-btn ob-btn-primary" style="font-size:1rem;padding:.9rem 2.5rem;">
        Inizia la configurazione &rarr;
    </a>

    <p style="margin-top:1.25rem;font-size:.8rem;color:var(--ink-muted);">
        Hai già un account? <a href="{{ route('portal.dashboard') }}" style="color:var(--primary);">Vai al portale</a>
    </p>
</div>

@endsection
