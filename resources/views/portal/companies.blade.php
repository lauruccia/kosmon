@extends('layouts.portal')

@section('content')
<style>
    /* ── Layout ── */
    .dir-main { display:grid; gap:20px; }
    .dir-searchbar {
        display:grid;
        grid-template-columns:minmax(0,1.4fr) 200px auto;
        gap:12px; align-items:end;
    }

    /* ── Top bar (search + stats) ── */
    .dir-topbar {
        display:flex; align-items:center; gap:16px;
        padding:14px 16px;
        background:var(--surface); border:1px solid var(--line);
        border-radius:var(--radius); box-shadow:var(--shadow-xs);
        flex-wrap:wrap;
    }
    .dir-topbar form { flex:1; min-width:280px; }
    .dir-searchbar {
        display:flex; gap:10px; align-items:flex-end; flex-wrap:nowrap;
    }
    .dir-searchbar .field { margin:0; flex:1; min-width:0; }
    .dir-searchbar .field label { font-size:11px; }
    .dir-searchbar .form-actions { margin:0; flex-shrink:0; }

    /* ── Stats ── */
    .dir-stats {
        display:flex; gap:20px; flex-wrap:wrap; flex-shrink:0;
        padding-left:16px;
        border-left:1px solid var(--line);
    }
    .dir-stat { display:flex; flex-direction:column; }
    .dir-stat-val { font-size:18px; font-weight:800; color:var(--ink); letter-spacing:-.02em; }
    .dir-stat-lbl { font-size:10px; font-weight:600; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.07em; }
    .dir-stat + .dir-stat { padding-left:20px; border-left:1px solid var(--line); }
    @media(max-width:700px){
        .dir-topbar { flex-direction:column; align-items:stretch; }
        .dir-stats { border-left:none; padding-left:0; border-top:1px solid var(--line); padding-top:12px; }
    }

    /* ── Grid ── */
    .dir-grid {
        display:grid;
        grid-template-columns:repeat(4, minmax(0,1fr));
        gap:18px; align-items:start;
    }
    @media(min-width:1680px){ .dir-grid { grid-template-columns:repeat(5,minmax(0,1fr)); } }
    @media(max-width:1480px){ .dir-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
    @media(max-width:1100px){ .dir-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media(max-width:680px){
        .dir-grid, .dir-searchbar { grid-template-columns:1fr; }
    }

    /* ── BASE CARD (comune a tutti i piani) ── */
    .dir-card {
        border-radius:16px;
        border:1px solid var(--line);
        background:#fff;
        box-shadow:0 1px 4px rgba(0,0,0,.06);
        overflow:hidden;
        display:flex; flex-direction:column;
        transition:box-shadow .2s, transform .18s, border-color .18s;
        position:relative;
    }
    .dir-card:hover {
        box-shadow:0 10px 26px rgba(13,28,48,.13);
        transform:translateY(-3px);
    }

    /* Accento superiore col colore del piano — l'unico elemento "premium"
       che distingue Ecommerce/Vetrina, coerente con la grafica piatta
       (logo inline, niente banner) richiesta per la directory. */
    .dir-plan-accent { height:4px; width:100%; flex-shrink:0; }

    .dir-plan-badge {
        position:absolute; top:12px; right:12px; z-index:2;
        display:inline-flex; align-items:center; gap:4px;
        padding:3px 9px; border-radius:999px;
        font-size:9.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase;
        color:#fff; box-shadow:0 1px 4px rgba(0,0,0,.18);
    }

    /* ── Header: logo inline + nome (rich / compact) ── */
    .dir-card-header {
        display:flex; align-items:flex-start; gap:12px;
        padding:18px 16px 12px;
    }
    .dir-logo {
        flex-shrink:0; width:52px; height:52px; border-radius:14px;
        background:linear-gradient(150deg,var(--dir-c1,#174d87),var(--dir-c2,#071d35));
        display:flex; align-items:center; justify-content:center;
        font-size:19px; font-weight:900; color:#fff; letter-spacing:-.02em;
        box-shadow:0 2px 8px rgba(0,0,0,.14);
        overflow:hidden;
    }
    .dir-logo img { width:100%; height:100%; object-fit:cover; }
    .dir-card--compact .dir-logo { width:42px; height:42px; border-radius:11px; font-size:15px; }

    .dir-company-name {
        font-size:15.5px; font-weight:800; color:var(--ink);
        margin:0; line-height:1.28; word-break:break-word;
    }
    .dir-card--compact .dir-company-name { font-size:14px; }
    .dir-sector-label {
        font-size:11.5px; font-weight:600;
        color:var(--ink-muted); margin-top:2px;
    }
    .dir-tagline {
        font-size:11.5px; color:var(--ink-soft); margin-top:4px;
        font-style:italic; line-height:1.4;
    }

    /* Contact list — icone coerenti con la card di riferimento (pin, tag, email, tel, globo) */
    .dir-contacts { display:flex; flex-direction:column; gap:6px; padding:2px 16px 14px; flex:1; }
    .dir-card--compact .dir-contacts { gap:5px; padding-bottom:10px; }
    .dir-contact {
        display:flex; align-items:center; gap:8px;
        font-size:12.5px; color:var(--ink-soft);
        overflow:hidden;
    }
    .dir-card--compact .dir-contact { font-size:11.5px; }
    .dir-contact svg { flex-shrink:0; opacity:.5; color:var(--ink-muted); }
    .dir-contact a, .dir-contact span {
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        text-decoration:none; color:inherit;
    }
    .dir-contact a:hover { color:var(--primary); text-decoration:underline; }

    /* Activity pills (solo piani che vendono prodotti) */
    .dir-pills { display:flex; gap:6px; flex-wrap:wrap; padding:0 16px 12px; }
    .dir-pill {
        display:inline-flex; align-items:center; gap:4px;
        padding:3px 9px; border-radius:999px;
        font-size:11px; font-weight:700;
        border:1px solid var(--line); background:var(--surface-soft); color:var(--ink-muted);
    }
    .dir-pill.active-shop  { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
    .dir-pill.active-ann   { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
    .dir-pill-dot { width:5px; height:5px; border-radius:50%; background:currentColor; flex-shrink:0; }

    /* Footer */
    .dir-footer {
        padding:10px 14px;
        border-top:1px solid var(--line);
        display:flex; gap:7px;
        margin-top:auto;
    }
    .dir-btn {
        flex:1; display:inline-flex; align-items:center; justify-content:center;
        padding:8px 10px; border-radius:9px;
        font-size:12.5px; font-weight:700; text-decoration:none; white-space:nowrap;
        transition:background .15s, border-color .15s;
        min-height:36px;
    }
    .dir-card--compact .dir-btn { font-size:11.5px; min-height:32px; padding:6px 9px; }
    .dir-btn-primary { background:#0c4a86; color:#fff; border:1.5px solid #0c4a86; }
    .dir-btn-primary:hover { background:#0e3158; color:#fff; }
    .dir-btn-ghost {
        background:#fff; color:#0c4a86;
        border:1.5px solid #c7d9ef; flex:0 0 auto; padding:8px 13px;
    }
    .dir-btn-ghost:hover { background:#f0f6ff; border-color:#94b4d8; }

    /* ── SIMPLE CARD (anagrafica) ── */
    .dir-card--simple .dir-body {
        padding:14px 16px;
        flex:1; display:flex; flex-direction:column; gap:8px;
    }
    .dir-simple-top {
        display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
    }
    .dir-simple-name {
        font-size:14.5px; font-weight:800; color:var(--ink);
        margin:0; line-height:1.3; word-break:break-word;
    }
    .dir-simple-sector { font-size:11px; font-weight:600; color:var(--ink-muted); margin-top:2px; text-transform:uppercase; letter-spacing:.04em; }
    .dir-cat-icon {
        flex-shrink:0; width:34px; height:34px;
        border-radius:9px; background:var(--surface-soft); border:1px solid var(--line);
        display:flex; align-items:center; justify-content:center;
        font-size:17px;
    }
    .dir-card--simple .dir-contact { font-size:12px; }
    .dir-card--simple .dir-footer { padding:8px 14px; border-top:1px solid var(--line); }
    .dir-card--simple .dir-btn-primary { font-size:12px; min-height:32px; }

    /* ── Badge KY percentuale ── */
    .ky-badge {
        display:inline-flex; align-items:center; gap:4px;
        font-size:10px; font-weight:800; letter-spacing:.04em;
        padding:2px 8px; border-radius:99px;
        white-space:nowrap; flex-shrink:0;
    }
    .ky-badge--full  { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; }
    .ky-badge--mix   { background:#dbeafe; color:#1d4ed8; border:1px solid #bfdbfe; }
    .ky-badge--debit { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
    .ky-badge--ceil  { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
    .ky-badge--gold  { background:linear-gradient(135deg,#fef9c3,#fde047); color:#854d0e; border:1px solid #eab308; box-shadow:0 1px 6px rgba(234,179,8,.35); font-weight:800; }

    /* ── Pagination ── */
    .dir-pagination {
        margin-top:4px; padding:14px 16px;
        border:1px solid var(--line); background:var(--surface);
        border-radius:var(--radius); box-shadow:var(--shadow-xs);
    }

    /* ── Tabs Lista/Mappa + Vicino a te ── */
    .dir-tabs-row {
        display:flex; align-items:center; justify-content:space-between;
        gap:12px; flex-wrap:wrap;
    }
    .dir-tabs {
        display:inline-flex; padding:4px; gap:4px;
        background:var(--surface-soft); border:1px solid var(--line);
        border-radius:999px;
    }
    .dir-tab {
        appearance:none; border:none; background:transparent; cursor:pointer;
        padding:8px 16px; border-radius:999px;
        font-size:12.5px; font-weight:700; color:var(--ink-muted);
        transition:background .15s, color .15s;
    }
    .dir-tab.is-active { background:#0c4a86; color:#fff; box-shadow:0 1px 4px rgba(0,0,0,.18); }
    .dir-locate-btn {
        appearance:none; cursor:pointer;
        display:inline-flex; align-items:center; gap:6px;
        padding:8px 14px; border-radius:999px;
        font-size:12.5px; font-weight:700;
        background:#fff; color:#0c4a86; border:1.5px solid #c7d9ef;
        transition:background .15s, border-color .15s;
    }
    .dir-locate-btn:hover { background:#f0f6ff; border-color:#94b4d8; }
    .dir-locate-btn.is-active { background:#0c4a86; color:#fff; border-color:#0c4a86; }
    .dir-locate-status { font-size:11.5px; color:var(--ink-muted); }
    .dir-locate-status.is-error { color:#b91c1c; }

    .dir-view--hidden { display:none; }

    /* ── Distanza (badge stile Monetica, es. "4,7 km") ── */
    .dir-distance-badge {
        position:absolute; top:12px; left:12px; z-index:2;
        display:none; align-items:center; gap:3px;
        padding:3px 9px; border-radius:999px;
        font-size:10.5px; font-weight:800;
        background:#0c4a86; color:#fff; box-shadow:0 1px 4px rgba(0,0,0,.18);
    }

    /* ── Vista mappa ── */
    .dir-map-layout {
        display:grid; grid-template-columns:340px minmax(0,1fr);
        gap:16px; height:74vh; min-height:480px;
    }
    @media(max-width:900px) {
        .dir-map-layout { grid-template-columns:1fr; height:auto; }
    }
    .dir-map-sidebar {
        background:var(--surface); border:1px solid var(--line); border-radius:var(--radius);
        box-shadow:var(--shadow-xs); overflow-y:auto;
    }
    @media(max-width:900px) { .dir-map-sidebar { max-height:60vh; order:2; } }
    .dir-map {
        border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-xs);
        overflow:hidden; min-height:340px;
    }
    @media(max-width:900px) { .dir-map { height:46vh; order:1; } }

    .dir-map-sidebar-item {
        display:flex; align-items:center; gap:10px;
        padding:12px 14px; border-bottom:1px solid var(--line);
        cursor:pointer; transition:background .12s;
    }
    .dir-map-sidebar-item:hover, .dir-map-sidebar-item.is-active { background:var(--surface-soft); }
    .dir-map-sidebar-logo {
        flex-shrink:0; width:38px; height:38px; border-radius:10px;
        background:linear-gradient(150deg,#174d87,#071d35);
        display:flex; align-items:center; justify-content:center;
        font-size:14px; font-weight:900; color:#fff; overflow:hidden;
    }
    .dir-map-sidebar-logo img { width:100%; height:100%; object-fit:cover; }
    .dir-map-sidebar-name { font-size:13px; font-weight:700; color:var(--ink); line-height:1.3; }
    .dir-map-sidebar-meta { font-size:11px; color:var(--ink-muted); margin-top:2px; }
    .dir-map-sidebar-distance { font-size:11px; font-weight:800; color:#0c4a86; margin-left:auto; padding-left:8px; white-space:nowrap; }

    .dir-map-popup { font-size:12.5px; min-width:180px; }
    .dir-map-popup strong { display:block; font-size:13.5px; margin-bottom:2px; }
    .dir-map-popup-meta { color:#64748b; margin-bottom:6px; }
    .dir-map-popup-actions { display:flex; gap:6px; margin-top:8px; }
    .dir-map-popup-btn {
        flex:1; text-align:center; text-decoration:none;
        padding:6px 8px; border-radius:7px; font-size:11px; font-weight:700;
    }
    .dir-map-popup-btn--primary { background:#0c4a86; color:#fff; }
    .dir-map-popup-btn--ghost { background:#f0f6ff; color:#0c4a86; border:1px solid #c7d9ef; }
</style>

<div class="dir-main">

    {{-- Tabs Lista/Mappa + Vicino a te --}}
    <div class="dir-tabs-row">
        <div class="dir-tabs" role="tablist">
            <button type="button" id="dir-tab-list" class="dir-tab is-active">📋 Lista</button>
            <button type="button" id="dir-tab-map" class="dir-tab">🗺️ Mappa</button>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button type="button" id="dir-locate-btn" class="dir-locate-btn">📍 Vicino a te</button>
            <span id="dir-locate-status" class="dir-locate-status"></span>
        </div>
    </div>

    {{-- Topbar: filtri + stats sulla stessa riga --}}
    <div class="dir-topbar">
        <form method="get" action="{{ $directoryRoute }}">
            <div class="dir-searchbar">
                <div class="field">
                    <label for="q">Cerca azienda</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}"
                           placeholder="Nome, settore, email…">
                </div>
                @if($sectorOptions->isNotEmpty())
                <div class="field" style="max-width:180px;">
                    <label for="sector">Settore</label>
                    <select id="sector" name="sector">
                        <option value="">Tutti i settori</option>
                        @foreach ($sectorOptions as $sector)
                            <option value="{{ $sector }}" @selected($filters['sector'] === $sector)>{{ $sector }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if(($cityOptions ?? collect())->isNotEmpty())
                <div class="field" style="max-width:180px;">
                    <label for="city">Città</label>
                    <select id="city" name="city">
                        <option value="">Tutte le città</option>
                        @foreach ($cityOptions as $city)
                            <option value="{{ $city }}" @selected($filters['city'] === $city)>{{ $city }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="field" style="max-width:200px;flex-direction:row;align-items:center;gap:8px;padding-top:18px;">
                    <input type="checkbox" id="accepts_ky" name="accepts_ky" value="1" @checked($filters['accepts_ky'] ?? false)
                        style="width:16px;height:16px;">
                    <label for="accepts_ky" style="margin:0;font-size:13px;cursor:pointer;">Solo chi accetta Kmoney</label>
                </div>
                <div class="form-actions" style="margin:0;flex-wrap:nowrap;">
                    <button class="cta" type="submit">Cerca</button>
                    @if($filters['q'] || $filters['sector'] || ($filters['city'] ?? '') || ($filters['accepts_ky'] ?? false))
                        <a href="{{ $directoryRoute }}" class="cta secondary">Reset</a>
                    @endif
                </div>
            </div>
        </form>

        <div class="dir-stats">
            <div class="dir-stat">
                <span class="dir-stat-val">{{ $directoryStats['companies'] }}</span>
                <span class="dir-stat-lbl">{{ $directoryStats['companies'] === 1 ? 'Azienda' : 'Aziende' }} attive</span>
            </div>
            @if($directoryStats['sectors'] > 0)
            <div class="dir-stat">
                <span class="dir-stat-val">{{ $directoryStats['sectors'] }}</span>
                <span class="dir-stat-lbl">Settori</span>
            </div>
            @endif
            <div class="dir-stat">
                <span class="dir-stat-val">{{ $directoryStats['listings'] }}</span>
                <span class="dir-stat-lbl">Prodotti disponibili</span>
            </div>
        </div>
    </div>

    {{-- Vista Lista (griglia esistente) --}}
    <div id="view-list" class="dir-view">
    @if ($companies->count() === 0)
        <div class="empty-state">
            <strong>Nessuna azienda trovata.</strong>
            <p>Prova a cambiare i filtri di ricerca.</p>
        </div>
    @else
        <div class="dir-grid">
            @foreach ($companies as $entry)
                @php
                    $company      = $entry['company'];
                    $listings     = $entry['listings_count'];
                    $anns         = $entry['announcements_count'];
                    $bizAccount   = $entry['biz_account'] ?? null;
                    $allowedKyPct = $entry['allowed_ky_pct'] ?? [];
                    $isInDebit    = $entry['is_in_debit'] ?? false;
                    $isAtCeiling  = $entry['is_at_ceiling'] ?? false;
                    $effectiveKyPct = $entry['effective_ky_pct'] ?? null;
                    $plan         = $company->plan;
                    $cardStyle    = $plan?->card_style ?? 'simple';

                    // Avatar letter (usato come fallback quando manca il logo)
                    preg_match('/[A-Za-z\xC0-\xD6\xD8-\xF6\xF8-\xFF]/u', $company->name, $avatarMatch);
                    $avatarChar = strtoupper($avatarMatch[0] ?? '#');

                    // Cover gradient palette per lettera (usata come sfondo del logo quando manca l'immagine)
                    $palettes = [
                        'A'=>'#1a56a0,#0b2d5c','B'=>'#1a6b50,#0b3324','C'=>'#6b1a1a,#3c0a0a',
                        'D'=>'#1a4d6b,#0b2233','E'=>'#5a1a6b,#2e0b3a','F'=>'#6b4a1a,#3a2608',
                        'G'=>'#1a6b6b,#0b3838','H'=>'#1a2e6b,#0b173a','I'=>'#6b1a4a,#3a0b27',
                        'J'=>'#2e6b1a,#163a0b','K'=>'#174d87,#071d35','L'=>'#6b3a1a,#3a1e0b',
                        'M'=>'#1a1a6b,#0b0b3a','N'=>'#1a6b2e,#0b3816','O'=>'#6b6b1a,#3a3a0b',
                        'P'=>'#1a5050,#0b2c2c','Q'=>'#501a50,#2c0b2c','R'=>'#1a3850,#0b1d2c',
                        'S'=>'#3a1a6b,#1e0b3a','T'=>'#1a6b3a,#0b3820','U'=>'#6b1a2e,#3a0b18',
                        'V'=>'#0e5c3a,#052a1a','W'=>'#3a501a,#1e2c0b','X'=>'#501a1a,#2c0b0b',
                        'Y'=>'#1a506b,#0b2c3a','Z'=>'#6b501a,#3a2c0b',
                    ];
                    [$c1, $c2] = explode(',', $palettes[$avatarChar] ?? '#174d87,#071d35');

                    // Sector icon
                    $sectorIconMap = [
                        'ristor'=>'🍽','food'=>'🍽','cucina'=>'🍽','ristorante'=>'🍽',
                        'bar '=>'🍺','pub'=>'🍺','pizz'=>'🍕',
                        'cafe'=>'☕','caffe'=>'☕','pasticc'=>'🧁',
                        'alloggio'=>'🛏','hotel'=>'🏨','b&b'=>'🛏','bed'=>'🛏','hostel'=>'🛏','affittacamere'=>'🛏','guesthouse'=>'🛏',
                        'turismo'=>'✈','viaggio'=>'✈','tour'=>'✈','vacanze'=>'✈',
                        'tecnolog'=>'💻','softw'=>'💻','digital'=>'💻','inform'=>'💻','web'=>'💻','it '=>'💻',
                        'salute'=>'❤️','medic'=>'🏥','farmac'=>'💊','benessere'=>'💆','estet'=>'💆',
                        'sport'=>'⚽','palestra'=>'🏋','fitness'=>'🏋',
                        'moda'=>'👗','abbigliamento'=>'👗','tessile'=>'🧵',
                        'immobil'=>'🏠','edilizia'=>'🏗','costruzion'=>'🏗',
                        'agricol'=>'🌿','ortofrutt'=>'🌿',
                        'vino'=>'🍷','cantina'=>'🍷','enoteca'=>'🍷',
                        'acquar'=>'🐠',
                        'trasport'=>'🚚','logistic'=>'🚚','corriere'=>'🚚',
                        'consulent'=>'📊','finanz'=>'💰','assicur'=>'📋','contabilit'=>'📋',
                        'formazione'=>'📚','istruzione'=>'📚','scuola'=>'📚',
                        'artigian'=>'🔨','manifattur'=>'⚙','meccanic'=>'⚙',
                        'energia'=>'⚡','solare'=>'☀','ambiente'=>'♻','ricicl'=>'♻',
                        'commercio'=>'🛒','negozio'=>'🛒','retail'=>'🛒',
                        'servizi'=>'🔧','manutenzione'=>'🔧','pulizie'=>'🧹',
                    ];
                    $sectorLower = strtolower($company->sector ?? '');
                    $sectorIcon = '🏢';
                    foreach ($sectorIconMap as $k => $ico) {
                        if (str_contains($sectorLower, $k)) { $sectorIcon = $ico; break; }
                    }
                @endphp

                @if($cardStyle === 'simple')
                {{-- ═══ SIMPLE CARD (piani senza logo/vetrina, es. Anagrafica) ═══ --}}
                <article class="dir-card dir-card--simple"
                    @if($company->hasCoordinates()) data-lat="{{ $company->latitude }}" data-lng="{{ $company->longitude }}" @endif>
                    <span class="dir-distance-badge"></span>
                    <div class="dir-body">
                        <div class="dir-simple-top">
                            <div style="min-width:0">
                                <h3 class="dir-simple-name">{{ $company->name }}</h3>
                                @if($company->sector)
                                    <div class="dir-simple-sector">{{ $company->sector }}</div>
                                @endif
                            </div>
                            <div class="dir-cat-icon" title="{{ $company->sector }}">{{ $sectorIcon }}</div>
                        </div>

                        <div class="dir-contacts">
                            @if($company->website)
                            <div class="dir-contact">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                <a href="{{ $company->website }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($company->website, '/')) }}</a>
                            </div>
                            @endif
                            @if($company->email)
                            <div class="dir-contact">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                <span>{{ $company->email }}</span>
                            </div>
                            @endif
                            @if($company->phone)
                            <div class="dir-contact">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.39 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.82a16 16 0 0 0 6 6l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.95 16.92z"/></svg>
                                <span>{{ $company->phone }}</span>
                            </div>
                            @endif
                        </div>
                    </div>
                    <div class="dir-footer" style="flex-wrap:wrap;gap:6px;">
                        @if($bizAccount && ($directoryMode ?? '') === 'portal')
                            @if($isInDebit)
                                <span class="ky-badge ky-badge--debit" title="Questa azienda ha saldo negativo: accetta solo 100% Kmoney">⚡ 100% Kmoney</span>
                            @elseif($isAtCeiling)
                                <span class="ky-badge ky-badge--ceil" title="Saldo al massimale: non può ricevere KY al momento">⛔ Al massimale</span>
                            @elseif($effectiveKyPct === 100)
                                <span class="ky-badge ky-badge--gold" title="Questa azienda accetta pagamenti al 100% in Kmoney">★ 100% Kmoney</span>
                            @elseif($effectiveKyPct !== null && $effectiveKyPct > 0)
                                <span class="ky-badge ky-badge--mix" title="Questa azienda accetta pagamenti in Kmoney fino al {{ $effectiveKyPct }}% del prezzo">✓ Kmoney {{ $effectiveKyPct }}%</span>
                            @endif
                        @endif
                        @if($listings > 0)
                            <a href="{{ route('portal.shop') }}?company={{ $company->id }}" class="dir-btn dir-btn-ghost">🛍</a>
                        @endif
                        @if($bizAccount && ($directoryMode ?? '') === 'portal' && !$isAtCeiling)
                            <a href="{{ route('portal.invia') }}?to={{ $bizAccount->id }}" class="dir-btn dir-btn-primary">💸 Paga</a>
                        @else
                            <a href="{{ route('portal.companies.show', $company->slug) }}" class="dir-btn dir-btn-primary">
                                Vedi →
                            </a>
                        @endif
                        @if(($directoryMode ?? '') === 'admin')
                            <a href="{{ route('admin.companies.show', $company) }}" class="dir-btn dir-btn-ghost">⚙</a>
                        @endif
                    </div>
                </article>

                @else
                {{-- ═══ RICH / COMPACT CARD (piani con logo — es. Ecommerce, Vetrina, Biglietto) ═══
                     Grafica piatta con logo inline (non su banner), coerente con lo stile
                     "scheda contatti" richiesto: nome, settore/città, contatti con icone,
                     badge del piano in alto a destra. ═══ --}}
                <article class="dir-card dir-card--{{ $cardStyle }}"
                    @if($company->hasCoordinates()) data-lat="{{ $company->latitude }}" data-lng="{{ $company->longitude }}" @endif>
                    <span class="dir-distance-badge"></span>
                    @if($plan)
                        <div class="dir-plan-accent" style="background:{{ $plan->effective_badge_color }};"></div>
                        <span class="dir-plan-badge" style="background:{{ $plan->effective_badge_color }};">{{ $plan->name }}</span>
                    @endif

                    <div class="dir-card-header">
                        <div class="dir-logo" style="--dir-c1:{{ $c1 }};--dir-c2:{{ $c2 }};">
                            @if($company->logo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo_path) }}" alt="{{ $company->name }}">
                            @else
                                {{ $avatarChar }}
                            @endif
                        </div>
                        <div style="min-width:0;flex:1;">
                            <h3 class="dir-company-name">{{ $company->name }}</h3>
                            @if($company->sector || $company->city)
                                <div class="dir-sector-label">
                                    {{ $company->sector }}{{ ($company->sector && $company->city) ? ' · ' : '' }}{{ $company->city }}
                                </div>
                            @endif
                            @if($cardStyle === 'rich' && $company->tagline)
                                <div class="dir-tagline">{{ Str::limit($company->tagline, 70) }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="dir-contacts">
                        @if($company->website)
                        <div class="dir-contact">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <a href="{{ $company->website }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($company->website, '/')) }}</a>
                        </div>
                        @endif
                        @if($company->email)
                        <div class="dir-contact">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <span>{{ $company->email }}</span>
                        </div>
                        @endif
                        @if($company->phone)
                        <div class="dir-contact">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.39 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.82a16 16 0 0 0 6 6l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.95 16.92z"/></svg>
                            <span>{{ $company->phone }}</span>
                        </div>
                        @endif
                        @if($cardStyle === 'rich' && $company->sector)
                        <div class="dir-contact">
                            <span style="opacity:.6;">{{ $sectorIcon }}</span>
                            <span>{{ $company->sector }}</span>
                        </div>
                        @endif
                    </div>

                    @if($cardStyle === 'rich' && ($plan?->can_sell_products || $anns > 0))
                    <div class="dir-pills">
                        @if($plan?->can_sell_products)
                        <span class="dir-pill {{ $listings > 0 ? 'active-shop' : '' }}">
                            @if($listings > 0)<span class="dir-pill-dot"></span>@endif
                            {{ $listings }} {{ $listings === 1 ? 'prodotto' : 'prodotti' }}
                        </span>
                        @endif
                        <span class="dir-pill {{ $anns > 0 ? 'active-ann' : '' }}">
                            @if($anns > 0)<span class="dir-pill-dot"></span>@endif
                            {{ $anns }} {{ $anns === 1 ? 'annuncio' : 'annunci' }}
                        </span>
                    </div>
                    @endif

                    <div class="dir-footer" style="flex-wrap:wrap;gap:6px;">
                        @if($bizAccount && ($directoryMode ?? '') === 'portal')
                            @if($isInDebit)
                                <span class="ky-badge ky-badge--debit" title="Accetta solo 100% Kmoney — ha bisogno di vendere">⚡ 100%</span>
                            @elseif($isAtCeiling)
                                <span class="ky-badge ky-badge--ceil" title="Saldo al massimale">⛔</span>
                            @elseif($effectiveKyPct === 100)
                                <span class="ky-badge ky-badge--gold" title="Questa azienda accetta pagamenti al 100% in Kmoney">★ 100%</span>
                            @elseif($effectiveKyPct !== null && $effectiveKyPct > 0)
                                <span class="ky-badge ky-badge--mix" title="Kmoney fino al {{ $effectiveKyPct }}%">✓ {{ $effectiveKyPct }}%</span>
                            @endif
                        @endif
                        @if($listings > 0)
                            <a href="{{ route('portal.shop') }}?company={{ $company->id }}"
                               class="dir-btn dir-btn-ghost">🛍</a>
                        @endif
                        <a href="{{ route('portal.companies.show', $company->slug) }}"
                           class="dir-btn dir-btn-ghost">Profilo</a>
                        @if($bizAccount && ($directoryMode ?? '') === 'portal' && !$isAtCeiling)
                            <a href="{{ route('portal.invia') }}?to={{ $bizAccount->id }}"
                               class="dir-btn dir-btn-primary">💸 Paga</a>
                        @endif
                        @if(($directoryMode ?? '') === 'admin')
                            <a href="{{ route('admin.companies.show', $company) }}" class="dir-btn dir-btn-ghost">⚙</a>
                        @endif
                    </div>

                </article>
                @endif

            @endforeach
        </div>

        <div class="dir-pagination">
            {{ $companies->links() }}
        </div>
    @endif
    </div>

    {{-- Vista Mappa: pin + sidebar (stile "punti convenzionati"), dati non
         paginati/randomizzati (a differenza della Lista sopra) --}}
    <div id="view-map" class="dir-view dir-view--hidden">
        @if($mapCompanies->isEmpty())
            <div class="empty-state">
                <strong>Nessuna azienda geolocalizzata al momento.</strong>
                <p>Le aziende che aggiungono un indirizzo nel proprio profilo compariranno qui come pin sulla mappa.</p>
            </div>
        @else
            <div class="dir-map-layout">
                <div class="dir-map-sidebar" id="map-sidebar">
                    <div id="map-sidebar-list">
                        @foreach($mapCompanies as $mc)
                            <div class="dir-map-sidebar-item" data-id="{{ $mc['id'] }}" data-lat="{{ $mc['lat'] }}" data-lng="{{ $mc['lng'] }}">
                                <div class="dir-map-sidebar-logo">
                                    @if($mc['logo_url'])
                                        <img src="{{ $mc['logo_url'] }}" alt="{{ $mc['name'] }}">
                                    @else
                                        {{ strtoupper(mb_substr($mc['name'], 0, 1)) }}
                                    @endif
                                </div>
                                <div style="min-width:0;flex:1;">
                                    <div class="dir-map-sidebar-name">{{ $mc['name'] }}</div>
                                    <div class="dir-map-sidebar-meta">{{ collect([$mc['sector'], $mc['city']])->filter()->implode(' · ') }}</div>
                                </div>
                                <div class="dir-map-sidebar-distance"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="dir-map" id="directory-map"></div>
            </div>
        @endif
        {{-- Js::from() esegue l'escaping sicuro per l'inserimento in una pagina HTML
             (es. neutralizza eventuali "</script>" nel nome di un'azienda) --}}
        <script type="application/json" id="dir-map-data">{{ \Illuminate\Support\Js::from($mapCompanies) }}</script>
    </div>

</div>

@push('head')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.Default.min.css">
@endpush

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/leaflet.markercluster.js"></script>
<script>
(function () {
    var companiesData = [];
    try {
        var dataEl = document.getElementById('dir-map-data');
        companiesData = dataEl ? JSON.parse(dataEl.textContent || '[]') : [];
    } catch (e) {
        companiesData = [];
    }

    var map = null;
    var markersLayer = null;
    var markersById = {};
    var userMarker = null;
    var userPos = null;

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function kyBadgeHtml(c) {
        if (c.is_in_debit) {
            return '<span class="ky-badge ky-badge--debit">⚡ 100% Kmoney</span>';
        }
        if (c.is_at_ceiling) {
            return '<span class="ky-badge ky-badge--ceil">⛔ Al massimale</span>';
        }
        if (c.effective_ky_pct === 100) {
            return '<span class="ky-badge ky-badge--gold">★ 100% Kmoney</span>';
        }
        if (c.effective_ky_pct !== null && c.effective_ky_pct > 0) {
            return '<span class="ky-badge ky-badge--mix">✓ Kmoney ' + c.effective_ky_pct + '%</span>';
        }
        return '';
    }

    function popupHtml(c) {
        var meta = [c.sector, c.city].filter(Boolean).join(' · ');
        var html = '<div class="dir-map-popup">';
        html += '<strong>' + escapeHtml(c.name) + '</strong>';
        if (meta) { html += '<div class="dir-map-popup-meta">' + escapeHtml(meta) + '</div>'; }
        html += kyBadgeHtml(c);
        html += '<div class="dir-map-popup-actions">';
        html += '<a href="' + c.profile_url + '" class="dir-map-popup-btn dir-map-popup-btn--ghost">Profilo</a>';
        if (c.pay_url) {
            html += '<a href="' + c.pay_url + '" class="dir-map-popup-btn dir-map-popup-btn--primary">💸 Paga</a>';
        }
        html += '</div></div>';
        return html;
    }

    function initMap() {
        if (map || typeof L === 'undefined' || companiesData.length === 0) {
            return;
        }

        map = L.map('directory-map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
        }).addTo(map);

        markersLayer = (typeof L.markerClusterGroup === 'function') ? L.markerClusterGroup() : L.layerGroup();

        companiesData.forEach(function (c) {
            var marker = L.marker([c.lat, c.lng]).bindPopup(popupHtml(c));
            markersById[c.id] = marker;
            markersLayer.addLayer(marker);
        });

        map.addLayer(markersLayer);

        var bounds = L.latLngBounds(companiesData.map(function (c) { return [c.lat, c.lng]; }));
        map.fitBounds(bounds.pad(0.15));
    }

    function haversineKm(lat1, lng1, lat2, lng2) {
        var R = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
            * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function formatKm(km) {
        return (km < 10 ? km.toFixed(1) : Math.round(km)) + ' km';
    }

    function applyDistances() {
        if (!userPos) { return; }

        var list = document.getElementById('map-sidebar-list');
        if (list) {
            Array.prototype.forEach.call(list.querySelectorAll('.dir-map-sidebar-item'), function (el) {
                var lat = parseFloat(el.dataset.lat);
                var lng = parseFloat(el.dataset.lng);
                var km = haversineKm(userPos.lat, userPos.lng, lat, lng);
                var badge = el.querySelector('.dir-map-sidebar-distance');
                if (badge) { badge.textContent = formatKm(km); }
                el.dataset.distanceKm = km;
            });
            var items = Array.prototype.slice.call(list.children);
            items.sort(function (a, b) { return parseFloat(a.dataset.distanceKm) - parseFloat(b.dataset.distanceKm); });
            items.forEach(function (el) { list.appendChild(el); });
        }

        // Badge distanza anche sulle card della vista Lista (senza riordino:
        // la lista resta paginata/randomizzata lato server).
        Array.prototype.forEach.call(document.querySelectorAll('#view-list .dir-card[data-lat]'), function (el) {
            var lat = parseFloat(el.dataset.lat);
            var lng = parseFloat(el.dataset.lng);
            if (isNaN(lat) || isNaN(lng)) { return; }
            var km = haversineKm(userPos.lat, userPos.lng, lat, lng);
            var badge = el.querySelector('.dir-distance-badge');
            if (badge) {
                badge.textContent = '📍 ' + formatKm(km);
                badge.style.display = 'inline-flex';
            }
        });

        if (map) {
            if (userMarker) { map.removeLayer(userMarker); }
            userMarker = L.circleMarker([userPos.lat, userPos.lng], {
                radius: 8, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1
            }).bindPopup('La tua posizione').addTo(map);
        }
    }

    function locateMe() {
        var status = document.getElementById('dir-locate-status');
        var btn = document.getElementById('dir-locate-btn');

        if (!('geolocation' in navigator)) {
            if (status) { status.textContent = 'Geolocalizzazione non supportata da questo browser.'; status.classList.add('is-error'); }
            return;
        }

        if (btn) { btn.classList.add('is-active'); btn.textContent = '📍 Localizzazione…'; }
        if (status) { status.textContent = ''; status.classList.remove('is-error'); }

        navigator.geolocation.getCurrentPosition(function (pos) {
            userPos = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            if (btn) { btn.textContent = '📍 Vicino a te ✓'; }
            applyDistances();
        }, function () {
            if (btn) { btn.classList.remove('is-active'); btn.textContent = '📍 Vicino a te'; }
            if (status) { status.textContent = 'Non è stato possibile ottenere la tua posizione.'; status.classList.add('is-error'); }
        }, { timeout: 8000, maximumAge: 300000 });
    }

    function showView(view) {
        var listEl = document.getElementById('view-list');
        var mapEl = document.getElementById('view-map');
        var tabList = document.getElementById('dir-tab-list');
        var tabMap = document.getElementById('dir-tab-map');
        if (!listEl || !mapEl || !tabList || !tabMap) { return; }

        if (view === 'map') {
            listEl.classList.add('dir-view--hidden');
            mapEl.classList.remove('dir-view--hidden');
            tabList.classList.remove('is-active');
            tabMap.classList.add('is-active');
            initMap();
            setTimeout(function () { if (map) { map.invalidateSize(); } }, 60);
        } else {
            mapEl.classList.add('dir-view--hidden');
            listEl.classList.remove('dir-view--hidden');
            tabMap.classList.remove('is-active');
            tabList.classList.add('is-active');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var tabList = document.getElementById('dir-tab-list');
        var tabMap = document.getElementById('dir-tab-map');
        var locateBtn = document.getElementById('dir-locate-btn');
        var sidebarList = document.getElementById('map-sidebar-list');

        if (tabList) { tabList.addEventListener('click', function () { showView('list'); }); }
        if (tabMap) { tabMap.addEventListener('click', function () { showView('map'); }); }
        if (locateBtn) { locateBtn.addEventListener('click', locateMe); }

        if (sidebarList) {
            sidebarList.addEventListener('click', function (e) {
                var item = e.target.closest('.dir-map-sidebar-item');
                if (!item || !map) { return; }
                var marker = markersById[item.dataset.id];
                if (marker) {
                    map.setView(marker.getLatLng(), 16);
                    marker.openPopup();
                }
                Array.prototype.forEach.call(document.querySelectorAll('.dir-map-sidebar-item.is-active'), function (el) {
                    el.classList.remove('is-active');
                });
                item.classList.add('is-active');
            });
        }
    });
})();
</script>
@endpush
@endsection
