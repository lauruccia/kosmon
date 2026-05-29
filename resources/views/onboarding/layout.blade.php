<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? 'KMoney — Benvenuto' }}</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0b2244">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/brand/icon-192.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* ── Tokens (stessa palette del portal) ──────────────────────── */
        :root {
            --navy:         #0b2244;
            --navy-deep:    #06152a;
            --primary:      #0f52c4;
            --primary-light:#e6effc;
            --ink:          #0d1c30;
            --ink-soft:     #4a637d;
            --ink-muted:    #7a95aa;
            --bg:           #edf1f8;
            --surface:      #ffffff;
            --line:         #dde6f0;
            --success:      #065f46;
            --success-soft: #d1fae5;
            --danger:       #9f1239;
            --danger-soft:  #ffe4e6;
            --warning-soft: #fef3c7;
            --radius:       16px;
            --shadow:       0 2px 16px rgba(10,30,60,.08), 0 1px 4px rgba(10,30,60,.04);
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; background: var(--bg); font-family: "Aptos","Segoe UI",system-ui,sans-serif; color: var(--ink); font-size: 15px; line-height: 1.6; }
        a { color: inherit; text-decoration: none; }
        button, input, select, textarea { font: inherit; }

        /* ── Shell ──────────────────────────────────────────────────── */
        .ob-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0 16px 60px;
        }

        /* ── Header ──────────────────────────────────────────────────── */
        .ob-header {
            width: 100%;
            max-width: 680px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px 0 32px;
        }
        .ob-brand {
            display: flex; align-items: center; gap: 10px;
        }
        .ob-brand-mark {
            width: 40px; height: 40px; border-radius: 12px;
            background: var(--navy); display: grid; place-items: center;
        }
        .ob-brand-mark img { width: 22px; height: 30px; object-fit: contain; }
        .ob-brand-name { font-size: 20px; font-weight: 700; color: var(--navy); letter-spacing: -.01em; }
        .ob-logout { font-size: 13px; color: var(--ink-muted); }
        .ob-logout:hover { color: var(--ink); }

        /* ── Card ────────────────────────────────────────────────────── */
        .ob-card {
            width: 100%;
            max-width: 680px;
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 36px 40px;
        }
        @media (max-width: 600px) {
            .ob-card { padding: 24px 20px; }
        }

        /* ── Stepper ──────────────────────────────────────────────────── */
        .ob-stepper {
            display: flex;
            align-items: center;
            margin-bottom: 36px;
        }
        .ob-step {
            display: flex; flex-direction: column; align-items: center;
            gap: 6px; flex: 1; text-align: center;
        }
        .ob-step-dot {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700;
            transition: all .2s;
        }
        .ob-step-dot.done     { background: #dcfce7; color: #166534; }
        .ob-step-dot.active   { background: var(--primary); color: #fff; box-shadow: 0 0 0 4px #bfdbfe; }
        .ob-step-dot.pending  { background: #f1f5f9; color: var(--ink-muted); }
        .ob-step-label {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .06em; color: var(--ink-muted);
        }
        .ob-step-label.active { color: var(--primary); }
        .ob-step-connector {
            flex: 1; height: 2px; background: var(--line);
            margin: 0 6px 20px; flex-shrink: 1;
            transition: background .3s;
        }
        .ob-step-connector.done { background: #86efac; }

        /* ── Typography ─────────────────────────────────────────────── */
        .ob-eyebrow {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .1em; color: var(--primary); margin: 0 0 8px;
        }
        .ob-title {
            font-size: 24px; font-weight: 800; color: var(--navy);
            margin: 0 0 6px; letter-spacing: -.02em;
        }
        .ob-subtitle {
            font-size: 14px; color: var(--ink-soft); margin: 0 0 28px;
        }

        /* ── Form ────────────────────────────────────────────────────── */
        .ob-form-group { margin-bottom: 20px; }
        .ob-label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--ink); margin-bottom: 6px;
        }
        .ob-label span { color: #e53e3e; margin-left: 2px; }
        .ob-input, .ob-select, .ob-textarea {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid var(--line); border-radius: 10px;
            background: var(--bg); color: var(--ink);
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }
        .ob-textarea { resize: vertical; min-height: 100px; }
        .ob-input:focus, .ob-select:focus, .ob-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15,82,196,.12);
        }
        .ob-input-hint { font-size: 12px; color: var(--ink-muted); margin-top: 4px; }
        .ob-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 520px) { .ob-row { grid-template-columns: 1fr; } }

        /* ── Buttons ──────────────────────────────────────────────────── */
        .ob-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 13px 28px; border-radius: 10px;
            font-size: 15px; font-weight: 700; border: none; cursor: pointer;
            transition: opacity .15s, transform .1s;
        }
        .ob-btn:hover:not([disabled]) { opacity: .88; transform: translateY(-1px); }
        .ob-btn-primary { background: var(--primary); color: #fff; }
        .ob-btn-secondary { background: var(--bg); color: var(--ink); border: 1.5px solid var(--line); }
        .ob-btn-full { width: 100%; }

        /* ── Alerts ───────────────────────────────────────────────────── */
        .ob-alert {
            padding: 12px 16px; border-radius: 10px;
            font-size: 14px; margin-bottom: 20px; display: flex; gap: 10px; align-items: flex-start;
        }
        .ob-alert.success { background: var(--success-soft); color: var(--success); }
        .ob-alert.error   { background: var(--danger-soft); color: var(--danger); }
        .ob-alert.info    { background: #dbeafe; color: #1d4ed8; }

        /* ── Error summary ────────────────────────────────────────────── */
        .ob-errors {
            background: var(--danger-soft); border-radius: 10px;
            padding: 14px 16px; margin-bottom: 20px;
        }
        .ob-errors ul { margin: 6px 0 0; padding-left: 20px; font-size: 13px; color: var(--danger); }

        /* ── Upload area ──────────────────────────────────────────────── */
        .ob-upload-zone {
            border: 2px dashed var(--line); border-radius: 12px;
            padding: 28px 20px; text-align: center;
            background: var(--bg); cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .ob-upload-zone:hover { border-color: var(--primary); background: var(--primary-light); }
        .ob-upload-zone input[type=file] { display: none; }
        .ob-upload-icon { font-size: 32px; margin-bottom: 8px; }
        .ob-upload-label { font-size: 14px; color: var(--ink-soft); }
        .ob-upload-label strong { color: var(--primary); cursor: pointer; }
        .ob-upload-formats { font-size: 12px; color: var(--ink-muted); margin-top: 4px; }

        /* ── Doc list ─────────────────────────────────────────────────── */
        .ob-doc-list { display: grid; gap: 10px; margin-bottom: 24px; }
        .ob-doc-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px; border-radius: 10px;
            background: var(--bg); border: 1px solid var(--line);
        }
        .ob-doc-icon { font-size: 22px; flex-shrink: 0; }
        .ob-doc-name { font-size: 13px; font-weight: 600; flex: 1; }
        .ob-doc-type { font-size: 12px; color: var(--ink-muted); }
        .ob-doc-badge {
            font-size: 11px; font-weight: 700; padding: 3px 8px;
            border-radius: 20px; background: #fef3c7; color: #92400e;
        }

        /* ── Status card ──────────────────────────────────────────────── */
        .ob-status-card {
            border-radius: 14px; padding: 24px; text-align: center;
            border: 1.5px solid var(--line);
        }
        .ob-status-icon { font-size: 48px; margin-bottom: 12px; }
        .ob-status-title { font-size: 20px; font-weight: 800; margin: 0 0 6px; color: var(--navy); }
        .ob-status-text { font-size: 14px; color: var(--ink-soft); margin: 0; }
        .ob-divider { border: none; border-top: 1px solid var(--line); margin: 28px 0; }
    </style>
</head>
<body>
<div class="ob-shell">

    {{-- Header --}}
    <header class="ob-header">
        <div class="ob-brand">
            <div class="ob-brand-mark">
                <img src="/assets/brand/logo.svg" alt="KMoney" onerror="this.style.display='none'">
            </div>
            <span class="ob-brand-name">KMoney</span>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="ob-logout" style="background:none;border:none;cursor:pointer;">
                Esci →
            </button>
        </form>
    </header>

    {{-- Card principale --}}
    <main class="ob-card">
        @yield('content')
    </main>

</div>
</body>
</html>
