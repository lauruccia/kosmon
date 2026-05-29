@extends('layouts.portal')

@section('content')
<div style="width:100%;">

    @if($errors->any())
        <div class="alert alert-danger" style="margin-bottom:10px;">
            <ul style="margin:0;padding-left:18px;">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $card->exists ? route('admin.ky-cards.update', $card) : route('admin.ky-cards.store') }}"
          id="kycard-form">
        @csrf
        @if($card->exists) @method('PUT') @endif

        <div class="card" style="padding:16px 20px;">

            {{-- Riga 1: Nome + Descrizione --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-bottom:12px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Nome card *</label>
                    <input type="text" name="name" value="{{ old('name', $card->name) }}"
                           placeholder="es. Starter Pack, Gold 500…"
                           class="form-control" required>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Descrizione <span style="font-weight:400;color:var(--ink-muted);">(opzionale)</span></label>
                    <textarea name="description" rows="1" class="form-control"
                              style="resize:none;min-height:0;"
                              placeholder="Breve testo visibile al cliente nel portale">{{ old('description', $card->description) }}</textarea>
                </div>
            </div>

            {{-- Riga 2: Prezzo EUR + KY nominali --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-bottom:12px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Prezzo in euro *</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="price_eur" id="price_eur"
                               value="{{ old('price_eur', $card->exists ? $card->price_eur : '') }}"
                               placeholder="es. 100" step="0.01" min="0.01"
                               class="form-control" required oninput="updatePreview()">
                        <span style="font-size:16px;font-weight:700;color:var(--ink-muted);">&euro;</span>
                    </div>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">KY nominali <span style="font-weight:400;color:var(--ink-muted);">(prima del bonus)</span> *</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="ky_base_amount" id="ky_base_amount"
                               value="{{ old('ky_base_amount', $card->ky_base_amount ?? '') }}"
                               placeholder="es. 100" step="1" min="1"
                               class="form-control" required oninput="updatePreview()">
                        <span style="font-size:13px;font-weight:700;color:var(--ink-muted);">KY</span>
                    </div>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">1&euro; = 1 KY di default</div>
                </div>
            </div>

            {{-- Riga 3: Tipo cashback + Valore bonus --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-bottom:12px;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--ink-soft);display:block;margin-bottom:6px;">Tipo cashback *</label>
                    <div style="display:flex;gap:8px;">
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:7px 12px;border:2px solid {{ old('bonus_type', $card->bonus_type ?? 'fixed') === 'fixed' ? '#16a34a' : '#e2e8f0' }};border-radius:8px;font-size:13px;flex:1;"
                               id="label-fixed">
                            <input type="radio" name="bonus_type" value="fixed"
                                   {{ old('bonus_type', $card->bonus_type ?? 'fixed') === 'fixed' ? 'checked' : '' }}
                                   onchange="switchBonusType('fixed')">
                            <span><strong>Fisso</strong> <span style="color:var(--ink-muted);">+25 KY</span></span>
                        </label>
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:7px 12px;border:2px solid {{ old('bonus_type', $card->bonus_type) === 'percentage' ? '#7c3aed' : '#e2e8f0' }};border-radius:8px;font-size:13px;flex:1;"
                               id="label-pct">
                            <input type="radio" name="bonus_type" value="percentage"
                                   {{ old('bonus_type', $card->bonus_type) === 'percentage' ? 'checked' : '' }}
                                   onchange="switchBonusType('percentage')">
                            <span><strong>Percentuale</strong> <span style="color:var(--ink-muted);">+25%</span></span>
                        </label>
                    </div>
                </div>
                <div>
                    <label class="form-label" id="bonus-label" style="margin-bottom:4px;">Valore bonus *</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="bonus_value" id="bonus_value"
                               value="{{ old('bonus_value', $card->bonus_value ?? 0) }}"
                               placeholder="0" step="0.01" min="0"
                               class="form-control" required oninput="updatePreview()">
                        <span id="bonus-unit" style="font-size:13px;font-weight:700;color:var(--ink-muted);white-space:nowrap;">KY extra</span>
                    </div>
                </div>
            </div>

            {{-- ANTEPRIMA LIVE --}}
            <div id="preview-box" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#166534;margin-right:4px;">Anteprima:</span>
                <span style="font-size:16px;font-weight:800;color:#166534;">Paga <span id="prev-eur">—</span> &euro;</span>
                <span style="font-size:16px;color:#94a3b8;">&#8594;</span>
                <span style="font-size:16px;font-weight:800;color:#1d4ed8;">Ricevi <span id="prev-ky">—</span> KY</span>
                <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;background:#dcfce7;color:#166534;" id="prev-badge">—</span>
            </div>

            {{-- Riga 4: Stripe Price ID + Ordine + Attiva --}}
            <div style="display:grid;grid-template-columns:1fr auto auto;gap:12px 20px;align-items:end;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;">
                        Stripe Price ID
                        <span style="font-weight:400;color:var(--ink-muted);">(per checkout online)</span>
                    </label>
                    <input type="text" name="stripe_price_id"
                           value="{{ old('stripe_price_id', $card->stripe_price_id) }}"
                           placeholder="price_1AbcXXXX…"
                           class="form-control">
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">
                        <a href="https://dashboard.stripe.com/products" target="_blank">Crea su Stripe Dashboard</a>
                    </div>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Ordine</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $card->sort_order ?? 0) }}"
                           min="0" class="form-control" style="width:90px;">
                </div>
                <div style="padding-bottom:2px;">
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;font-weight:600;white-space:nowrap;">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                               {{ old('is_active', $card->is_active ?? true) ? 'checked' : '' }}
                               style="width:15px;height:15px;">
                        Card attiva
                    </label>
                </div>
            </div>

        </div>

        <div style="margin-top:12px;display:flex;gap:12px;">
            <button type="submit" class="btn btn-primary" style="min-width:140px;">
                {{ $card->exists ? 'Salva modifiche' : 'Crea KYCard' }}
            </button>
            <a href="{{ route('admin.ky-cards.index') }}" class="btn btn-secondary">Annulla</a>
        </div>

    </form>
</div>

<script>
function switchBonusType(type) {
    document.getElementById('label-fixed').style.borderColor = type === 'fixed' ? '#16a34a' : '#e2e8f0';
    document.getElementById('label-pct').style.borderColor   = type === 'percentage' ? '#7c3aed' : '#e2e8f0';
    document.getElementById('bonus-label').textContent = type === 'fixed' ? 'KY extra fissi da aggiungere *' : 'Percentuale bonus (%) *';
    document.getElementById('bonus-unit').textContent  = type === 'fixed' ? 'KY extra' : '%';
    updatePreview();
}

function updatePreview() {
    const eur        = parseFloat(document.getElementById('price_eur').value) || 0;
    const kyBase     = parseInt(document.getElementById('ky_base_amount').value) || 0;
    const bonusVal   = parseFloat(document.getElementById('bonus_value').value) || 0;
    const bonusType  = document.querySelector('input[name="bonus_type"]:checked')?.value || 'fixed';

    let kyTotal, badgeText;
    if (bonusType === 'fixed') {
        kyTotal   = kyBase + bonusVal;
        badgeText = bonusVal > 0 ? '+' + bonusVal.toLocaleString('it-IT') + ' KY cashback' : 'Nessun bonus';
    } else {
        kyTotal   = Math.round(kyBase * (1 + bonusVal / 100));
        badgeText = bonusVal > 0 ? '+' + bonusVal + '% cashback' : 'Nessun bonus';
    }

    document.getElementById('prev-eur').textContent  = eur > 0 ? eur.toLocaleString('it-IT', {minimumFractionDigits:2, maximumFractionDigits:2}) : '—';
    document.getElementById('prev-ky').textContent   = kyTotal > 0 ? kyTotal.toLocaleString('it-IT') : '—';
    document.getElementById('prev-badge').textContent = badgeText;
}

// Init on load
document.addEventListener('DOMContentLoaded', function() {
    const currentType = document.querySelector('input[name="bonus_type"]:checked')?.value || 'fixed';
    switchBonusType(currentType);
});
</script>
@endsection
