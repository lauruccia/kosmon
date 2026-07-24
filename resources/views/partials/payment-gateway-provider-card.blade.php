{{--
    Card di configurazione di UN metodo di pagamento EUR (stripe/paypal/
    bank_transfer) per un'azienda. Riusata sia dal self-service azienda
    (portal.payment-gateways) sia dalla sezione admin dentro company-show.

    Variabili attese:
    - string  $provider       chiave provider (PaymentGateway::PROVIDER_*)
    - string  $providerLabel  etichetta leggibile
    - array   $fieldSpecs     PaymentGateway::CREDENTIAL_FIELDS[$provider]
    - ?PaymentGateway $gateway  gateway esistente per questo provider, o null
    - string  $updateUrl
    - string  $toggleUrl
    - string  $destroyUrl
--}}
@php
    $icon = match($provider) {
        'stripe' => '💳',
        'paypal' => '🅿',
        'bank_transfer' => '🏦',
        default => '💠',
    };
    $isConfigured = $gateway?->is_configured ?? false;
    $isActive = $gateway?->is_active ?? false;
@endphp
@once
<style>
    /* Riuso minimo dello stile "profile-section" (definito localmente in
       portal/profile-edit.blade.php) per questa card: qui serve anche fuori
       da quella pagina (self-service pagamenti + sezione admin azienda),
       quindi lo definiamo una sola volta invece di duplicarlo per ogni provider. */
    .pgw-section {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-xs);
        overflow: hidden;
    }
    .pgw-section-header {
        padding: 16px 20px 12px;
        border-bottom: 1px solid var(--line);
        display: flex; align-items: center; gap: 10px;
    }
    .pgw-section-icon {
        width: 30px; height: 30px; border-radius: 9px;
        background: var(--primary-faint, var(--surface-soft));
        display: grid; place-items: center;
        font-size: 14px;
    }
    .pgw-section-title { font-size: 14px; font-weight: 700; color: var(--ink); margin: 0; }
    .pgw-section-body { padding: 18px 20px; }
    .field-error { display: block; margin-top: 4px; font-size: 11.5px; color: #dc2626; }
</style>
@endonce
<div class="pgw-section" style="margin-bottom:16px;">
    <div class="pgw-section-header" style="justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:10px;">
            <div class="pgw-section-icon">{{ $icon }}</div>
            <h2 class="pgw-section-title">{{ $providerLabel }}</h2>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            @if($gateway)
                @if($isConfigured)
                    <span class="pill success" style="font-size:11px;">{{ $isActive ? 'Attivo' : 'Disattivato' }}</span>
                @else
                    <span class="pill warn" style="font-size:11px;">Incompleto</span>
                @endif
            @else
                <span class="pill" style="font-size:11px;background:var(--surface-soft);color:var(--ink-muted);">Non configurato</span>
            @endif
        </div>
    </div>
    <div class="pgw-section-body">
        <form method="POST" action="{{ $updateUrl }}">
            @csrf
            <div class="field">
                <label>Etichetta <span style="font-weight:400;color:var(--ink-muted)">(opzionale, es. "Conto principale")</span></label>
                <input type="text" name="label" value="{{ old('label', $gateway?->label) }}" maxlength="100">
            </div>
            @foreach($fieldSpecs as $field)
                @php
                    $fieldKey = $field['key'];
                    $fieldValue = old('credentials.' . $fieldKey);
                    $existingValue = $gateway?->credential($fieldKey);
                    $isSensitive = $field['sensitive'] ?? false;
                @endphp
                <div class="field" style="margin-top:14px;">
                    <label for="{{ $provider }}-{{ $fieldKey }}">{{ $field['label'] }}</label>
                    @if(($field['type'] ?? 'text') === 'select')
                        <select id="{{ $provider }}-{{ $fieldKey }}" name="credentials[{{ $fieldKey }}]">
                            @foreach($field['options'] as $optValue => $optLabel)
                                <option value="{{ $optValue }}" @selected(($fieldValue ?? $existingValue) === $optValue)>{{ $optLabel }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="{{ $isSensitive ? 'password' : 'text' }}"
                               id="{{ $provider }}-{{ $fieldKey }}"
                               name="credentials[{{ $fieldKey }}]"
                               value="{{ $fieldValue ?? ($isSensitive ? '' : $existingValue) }}"
                               placeholder="{{ $isSensitive && $existingValue ? '••••••••  (lascia vuoto per non modificare)' : ($field['placeholder'] ?? '') }}"
                               autocomplete="off">
                    @endif
                    @error('credentials.' . $fieldKey)<span class="field-error">{{ $message }}</span>@enderror
                </div>
            @endforeach

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button class="cta" type="submit" style="font-size:13px;">Salva</button>
            </div>
        </form>

        @if($gateway)
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px;">
                <form method="POST" action="{{ $toggleUrl }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="cta secondary" style="font-size:13px;">
                        {{ $isActive ? 'Disattiva' : 'Riattiva' }}
                    </button>
                </form>
                <form method="POST" action="{{ $destroyUrl }}" style="margin:0;"
                      onsubmit="return confirm('Rimuovere questo metodo di pagamento? Gli ordini futuri non potranno più usarlo.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="cta secondary" style="font-size:13px;color:#dc2626;">
                        Rimuovi
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
