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
                <label for="company-search">Titolare (azienda o privato) <span style="color:#dc2626;">*</span></label>
                @php
                    $selected = $participants->first(fn ($p) => $p['type'] . ':' . $p['id'] === old('participant'));
                @endphp
                <div class="combo" data-combo style="position:relative;">
                    <input type="hidden" name="participant" value="{{ old('participant') }}" required>
                    <input type="text" id="company-search" class="combo-input" autocomplete="off" placeholder="Cerca azienda o privato per nome..."
                           value="{{ $selected['name'] ?? '' }}" style="width:100%;">
                    <div class="combo-list" style="display:none;position:absolute;z-index:30;left:0;right:0;top:calc(100% + 4px);max-height:280px;overflow-y:auto;background:#fff;border:1.5px solid var(--line);border-radius:10px;box-shadow:0 12px 28px rgba(0,0,0,.12);">
                        @foreach($participants as $p)
                            <div class="combo-opt" data-value="{{ $p['type'] }}:{{ $p['id'] }}" data-name="{{ \Illuminate\Support\Str::lower($p['name']) }}"
                                 style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;font-size:14px;color:var(--ink);cursor:pointer;">
                                <span>{{ $p['name'] }}</span>
                                <span style="flex-shrink:0;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:2px 7px;border-radius:999px;{{ $p['type'] === 'user' ? 'background:#ede9fe;color:#6d28d9;' : 'background:#dbeafe;color:#1d4ed8;' }}">{{ $p['label'] }}</span>
                            </div>
                        @endforeach
                        <div class="combo-empty" style="display:none;padding:12px;font-size:13px;color:var(--ink-muted);">Nessun titolare trovato.</div>
                    </div>
                </div>
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

(function () {
    var combo  = document.querySelector('[data-combo]');
    if (!combo) return;
    var input  = combo.querySelector('.combo-input');
    var hidden = combo.querySelector('input[type=hidden]');
    var list   = combo.querySelector('.combo-list');
    var empty  = combo.querySelector('.combo-empty');
    var opts   = Array.prototype.slice.call(combo.querySelectorAll('.combo-opt'));
    var active = -1;

    function open()  { list.style.display = 'block'; }
    function close() { list.style.display = 'none'; active = -1; paintActive(); }
    function visibleOpts() { return opts.filter(function (o) { return o.style.display !== 'none'; }); }

    function paintActive() {
        opts.forEach(function (o) { o.style.background = ''; });
        var vis = visibleOpts();
        if (active >= 0 && active < vis.length) {
            vis[active].style.background = 'var(--surface-soft)';
            vis[active].scrollIntoView({ block: 'nearest' });
        }
    }

    function filter() {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        opts.forEach(function (o) {
            var match = o.getAttribute('data-name').indexOf(q) !== -1;
            o.style.display = match ? 'block' : 'none';
            if (match) shown++;
        });
        empty.style.display = shown === 0 ? 'block' : 'none';
        active = -1;
        paintActive();
    }

    function choose(o) {
        hidden.value = o.getAttribute('data-value');
        var nameEl   = o.querySelector('span');
        input.value  = nameEl ? nameEl.textContent.trim() : o.textContent.trim();
        close();
    }

    input.addEventListener('focus', function () { filter(); open(); });
    input.addEventListener('input', function () { hidden.value = ''; open(); filter(); });
    input.addEventListener('keydown', function (e) {
        var vis = visibleOpts();
        if (e.key === 'ArrowDown') { e.preventDefault(); open(); active = Math.min(active + 1, vis.length - 1); paintActive(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); paintActive(); }
        else if (e.key === 'Enter') { if (active >= 0 && vis[active]) { e.preventDefault(); choose(vis[active]); } }
        else if (e.key === 'Escape') { close(); }
    });
    opts.forEach(function (o) {
        o.addEventListener('mousedown', function (e) { e.preventDefault(); choose(o); });
        o.addEventListener('mouseover', function () { active = visibleOpts().indexOf(o); paintActive(); });
    });
    document.addEventListener('click', function (e) { if (!combo.contains(e.target)) close(); });
})();
</script>
@endsection
