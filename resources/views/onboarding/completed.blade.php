@extends('onboarding.layout')

@section('content')

{{-- Stepper completato --}}
<div class="ob-stepper">
    <div class="ob-step">
        <div class="ob-step-dot done">&#10003;</div>
        <div class="ob-step-label">Benvenuto</div>
    </div>
    <div class="ob-step-connector done"></div>
    <div class="ob-step">
        <div class="ob-step-dot done">&#10003;</div>
        <div class="ob-step-label">Profilo</div>
    </div>
    <div class="ob-step-connector done"></div>
    <div class="ob-step">
        <div class="ob-step-dot done">&#10003;</div>
        <div class="ob-step-label">Documenti</div>
    </div>
    <div class="ob-step-connector done"></div>
    <div class="ob-step">
        <div class="ob-step-dot done">&#10003;</div>
        <div class="ob-step-label active">Verificato!</div>
    </div>
</div>

<div class="ob-card">

    {{-- Hero --}}
    <div style="text-align:center;margin-bottom:2rem;">
        <div style="font-size:3.5rem;margin-bottom:.75rem;">&#127881;</div>
        <h1 style="font-size:1.7rem;font-weight:800;color:var(--ink);margin:0 0 .5rem;">
            {{ $company->name }} &egrave; verificata!
        </h1>
        <p style="color:var(--ink-soft);max-width:440px;margin:0 auto;line-height:1.7;">
            La tua azienda &egrave; ora attiva nel circuito KMoney. Il tuo conto KY &egrave; pronto.
            Ecco da dove puoi iniziare:
        </p>
    </div>

    {{-- Quick-start checklist --}}
    <div style="margin-bottom:2rem;">
        <p style="font-weight:700;font-size:.9rem;color:var(--ink);margin:0 0 1rem;">&#9989; Guida rapida — prime azioni consigliate</p>

        <div style="display:flex;flex-direction:column;gap:.75rem;">

            <a href="{{ route('portal.dashboard') }}" style="display:flex;align-items:center;gap:1rem;background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:1rem 1.25rem;text-decoration:none;color:var(--ink);transition:background .15s;" onmouseover="this.style.background='var(--primary-light)'" onmouseout="this.style.background='#f8fafc'">
                <span style="font-size:1.5rem;flex-shrink:0;">&#127968;</span>
                <div>
                    <div style="font-weight:700;font-size:.9rem;">Vai alla dashboard</div>
                    <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.1rem;">Visualizza saldo, movimenti e KPI del tuo conto KY</div>
                </div>
                <span style="margin-left:auto;color:var(--ink-muted);font-size:1.2rem;">&rarr;</span>
            </a>

            <a href="{{ route('portal.shop') }}" style="display:flex;align-items:center;gap:1rem;background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:1rem 1.25rem;text-decoration:none;color:var(--ink);transition:background .15s;" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='#f8fafc'">
                <span style="font-size:1.5rem;flex-shrink:0;">&#127978;</span>
                <div>
                    <div style="font-weight:700;font-size:.9rem;">Pubblica un prodotto o servizio</div>
                    <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.1rem;">Aggiungi la tua offerta allo shop del circuito</div>
                </div>
                <span style="margin-left:auto;color:var(--ink-muted);font-size:1.2rem;">&rarr;</span>
            </a>

            <a href="{{ route('portal.companies') }}" style="display:flex;align-items:center;gap:1rem;background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:1rem 1.25rem;text-decoration:none;color:var(--ink);transition:background .15s;" onmouseover="this.style.background='#fdf4ff'" onmouseout="this.style.background='#f8fafc'">
                <span style="font-size:1.5rem;flex-shrink:0;">&#128101;</span>
                <div>
                    <div style="font-weight:700;font-size:.9rem;">Scopri le aziende del circuito</div>
                    <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.1rem;">Trova nuovi partner e fornitori tra i membri KMoney</div>
                </div>
                <span style="margin-left:auto;color:var(--ink-muted);font-size:1.2rem;">&rarr;</span>
            </a>

            <a href="{{ route('portal.incasso-qr.form') }}" style="display:flex;align-items:center;gap:1rem;background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:1rem 1.25rem;text-decoration:none;color:var(--ink);transition:background .15s;" onmouseover="this.style.background='#fff7ed'" onmouseout="this.style.background='#f8fafc'">
                <span style="font-size:1.5rem;flex-shrink:0;">&#128248;</span>
                <div>
                    <div style="font-weight:700;font-size:.9rem;">Incassa con QR code</div>
                    <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.1rem;">Genera un QR di pagamento in secondi</div>
                </div>
                <span style="margin-left:auto;color:var(--ink-muted);font-size:1.2rem;">&rarr;</span>
            </a>

            <a href="{{ route('portal.requests') }}" style="display:flex;align-items:center;gap:1rem;background:#f8fafc;border:1px solid var(--line);border-radius:12px;padding:1rem 1.25rem;text-decoration:none;color:var(--ink);transition:background .15s;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='#f8fafc'">
                <span style="font-size:1.5rem;flex-shrink:0;">&#128196;</span>
                <div>
                    <div style="font-weight:700;font-size:.9rem;">Esegui il tuo primo pagamento</div>
                    <div style="font-size:.8rem;color:var(--ink-soft);margin-top:.1rem;">Trasferisci KY a un'altra azienda del circuito</div>
                </div>
                <span style="margin-left:auto;color:var(--ink-muted);font-size:1.2rem;">&rarr;</span>
            </a>

        </div>
    </div>

    {{-- Tips box --}}
    <div style="background:var(--primary-light);border-radius:12px;padding:1.1rem 1.25rem;margin-bottom:1.75rem;">
        <p style="font-weight:700;font-size:.875rem;color:var(--ink);margin:0 0 .5rem;">&#128161; Sapevi che...</p>
        <ul style="margin:0;padding-left:1.25rem;font-size:.85rem;color:var(--ink-soft);line-height:1.8;">
            <li>Puoi configurare <strong>pagamenti programmati</strong> per automatizzare i bonifici ricorrenti</li>
            <li>L'<strong>API REST</strong> ti permette di integrare KMoney nei tuoi gestionali</li>
            <li>Puoi attivare la <strong>2FA</strong> per proteggere ulteriormente il tuo account</li>
            <li>I <strong>Webhook</strong> notificano i tuoi sistemi in tempo reale ad ogni evento</li>
        </ul>
    </div>

    <div style="text-align:center;">
        <a href="{{ route('portal.dashboard') }}" class="ob-btn ob-btn-primary" style="font-size:1rem;padding:.9rem 2.5rem;">
            Entra nel portale KMoney &rarr;
        </a>
    </div>

</div>

@endsection
