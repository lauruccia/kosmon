<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Apri un conto KMoney</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:      #0c1518;
            --bg2:     #111f25;
            --slate:   #3d5566;
            --slate2:  #4d6878;
            --green:   #4d7a52;
            --green2:  #6a9a6f;
            --white:   #ffffff;
            --gray3:   #7a9098;
            --line:    rgba(255,255,255,0.10);
            --glass:   rgba(255,255,255,0.055);
            --grad:    linear-gradient(135deg,#3d5566,#4d7a52);
            --grad-l:  linear-gradient(135deg,#6a8898,#6a9a6f);
            --ink:     #e8f0f4;
            --muted:   #7a9098;
            --error-bg:#2a1018;
            --error:   #f87171;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100vh;
            display: flex; flex-direction: column;
        }

        /* ── TOP BAR ── */
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px clamp(20px,5vw,64px);
            border-bottom: 1px solid var(--line);
            background: rgba(12,21,24,0.9); backdrop-filter: blur(12px);
            position: sticky; top: 0; z-index: 10;
        }
        .topbar-logo {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Playfair Display', serif;
            font-size: 20px; font-weight: 800; color: var(--white);
            text-decoration: none;
        }
        .topbar-logo svg { width: 30px; height: 30px; }
        .topbar-link {
            font-size: 13px; font-weight: 600;
            color: rgba(255,255,255,0.65);
            border: 1px solid var(--line);
            padding: 8px 18px; border-radius: 50px;
            text-decoration: none; transition: all .2s;
        }
        .topbar-link:hover { background: var(--glass); color: var(--white); }

        /* ── PAGE LAYOUT ── */
        .page {
            flex: 1;
            display: grid; grid-template-columns: 1fr 420px;
            min-height: calc(100vh - 65px);
        }

        /* ── LEFT: FORM PANEL ── */
        .form-panel {
            padding: clamp(32px,5vw,64px) clamp(24px,5vw,72px);
            border-right: 1px solid var(--line);
            overflow-y: auto;
        }
        .form-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(28px,4vw,42px);
            font-weight: 800; line-height: 1.15;
            margin-bottom: 8px;
        }
        .form-title .grad {
            background: var(--grad-l);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .form-sub {
            font-size: 15px; color: var(--muted); line-height: 1.65;
            margin-bottom: 36px;
        }

        /* ── ERROR ── */
        .err {
            display: flex; align-items: flex-start; gap: 12px;
            background: var(--error-bg); border: 1px solid rgba(248,113,113,0.3);
            border-radius: 14px; padding: 16px 18px;
            color: var(--error); font-weight: 600; font-size: 14px;
            margin-bottom: 28px;
        }
        .err-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

        /* ── TYPE SWITCH ── */
        .type-switch {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 14px; margin-bottom: 32px;
        }
        .type-card {
            position: relative; cursor: pointer;
            border: 1.5px solid var(--line);
            border-radius: 18px; padding: 20px;
            background: var(--glass);
            transition: all .25s; user-select: none;
        }
        .type-card input[type="radio"] {
            position: absolute; opacity: 0; width: 0; height: 0;
        }
        .type-card-inner { display: flex; gap: 14px; align-items: flex-start; }
        .type-icon {
            width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
            background: var(--glass); border: 1px solid var(--line);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; transition: all .25s;
        }
        .type-label { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
        .type-desc { font-size: 12px; color: var(--muted); line-height: 1.55; }
        .type-dot {
            position: absolute; top: 16px; right: 16px;
            width: 18px; height: 18px; border-radius: 50%;
            border: 2px solid var(--line);
            background: transparent; transition: all .25s;
        }
        /* SELECTED state */
        .type-card.selected {
            border-color: var(--green);
            background: rgba(77,122,82,0.08);
            box-shadow: 0 0 0 1px rgba(77,122,82,0.3), 0 8px 24px rgba(0,0,0,0.2);
        }
        .type-card.selected .type-icon {
            background: rgba(77,122,82,0.2); border-color: rgba(77,122,82,0.3);
        }
        .type-card.selected .type-dot {
            background: var(--grad); border-color: var(--green);
            box-shadow: 0 0 8px rgba(77,122,82,0.5);
        }

        /* ── FORM FIELDS ── */
        .section-label {
            font-size: 11px; font-weight: 700; letter-spacing: 1.5px;
            text-transform: uppercase; color: var(--green2);
            margin-bottom: 16px; padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
        }
        .field-group { display: flex; flex-direction: column; gap: 18px; margin-bottom: 28px; }
        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .field label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--slate3, #6a8898); margin-bottom: 7px;
            letter-spacing: .3px;
        }
        .field input, .field select {
            width: 100%; height: 52px;
            padding: 0 16px;
            background: var(--glass); border: 1.5px solid var(--line);
            border-radius: 14px; color: var(--white);
            font-size: 15px; font-family: 'Inter', sans-serif;
            outline: none; transition: border-color .2s, box-shadow .2s;
            -webkit-appearance: none;
        }
        .field input::placeholder { color: rgba(122,144,152,0.6); }
        .field input:focus, .field select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(77,122,82,0.18);
        }
        .field input.err-input { border-color: var(--error); }
        .field select option { background: #1a2a30; color: var(--white); }

        /* ── COMPANY SECTION (toggled) ── */
        #company-fields {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            transition: max-height .4s cubic-bezier(.4,0,.2,1), opacity .3s ease, margin .3s ease;
            margin-bottom: 0;
        }
        #company-fields.visible {
            max-height: 600px;
            opacity: 1;
            margin-bottom: 28px;
        }
        .company-inner {
            padding: 24px;
            border-radius: 18px;
            border: 1px solid rgba(77,122,82,0.25);
            background: rgba(77,122,82,0.05);
        }

        /* ── SUBMIT ROW ── */
        .submit-row { display: flex; gap: 14px; align-items: center; margin-top: 8px; }
        .btn-submit {
            flex: 1; height: 54px; border: none; border-radius: 16px;
            background: var(--grad);
            color: var(--white); font-size: 16px; font-weight: 700;
            cursor: pointer; transition: all .25s;
            box-shadow: 0 8px 24px rgba(61,85,102,0.35);
            letter-spacing: .3px;
        }
        .btn-submit:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 12px 32px rgba(77,122,82,0.4); }
        .btn-ghost {
            height: 54px; padding: 0 22px; border-radius: 16px;
            border: 1.5px solid var(--line);
            background: transparent; color: rgba(255,255,255,0.7);
            font-size: 14px; font-weight: 600;
            text-decoration: none; display: inline-flex; align-items: center;
            transition: all .2s; white-space: nowrap;
        }
        .btn-ghost:hover { background: var(--glass); color: var(--white); }
        .privacy-note {
            margin-top: 18px; font-size: 12px; color: var(--muted);
            line-height: 1.6;
        }
        .privacy-note a { color: var(--green2); }

        /* ── RIGHT: INFO PANEL ── */
        .info-panel {
            padding: clamp(32px,4vw,56px) 36px;
            background: var(--bg2);
            display: flex; flex-direction: column; gap: 24px;
            position: sticky; top: 65px;
            height: calc(100vh - 65px); overflow-y: auto;
        }
        .info-block {
            border: 1px solid var(--line); border-radius: 20px;
            padding: 24px; background: var(--glass);
        }
        .info-block-title {
            font-family: 'Playfair Display', serif;
            font-size: 20px; font-weight: 800; margin-bottom: 14px;
        }
        .info-block p, .info-block li {
            font-size: 14px; color: var(--muted); line-height: 1.7;
        }
        .info-block ul { padding-left: 18px; display: flex; flex-direction: column; gap: 6px; }
        .info-block li::marker { color: var(--green2); }

        /* Stat pill */
        .stat-pills { display: flex; flex-direction: column; gap: 12px; margin-top: 4px; }
        .stat-pill {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 16px; border-radius: 14px;
            background: rgba(255,255,255,0.04); border: 1px solid var(--line);
        }
        .stat-pill-icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, rgba(61,85,102,0.3), rgba(77,122,82,0.15));
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .stat-pill-num {
            font-family: 'Playfair Display', serif;
            font-size: 20px; font-weight: 800;
            background: var(--grad-l);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .stat-pill-label { font-size: 12px; color: var(--muted); }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .page { grid-template-columns: 1fr; }
            .info-panel { position: static; height: auto; border-right: none; border-top: 1px solid var(--line); }
            .form-panel { border-right: none; }
        }
        @media (max-width: 560px) {
            .two { grid-template-columns: 1fr; }
            .type-switch { grid-template-columns: 1fr; }
            .submit-row { flex-direction: column; }
            .btn-ghost { width: 100%; justify-content: center; }
        }
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 42px !important; }
        .pw-eye {
            position: absolute; right: 11px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 4px;
            color: rgba(255,255,255,0.45); display: flex; align-items: center;
        }
        .pw-eye:hover { color: rgba(255,255,255,0.85); }
    </style>
