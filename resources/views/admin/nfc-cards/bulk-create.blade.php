@extends('layouts.portal')

@section('content')
<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="{{ route('admin.nfc-cards.index') }}">Card NFC</a> / Emissione bulk</div>
        <h1>Emissione bulk Card NFC</h1>
    </div>
    <a href="{{ route('admin.nfc-cards.index') }}" class="cta secondary">← Torna alla lista</a>
</div>

<div style="width:100%;">
<section class="card card-pad" style="width:100%;">
    <div class="section-head" style="margin-bottom:20px;">
        <div>
            <span class="eyebrow">Produzione</span>
            <h3 class="section-title">Crea più card contemporaneamente</h3>
        </div>
    </div>

    <div style="background:#dbeafe;border-radius:10px;padding:14px 16px;margin-bottom:22px;font-size:13px;color:#1d4ed8;border:1px solid #bfdbfe;">
        <strong>Come funziona:</strong> ogni card riceve un UUID univoco e il relativo payload HMAC per la scrittura sul chip NFC.
        Le card vengono create in stato <strong>pending</strong> — potrai poi scriverle una a una dalla lista.
    </div>

    @if ($errors->any())
        <div style="background:#ffe4e6;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#9f1239;">
            <strong>Errore:</strong>
            <ul style="margin:6px 0 0;padding-left:18px;">
                @foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.nfc-cards.bulk-store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:1fr 200px 1.4fr;gap:20px;align-items:start;">

            <div class="field" style="margin:0;">
                <label for="company_id">Azienda / partecipante <span style="color:#dc2626;">*</span></label>
                <select id="company_id" name="company_id" required style="width:100%;">
                    <option value="">— Seleziona azienda —</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}" @selected(old('company_id') == $company->id)>
                            {{ $company->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field" style="margin:0;">
                <label for="quantity">Numero di card da creare <span style="color:#dc2626;">*</span></label>
                <input id="quantity" name="quantity" type="number"
                    min="1" max="50" value="{{ old('quantity', 1) }}" required
                    placeholder="Es. 10" style="width:100%;">
                <div style="font-size:11.5px;color:var(--ink-muted);margin-top:4px;">Massimo 50 card per operazione.</div>
            </div>

            <div class="field" style="margin:0;">
                <label for="notes">Note (opzionale)</label>
                <textarea id="notes" name="notes" rows="3" style="width:100%;" placeholder="Es. Lotto giugno 2026 — chip Mifare Classic">{{ old('notes') }}</textarea>
            </div>

        </div>

        {{-- Preview contatore + azioni su un'unica riga --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-top:22px;">
            <div id="bulk-preview" style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:12px 16px;font-size:13px;color:#166534;display:none;flex:1;min-width:240px;">
                <strong>🃏 Stai per creare <span id="qty-label">0</span> card NFC</strong> in stato <em>pending</em>.
            </div>
            <div class="form-actions" style="margin:0;flex-shrink:0;">
                <a href="{{ route('admin.nfc-cards.index') }}" class="cta secondary">Annulla</a>
                <button type="submit" class="cta" id="submit-btn">Crea card</button>
            </div>
        </div>
    </form>
</section>
</div>

<script>
(function() {
    var qtyInput  = document.getElementById('quantity');
    var preview   = document.getElementById('bulk-preview');
    var qtyLabel  = document.getElementById('qty-label');
    var submitBtn = document.getElementById('submit-btn');

    function update() {
        var n = parseInt(qtyInput.value) || 0;
        if (n > 0) {
            preview.style.display = 'block';
            qtyLabel.textContent  = n;
            submitBtn.textContent = 'Crea ' + n + ' card';
        } else {
            preview.style.display = 'none';
            submitBtn.textContent = 'Crea card';
        }
    }
    qtyInput.addEventListener('input', update);
    update();
})();
</script>
@endsection
