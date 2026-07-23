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

    /* ── BASE CARD ── */
    .dir-card {
        border-radius:16px;
        border:1px solid var(--line);
        background:#fff;
        box-shadow:0 1px 4px rgba(0,0,0,.07);
        overflow:hidden;
        display:flex; flex-direction:column;
        transition:box-shadow .2s, transform .18s;
    }
    .dir-card:hover {
        box-shadow:0 6px 20px rgba(0,0,0,.12);
        transform:translateY(-2px);
    }

    /* ── RICH CARD cover ── */
    .dir-card--rich .dir-cover {
        height:150px;
        position:relative;
        overflow:hidden;
    }
    /* decorative circle inside cover */
    .dir-cover-deco {
        position:absolute;
        top:-40px; right:-40px;
        width:160px; height:160px;
        border-radius:50%;
        background:radial-gradient(circle,rgba(255,255,255,.12),transparent 70%);
        pointer-events:none;
    }
    .dir-cover-deco2 {
        position:absolute;
        bottom:-20px; left:50%;
        transform:translateX(-50%);
        width:200px; height:80px;
        background:linear-gradient(to bottom,transparent,rgba(0,0,0,.25));
        pointer-events:none;
    }

    /* avatar ring — sits between cover and body */
    .dir-logo-ring {
        position:relative; height:0; z-index:3;
        /* no height so body flows normally */
    }
    .dir-logo {
        position:absolute;
        top:-28px; left:16px;
        width:56px; height:56px;
        border-radius:50%;
        background:#fff;
        border:3px solid #fff;
        box-shadow:0 2px 10px rgba(0,0,0,.18);
        display:flex; align-items:center; justify-content:center;
        font-size:22px; font-weight:900; color:#1a3a5c;
        letter-spacing:-.02em;
    }

    /* body for rich */
    .dir-card--rich .dir-body {
        padding:36px 16px 14px;
        flex:1;
        display:flex; flex-direction:column; gap:10px;
    }
    .dir-company-name {
        font-size:16px; font-weight:800; color:var(--ink);
        margin:0; line-height:1.25; word-break:break-word;
    }
    .dir-sector-label {
        font-size:11.5px; font-weight:600;
        color:var(--ink-muted);
        text-transform:uppercase; letter-spacing:.05em;
    }

    /* Contact list */
    .dir-contacts { display:flex; flex-direction:column; gap:5px; }
    .dir-contact {
        display:flex; align-items:center; gap:8px;
        font-size:12.5px; color:var(--ink-soft);
        overflow:hidden;
    }
    .dir-contact svg { flex-shrink:0; opacity:.55; }
    .dir-contact a, .dir-contact span {
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        text-decoration:none; color:inherit;
    }
    .dir-contact a:hover { color:var(--primary); text-decoration:underline; }

    /* Activity pills */
    .dir-pills { display:flex; gap:6px; flex-wrap:wrap; margin-top:2px; }
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
    }
    .dir-btn {
        flex:1; display:inline-flex; align-items:center; justify-content:center;
        padding:8px 10px; border-radius:9px;
        font-size:12.5px; font-weight:700; text-decoration:none; white-space:nowrap;
        transition:background .15s, border-color .15s;
        min-height:36px;
    }
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
</style>