</head>
<body>

<!-- SVG defs (brand gradient K) -->
<svg width="0" height="0" style="position:absolute">
  <defs>
    <linearGradient id="kG" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#3d5566"/>
      <stop offset="100%" stop-color="#4d7a52"/>
    </linearGradient>
  </defs>
</svg>

<!-- TOP BAR -->
<header class="topbar">
    <a href="/" class="topbar-logo">
        <svg viewBox="0 0 100 120" xmlns="http://www.w3.org/2000/svg">
            <path d="M15 10 L15 110 L35 110 L35 70 L75 110 L100 110 L55 60 L95 10 L70 10 L35 52 L35 10 Z" fill="url(#kG)"/>
        </svg>
        KMoney
    </a>
    <a href="{{ route('login') }}" class="topbar-link">Hai già un account? Accedi</a>
</header>

<!-- PAGE -->
<div class="page">

    <!-- ── FORM PANEL ── -->
    <main class="form-panel">
        <h1 class="form-title">Apri il tuo conto<br><span class="grad">KMoney</span></h1>
        <p class="form-sub">Scegli il tipo di conto, compila i dati e inizia subito a usare il circuito. La registrazione è gratuita.</p>

        @if ($errors->any())
        <div class="err">
            <span class="err-icon">⚠️</span>
            <span>{{ $errors->first() }}</span>
        </div>
        @endif

        <form method="post" action="{{ route('register.store') }}">
            @csrf

            {{-- ── TIPO CONTO ── --}}
            <div class="section-label">Tipo di conto</div>
            <div class="type-switch">
                <label class="type-card {{ old('account_holder_type', 'private') === 'private' ? 'selected' : '' }}" id="card-private">
                    <input type="radio" name="account_holder_type" value="private"
                        {{ old('account_holder_type', 'private') === 'private' ? 'checked' : '' }}>
                    <div class="type-card-inner">
                        <div class="type-icon">👤</div>
                        <div>
                            <div class="type-label">Privato</div>
                            <div class="type-desc">Conto personale per acquisti, vendite e budget famiglia.</div>
                        </div>
                    </div>
                    <div class="type-dot"></div>
                </label>
                <label class="type-card {{ old('account_holder_type') === 'company' ? 'selected' : '' }}" id="card-company">
                    <input type="radio" name="account_holder_type" value="company"
                        {{ old('account_holder_type') === 'company' ? 'checked' : '' }}>
                    <div class="type-card-inner">
                        <div class="type-icon">🏢</div>
                        <div>
                            <div class="type-label">Azienda</div>
                            <div class="type-desc">Presenza nel circuito con sottoconti per dipendenti e team.</div>
                        </div>
                    </div>
                    <div class="type-dot"></div>
                </label>
            </div>

            {{-- ── DATI PERSONALI ── --}}
            <div class="section-label">Dati referente</div>
            <div class="field-group">
                <div class="two">
                    <div class="field">
                        <label for="name">Nome e cognome</label>
                        <input id="name" name="name" type="text"
                            placeholder="Mario Rossi"
                            value="{{ old('name') }}" required>
                    </div>
                    <div class="field">
                        <label for="email">Email di accesso</label>
                        <input id="email" name="email" type="email"
                            placeholder="mario@esempio.it"
                            value="{{ old('email') }}" required>
                    </div>
                </div>
                <div class="two">
                    <div class="field">
                        <label for="phone">Telefono</label>
                        <input id="phone" name="phone" type="tel"
                            placeholder="+39 333 0000000"
                            value="{{ old('phone') }}">
                    </div>
                    <div class="field">
                        <label for="fiscal_code">Codice fiscale</label>
                        <input id="fiscal_code" name="fiscal_code" type="text"
                            placeholder="RSSMRA80A01H501Z"
                            value="{{ old('fiscal_code') }}">
                    </div>
                </div>
                <div class="two">
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password"
                            placeholder="Minimo 8 caratteri" required>
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Conferma password</label>
                        <input id="password_confirmation" name="password_confirmation"
                            type="password" placeholder="Ripeti la password" required>
                    </div>
                </div>
            </div>

            {{-- ── DATI AZIENDA (solo se "Azienda" selezionato) ── --}}
            <div id="company-fields" class="{{ old('account_holder_type') === 'company' ? 'visible' : '' }}">
                <div class="company-inner">
                    <div class="section-label" style="margin-bottom:18px;">Dati azienda</div>
                    <div class="field-group">
                        <div class="two">
                            <div class="field">
                                <label for="company_name">Ragione sociale</label>
                                <input id="company_name" name="company_name" type="text"
                                    placeholder="Kosmos S.r.l."
                                    value="{{ old('company_name') }}"
                                    {{ old('account_holder_type') === 'company' ? 'required' : '' }}>
                            </div>
                            <div class="field">
                                <label for="vat_number">Partita IVA</label>
                                <input id="vat_number" name="vat_number" type="text"
                                    placeholder="IT12345678901"
                                    value="{{ old('vat_number') }}"
                                    {{ old('account_holder_type') === 'company' ? 'required' : '' }}>
                            </div>
                        </div>
                        <div class="field">
                            <label for="company_email">Email aziendale</label>
                            <input id="company_email" name="company_email" type="email"
                                placeholder="info@kosmos.it"
                                value="{{ old('company_email') }}">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── SUBMIT ── --}}
            <div class="submit-row">
                <button class="btn-submit" type="submit">
                    Apri il conto →
                </button>
                <a class="btn-ghost" href="{{ route('login') }}">Accedi</a>
            </div>
            <p class="privacy-note">
                Registrandoti accetti i nostri <a href="/termini">Termini di servizio</a>
                e la <a href="/privacy">Privacy Policy</a>.
            </p>
        </form>
    </main>

    <!-- ── INFO PANEL ── -->
    <aside class="info-panel">
        <div class="info-block">
            <div class="info-block-title">Cosa succede subito</div>
            <ul>
                <li>Il tuo conto KMoney viene creato istantaneamente.</li>
                <li>Ricevi una email di verifica per attivarlo.</li>
                <li>Se sei un'azienda, entri nella directory interna del circuito.</li>
                <li>Puoi aggiungere sottoconti con budget e limiti di spesa.</li>
            </ul>
        </div>

        <div class="info-block">
            <div class="info-block-title">Privato vs Azienda</div>
            <p style="margin-bottom:12px;"><strong style="color:var(--white);">Privato</strong> — conto personale, gestione familiare, sottoconti per figli o delegati.</p>
            <p><strong style="color:var(--white);">Azienda</strong> — presenza nel network, acquisti e vendite nel circuito, delega budget a dipendenti o team.</p>
        </div>

        <div class="info-block">
            <div class="info-block-title">Il circuito in numeri</div>
            <div class="stat-pills">
                <div class="stat-pill">
                    <div class="stat-pill-icon">🏢</div>
                    <div>
                        <div class="stat-pill-num">6.000+</div>
                        <div class="stat-pill-label">Aziende nel circuito</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <div class="stat-pill-icon">🌍</div>
                    <div>
                        <div class="stat-pill-num">240+</div>
                        <div class="stat-pill-label">Città servite</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <div class="stat-pill-icon">⚡</div>
                    <div>
                        <div class="stat-pill-num">0</div>
                        <div class="stat-pill-label">Commissioni di transazione</div>
                    </div>
                </div>
            </div>
        </div>
    </aside>

