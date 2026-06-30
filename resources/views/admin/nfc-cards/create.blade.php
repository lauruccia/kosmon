@extends('layouts.portal')

@section('content')
<div class="stack">

    <div>
        <a href="{{ route('admin.nfc-cards.index') }}" style="font-size:13px;color:var(--ink-muted);">&#8592; Torna alle card</a>
        <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:8px 0 0;">Emetti nuova Card NFC</h1>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        <section class="card card-pad">
            <form method="POST" action="{{ route('admin.nfc-cards.store') }}" class="stack">
                @csrf

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Titolare * <span style="text-transform:none;font-weight:400;">(azienda o privato)</span>
                    </label>
                    @php
                        $selected = $participants->first(fn ($p) => $p['type'] . ':' . $p['id'] === old('participant'));
                    @endphp
                    <div class="combo" data-combo style="position:relative;">
                        <input type="hidden" name="participant" value="{{ old('participant') }}" required>
                        <input type="text" class="combo-input" autocomplete="off" placeholder="Cerca azienda o privato per nome..."
                               value="{{ $selected['name'] ?? '' }}"
                               style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);">
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
                    @error('participant')<p style="color:var(--danger);font-size:12px;margin-top:4px;">{{ $message }}</p>@enderror
                    @error('participant_id')<p style="color:var(--danger);font-size:12px;margin-top:4px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Note interne <span style="text-transform:none;font-weight:400;">(opzionale)</span>
                    </label>
                    <textarea name="notes" rows="3" placeholder="Annotazioni sull'emissione..."
                              style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);resize:vertical;">{{ old('notes') }}</textarea>
                </div>

                <div style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:12px;color:var(--ink-muted);">
                    &#128246; Il numero seriale viene generato automaticamente nel formato <strong>KMY-YYYY-XXXXXX-C</strong>
                </div>

                <button type="submit" class="cta" style="width:100%;">
                    &#128246; Crea card e genera chip
                </button>
            </form>
        </section>

        <section class="card card-pad" style="background:var(--surface-soft);">
            <div style="font-size:13px;font-weight:700;color:var(--ink-muted);margin-bottom:14px;text-transform:uppercase;letter-spacing:.06em;">Come funziona l'emissione</div>
            <ol style="margin:0;padding-left:18px;color:var(--ink-muted);font-size:13px;line-height:1.9;">
                <li>Seleziona il cliente e crea la card</li>
                <li>Il sistema genera seriale e firma HMAC univoci</li>
                <li>Scrivi il chip NFC fisico dalla pagina dettaglio</li>
                <li>Segna la card come consegnata al cliente</li>
                <li>Il cliente la attiva impostando il PIN dall'app</li>
            </ol>
            <div style="margin-top:16px;padding:12px;background:#fff;border-radius:8px;border:1px solid var(--line);font-size:12px;color:var(--ink-muted);">
                <strong style="color:var(--ink);">Formato seriale:</strong><br>
                <code style="font-size:13px;color:var(--primary);">KMY-2026-A3F9K2-M</code><br>
                <span>KMY = prefisso · YYYY = anno · 6 char casuali · check digit</span>
            </div>
        </section>

    </div>

</div>

<script>
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
