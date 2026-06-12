@extends('onboarding.layout')

@section('content')

{{-- Stepper --}}
<div class="ob-stepper">
    <div class="ob-step">
        <div class="ob-step-dot done">✓</div>
        <div class="ob-step-label">Profilo</div>
    </div>
    <div class="ob-step-connector done"></div>
    <div class="ob-step">
        <div class="ob-step-dot done">✓</div>
        <div class="ob-step-label">Documenti</div>
    </div>
    <div class="ob-step-connector done"></div>
    <div class="ob-step">
        <div class="ob-step-dot active">3</div>
        <div class="ob-step-label active">Verifica</div>
    </div>
</div>

@php
    $isRejected   = $company->kyc_status === 'rejected';
    $isUnderReview = $company->kyc_status === 'under_review';
    $submittedAt  = $company->kycDocuments->min('created_at');
    $reviewedAt   = $company->kyc_reviewed_at;
@endphp

{{-- Stato principale --}}
<div class="ob-status-card" style="
    background: {{ $isRejected ? 'var(--danger-soft)' : '#f0f9ff' }};
    border-color: {{ $isRejected ? '#fecaca' : '#bae6fd' }};
    margin-bottom: 24px;
">
    <div class="ob-status-icon">
        {{ $isRejected ? '❌' : '⏳' }}
    </div>
    <h1 class="ob-status-title">
        {{ $isRejected ? 'Verifica non approvata' : 'Documenti inviati — in attesa di verifica' }}
    </h1>
    <p class="ob-status-text">
        @if($isRejected)
            Il team KMoney ha esaminato i documenti e non ha potuto approvare la verifica.
            @if($company->kyc_notes)
                <br><br><strong>Motivo:</strong> {{ $company->kyc_notes }}
            @endif
            <br><br>Puoi caricare nuovi documenti per richiedere una nuova verifica.
        @else
            Abbiamo ricevuto i tuoi documenti e li stiamo esaminando.
            Riceverai una notifica e un'email quando la verifica sarà completata.
        @endif
    </p>
</div>

{{-- Timeline stato pratica ─────────────────────────────────────────────────── --}}
@if(!$isRejected)
<div style="margin-bottom:28px;">
    <p style="font-size:13px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;margin:0 0 16px;">
        Stato della pratica
    </p>

    @php
        // Definisce i 4 passaggi e lo stato corrente
        $steps = [
            [
                'label'   => 'Registrazione',
                'desc'    => 'Account creato',
                'status'  => 'done',
                'date'    => $company->created_at?->format('d/m/Y'),
            ],
            [
                'label'   => 'Documenti inviati',
                'desc'    => $submittedAt ? $submittedAt->format('d/m/Y H:i') : 'Documenti caricati',
                'status'  => 'done',
                'date'    => null,
            ],
            [
                'label'   => 'In revisione',
                'desc'    => $isUnderReview ? 'Il team sta esaminando i tuoi documenti' : 'In attesa di avvio revisione',
                'status'  => $isUnderReview ? 'active' : 'pending',
                'date'    => null,
                'avg'     => 'Tipicamente 1–2 giorni lavorativi',
            ],
            [
                'label'   => 'Approvazione',
                'desc'    => 'Accesso completo al circuito KMoney',
                'status'  => 'pending',
                'date'    => null,
            ],
        ];
    @endphp

    <div style="display:flex;flex-direction:column;gap:0;">
        @foreach($steps as $i => $step)
        @php
            $isLast = $i === count($steps) - 1;
            $dotBg  = match($step['status']) {
                'done'   => '#16a34a',
                'active' => '#0f52c4',
                default  => '#d1d5db',
            };
            $dotColor = $step['status'] === 'pending' ? '#9ca3af' : '#fff';
            $dotIcon  = $step['status'] === 'done' ? '✓' : ($step['status'] === 'active' ? '●' : ($i + 1));
        @endphp
        <div style="display:flex;gap:16px;align-items:flex-start;">
            {{-- Colonna timeline --}}
            <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:28px;">
                <div style="width:28px;height:28px;border-radius:50%;background:{{ $dotBg }};color:{{ $dotColor }};
                            display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;
                            flex-shrink:0;box-shadow:{{ $step['status'] === 'active' ? '0 0 0 4px rgba(15,82,196,.15)' : 'none' }};">
                    {{ $dotIcon }}
                </div>
                @if(!$isLast)
                <div style="width:2px;background:{{ $step['status'] === 'done' ? '#16a34a' : '#e5e7eb' }};
                            flex:1;min-height:24px;margin:4px 0;"></div>
                @endif
            </div>
            {{-- Contenuto step --}}
            <div style="padding-bottom:{{ $isLast ? '0' : '20px' }};padding-top:4px;flex:1;min-width:0;">
                <div style="font-size:14px;font-weight:700;color:{{ $step['status'] === 'pending' ? 'var(--ink-muted)' : 'var(--ink)' }};">
                    {{ $step['label'] }}
                </div>
                <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">
                    {{ $step['desc'] }}
                </div>
                @if(isset($step['avg']) && $step['status'] === 'active')
                <div style="margin-top:6px;display:inline-flex;align-items:center;gap:5px;
                            background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;
                            padding:3px 10px;font-size:11px;color:#1d4ed8;font-weight:600;">
                    🕐 {{ $step['avg'] }}
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- Riepilogo documenti --}}
@if($documents->isNotEmpty())
    <p style="font-size:13px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;margin:0 0 12px;">
        Documenti caricati
    </p>
    <div class="ob-doc-list">
        @foreach($documents as $doc)
            <div class="ob-doc-item">
                <div class="ob-doc-icon">
                    {{ str_ends_with(strtolower($doc->original_name), '.pdf') ? '📄' : '🖼️' }}
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="ob-doc-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        {{ $doc->original_name }}
                    </div>
                    <div class="ob-doc-type" style="font-size:12px;color:var(--ink-muted);">
                        {{ \App\Models\KycDocument::TYPES[$doc->type] ?? $doc->type }}
                    </div>
                </div>
                <span class="ob-doc-badge" style="
                    background: {{ $doc->status === 'accepted' ? 'var(--success-soft)' : ($doc->status === 'rejected' ? 'var(--danger-soft)' : '#fef3c7') }};
                    color: {{ $doc->status === 'accepted' ? 'var(--success)' : ($doc->status === 'rejected' ? 'var(--danger)' : '#92400e') }};
                ">
                    @if($doc->status === 'accepted') ✅ Accettato
                    @elseif($doc->status === 'rejected') ❌ Rifiutato
                    @else ⏳ In attesa
                    @endif
                </span>
            </div>
        @endforeach
    </div>