</div>

<script>
(function () {
    const cardPrivate  = document.getElementById('card-private');
    const cardCompany  = document.getElementById('card-company');
    const radioPrivate = cardPrivate.querySelector('input');
    const radioCompany = cardCompany.querySelector('input');
    const companyBlock = document.getElementById('company-fields');
    const companyName  = document.getElementById('company_name');
    const vatNumber    = document.getElementById('vat_number');

    function applyState(isCompany) {
        // Toggle card style
        cardPrivate.classList.toggle('selected', !isCompany);
        cardCompany.classList.toggle('selected',  isCompany);

        // Toggle fields visibility (CSS transition via class)
        companyBlock.classList.toggle('visible', isCompany);

        // Toggle required on company fields
        companyName.required = isCompany;
        vatNumber.required   = isCompany;
    }

    // Click on entire card label
    cardPrivate.addEventListener('click', function () {
        radioPrivate.checked = true;
        applyState(false);
    });
    cardCompany.addEventListener('click', function () {
        radioCompany.checked = true;
        applyState(true);
    });

    // Keyboard support
    radioPrivate.addEventListener('change', () => applyState(false));
    radioCompany.addEventListener('change', () => applyState(true));

    // Init on page load (handles old() server-side state)
    applyState(radioCompany.checked);
})();
</script>
@include('partials.password-toggle')
</body>
</html>
