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
        <div class="ob-step-dot active">2</div>
        <div class="ob-step-label active">Documenti</div>
    </div>
    <div class="ob-step-connector"></div>
    <div class="ob-step">
        <div class="ob-step-dot pending">3</div>
        <div class="ob-step-label">Verifica</div>
    </div>
</div>

{{-- Titolo --}}
<p class="ob-eyebrow">Passo 2 di 3</p>
<h1 class="ob-title">Verifica aziendale (KYC)</h1>
<p class="ob-subtitle">
    Carica i documenti necessari per completare la verifica di <strong>{{ $company->name }}</strong>.
    Il team KMoney li esaminerà entro 1–2 giorni lavorativi.
</p>

{{-- Documenti già caricati --}}
@if($documents->isNotEmpty())
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
                    <div class="ob-doc-type">{{ $docTypes[$doc->type] ?? $doc->type }}</div>
                </div>
                <span class="ob-doc-badge">
                    {{ $doc->status === 'accepted' ? '✅ Accettato' : '⏳ In attesa' }}
                </span>
            </div>
        @endforeach
    </div>
@endif

{{-- Alert --}}
@if(session('onboarding_success'))
    <div class="ob-alert success">
        <span>✅</span>
        <span>{{ session('onboarding_success') }}</span>
    </div>
@endif
@if(session('onboarding_error'))
    <div class="ob-alert error">
        <span>❌</span>
        <span>{{ session('onboarding_error') }}</span>
    </div>
@endif
@if(session('onboarding_info'))
    <div class="ob-alert info">
        <span>ℹ️</span>
        <span>{{ session('onboarding_info') }}</span>
    </div>
@endif

@if($errors->any())
    <div class="ob-errors">
        <strong style="color:var(--danger);font-size:13px;">Errore nel caricamento:</strong>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Form upload --}}
<form method="POST" action="{{ route('onboarding.step2.upload') }}" enctype="multipart/form-data" id="uploadForm">
    @csrf

    {{-- Tipo documento --}}
    <div class="ob-form-group">
        <label class="ob-label" for="type">
            Tipo di documento <span>*</span>
        </label>
        <select name="type" id="type" class="ob-select" required>
            <option value="">— Seleziona il tipo —</option>
            @foreach($docTypes as $key => $label)
                <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- Upload file --}}
    <div class="ob-form-group">
        <label class="ob-label">File <span>*</span></label>
        <label class="ob-upload-zone" for="document" id="dropzone">
            <input type="file" name="document" id="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
            <div class="ob-upload-icon">📎</div>
            <div class="ob-upload-label" id="dropzoneLabel">
                <strong>Clicca per scegliere un file</strong> o trascinalo qui
            </div>
            <div class="ob-upload-formats">PDF, JPG, PNG, WEBP · max 10 MB</div>
        </label>
    </div>

    {{-- Documenti richiesti --}}
    <div style="background:var(--bg);border-radius:10px;padding:14px 16px;margin-bottom:24px;font-size:13px;color:var(--ink-soft);">
        <strong style="color:var(--ink);display:block;margin-bottom:6px;">Documenti normalmente richiesti:</strong>
        <ul style="margin:0;padding-left:18px;display:grid;gap:3px;">
            <li>Visura camerale aggiornata (non più di 6 mesi)</li>
            <li>Documento d'identità del legale rappresentante</li>
            <li>Statuto societario (per S.r.l., S.p.A. ecc.)</li>
        </ul>
        <p style="margin:8px 0 0;">Puoi caricare più documenti uno alla volta.</p>
    </div>

    <button type="submit" class="ob-btn ob-btn-primary ob-btn-full" id="submitBtn">
        Carica documento
    </button>
</form>

{{-- Azione: procedi alla pagina di attesa --}}
@if($documents->isNotEmpty())
    <hr class="ob-divider">
    <div style="text-align:center;">
        <p style="font-size:14px;color:var(--ink-soft);margin:0 0 14px;">
            Hai caricato {{ $documents->count() }} {{ $documents->count() === 1 ? 'documento' : 'documenti' }}.
            Quando sei pronto, invia la richiesta di verifica.
        </p>
        <form method="POST" action="{{ route('onboarding.step2.proceed') }}">
            @csrf
            <button type="submit" class="ob-btn ob-btn-primary ob-btn-full">
                Invia per la verifica →
            </button>
        </form>
    </div>
@endif

<div style="margin-top:16px;text-align:center;">
    <a href="{{ route('onboarding.step1') }}" style="font-size:13px;color:var(--ink-muted);">
        ← Torna al profilo
    </a>
</div>

<script>
    // Aggiorna label dropzone con il nome del file selezionato
    document.getElementById('document').addEventListener('change', function () {
        const label = document.getElementById('dropzoneLabel');
        if (this.files.length > 0) {
            label.innerHTML = '<strong>' + this.files[0].name + '</strong>';
        }
    });
</script>

@endsection