<div class="dir-main">

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

    {{-- Grid --}}
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

                    // Avatar letter
                    preg_match('/[A-Za-z\xC0-\xD6\xD8-\xF6\xF8-\xFF]/u', $company->name, $avatarMatch);
                    $avatarChar = strtoupper($avatarMatch[0] ?? '#');

                    // Cover gradient palette per lettera
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
                    $logoColor = $c1; // for logo letter color tint

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

                    $isSimple = $company->subscription_plan === 'anagrafica';
                    $cardClass = $isSimple ? 'dir-card--simple' : 'dir-card--rich';
                @endphp

                @if($isSimple)
                {{-- ═══ SIMPLE CARD (anagrafica) ═══ --}}
                <article class="dir-card dir-card--simple">
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
                        {{-- Badge KY --}}
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
                {{-- ═══ RICH CARD (ecommerce / vetrina / biglietto) ═══ --}}
                <article class="dir-card dir-card--rich">

                    {{-- Cover --}}
                    <div class="dir-cover" style="{{ $company->banner_path ? 'background-image:url('.\Illuminate\Support\Facades\Storage::disk('public')->url($company->banner_path).');background-size:cover;background-position:center;' : 'background:linear-gradient(150deg,'.$c1.' 0%,'.$c2.' 100%);' }}">
                        <div class="dir-cover-deco"></div>
                        <div class="dir-cover-deco2"></div>
                    </div>

                    {{-- Logo ring --}}
                    <div class="dir-logo-ring">
                        <div class="dir-logo" style="{{ $company->logo_path ? 'padding:0;overflow:hidden;' : '' }}">
                            @if($company->logo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo_path) }}"
                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;" alt="{{ $company->name }}">
                            @else
                                {{ $avatarChar }}
                            @endif
                        </div>
                    </div>

                    {{-- Body --}}
                    <div class="dir-body">
                        <div>
                            <h3 class="dir-company-name">{{ $company->name }}</h3>
                            @if($company->sector || $company->city)
                                <div class="dir-sector-label">
                                    {{ $company->sector }}{{ ($company->sector && $company->city) ? ' · ' : '' }}{{ $company->city }}
                                </div>
                            @endif
                            @if($company->tagline)
                                <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;font-style:italic;line-height:1.4;">{{ Str::limit($company->tagline, 80) }}</div>
                            @endif
                        </div>

                        <div class="dir-contacts">
                            @if($company->website)
                            <div class="dir-contact">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                <a href="{{ $company->website }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($company->website, '/')) }}</a>
                            </div>
                            @endif
                            @if($company->email)
                            <div class="dir-contact">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                <span>{{ $company->email }}</span>
                            </div>
                            @endif
                            @if($company->phone)
                            <div class="dir-contact">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.39 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.82a16 16 0 0 0 6 6l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.95 16.92z"/></svg>
                                <span>{{ $company->phone }}</span>
                            </div>
                            @endif
                        </div>

                        <div class="dir-pills">
                            <span class="dir-pill {{ $listings > 0 ? 'active-shop' : '' }}">
                                @if($listings > 0)<span class="dir-pill-dot"></span>@endif
                                {{ $listings }} {{ $listings === 1 ? 'prodotto' : 'prodotti' }}
                            </span>
                            <span class="dir-pill {{ $anns > 0 ? 'active-ann' : '' }}">
                                @if($anns > 0)<span class="dir-pill-dot"></span>@endif
                                {{ $anns }} {{ $anns === 1 ? 'annuncio' : 'annunci' }}
                            </span>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="dir-footer" style="flex-wrap:wrap;gap:6px;">
                        {{-- Badge KY --}}
                        @if($bizAccount && ($directoryMode ?? '') === 'portal')
                            @if($isInDebit)
                                <span class="ky-badge ky-badge--debit" title="Accetta solo 100% Kmoney — ha bisogno di vendere">⚡ 100% Kmoney</span>
                            @elseif($isAtCeiling)
                                <span class="ky-badge ky-badge--ceil" title="Saldo al massimale">⛔ Al massimale</span>
                            @elseif($effectiveKyPct === 100)
                                <span class="ky-badge ky-badge--gold" title="Questa azienda accetta pagamenti al 100% in Kmoney">★ 100% Kmoney</span>
                            @elseif($effectiveKyPct !== null && $effectiveKyPct > 0)
                                <span class="ky-badge ky-badge--mix" title="Questa azienda accetta pagamenti in Kmoney fino al {{ $effectiveKyPct }}% del prezzo">✓ Kmoney {{ $effectiveKyPct }}%</span>
                            @endif
                        @endif
                        @if($listings > 0)
                            <a href="{{ route('portal.shop') }}?company={{ $company->id }}"
                               class="dir-btn dir-btn-ghost">🛍 Shop</a>
                        @endif
                        <a href="{{ route('portal.companies.show', $company->slug) }}"
                           class="dir-btn dir-btn-ghost">Profilo</a>
                        @if($bizAccount && ($directoryMode ?? '') === 'portal' && !$isAtCeiling)
                            <a href="{{ route('portal.invia') }}?to={{ $bizAccount->id }}"
                               class="dir-btn dir-btn-primary">💸 Paga</a>
                        @endif
                        @if(($directoryMode ?? '') === 'admin')
                            <a href="{{ route('admin.companies.show', $company) }}"
                               class="dir-btn dir-btn-ghost">⚙</a>
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
@endsection
