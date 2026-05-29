@extends('onboarding.layout')

@section('content')

{{-- Stepper --}}
<div class="ob-stepper">
    <div class="ob-step">
        <div class="ob-step-dot active">1</div>
        <div class="ob-step-label active">Profilo</div>
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

{{-- Titolo --}}
<p class="ob-eyebrow">Passo 1 di 3</p>
<h1 class="ob-title">Completa il profilo aziendale</h1>
<p class="ob-subtitle">
    Questi dati saranno visibili alle altre aziende del circuito e aiuteranno il team KMoney a verificare la tua identità.
</p>

{{-- Alert sessione --}}
@if(session('onboarding_info'))
    <div class="ob-alert info">
        <span>ℹ️</span>
        <span>{{ session('onboarding_info') }}</span>
    </div>
@endif

@if(session('onboarding_error'))
    <div class="ob-alert error">
        <span>❌</span>
        <span>{{ session('onboarding_error') }}</span>
    </div>
@endif

{{-- Errori di validazione --}}
@if($errors->any())
    <div class="ob-errors">
        <strong style="color:var(--danger);font-size:13px;">Correggi i seguenti errori:</strong>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Form --}}
<form method="POST" action="{{ route('onboarding.step1.save') }}">
    @csrf

    {{-- Settore --}}
    <div class="ob-form-group">
        <label class="ob-label" for="sector">
            Settore di attività <span>*</span>
        </label>
        <select name="sector" id="sector" class="ob-select" required>
            <option value="">— Scegli il settore —</option>
            @foreach($sectors as $s)
                <option value="{{ $s }}" @selected(old('sector', $company->sector) === $s)>
                    {{ $s }}
                </option>
            @endforeach
        </select>
    </div>

    {{-- Descrizione --}}
    <div class="ob-form-group">
        <label class="ob-label" for="description">
            Descrizione dell'azienda <span>*</span>
        </label>
        <textarea name="description" id="description" class="ob-textarea"
            placeholder="Descrivi brevemente la tua attività, i prodotti o servizi offerti…"
            minlength="20" maxlength="500" required>{{ old('description', $company->description) }}</textarea>
        <div class="ob-input-hint">Minimo 20 caratteri · max 500</div>
    </div>

    <hr class="ob-divider">

    <p style="font-size:13px;font-weight:700;color:var(--ink-soft);margin:0 0 16px;text-transform:uppercase;letter-spacing:.06em;">
        Informazioni aggiuntive <span style="font-weight:400;">(facoltative)</span>
    </p>

    <div class="ob-row">
        {{-- Partita IVA --}}
        <div class="ob-form-group">
            <label class="ob-label" for="vat_number">Partita IVA</label>
            <input type="text" name="vat_number" id="vat_number" class="ob-input"
                   placeholder="IT12345678901"
                   value="{{ old('vat_number', $company->vat_number) }}">
        </div>
        {{-- Codice fiscale --}}
        <div class="ob-form-group">
            <label class="ob-label" for="fiscal_code">Codice fiscale</label>
            <input type="text" name="fiscal_code" id="fiscal_code" class="ob-input"
                   placeholder="RSSMRA80A01H501Z"
                   value="{{ old('fiscal_code', $company->fiscal_code) }}">
        </div>
    </div>

    <div class="ob-row">
        {{-- Sito web --}}
        <div class="ob-form-group">
            <label class="ob-label" for="website">Sito web</label>
            <input type="url" name="website" id="website" class="ob-input"
                   placeholder="https://tuaazienda.it"
                   value="{{ old('website', $company->website) }}">
        </div>
        {{-- Telefono --}}
        <div class="ob-form-group">
            <label class="ob-label" for="phone">Telefono</label>
            <input type="tel" name="phone" id="phone" class="ob-input"
                   placeholder="+39 0123 456789"
                   value="{{ old('phone', $company->phone) }}">
        </div>
    </div>

    {{-- CTA --}}
    <div style="margin-top:10px;">
        <button type="submit" class="ob-btn ob-btn-primary ob-btn-full">
            Continua — Carica i documenti →
        </button>
    </div>
</form>

@endsection