@endif

<hr class="ob-divider">

@if($isRejected)
    {{-- Se rifiutata: bottone per tornare allo step 2 e ricaricare --}}
    <div style="text-align:center;">
        <a href="{{ route('onboarding.step2') }}" class="ob-btn ob-btn-primary" style="display:inline-flex;">
            Carica nuovi documenti →
        </a>
    </div>
@else
    {{-- Non ancora approvata: info utili --}}
    <div style="display:grid;gap:12px;">

        <div style="display:flex;gap:14px;align-items:flex-start;padding:14px;background:var(--bg);border-radius:10px;">
            <span style="font-size:20px;flex-shrink:0;">📧</span>
            <div>
                <div style="font-size:13px;font-weight:700;margin-bottom:3px;">Tieni d'occhio la tua email</div>
                <div style="font-size:13px;color:var(--ink-soft);">
                    Ti notificheremo a <strong>{{ auth()->user()->email }}</strong> non appena la verifica sarà completata.
                </div>
            </div>
        </div>

        <div style="display:flex;gap:14px;align-items:flex-start;padding:14px;background:var(--bg);border-radius:10px;">
            <span style="font-size:20px;flex-shrink:0;">🕐</span>
            <div>
                <div style="font-size:13px;font-weight:700;margin-bottom:3px;">Tempi di revisione</div>
                <div style="font-size:13px;color:var(--ink-soft);">
                    Tipicamente 1–2 giorni lavorativi. Nei periodi di picco potrebbe richiedere fino a 5 giorni.
                </div>
            </div>
        </div>

        <div style="display:flex;gap:14px;align-items:flex-start;padding:14px;background:var(--bg);border-radius:10px;">
            <span style="font-size:20px;flex-shrink:0;">📎</span>
            <div>
                <div style="font-size:13px;font-weight:700;margin-bottom:3px;">Vuoi aggiungere altri documenti?</div>
                <div style="font-size:13px;color:var(--ink-soft);">
                    <a href="{{ route('onboarding.step2') }}" style="color:var(--primary);font-weight:600;">
                        Torna alla pagina documenti
                    </a> per caricare materiale aggiuntivo.
                </div>
            </div>
        </div>

    </div>
@endif

@endsection
