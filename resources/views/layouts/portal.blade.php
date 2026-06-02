<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $pageTitle ?? 'KMoney' }}</title>

    {{-- PWA --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0b2244">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="KMoney">
    <link rel="apple-touch-icon" href="/assets/brand/icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/brand/icon-192.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        /* Prevent flash of unstyled theme */
        (function () {
            var t = localStorage.getItem('km-theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        /* ============================================================
           KMoney Portal — Design System v2.0
           Hybrid Fineco (professional) + Revolut (modern)
           Light & Dark mode
           ============================================================ */

        /* ── DESIGN TOKENS – LIGHT ─────────────────────────────────── */
        :root {
            /* Navy / Brand (Fineco core) */
            --navy:          #0b2244;
            --navy-deep:     #06152a;
            --navy-mid:      #10305e;
            --navy-soft:     #1a4080;

            /* Text */
            --ink:           #0d1c30;
            --ink-soft:      #4a637d;
            --ink-muted:     #7a95aa;

            /* Primary blue */
            --primary:       #0f52c4;
            --primary-strong:#0b3f9a;
            --primary-light: #e6effc;

            /* Accent violet (Revolut touch) */
            --accent:        #6d28d9;
            --accent-soft:   #ede9fe;

            /* Teal (positive / balances) */
            --teal:          #0284c7;
            --teal-strong:   #0369a1;
            --teal-soft:     #e0f2fe;

            /* Status */
            --success:       #065f46;
            --success-soft:  #d1fae5;
            --danger:        #9f1239;
            --danger-soft:   #ffe4e6;
            --warning:       #78350f;
            --warning-soft:  #fef3c7;

            /* Surfaces */
            --bg:            #edf1f8;
            --surface:       #ffffff;
            --surface-soft:  #f8fafc;
            --surface-hover: #f0f4fa;

            /* Borders */
            --line:          #dde6f0;
            --line-strong:   #c4d2e4;

            /* Shadows */
            --shadow-xs: 0 1px 2px rgba(10,30,60,.04);
            --shadow:    0 2px 16px rgba(10,30,60,.08), 0 1px 4px rgba(10,30,60,.04);
            --shadow-lg: 0 8px 40px rgba(10,30,60,.12), 0 2px 10px rgba(10,30,60,.06);

            /* Shape */
            --radius:    16px;
            --radius-sm: 10px;
            --radius-lg: 22px;

            /* Gradients */
            --grad-sidebar: linear-gradient(175deg, #0b2244 0%, #06152a 100%);
            --grad-hero:    linear-gradient(145deg, #10305e 0%, #0b2244 55%, #060f20 100%);
            --grad-bar-in:  linear-gradient(180deg, #60a5fa, #0f52c4);
            --grad-bar-out: linear-gradient(180deg, #a5b4fc, #4f46e5);
        }

        /* ── DESIGN TOKENS – DARK ──────────────────────────────────── */
        [data-theme="dark"] {
            --navy:          #1a2e52;
            --navy-deep:     #0d1a30;
            --navy-mid:      #243866;
            --navy-soft:     #2e4880;

            --ink:           #dce8f5;
            --ink-soft:      #7d96b5;
            --ink-muted:     #4e6a88;

            --primary:       #4d8ff5;
            --primary-strong:#3a7ded;
            --primary-light: #0a1830;

            --accent:        #8b5cf6;
            --accent-soft:   #130d28;

            --teal:          #22d3ee;
            --teal-strong:   #06b6d4;
            --teal-soft:     #071c25;

            --success:       #34d399;
            --success-soft:  #061811;
            --danger:        #fb7185;
            --danger-soft:   #1e0810;
            --warning:       #fbbf24;
            --warning-soft:  #1c1205;

            --bg:            #060c16;
            --surface:       #0c1424;
            --surface-soft:  #101d32;
            --surface-hover: #152038;

            --line:          rgba(255,255,255,.07);
            --line-strong:   rgba(255,255,255,.13);

            --shadow-xs: 0 1px 2px rgba(0,0,0,.3);
            --shadow:    0 2px 16px rgba(0,0,0,.4),  0 1px 4px rgba(0,0,0,.25);
            --shadow-lg: 0 8px 40px rgba(0,0,0,.55), 0 2px 12px rgba(0,0,0,.3);

            --grad-sidebar: linear-gradient(175deg, #0d1c38 0%, #07101e 100%);
            --grad-hero:    linear-gradient(145deg, #192c50 0%, #0d1c38 55%, #060c18 100%);
            --grad-bar-in:  linear-gradient(180deg, #93c5fd, #3a7ded);
            --grad-bar-out: linear-gradient(180deg, #c4b5fd, #7c3aed);
        }

        /* ── RESET ─────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }
        html { background: var(--bg); }
        body {
            margin: 0;
            font-family: "Aptos", "Segoe UI", system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.5;
            color: var(--ink);
            background: var(--bg);
            transition: background .28s ease, color .28s ease;
        }
        a { color: inherit; text-decoration: none; }
        button, input, select, textarea { font: inherit; }
        button[disabled] { opacity: .5; cursor: not-allowed; }

        /* ── FORM CONTROLS ──────────────────────────────────────────── */
        .form-control {
            display: block;
            width: 100%;
            padding: 9px 12px;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.5;
            color: var(--ink);
            background: #fff;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
            box-sizing: border-box;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }
        .form-control::placeholder { color: #94a3b8; }
        select.form-control { cursor: pointer; }
        textarea.form-control { resize: vertical; min-height: 80px; }

        /* ── BUTTONS ────────────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 700;
            border-radius: 8px;
            border: 1.5px solid transparent;
            cursor: pointer;
            transition: background .15s, box-shadow .15s, transform .1s;
            line-height: 1;
            white-space: nowrap;
            text-decoration: none;
        }
        .btn:active { transform: translateY(1px); }
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(37,99,235,.25);
        }
        .btn-primary:hover {
            background: var(--primary-strong);
            border-color: var(--primary-strong);
            box-shadow: 0 4px 12px rgba(37,99,235,.3);
        }
        .btn-secondary {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: var(--ink);
        }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-sm { padding: 5px 12px; font-size: 12px; border-radius: 6px; }
        .btn-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .btn-danger:hover { background: #fee2e2; }

        /* ── ALERT / FLASH ──────────────────────────────────────────── */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 500;
            border: 1px solid transparent;
        }
        .alert-success {
            background: #f0fdf4;
            border-color: #86efac;
            color: #166534;
        }
        .alert-danger {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        .alert-warning {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        /* ── LABEL nel contenuto principale (non sidebar) ───────────── */
        .card label, form label, .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink-soft);
            margin-bottom: 5px;
            letter-spacing: 0;
            text-transform: none;
        }

        /* ── APP SHELL ──────────────────────────────────────────────── */
        .app-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 272px minmax(0, 1fr);
        }

        /* ── SIDEBAR ────────────────────────────────────────────────── */
        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            padding: 20px 14px;
            background: var(--grad-sidebar);
            border-right: 1px solid rgba(255,255,255,.05);
            scrollbar-width: none;
            transition: background .28s ease;
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sidebar::before {
            content: "";
            position: absolute;
            top: -60px; right: -60px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,.06), transparent 70%);
            pointer-events: none;
        }
        .sidebar::after {
            content: "";
            position: absolute;
            bottom: -50px; left: -40px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79,70,229,.14), transparent 70%);
            pointer-events: none;
        }
        .sidebar-inner { position: relative; z-index: 1; display: grid; gap: 12px; }

        /* Brand lockup */
        .brand-lockup {
            display: flex; gap: 12px; align-items: center;
            padding: 14px; border-radius: 18px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            transition: background .18s;
        }
        .brand-lockup:hover { background: rgba(255,255,255,.09); }
        .brand-mark {
            flex-shrink: 0; width: 48px; height: 48px;
            border-radius: 14px; background: #fff;
            display: grid; place-items: center;
            box-shadow: 0 4px 14px rgba(0,0,0,.22);
        }
        .brand-mark img { width: 26px; height: 36px; object-fit: contain; display: block; }
        .brand-copy strong { display: block; font-size: 20px; letter-spacing: -.01em; color: #fff; }
        .brand-copy small {
            display: block; margin-top: 2px;
            font-size: 10px; text-transform: uppercase;
            letter-spacing: .1em; color: rgba(255,255,255,.52);
        }

        /* Sidebar panel */
        .sidebar-panel {
            padding: 14px; border-radius: 15px;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.07);
        }
        .sidebar-section-label {
            margin: 0 0 10px; font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .12em;
            color: rgba(255,255,255,.42);
        }
        .sidebar-nav, .sidebar-nav-group { display: grid; gap: 3px; }
        .sidebar-nav-group { padding-top: 4px; border-top: 1px solid rgba(255,255,255,.06); }

        /* Links */
        .sidebar-link, .sidebar-sublink {
            display: flex; align-items: center; gap: 10px;
            border-radius: 10px;
            transition: background .16s, color .16s;
            color: rgba(255,255,255,.76);
        }
        .sidebar-link { padding: 9px 10px; font-size: 14px; font-weight: 600; }
        .sidebar-sublink { padding: 7px 10px 7px 16px; font-size: 12.5px; color: rgba(255,255,255,.58); }
        .sidebar-link:hover { background: rgba(255,255,255,.08); color: #fff; }
        .sidebar-sublink:hover { background: rgba(255,255,255,.06); color: rgba(255,255,255,.9); }
        .sidebar-link.active {
            background: rgba(255,255,255,.12); color: #fff;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.1);
        }
        .sidebar-sublink.active { color: rgba(255,255,255,.95); }

        /* Icons */
        .nav-icon, .section-icon, .subnav-icon { display: grid; place-items: center; font-weight: 800; }
        .nav-icon {
            flex-shrink: 0; width: 30px; height: 30px;
            border-radius: 9px; background: rgba(255,255,255,.1);
            color: rgba(255,255,255,.9); font-size: 10px; letter-spacing: .02em;
        }
        .sidebar-link.active .nav-icon { background: var(--primary); }
        .subnav-icon {
            flex-shrink: 0; width: 18px; height: 18px;
            border-radius: 999px; background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.65); font-size: 8px;
        }

        /* Sidebar note / user */
        .sidebar-note { margin: 0; font-size: 12.5px; line-height: 1.55; color: rgba(255,255,255,.58); }
        .sidebar-user { display: flex; gap: 10px; align-items: center; }
        .sidebar-user strong { display: block; font-size: 13.5px; color: #fff; }
        .sidebar-user span { display: block; font-size: 11.5px; color: rgba(255,255,255,.58); }
        .sidebar-avatar {
            flex-shrink: 0; width: 40px; height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #1c5da8, #0b3f9a);
            color: #fff; font-size: 14px; font-weight: 800;
            display: grid; place-items: center;
            border: 1.5px solid rgba(255,255,255,.15);
        }
        .logout-btn {
            width: 100%; min-height: 40px; margin-top: 10px;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 10px; color: rgba(255,255,255,.82);
            background: rgba(255,255,255,.05); cursor: pointer;
            font-size: 13px; transition: background .16s;
        }
        .logout-btn:hover { background: rgba(255,255,255,.09); color: #fff; }
        .switch-profile-btn { display: none; width: 100%; text-align: left; border: none; background: rgba(255,255,255,.06); color: rgba(255,255,255,.78); cursor: pointer; font-size: 13px; font-weight: 700; padding: 10px 14px; border-radius: 12px; gap: 10px; align-items: center; margin-bottom: 6px; transition: background .15s; }
        .switch-profile-btn:hover { background: rgba(255,255,255,.11); color: #fff; }
        .switch-profile-btn svg { flex-shrink: 0; opacity: .7; }
        /* Overlay switch profilo */
        .switch-overlay { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.55); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
        .switch-overlay.open { display: flex; }
        .switch-modal { background: var(--bg); border-radius: 20px; padding: 28px 24px; width: min(92%, 360px); box-shadow: 0 20px 60px rgba(0,0,0,.2); }
        .switch-modal h3 { margin: 0 0 8px; font-size: 19px; }
        .switch-modal p { margin: 0 0 20px; font-size: 14px; color: var(--ink-muted); line-height: 1.5; }
        .switch-modal-msg { display: none; margin-top: 14px; padding: 10px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; }
        .switch-modal-msg.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .switch-modal-msg.err { background: #fff1f2; color: #9f1239; border: 1px solid #fecdd3; }

        /* ── CONTENT SHELL ──────────────────────────────────────────── */
        .content-shell {
            padding: 14px; background: var(--bg);
            transition: background .28s ease;
        }

        /* ── TOPBAR ─────────────────────────────────────────────────── */
        .topbar {
            display: flex; justify-content: space-between;
            align-items: center; gap: 10px; flex-wrap: wrap;
            margin-bottom: 10px; padding: 10px 14px;
            border-radius: var(--radius);
            background: var(--surface);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            transition: background .28s, border-color .28s;
        }
        .topbar-title h1 {
            margin: 0; font-size: 20px; font-weight: 700;
            letter-spacing: -.02em; color: var(--ink);
        }
        .topbar-title p { margin: 2px 0 0; font-size: 12.5px; color: var(--ink-soft); line-height: 1.4; }
        .topbar-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .company-switch {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 10px; border-radius: var(--radius-sm);
            background: var(--surface-soft); border: 1px solid var(--line);
            color: var(--ink); transition: background .2s;
        }
        .company-switch:hover { background: var(--surface-hover); }
        .account-switcher-wrap { position: relative; }
        .account-switcher-btn { display: flex; align-items: center; gap: 6px; padding: 6px 10px; background: var(--surface); border: 1.5px solid var(--border); border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ink); white-space: nowrap; }
        .account-switcher-btn:hover { background: var(--surface-hover); }
        .account-switcher-menu { display: none; position: absolute; right: 0; top: calc(100% + 6px); background: var(--bg); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,.1); min-width: 200px; z-index: 9999; overflow: hidden; }
        .account-switcher-menu.open { display: block; }
        .account-switcher-item { display: block; padding: 10px 14px; font-size: 13px; color: var(--ink); text-decoration: none; border: none; background: none; width: 100%; text-align: left; cursor: pointer; }
        .account-switcher-item:hover { background: var(--surface-hover); }
        .account-switcher-item.active-account { background: #eff6ff; color: #1a56db; font-weight: 700; }
        .account-switcher-item .sub-badge { font-size: 10px; background: #e0e7ff; color: #3730a3; padding: 1px 6px; border-radius: 99px; margin-left: 4px; }
        .company-switch strong { display: block; font-size: 12.5px; font-weight: 700; }
        .company-switch small { display: block; font-size: 11px; color: var(--ink-soft); }

        /* Theme toggle */
        .theme-toggle {
            width: 36px; height: 36px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: var(--surface-soft);
            cursor: pointer; display: grid; place-items: center;
            font-size: 15px; line-height: 1;
            transition: background .16s, border-color .16s, transform .12s;
        }
        .theme-toggle:hover { background: var(--surface-hover); transform: scale(1.08); }

        /* Notification bell */
        .notif-bell {
            position: relative;
            width: 36px; height: 36px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: var(--surface-soft);
            display: grid; place-items: center;
            font-size: 16px; line-height: 1; color: var(--ink);
            transition: background .16s, transform .12s;
            text-decoration: none;
        }
        .notif-bell:hover { background: var(--surface-hover); transform: scale(1.08); }
        .notif-badge {
            position: absolute; top: -5px; right: -5px;
            min-width: 18px; height: 18px; padding: 0 4px;
            border-radius: 999px;
            background: var(--danger); color: #fff;
            font-size: 10px; font-weight: 800;
            display: grid; place-items: center;
            border: 2px solid var(--surface);
            line-height: 1;
        }

        /* ── PILLS / CHIPS ──────────────────────────────────────────── */
        .pill, .k-tag, .chip {
            display: inline-flex; align-items: center; gap: 5px;
            min-height: 26px; padding: 0 10px; border-radius: 999px;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em; white-space: nowrap;
        }
        .pill { background: var(--primary-light); color: var(--primary-strong); border: 1px solid rgba(15,82,196,.18); }
        .pill.success, .chip.success { background: var(--success-soft); color: var(--success); border: 1px solid rgba(6,95,70,.18); }
        .pill.warn, .chip.pink { background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(159,18,57,.18); }
        .k-tag { background: var(--primary-light); color: var(--primary-strong); border: 1px solid rgba(15,82,196,.18); }
        .chip { background: var(--surface-soft); color: var(--ink-soft); border: 1px solid var(--line); }

        /* ── NOTICES ────────────────────────────────────────────────── */
        .notice {
            margin-bottom: 14px; padding: 11px 14px;
            border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 600;
            background: var(--surface-soft); border: 1px solid var(--line); color: var(--ink-soft);
        }
        .notice.success { background: var(--success-soft); color: var(--success); border-color: rgba(6,95,70,.2); }
        .notice.error   { background: var(--danger-soft);  color: var(--danger);  border-color: rgba(159,18,57,.2); }

        /* ── PAGE INTRO ─────────────────────────────────────────────── */
        /* ── PAGE INTRO ─────────────────────────────────────────────── */
        .page-intro {
            position: relative; overflow: hidden;
            margin-bottom: 12px; padding: 13px 18px;
            border-radius: var(--radius);
            background:
                linear-gradient(135deg, color-mix(in srgb, var(--surface) 94%, var(--primary) 6%), var(--surface) 62%),
                var(--surface);
            border: 1px solid var(--line);
            border-left: 4px solid var(--primary);
            box-shadow: var(--shadow);
            transition: background .28s, border-color .28s;
        }
        .page-intro::after {
            content: "";
            position: absolute; inset: 0 0 auto auto;
            width: 38%; height: 100%;
            background: linear-gradient(120deg, transparent, rgba(15,82,196,.08));
            clip-path: polygon(40% 0, 100% 0, 100% 100%, 0 100%);
            pointer-events: none;
        }
        /* Variante orizzontale: titolo a sx, azioni a dx */
        .page-intro.page-intro--row {
            display: flex; align-items: center;
            justify-content: space-between; gap: 16px; flex-wrap: wrap;
        }
        .page-intro--row .page-intro-body { flex: 1; min-width: 0; }
        .page-intro--row .page-actions { margin-top: 0; flex-shrink: 0; }
        .page-intro.delegate-hero, .page-intro.is-suspended { border-left-color: var(--warning); }
        .page-intro h2 {
            position: relative; z-index: 1;
            margin: 2px 0 0; font-size: 18px; font-weight: 700;
            letter-spacing: -.02em;
            font-family: "Aptos Display", "Aptos", "Segoe UI", sans-serif;
        }
        .page-intro p { position: relative; z-index: 1; margin: 3px 0 0; max-width: 720px; font-size: 12.5px; line-height: 1.45; color: var(--ink-soft); }
        .page-intro .eyebrow, .page-intro .page-actions { position: relative; z-index: 1; }

        /* Typography */
        .section-title, .card-title {
            margin: 0; font-weight: 700; letter-spacing: -.01em;
            font-family: "Aptos Display", "Aptos", "Segoe UI", sans-serif;
        }
        .section-title { font-size: 20px; color: var(--ink); }
        .card-title    { font-size: 16px; color: var(--ink); }

        /* ── ACTIONS / CTAs ─────────────────────────────────────────── */
        .page-actions, .quick-actions, .form-actions, .mini-list, .table-tags {
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .page-actions { margin-top: 8px; }
        .form-actions { justify-content: flex-end; margin-top: 14px; }

        .cta {
            display: inline-flex; align-items: center;
            justify-content: center; gap: 6px;
            min-height: 36px; padding: 0 14px;
            border-radius: 9px;
            background: linear-gradient(180deg, color-mix(in srgb, var(--primary) 92%, #fff 8%), var(--primary-strong));
            border: 1px solid color-mix(in srgb, var(--primary) 86%, #06152a 14%);
            color: #fff; font-size: 13px; font-weight: 700; letter-spacing: .02em;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(15,82,196,.25);
            transition: background .16s, transform .12s, box-shadow .16s;
        }
        .cta:hover {
            background: var(--primary-strong);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(15,82,196,.36);
        }
        .cta:active { transform: translateY(0); }
        .cta.secondary {
            background: var(--surface); color: var(--ink);
            border-color: var(--line-strong); box-shadow: var(--shadow-xs);
        }
        .cta.secondary:hover { background: var(--surface-hover); box-shadow: var(--shadow); }

        /* ── SUMMARY TOPLINE ────────────────────────────────────────── */
        .summary-topline {
            display: flex; justify-content: flex-end; gap: 16px; flex-wrap: wrap;
            margin: 0 0 10px; padding: 8px 14px;
            border-radius: var(--radius-sm);
            background: var(--surface); border: 1px solid var(--line);
            font-size: 12.5px; color: var(--ink-soft);
            transition: background .28s, border-color .28s;
        }
        .summary-topline strong { color: var(--ink); }

        /* ── GRID LAYOUTS ───────────────────────────────────────────── */
        .stack, .summary-cards, .timeline-list { display: grid; gap: 12px; }
        .portal-grid, .summary-grid, .admin-grid, .grid-cards,
        .spotlight-grid, .catalog-grid, .stats-grid, .hero-strip,
        .entity-grid, .info-grid, .form-split, .tile-grid { display: grid; gap: 12px; }

        .portal-grid   { grid-template-columns: 306px minmax(0, 1fr); align-items: start; }
        .summary-grid  { grid-template-columns: minmax(0, 1.25fr) minmax(280px, .85fr); }
        .delegate-grid { grid-template-columns: 340px minmax(0, 1fr); }
        .admin-grid, .spotlight-grid, .catalog-grid, .grid-cards,
        .stats-grid, .entity-grid, .tile-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .hero-strip    { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .info-grid     { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .form-split    { grid-template-columns: minmax(0, 1.1fr) minmax(280px, .9fr); }

        /* ── CARDS ──────────────────────────────────────────────────── */
        .card, .dark-panel, .dark-stat, .spotlight-card, .catalog-card,
        .section-panel, .form-card, .stat-card {
            position: relative;
            border-radius: var(--radius-sm);
            background: var(--surface); border: 1px solid var(--line);
            box-shadow: var(--shadow);
            transition: background .28s, border-color .28s, box-shadow .28s;
        }
        .card-pad, .light-card, .spotlight-card, .catalog-card,
        .section-panel, .dark-stat, .form-body, .stat-card { padding: 14px; }
        .light-card { background: var(--surface); }
        .card:hover, .section-panel:hover, .stat-card:hover {
            border-color: var(--line-strong);
            box-shadow: 0 6px 22px rgba(10,30,60,.10), 0 1px 4px rgba(10,30,60,.05);
        }
        .stat-card {
            overflow: hidden;
            min-height: 86px;
            border-left: 3px solid color-mix(in srgb, var(--primary) 84%, var(--teal) 16%);
            background:
                linear-gradient(180deg, color-mix(in srgb, var(--surface) 96%, #fff 4%), var(--surface)),
                var(--surface);
        }
        .stat-card::after {
            content: "";
            position: absolute;
            left: 0; right: 0; bottom: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--teal), var(--accent));
            opacity: .55;
        }
        .stat-card strong, .stat-card .section-title {
            letter-spacing: -.03em;
            font-variant-numeric: tabular-nums;
        }

        /* Dark hero cards */
        .account-hero, .dark-panel, .dark-stat {
            position: relative; overflow: hidden;
            color: #fff;
            background:
                linear-gradient(135deg, rgba(255,255,255,.08), transparent 42%),
                linear-gradient(160deg, #081b36 0%, #0b2244 46%, #111827 100%);
            border-color: rgba(255,255,255,.07);
        }
        .account-hero { padding: 18px; }

        /* Structured banking accents */
        .account-hero::before, .dark-stat::before {
            content: ""; position: absolute; inset: 0;
            background:
                linear-gradient(90deg, transparent 0 58%, rgba(255,255,255,.06) 58% 59%, transparent 59%),
                linear-gradient(0deg, transparent 0 70%, rgba(255,255,255,.05) 70% 71%, transparent 71%);
            pointer-events: none;
        }
        .account-hero::after, .dark-stat::after {
            content: ""; position: absolute;
            right: 16px; top: 16px; width: 54px; height: 34px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,.22);
            background: linear-gradient(135deg, rgba(255,255,255,.16), rgba(255,255,255,.04));
            pointer-events: none;
        }

        /* Metrics in hero */
        .metric { position: relative; z-index: 1; margin-top: 10px; }
        .metric-label, .stat-label {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em; color: rgba(255,255,255,.6);
        }
        .metric-value, .stat-value {
            margin-top: 3px; font-size: 22px; font-weight: 800;
            letter-spacing: -.01em; color: #fff;
        }
        .stat-note { margin-top: 5px; font-size: 11.5px; color: rgba(255,255,255,.68); }
        .hero-title {
            position: relative; z-index: 1;
            margin: 10px 0 12px; font-size: 18px; font-weight: 700;
            letter-spacing: -.01em; color: #fff;
        }

        /* KPI Strip */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }
        .kpi-card {
            padding: 14px 16px;
            border-radius: var(--radius);
            background: var(--surface);
            border: 1px solid var(--line);
            box-shadow: var(--shadow-xs);
            transition: background .28s, border-color .28s;
        }
        .kpi-label {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em;
            color: var(--ink-muted);
        }
        .kpi-value {
            margin-top: 4px; font-size: 22px; font-weight: 800;
            letter-spacing: -.02em; color: var(--ink);
        }
        .kpi-value.positive { color: var(--success); }
        .kpi-value.teal     { color: var(--teal-strong); }
        .kpi-note {
            margin-top: 2px; font-size: 11px; color: var(--ink-muted);
        }

        /* Hero badge / section icon */
        .hero-badge, .section-icon {
            width: 44px; height: 44px; border-radius: 13px;
            background: rgba(255,255,255,.12); color: #fff;
            font-size: 12px; display: grid; place-items: center;
        }

        /* ── SECTION HEAD ───────────────────────────────────────────── */
        .panel-title, .section-head, .entity-head, .catalog-head,
        .spotlight-head, .company-head {
            display: flex; justify-content: space-between;
            gap: 10px; align-items: flex-start;
        }
        .section-head {
            margin-bottom: 10px;
            padding-bottom: 9px;
            border-bottom: 1px solid var(--line);
        }
        .eyebrow, .subtle, .table-muted { color: var(--ink-soft); }
        .eyebrow { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; }
        .subtle { font-size: 13px; }

        /* ── PROGRESS BAR ───────────────────────────────────────────── */
        .progress {
            height: 6px; border-radius: 999px;
            background: rgba(255,255,255,.14); overflow: hidden; margin-top: 10px;
        }
        .progress span {
            display: block; height: 100%; border-radius: 999px;
            background: linear-gradient(90deg, var(--teal), var(--primary));
            transition: width .45s ease;
        }

        /* ── CHART ──────────────────────────────────────────────────── */
        .chart-box { min-height: 300px; padding: 20px; }
        .chart-lines {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(50px, 1fr));
            align-items: end; gap: 12px; min-height: 200px; margin-top: 16px;
        }
        .chart-col {
            display: grid; justify-items: center;
            align-content: end; gap: 8px; min-height: 200px;
        }
        .chart-bars {
            display: flex; align-items: flex-end; justify-content: center;
            gap: 6px; width: 100%; min-height: 160px;
            padding: 0 4px; border-bottom: 1px solid var(--line);
        }
        .bar {
            width: 14px; min-height: 8px;
            border-radius: 6px 6px 3px 3px;
            transition: opacity .18s, transform .18s; cursor: default;
        }
        .bar:hover { opacity: .8; transform: scaleY(1.03); transform-origin: bottom; }
        .bar.income  { background: var(--grad-bar-in); }
        .bar.expense { background: var(--grad-bar-out); }
        .chart-label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em; color: var(--ink-muted);
        }

        /* ── DARK STAT CARDS ────────────────────────────────────────── */
        .dark-stat {
            border-radius: var(--radius);
            border: 1px solid rgba(255,255,255,.07);
        }

        /* ── TRANSACTIONS TABLE ─────────────────────────────────────── */
        .transactions-table, .admin-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
        }
        .transactions-table th, .transactions-table td,
        .admin-table th, .admin-table td {
            padding: 9px 12px; border-top: 1px solid var(--line);
            vertical-align: middle; transition: border-color .28s;
        }
        .transactions-table thead th, .admin-table thead th {
            padding: 10px 12px; color: var(--ink-muted);
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            text-align: left;
            background: linear-gradient(180deg, color-mix(in srgb, var(--surface-soft) 86%, #fff 14%), var(--surface-soft));
            border-top: none; border-bottom: 1px solid var(--line);
        }
        .transactions-table tbody tr, .admin-table tbody tr { transition: background .16s; }
        .transactions-table tbody tr:hover, .admin-table tbody tr:hover { background: var(--surface-soft); }
        .date-block { font-size: 13px; font-weight: 700; line-height: 1.25; color: var(--ink); white-space: nowrap; }
        .date-block span {
            display: inline; color: var(--ink-soft);
            font-size: 13px; font-weight: 400; text-transform: capitalize; letter-spacing: 0;
        }
        .flow { font-weight: 600; font-size: 12px; white-space: nowrap; }
        .flow.out { color: var(--danger); }
        .flow.in  { color: var(--success); }
        .flow small { display: none; }
        .trx-month-group td {
            padding: 6px 12px 5px; background: var(--surface-soft);
            font-size: 11px; font-weight: 700; color: var(--ink-muted);
            text-transform: uppercase; letter-spacing: .08em;
            border-top: 1px solid var(--line);
        }

        /* ── FORM ELEMENTS ──────────────────────────────────────────── */
        .entity-meta, .meta-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .field-grid { display: grid; gap: 14px; }
        .field label { display: block; margin-bottom: 6px; font-size: 12.5px; font-weight: 700; color: var(--ink-soft); }
        .field input, .field select, .field textarea {
            width: 100%; min-height: 44px; padding: 10px 14px;
            border-radius: 9px; border: 1px solid var(--line-strong);
            background: var(--surface); color: var(--ink);
            transition: border-color .16s, box-shadow .16s, background .28s;
        }
        .field input:focus, .field select:focus, .field textarea:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15,82,196,.12);
        }
        .field textarea { min-height: 100px; resize: vertical; }
        .field-inline { display: grid; grid-template-columns: minmax(0, 1fr) 180px; gap: 14px; }

        /* ── PERMISSION TILES ───────────────────────────────────────── */
        .permission-grid, .role-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .check-tile {
            position: relative; display: block;
            padding: 14px 16px; border-radius: var(--radius);
            background: var(--surface-soft); border: 1px solid var(--line);
            cursor: pointer; transition: border-color .16s, background .16s;
        }
        .check-tile:hover { border-color: var(--primary); background: var(--surface); }
        .check-tile input.check-mark { position: absolute; top: 14px; right: 14px; width: 15px; height: 15px; margin: 0; accent-color: var(--primary); }
        .check-tile-copy { display: grid; gap: 6px; padding-right: 26px; }
        .check-tile-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .check-tile-head strong { font-size: 14px; line-height: 1.2; }
        .check-tile-meta {
            display: inline-flex; align-items: center; min-height: 20px; padding: 0 8px;
            border-radius: 999px; background: var(--primary-light); color: var(--primary-strong);
            font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        }
        .check-tile .subtle { font-size: 12px; }
        .perm-badges { display: flex; flex-wrap: wrap; gap: 5px; }
        .perm-badge, .perm-more {
            display: inline-flex; align-items: center;
            min-height: 20px; padding: 0 7px;
            border-radius: 999px; font-size: 10px; font-weight: 700;
        }
        .perm-badge { background: var(--surface-soft); color: var(--ink-soft); border: 1px solid var(--line); }
        .perm-more  { background: var(--warning-soft); color: var(--warning); border: 1px solid rgba(120,53,15,.15); }
        .perm-empty { font-size: 12px; color: var(--ink-soft); }

        /* ── MISC ───────────────────────────────────────────────────── */
        .form-shell { max-width: 1100px; margin: 0 auto; display: grid; gap: 20px; }
        .form-header { padding: 22px 26px; font-size: 28px; font-weight: 700; letter-spacing: -.02em; }
        .timeline-item {
            display: grid; gap: 10px; padding: 14px 16px;
            border-radius: var(--radius-sm); background: var(--surface);
            border: 1px solid var(--line);
            box-shadow: var(--shadow-xs);
            transition: background .2s, border-color .2s, box-shadow .2s;
        }
        .timeline-item:hover { border-color: var(--line-strong); background: var(--surface); box-shadow: var(--shadow); }
        .empty-state {
            padding: 22px; text-align: center;
            border-radius: var(--radius); border: 1.5px dashed var(--line-strong);
            background: var(--surface-soft); color: var(--ink-soft);
        }
        .section-title-band { display: none; }

        /* ── COMPACT-FIRST: riduzione spazi verticali globali ────────── */
        /* hero-strip: numeri più piccoli e meno margine */
        .hero-strip .section-title { font-size: 20px !important; }
        .hero-strip { margin-bottom: 12px !important; }
        /* stat-card: meno padding interno */
        .stat-card { padding: 14px 16px !important; }
        /* page-intro: riduci h2 e descrizione */
        .page-intro { padding: 12px 16px; margin-bottom: 10px; }
        .page-intro h2 { font-size: 17px; margin: 0; }
        .page-intro p { font-size: 12px; margin: 2px 0 0; line-height: 1.4; }
        /* section-head: meno margine inferiore */
        .section-head { margin-bottom: 8px !important; }
        /* field-grid: gap ridotto */
        .field-grid { gap: 10px !important; }
        /* form-actions: meno margine sopra */
        .form-actions { margin-top: 10px !important; }
        /* light-card: meno padding */
        .light-card { padding: 14px 16px !important; }
        /* data-table: righe più compatte */
        .data-table th, .data-table td { padding: 7px 10px !important; font-size: 13px; }
        .data-table thead th { padding: 6px 10px !important; }

        /* ── RESPONSIVE ─────────────────────────────────────────────── */
        @media (max-width: 1280px) {
            .app-shell { grid-template-columns: 1fr; }
            .sidebar { position: relative; height: auto; overflow-y: visible; }
            .sidebar::before, .sidebar::after { display: none; }
            .hero-strip, .info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 980px) {
            .portal-grid, .summary-grid, .delegate-grid, .admin-grid, .grid-cards,
            .spotlight-grid, .catalog-grid, .stats-grid, .hero-strip, .entity-grid,
            .info-grid, .form-split, .tile-grid, .permission-grid, .role-grid {
                grid-template-columns: 1fr;
            }
            .content-shell { padding: 14px; }
            .topbar { padding: 10px 14px; }
            .page-intro { padding: 14px 16px; }
        }
        @media (max-width: 720px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .topbar-tools, .quick-actions, .page-actions, .form-actions { width: 100%; }
            .cta, .logout-btn { width: 100%; }
            .field-inline { grid-template-columns: 1fr; }
            .transactions-table, .admin-table { display: block; overflow-x: auto; }
        }

        /* ── MOBILE HAMBURGER + SIDEBAR OVERLAY ────────────────────── */
        .hamburger-btn {
            display: none;
            background: none;
            border: 1.5px solid var(--line);
            cursor: pointer;
            padding: 7px 9px;
            border-radius: 10px;
            color: var(--ink);
            flex-direction: column;
            gap: 4px;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .hamburger-btn span {
            display: block;
            width: 18px;
            height: 2px;
            background: currentColor;
            border-radius: 2px;
            transition: transform .22s, opacity .22s, width .22s;
        }
        .hamburger-btn.open span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .hamburger-btn.open span:nth-child(2) { opacity: 0; width: 0; }
        .hamburger-btn.open span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: opacity .28s;
        }

        @media (max-width: 768px) {
            .app-shell { display: block; }
            .sidebar {
                position: fixed !important;
                top: 0; left: 0;
                width: 280px !important;
                height: 100vh !important;
                overflow-y: auto !important;
                z-index: 1000;
                padding: 20px 14px !important;
                transform: translateX(-100%);
                transition: transform .28s cubic-bezier(.4,0,.2,1);
            }
            .sidebar.is-open { transform: translateX(0) !important; }
            .sidebar-overlay { display: block; }
            .sidebar-overlay.is-open { opacity: 1; pointer-events: auto; }
            .hamburger-btn { display: flex !important; }
            .topbar { flex-direction: row !important; align-items: center !important; flex-wrap: nowrap !important; gap: 10px !important; }
            .topbar-title { flex: 1; min-width: 0; }
            .topbar-title h1 { font-size: 16px !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .topbar-title p { display: none; }
            .topbar-tools { width: auto !important; flex-wrap: nowrap !important; }
            .company-switch { display: none !important; }
        }

        /* ── BOTTOM NAVIGATION BAR ──────────────────────────────────── */
        .mobile-bottom-nav {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: var(--surface);
            border-top: 1.5px solid var(--line);
            box-shadow: 0 -2px 20px rgba(10,30,60,.10);
            z-index: 900;
            padding-bottom: env(safe-area-inset-bottom, 0px);
        }
        .mobile-bottom-nav-inner {
            display: flex;
            height: 58px;
            align-items: stretch;
        }
        .mobile-tab {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            padding: 6px 2px;
            color: var(--ink-muted);
            font-size: 10px;
            font-weight: 600;
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
            user-select: none;
            transition: color .15s, background .15s;
            border-radius: 0;
        }
        .mobile-tab.active { color: var(--primary); }
        .mobile-tab:active { background: var(--surface-hover); }
        .mobile-tab-icon { font-size: 19px; line-height: 1; }
        .mobile-tab-label { font-size: 9px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
        /* Active indicator line */
        .mobile-tab { position: relative; }
        .mobile-tab.active::before {
            content: "";
            position: absolute;
            top: 0; left: 20%; right: 20%;
            height: 2.5px;
            background: var(--primary);
            border-radius: 0 0 3px 3px;
        }

        /* ── MOBILE APP EXPERIENCE ──────────────────────────────────── */
        @media (max-width: 768px) {
            /* Global touch polish */
            a, button { -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
            * { -webkit-font-smoothing: antialiased; }

            /* Show bottom nav for portal users */
            .has-bottom-nav .mobile-bottom-nav { display: block; }

            /* Padding bottom per non coprire contenuto con bottom nav */
            .has-bottom-nav .content-shell {
                padding-bottom: calc(68px + env(safe-area-inset-bottom, 0px)) !important;
            }

            /* Sidebar: momentum scroll + no overscroll */
            .sidebar { -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }

            /* Previeni auto-zoom iOS su input (richiede font-size ≥ 16px) */
            input:not([type=checkbox]):not([type=radio]),
            select, textarea {
                font-size: 16px !important;
            }

            /* Touch target minimo 44px su CTA */
            .cta, .btn { min-height: 44px !important; padding-top: 10px !important; padding-bottom: 10px !important; }

            /* Topbar più compatta */
            .topbar { padding: 8px 12px !important; margin-bottom: 8px !important; border-radius: 12px !important; }

            /* Card border-radius app-like */
            .card, .card-pad, .light-card, .stat-card, .section-panel, .form-card { border-radius: 12px !important; }

            /* KPI / hero strips: 2 colonne su mobile */
            .hero-strip { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .kpi-strip  { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }

            /* Meno padding interno su mobile */
            .content-shell { padding: 10px !important; }
            .card-pad, .light-card { padding: 12px !important; }
            .stat-card { padding: 12px 14px !important; }

            /* Tabelle scrollabili */
            .transactions-table, .admin-table { font-size: 12px !important; }

            /* Page intro compatta */
            .page-intro { padding: 10px 14px !important; margin-bottom: 8px !important; }
            .page-intro h2 { font-size: 15px !important; }

            /* Stack azioni su tutta larghezza */
            .page-actions, .form-actions, .quick-actions { flex-direction: column !important; }
            .page-actions .cta, .form-actions .cta, .form-actions .btn { width: 100% !important; }

            /* Previeni pull-to-refresh accidentale nel content */
            .content-shell { overscroll-behavior-y: contain; }

            /* Smooth scroll globale */
            html { scroll-behavior: smooth; }
        }

        /* Pill nascosto su schermi molto piccoli (< 400px) */
        @media (max-width: 400px) {
            .topbar-tools .pill { display: none !important; }
            .topbar-tools { gap: 6px !important; }
        }
    </style>
    @stack('head')
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css" rel="stylesheet">
    <style>
        .ts-wrapper { flex: 1; min-width: 0; }
        .ts-wrapper.single .ts-control { border-radius: 10px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #fff); color: var(--text, #1e293b); font-size: 14px; padding: 9px 36px 9px 12px; cursor: pointer; }
        .ts-wrapper.single.input-active .ts-control { border-color: #0f52c4; box-shadow: 0 0 0 3px rgba(15,82,196,.12); }
        .ts-dropdown { border-radius: 10px; border: 1.5px solid #e2e8f0; box-shadow: 0 8px 24px rgba(0,0,0,.12); font-size: 14px; margin-top: 4px; }
        .ts-dropdown .option { padding: 9px 14px; }
        .ts-dropdown .option.active { background: #0f52c4; color: #fff; }
        .ts-dropdown-content { max-height: 240px; }
        .ts-control input { color: var(--text, #1e293b) !important; }
        [data-theme="dark"] .ts-wrapper.single .ts-control,
        [data-theme="dark"] .ts-dropdown { background: #162032; color: #e2e8f0; border-color: #2d3f5a; }
        [data-theme="dark"] .ts-dropdown .option { color: #e2e8f0; }
    </style>
</head>
<body class="{{ !(auth()->user()?->canAccessBackoffice()) ? 'has-bottom-nav' : '' }}">
    @php
        $authUser = auth()->user();
        $viewer = $currentUser ?? $authUser;
        $isBackoffice = (bool) ($authUser?->canAccessBackoffice());
        $companyName = $isBackoffice ? 'Sala controllo' : (($currentAccount ?? null)?->display_name ?? $authUser?->company?->name ?? 'KMoney');
        $companyInitials = strtoupper(substr($companyName, 0, 2));
        $userInitials = strtoupper(substr($authUser?->name ?? 'KM', 0, 2));
        $isDelegate = (bool) ($viewer?->managed_account_id);
        $switchableAccounts = $viewer ? $viewer->switchableAccounts() : collect();
        $activeAccountId = session('active_account_id');
        $topbarTitle = $pageTitle ?? 'KMoney';
        $topbarSubtitle = $isBackoffice
            ? 'Controllo centralizzato di clienti, conti, autorizzazioni e movimenti.'
            : ($isDelegate
                ? 'Vista delegata con disponibilità, limiti operativi e operazioni riservate.'
                : 'Portale operativo per conti personali, aziendali e sottoconti delegati.');
        $profileLabel = $isBackoffice ? ($authUser?->is_super_admin ? 'Superadmin' : 'Backoffice') : ($isDelegate ? 'Delegato' : (($currentAccount ?? null)?->owner_type === 'private' ? 'Privato' : 'Azienda'));
        // Visibilità menu utente (risolto una volta sola per questa request)
        $menuVis = app(\App\Services\MenuVisibilityService::class);
        $mv = fn(string $k) => $isBackoffice || $menuVis->isVisible($k, $viewer);
    @endphp
    <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-inner">
                <a href="{{ $isBackoffice ? route('admin.dashboard') : route('portal.dashboard') }}" class="brand-lockup">
                    <span class="brand-mark"><img src="/assets/brand/kmoney-logo.png" alt="KMoney logo"></span>
                    <span class="brand-copy">
                        <strong>KMoney</strong>
                        <small>{{ $isBackoffice ? 'Backoffice bancario' : ($isDelegate ? 'Console delegato' : 'Conti privati e aziendali') }}</small>
                    </span>
                </a>
                <section class="sidebar-panel">
                    <p class="sidebar-section-label">Navigazione</p>
                    <nav class="sidebar-nav">
                        @if ($isBackoffice)
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin' ? 'active' : '' }}" href="{{ route('admin.dashboard') }}"><span class="nav-icon">SA</span><span>Bacheca</span></a>
                            <div class="sidebar-nav-group">
                                <a class="sidebar-sublink {{ ($activeNav ?? '') === 'admin' ? 'active' : '' }}" href="{{ route('admin.dashboard') }}"><span class="subnav-icon">OV</span><span>Panoramica</span></a>
                            </div>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'users' ? 'active' : '' }}" href="{{ route('admin.users.index') }}"><span class="nav-icon">US</span><span>Utenti</span></a>
                            <div class="sidebar-nav-group">
                                <a class="sidebar-sublink {{ ($activeNav ?? '') === 'users' ? 'active' : '' }}" href="{{ route('admin.users.index') }}#create-user"><span class="subnav-icon">AN</span><span>Anagrafica</span></a>
                            </div>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'limits' ? 'active' : '' }}" href="{{ route('admin.limits.index') }}"><span class="nav-icon">LM</span><span>Limiti</span></a>
                            <div class="sidebar-nav-group">
                                <a class="sidebar-sublink {{ ($activeNav ?? '') === 'limits' ? 'active' : '' }}" href="{{ route('admin.limits.index') }}"><span class="subnav-icon">DF</span><span>Default</span></a>
                            </div>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'roles' ? 'active' : '' }}" href="{{ route('admin.roles.index') }}"><span class="nav-icon">RB</span><span>Ruoli</span></a>
                            <div class="sidebar-nav-group">
                                <a class="sidebar-sublink {{ ($activeNav ?? '') === 'roles' ? 'active' : '' }}" href="{{ route('admin.roles.index') }}#create-role"><span class="subnav-icon">PM</span><span>Permessi</span></a>
                            </div>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'accounts' ? 'active' : '' }}" href="{{ route('admin.accounts.index') }}"><span class="nav-icon">AC</span><span>Conti</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'companies' ? 'active' : '' }}" href="{{ route('admin.companies.index') }}"><span class="nav-icon">AZ</span><span>Aziende</span></a>
                            <div class="sidebar-nav-group">
                                <a class="sidebar-sublink {{ ($activeNav ?? '') === 'companies' ? 'active' : '' }}" href="{{ route('admin.companies.index') }}"><span class="subnav-icon">DR</span><span>Directory</span></a>
                            </div>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'transfers' ? 'active' : '' }}" href="{{ route('admin.transfers.index') }}"><span class="nav-icon">MV</span><span>Movimenti</span></a>
                            <div class="sidebar-nav-group">
                                <a class="sidebar-sublink {{ ($activeNav ?? '') === 'transfers' ? 'active' : '' }}" href="{{ route('admin.transfers.index') }}"><span class="subnav-icon">ST</span><span>Storni</span></a>
                                <a class="sidebar-sublink {{ ($activeNav ?? '') === 'report' ? 'active' : '' }}" href="{{ route('admin.report') }}"><span class="subnav-icon">RP</span><span>Rapporti</span></a>
                            </div>
                            @if(auth()->user()?->is_super_admin)
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'audit' ? 'active' : '' }}" href="{{ route('admin.audit') }}"><span class="nav-icon">AL</span><span>Audit Log</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'analytics' ? 'active' : '' }}" href="{{ route('admin.analytics') }}"><span class="nav-icon">&#128202;</span><span>Analytics</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'branding' ? 'active' : '' }}" href="{{ route('admin.branding') }}"><span class="nav-icon">&#127912;</span><span>Brand</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-nfc-cards' ? 'active' : '' }}" href="{{ route('admin.nfc-cards.index') }}"><span class="nav-icon">&#128246;</span><span>Card NFC</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'webhook-deliveries' ? 'active' : '' }}" href="{{ route('admin.webhook-deliveries') }}">WD Log Webhook</a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-sectors' ? 'active' : '' }}" href="{{ route('admin.sectors.index') }}"><span class="nav-icon">ST</span><span>Settori</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-credit-requests' ? 'active' : '' }}" href="{{ route('admin.credit-requests.index') }}">
                                <span class="nav-icon">FD</span>
                                <span>Richieste Fido</span>
                                @php $pendingFidoCount = \App\Models\CreditLimitRequest::where('status','pending')->count(); @endphp
                                @if($pendingFidoCount > 0)
                                <span style="margin-left:auto;background:#ef4444;color:#fff;border-radius:999px;padding:1px 7px;font-size:10px;font-weight:700;">{{ $pendingFidoCount }}</span>
                                @endif
                            </a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'emit' ? 'active' : '' }}" href="{{ route('admin.ky.emit') }}"><span class="nav-icon">KY</span><span>Emissione KY</span></a>
                            @endif
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-ky-cards' ? 'active' : '' }}" href="{{ route('admin.ky-cards.index') }}"><span class="nav-icon">KY</span><span>KYCard</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-ky-bonifici' ? 'active' : '' }}" href="{{ route('admin.ky-cards.pending-transfers') }}"><span class="nav-icon">&#127968;</span><span>Bonifici KY</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-ky-orders' ? 'active' : '' }}" href="{{ route('admin.ky-cards.orders') }}"><span class="nav-icon">&#128203;</span><span>Ordini KYCard</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'cashback' ? 'active' : '' }}" href="{{ route('admin.cashback.index') }}"><span class="nav-icon">CB</span><span>Cashback</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-fees' ? 'active' : '' }}" href="{{ route('admin.fees.index') }}"><span class="nav-icon">&#x1F4B0;</span><span>Commissioni</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'broadcast' ? 'active' : '' }}" href="{{ route('admin.broadcast.index') }}"><span class="nav-icon">&#x1F4E2;</span><span>Comunicazioni</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-support' ? 'active' : '' }}" href="{{ route('admin.support.index') }}"><span class="nav-icon">&#x2753;</span><span>Assistenza</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-contract' ? 'active' : '' }}" href="{{ route('admin.contract-settings') }}"><span class="nav-icon">&#x1F4DC;</span><span>Contratto</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'admin-menu-visibility' ? 'active' : '' }}" href="{{ route('admin.menu-visibility.index') }}"><span class="nav-icon">&#x1F441;</span><span>Menu utenti</span></a>
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'broker' ? 'active' : '' }}" href="{{ route('broker.dashboard') }}"><span class="nav-icon">BR</span><span>Operatori</span></a>
                        @else
                            @if($mv('conto'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'conto' ? 'active' : '' }}" href="{{ route('portal.dashboard') }}"><span class="nav-icon">KY</span><span>{{ $isDelegate ? 'Vista delegato' : 'Conto' }}</span></a>
                            @endif
                            @if($mv('movimenti'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'movimenti' ? 'active' : '' }}" href="{{ route('portal.movements') }}"><span class="nav-icon">MV</span><span>Movimenti</span></a>
                            @endif
                            @if($mv('richieste'))
                            <a class="sidebar-link {{ in_array($activeNav ?? '', ['richieste', 'richieste-text']) ? 'active' : '' }}" href="{{ route('portal.requests') }}"><span class="nav-icon">RQ</span><span>Richieste</span></a>
                            @endif
                            @if($mv('wallet'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'wallet' ? 'active' : '' }}" href="{{ route('portal.wallet') }}"><span class="nav-icon">&#128179;</span><span>KY Wallet</span></a>
                            @endif
                            @if($mv('incasso-qr'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'incasso-qr' ? 'active' : '' }}" href="{{ route('portal.incasso-qr.form') }}"><span class="nav-icon">QR</span><span>Incassa QR</span></a>
                            @endif
                            @if($mv('incasso-nfc'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'incasso-nfc' ? 'active' : '' }}" href="{{ route('portal.incasso-nfc.form') }}"><span class="nav-icon">NFC</span><span>Incassa NFC</span></a>
                            @endif
                            @if($mv('incasso-sonic'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'incasso-sonic' ? 'active' : '' }}" href="{{ route('portal.incasso-sonic.form') }}"><span class="nav-icon">&#128266;</span><span>Incassa Sonic</span></a>
                            @endif
                            @if($mv('paga-sonic'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'paga-sonic' ? 'active' : '' }}" href="{{ route('portal.paga-sonic.form') }}"><span class="nav-icon">&#127908;</span><span>Paga Sonic</span></a>
                            @endif
                            @if($mv('incasso-codice'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'incasso-codice' ? 'active' : '' }}" href="{{ route('portal.incasso-codice.form') }}"><span class="nav-icon">&#128290;</span><span>Incassa Codice</span></a>
                            @endif
                            @if($mv('paga-codice'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'paga-codice' ? 'active' : '' }}" href="{{ route('portal.paga-codice.form') }}"><span class="nav-icon">123</span><span>Paga Codice</span></a>
                            @endif
                            @if($mv('nfc-cards'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'nfc-cards' ? 'active' : '' }}" href="{{ route('portal.nfc-cards.index') }}"><span class="nav-icon">&#128246;</span><span>Le mie Card NFC</span></a>
                            @endif
                            @if($mv('scheduled-payments'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'scheduled-payments' ? 'active' : '' }}" href="{{ route('portal.scheduled-payments.index') }}"><span class="nav-icon">SC</span><span>Pag. programmati</span></a>
                            @endif
                            @if($mv('webhooks') && !$isDelegate && ($currentUser ?? $authUser)?->canAccessBackoffice())
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'webhooks' ? 'active' : '' }}" href="{{ route('portal.webhooks.index') }}"><span class="nav-icon">WH</span><span>Webhook</span></a>
                            @endif
                            @if($mv('api-tokens') && !$isDelegate && ($currentUser ?? $authUser)?->canAccessBackoffice())
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'api-tokens' ? 'active' : '' }}" href="{{ route('portal.api-tokens.index') }}"><span class="nav-icon">API</span><span>Token API</span></a>
                            @endif
                            @if($mv('docs-api') && !$isDelegate && ($currentUser ?? $authUser)?->canAccessBackoffice())
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'docs-api' ? 'active' : '' }}" href="{{ route('portal.docs-api') }}"><span class="nav-icon">&#128216;</span><span>Docs API</span></a>
                            @endif
                            @if($mv('link-pagamento'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'link-pagamento' ? 'active' : '' }}" href="{{ route('portal.payment-links.index') }}"><span class="nav-icon">&#128279;</span><span>Link pagamento</span></a>
                            @endif
                            @if($mv('rate'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'rate' ? 'active' : '' }}" href="{{ route('portal.payment-plans.index') }}"><span class="nav-icon">RT</span><span>Rate</span></a>
                            @endif
                            @if($mv('fido'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'fido' ? 'active' : '' }}" href="{{ route('portal.fido') }}"><span class="nav-icon">FD</span><span>Fido</span></a>
                            @endif
                            @if($mv('ky-cards'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'ky-cards' ? 'active' : '' }}" href="{{ route('portal.ky-cards.index') }}"><span class="nav-icon">KY</span><span>Ricarica KY</span></a>
                            @endif
                            @if($mv('netting'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'netting' ? 'active' : '' }}" href="{{ route('portal.netting.index') }}"><span class="nav-icon">⇄</span><span>Compensazione</span></a>
                            @endif
                            @if($mv('sottoconti') && ! $isDelegate && ($currentAccount ?? $currentUser?->managedAccount ?? null) !== null && ($currentUser ?? $authUser)?->canCreateSubaccountsFor($currentAccount ?? $currentUser?->managedAccount))
                                <a class="sidebar-link {{ ($activeNav ?? '') === 'conti' ? 'active' : '' }}" href="{{ route('portal.accounts.structure') }}"><span class="nav-icon">SC</span><span>Sottoconti</span></a>
                            @endif
                            @if($mv('aziende') && ($currentUser ?? $authUser)?->canViewCompaniesDirectory())
                                <a class="sidebar-link {{ ($activeNav ?? '') === 'aziende' ? 'active' : '' }}" href="{{ route('portal.companies') }}"><span class="nav-icon">AZ</span><span>Aziende</span></a>
                            @endif
                            @if($mv('annunci') && ($currentUser ?? $authUser)?->canAccessAnnouncements())
                                <a class="sidebar-link {{ ($activeNav ?? '') === 'annunci' ? 'active' : '' }}" href="{{ route('portal.announcements') }}"><span class="nav-icon">AN</span><span>Annunci</span></a>
                            @endif
                            @if($mv('shop') && ($currentUser ?? $authUser)?->canAccessMarketplace())
                                <a class="sidebar-link {{ ($activeNav ?? '') === 'shop' ? 'active' : '' }}" href="{{ route('portal.shop') }}"><span class="nav-icon">SH</span><span>Shop</span></a>
                            @endif
                            @if($mv('operatore') && (($currentUser ?? $authUser)?->hasRole('broker') || $isBackoffice))
                                <a class="sidebar-link {{ ($activeNav ?? '') === 'broker' ? 'active' : '' }}" href="{{ route('broker.dashboard') }}"><span class="nav-icon">BR</span><span>Operatore</span></a>
                            @endif
                            @if($mv('help'))
                            <a class="sidebar-link {{ ($activeNav ?? '') === 'help' ? 'active' : '' }}" href="{{ route('help.index') }}"><span class="nav-icon">&#x2753;</span><span>Assistenza</span></a>
                            @endif
                        @endif
                    </nav>
                </section>
                <section class="sidebar-panel">
                    <p class="sidebar-section-label">Presidio</p>
                    <p class="sidebar-note">{{ $isBackoffice ? 'Vista centrale per governare utenze, conti, limiti operativi e movimenti senza impersonare i clienti.' : ($isDelegate ? 'Console essenziale per il delegato con saldo, disponibilità e limiti residui del sottoconto assegnato.' : 'Un unico ambiente operativo per conto principale, sottoconti e movimenti del circuito.') }}</p>
                </section>
                <section class="sidebar-panel">
                    <div class="sidebar-user">
                        <div class="sidebar-avatar">{{ $userInitials }}</div>
                        <div>
                            <strong>{{ $authUser?->name }}</strong>
                            <span>{{ $authUser?->email }}</span>
                        </div>
                    </div>
                    @if(!$isBackoffice && $mv('profile'))
                    <a class="sidebar-link {{ ($activeNav ?? '') === 'profile' ? 'active' : '' }}" href="{{ route('portal.profile.edit') }}" style="margin-bottom:2px;">
                        <span class="nav-icon">PF</span><span>Profilo azienda</span>
                        @php
                            $__company = auth()->user()?->company;
                            $__filled = collect(['sector','tagline','description','city','website','phone','email'])
                                ->filter(fn($f) => !empty($__company?->{$f}))->count();
                            $__hasSocial = $__company && ($__company->linkedin_url || $__company->instagram_url || $__company->facebook_url);
                            $__pct = $__company ? round((($__filled + ($__hasSocial ? 1 : 0)) / 8) * 100) : 0;
                        @endphp
                        @if($__pct < 100)
                            <span style="margin-left:auto;font-size:10px;background:#7c3aed;color:#ede9fe;border-radius:4px;padding:1px 5px;font-weight:700;">{{ $__pct }}%</span>
                        @else
                            <span style="margin-left:auto;font-size:10px;background:#065f46;color:#d1fae5;border-radius:4px;padding:1px 5px;font-weight:700;">OK</span>
                        @endif
                    </a>
                    @endif
                    @if($mv('security'))
                    <a class="sidebar-link {{ ($activeNav ?? '') === 'security' ? 'active' : '' }}" href="{{ route('portal.security') }}" style="margin-bottom:6px;">
                        <span class="nav-icon">2F</span><span>Sicurezza</span>
                        @if(auth()->user()?->hasTwoFactorEnabled())
                            <span style="margin-left:auto;font-size:10px;background:#065f46;color:#d1fae5;border-radius:4px;padding:1px 5px;font-weight:700;">ON</span>
                        @endif
                    </a>
                    @endif
                    @if($mv('login-logs'))
                    <a class="sidebar-link {{ ($activeNav ?? '') === 'sessioni' ? 'active' : '' }}" href="{{ route('portal.login-logs') }}" style="margin-bottom:6px;">
                        <span class="nav-icon">&#x1F512;</span><span>Accessi</span>
                    </a>
                    @endif
                    @if(!$isBackoffice && $mv('balance-alerts'))
                    <a class="sidebar-link {{ ($activeNav ?? '') === 'balance-alerts' ? 'active' : '' }}" href="{{ route('portal.balance-alerts.index') }}" style="margin-bottom:6px;">
                        <span class="nav-icon">&#x1F514;</span> Avvisi saldo
                    </a>
                    @endif
                    @if(!$isBackoffice && $mv('beneficiari'))
                    <a class="sidebar-link {{ ($activeNav ?? '') === 'beneficiari' ? 'active' : '' }}" href="{{ route('portal.beneficiaries.index') }}" style="margin-bottom:6px;">
                        <span class="nav-icon">&#x1F4CB;</span><span>Beneficiari</span>
                    </a>
                    @endif
                    @if(!$isBackoffice && $mv('notifications'))
                    <a class="sidebar-link {{ ($activeNav ?? '') === 'notifications' ? 'active' : '' }}" href="{{ route('portal.notification-preferences') }}" style="margin-bottom:6px;">
                        <span class="nav-icon">&#x1F514;</span><span>Notifiche</span>
                    </a>
                    @endif
                    @if($mv('email-change'))
                    <a class="sidebar-link {{ ($activeNav ?? '') === 'email-change' ? 'active' : '' }}" href="{{ route('portal.email-change') }}" style="margin-bottom:6px;">
                        <span class="nav-icon">&#x2709;</span><span>Cambia email</span>
                    </a>
                    @endif
                    {{-- Cambia profilo con impronta (visibile solo se WebAuthn disponibile e non in backoffice) --}}
                    @if(!$isBackoffice)
                    <button id="btn-switch-profile" class="switch-profile-btn" type="button">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                        Cambia profilo
                    </button>
                    @endif
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="logout-btn" type="submit">Esci dal pannello</button>
                    </form>

                    {{-- Modal switch profilo --}}
                    <div class="switch-overlay" id="switch-overlay">
                        <div class="switch-modal">
                            <h3>Cambia profilo</h3>
                            <p>Usa la tua impronta o Face ID per accedere a un profilo diverso registrato su questo dispositivo.</p>
                            <button id="btn-switch-confirm" style="width:100%;min-height:52px;border:none;border-radius:14px;background:linear-gradient(135deg,#4d7386,#718b5c);color:#fff;font-size:16px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;" type="button">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/><path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/><path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/><path d="M12 12v.01"/></svg>
                                Autentica con impronta
                            </button>
                            <div id="switch-msg" class="switch-modal-msg"></div>
                            <button onclick="closeSwitchOverlay()" style="width:100%;margin-top:12px;border:none;background:none;color:var(--ink-muted);font-size:14px;cursor:pointer;padding:8px;">Annulla</button>
                        </div>
                    </div>
                </section>
            </div>
        </aside>
        <main class="content-shell">
            <header class="topbar">
                <button class="hamburger-btn" id="hamburger-btn" onclick="toggleSidebar()" aria-label="Menu">
                    <span></span><span></span><span></span>
                </button>
                <div class="topbar-title">
                    <h1>{{ $topbarTitle }}</h1>
                    <p>{{ $topbarSubtitle }}</p>
                </div>
                @hasSection('page-actions')
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    @yield('page-actions')
                </div>
                @endif
                <div class="topbar-tools">
                    @if (!$isBackoffice)
                    @php $unreadCount = auth()->user()?->unreadNotifications()->count() ?? 0; @endphp
                    <a href="{{ route('portal.notifications') }}" class="notif-bell" title="Notifiche">
                        🔔
                        @if ($unreadCount > 0)
                            <span class="notif-badge">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                        @endif
                    </a>
                    @endif
                    <button class="theme-toggle" id="km-theme-btn" onclick="toggleTheme()" title="Cambia tema">🌙</button>
                    @if ($switchableAccounts->count() > 1)
                    <div class="account-switcher-wrap">
                        <button class="account-switcher-btn" onclick="toggleSwitcher()" type="button">
                            <span>{{ ($activeAccountId ? $switchableAccounts->firstWhere('id', $activeAccountId)?->account_name : null) ?? $companyName }}</span>
                            <span style="font-size:10px;opacity:.6;">&#9660;</span>
                        </button>
                        <div class="account-switcher-menu" id="account-switcher-menu">
                            @foreach ($switchableAccounts as $sw)
                                <form method="POST" action="{{ route('portal.switch-account') }}" style="margin:0;">
                                    @csrf
                                    <input type="hidden" name="account_id" value="{{ $sw->id }}">
                                    <button type="submit" class="account-switcher-item {{ $activeAccountId == $sw->id ? 'active-account' : '' }}">
                                        {{ $sw->account_name ?? $sw->display_name }}
                                        @if ($sw->isSubAccount())
                                            <span class="sub-badge">sub</span>
                                        @endif
                                    </button>
                                </form>
                            @endforeach
                            @if ($activeAccountId)
                                <form method="POST" action="{{ route('portal.switch-account') }}" style="margin:0;">
                                    @csrf
                                    <input type="hidden" name="account_id" value="0">
                                    <button type="submit" class="account-switcher-item" style="border-top:1px solid var(--border);color:#888;">
                                        Torna al conto principale
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                    @else
                    <div class="company-switch">
                        <div class="sidebar-avatar" style="width:36px;height:36px;border-radius:10px;font-size:13px;">{{ $companyInitials }}</div>
                        <div>
                            <strong>{{ $companyName }}</strong>
                            <small>{{ $profileLabel }}</small>
                        </div>
                    </div>
                    @endif
                    <span class="pill {{ $isBackoffice ? 'warn' : ($isDelegate ? 'warn' : 'success') }}">{{ $profileLabel }}</span>
                </div>
            </header>
            @if (session('portal_success'))<div class="notice success">{{ session('portal_success') }}</div>@endif
            @if (session('portal_error'))<div class="notice error">{{ session('portal_error') }}</div>@endif
            @if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
            @yield('content')
        </main>
    </div>

    <script>

        function toggleSwitcher() {
            var menu = document.getElementById('account-switcher-menu');
            if (menu) menu.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            var wrap = document.querySelector('.account-switcher-wrap');
            var menu = document.getElementById('account-switcher-menu');
            if (menu && wrap && !wrap.contains(e.target)) {
                menu.classList.remove('open');
            }
        });

        function toggleSidebar() {
            var sidebar = document.querySelector('.sidebar');
            var overlay = document.getElementById('sidebar-overlay');
            var btn = document.getElementById('hamburger-btn');
            sidebar.classList.toggle('is-open');
            overlay.classList.toggle('is-open');
            if (btn) btn.classList.toggle('open');
        }
        // ── Switch profilo WebAuthn ─────────────────────────────────────────────────
        (function () {
            // Helpers base64url
            function b64urlToBuffer(b) {
                b = b.replace(/-/g, '+').replace(/_/g, '/');
                var pad = b.length % 4; if (pad) b += '===='.slice(pad);
                var bin = atob(b), buf = new Uint8Array(bin.length);
                for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
                return buf.buffer;
            }
            function bufferToB64url(buf) {
                var bytes = new Uint8Array(buf), bin = '';
                bytes.forEach(function(b) { bin += String.fromCharCode(b); });
                return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
            }

            var switchBtn = document.getElementById('btn-switch-profile');
            var confirmBtn = document.getElementById('btn-switch-confirm');
            var overlay = document.getElementById('switch-overlay');
            var msg = document.getElementById('switch-msg');

            // Mostra il bottone solo se il browser supporta WebAuthn
            if (switchBtn && window.PublicKeyCredential) {
                switchBtn.style.display = 'flex';
            }

            window.closeSwitchOverlay = function () {
                if (overlay) overlay.classList.remove('open');
                if (msg) { msg.style.display = 'none'; msg.className = 'switch-modal-msg'; }
            };

            if (switchBtn) {
                switchBtn.addEventListener('click', function () {
                    if (overlay) overlay.classList.add('open');
                    closeSidebar();
                });
            }

            if (confirmBtn) {
                confirmBtn.addEventListener('click', async function () {
                    confirmBtn.disabled = true;
                    confirmBtn.textContent = 'In attesa del dispositivo…';
                    if (msg) { msg.style.display = 'none'; }

                    try {
                        // 1. Ottieni challenge discoverable
                        var optRes = await fetch('{{ route("webauthn.switch.options") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: '{}',
                        });
                        var optData = await optRes.json();
                        if (!optRes.ok) { showSwitchMsg(optData.error || 'Errore opzioni.', 'err'); return; }

                        // 2. Decodifica challenge
                        optData.challenge = b64urlToBuffer(optData.challenge);
                        if (optData.allowCredentials) {
                            optData.allowCredentials = optData.allowCredentials.map(function (c) {
                                return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
                            });
                        }

                        // 3. Prompt biometrico — browser mostra tutti i profili registrati
                        var assertion = await navigator.credentials.get({ publicKey: optData });

                        // 4. Prepara payload
                        var payload = {
                            id: assertion.id,
                            rawId: bufferToB64url(assertion.rawId),
                            type: assertion.type,
                            response: {
                                clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
                                authenticatorData: bufferToB64url(assertion.response.authenticatorData),
                                signature: bufferToB64url(assertion.response.signature),
                                userHandle: assertion.response.userHandle ? bufferToB64url(assertion.response.userHandle) : null,
                            },
                        };

                        // 5. Verifica e switch
                        var verRes = await fetch('{{ route("webauthn.switch.verify") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify(payload),
                        });
                        var verData = await verRes.json();

                        if (!verRes.ok) { showSwitchMsg(verData.error || 'Switch fallito.', 'err'); return; }

                        if (verData.same_user) {
                            showSwitchMsg('Sei già su questo profilo.', 'ok');
                            return;
                        }

                        showSwitchMsg('Profilo cambiato! Reindirizzamento…', 'ok');
                        setTimeout(function () { window.location.href = verData.redirect; }, 700);

                    } catch (err) {
                        if (err.name === 'NotAllowedError') {
                            showSwitchMsg('Autenticazione annullata.', 'err');
                        } else {
                            showSwitchMsg('Errore: ' + err.message, 'err');
                        }
                    } finally {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.5 2 5.5 4.1 4.2 7.1"/><path d="M3.5 12c0-1.4.3-2.7.8-3.9"/><path d="M12 22c3.5 0 6.5-2.1 7.8-5.1"/><path d="M20.5 12c0 1.4-.3 2.7-.8 3.9"/><path d="M12 8a4 4 0 0 1 4 4c0 1-.2 2-.7 2.8"/><path d="M8.5 15.2A4 4 0 0 1 8 12a4 4 0 0 1 4-4"/><path d="M12 12v.01"/></svg> Autentica con impronta';
                    }
                });
            }

            function showSwitchMsg(text, type) {
                if (!msg) return;
                msg.textContent = text;
                msg.className = 'switch-modal-msg ' + type;
                msg.style.display = 'block';
            }
        })();
        // ── Fine switch profilo ──────────────────────────────────────────────────

        function closeSidebar() {
            var sidebar = document.querySelector('.sidebar');
            var overlay = document.getElementById('sidebar-overlay');
            var btn = document.getElementById('hamburger-btn');
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-open');
            if (btn) btn.classList.remove('open');
        }
        /* Chiudi sidebar quando si clicca un link (navigazione mobile) */
        document.addEventListener('DOMContentLoaded', function () {
            var links = document.querySelectorAll('.sidebar-link, .sidebar-sublink');
            links.forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth <= 768) closeSidebar();
                });
            });
        });

        function _updateThemeColor(isDark) {
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', isDark ? '#0d1a30' : '#0b2244');
        }

        function toggleTheme() {
            var html = document.documentElement;
            var isDark = html.getAttribute('data-theme') === 'dark';
            var next = isDark ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('km-theme', next);
            var btn = document.getElementById('km-theme-btn');
            if (btn) btn.textContent = next === 'dark' ? '☀️' : '🌙';
            _updateThemeColor(next === 'dark');
        }

        /* Sync button icon + theme-color on page load */
        (function () {
            var btn = document.getElementById('km-theme-btn');
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (btn) btn.textContent = isDark ? '☀️' : '🌙';
            _updateThemeColor(isDark);
        })();

        /* Swipe gesture: bordo sinistro → apre sidebar; swipe left → chiude */
        (function () {
            var startX = null, startY = null;
            var EDGE = 32, MIN_SWIPE = 55, MAX_VERTICAL = 90;
            document.addEventListener('touchstart', function (e) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
            }, { passive: true });
            document.addEventListener('touchend', function (e) {
                if (startX === null) return;
                var dx = e.changedTouches[0].clientX - startX;
                var dy = Math.abs(e.changedTouches[0].clientY - startY);
                if (dy > MAX_VERTICAL) { startX = null; return; }
                var sidebar = document.querySelector('.sidebar');
                if (startX < EDGE && dx > MIN_SWIPE) {
                    // Swipe right da bordo: apri sidebar
                    if (sidebar && !sidebar.classList.contains('is-open')) toggleSidebar();
                } else if (dx < -MIN_SWIPE) {
                    // Swipe left ovunque: chiudi sidebar se aperta
                    if (sidebar && sidebar.classList.contains('is-open')) closeSidebar();
                }
                startX = null;
            }, { passive: true });
        })();

        /* PWA — Service Worker + Push Notifications */
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js')
                    .then(function (reg) {
                        console.log('[KMoney SW] Registrato:', reg.scope);
                        // Inizializza push dopo che il SW e' pronto
                        if ('PushManager' in window) {
                            reg.ready.then(function (readyReg) {
                                window._kmPushInit(readyReg);
                            });
                        }
                    })
                    .catch(function (err) { console.warn('[KMoney SW] Registrazione fallita:', err); });
            });
        }

        /* Web Push — sottoscrizione automatica se l'utente ha gia' dato il permesso */
        window._kmPushInit = function (reg) {
            var pushEnabled = localStorage.getItem('km-push-enabled');
            if (pushEnabled !== '1') return; // non chiedere fino a consenso esplicito

            reg.pushManager.getSubscription().then(function (existing) {
                if (existing) return; // gia' iscritto
                _kmPushSubscribe(reg);
            });
        };

        window._kmPushSubscribe = function (reg) {
            fetch('/push/vapid-key')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var applicationServerKey = _urlBase64ToUint8Array(data.publicKey);
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: applicationServerKey
                    });
                })
                .then(function (sub) {
                    var key  = sub.getKey('p256dh');
                    var auth = sub.getKey('auth');
                    return fetch('/push/subscribe', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? ''
                        },
                        body: JSON.stringify({
                            endpoint: sub.endpoint,
                            keys: {
                                p256dh: key  ? btoa(String.fromCharCode.apply(null, new Uint8Array(key)))  : '',
                                auth:   auth ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth))) : ''
                            },
                            contentEncoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0]
                        })
                    });
                })
                .then(function (r) {
                    if (r.ok) {
                        localStorage.setItem('km-push-enabled', '1');
                        console.log('[KMoney Push] Iscrizione completata.');
                    }
                })
                .catch(function (err) {
                    console.warn('[KMoney Push] Errore iscrizione:', err);
                    localStorage.removeItem('km-push-enabled');
                });
        };

        /* Richiede il permesso push e si iscrive (chiamato dal pulsante nelle notifiche) */
        window.kmRequestPush = function () {
            if (! ('Notification' in window) || ! ('serviceWorker' in navigator)) return;
            Notification.requestPermission().then(function (permission) {
                if (permission !== 'granted') return;
                localStorage.setItem('km-push-enabled', '1');
                navigator.serviceWorker.ready.then(function (reg) {
                    _kmPushSubscribe(reg);
                });
            });
        };

        /* Utility: converte base64url in Uint8Array per applicationServerKey */
        function _urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var output  = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i) { output[i] = rawData.charCodeAt(i); }
            return output;
        }

        /* PWA — Install prompt (banner "Aggiungi alla schermata home") */
        (function () {
            var deferredPrompt = null;
            var banner = null;

            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                deferredPrompt = e;
                window._kmInstallPrompt = e; // esposto globalmente per altre pagine

                banner = document.createElement('div');
                banner.id = 'pwa-install-banner';
                banner.style.cssText = [
                    'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);',
                    'background:#0b2244;color:#fff;padding:12px 20px;border-radius:12px;',
                    'display:flex;align-items:center;gap:12px;z-index:9999;',
                    'box-shadow:0 4px 24px rgba(0,0,0,.28);font-size:13px;',
                    'max-width:calc(100vw - 32px);'
                ].join('');
                banner.innerHTML = [
                    '<span>Installa <strong>KMoney</strong> come app</span>',
                    '<button id="pwa-install-btn" style="background:#0f52c4;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;">Installa</button>',
                    '<button id="pwa-dismiss-btn" style="background:transparent;color:rgba(255,255,255,.5);border:none;cursor:pointer;font-size:16px;padding:4px;">&#x2715;</button>'
                ].join('');
                document.body.appendChild(banner);

                document.getElementById('pwa-install-btn').addEventListener('click', function () {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then(function () {
                        deferredPrompt = null;

                        deferredPrompt = null;
                        banner.remove();
                    });
                });

                document.getElementById('pwa-dismiss-btn').addEventListener('click', function () {
                    banner.remove();
                });
            });
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('select').forEach(function (el) {
                // Skip if already initialized or explicitly excluded
                if (el.tomselect || el.dataset.noSearch !== undefined) return;
                new TomSelect(el, {
                    allowEmptyOption: true,
                    placeholder: el.options[0]?.text || '',
                    searchField: ['text'],
                    maxOptions: null,
                    plugins: el.multiple ? ['remove_button'] : [],
                    render: {
                        no_results: function() {
                            return '<div class="no-results" style="padding:10px 14px;color:#64748b;">Nessun risultato</div>';
                        }
                    }
                });
            });
        });
    </script>
    @stack('scripts')

    {{-- Mobile Bottom Navigation (solo portal, non backoffice) --}}
    @if (!$isBackoffice)
    <nav class="mobile-bottom-nav" id="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="{{ route('portal.dashboard') }}"
               class="mobile-tab {{ ($activeNav ?? '') === 'conto' ? 'active' : '' }}">
                <span class="mobile-tab-icon">🏠</span>
                <span class="mobile-tab-label">Conto</span>
            </a>
            <a href="{{ route('portal.movements') }}"
               class="mobile-tab {{ ($activeNav ?? '') === 'movimenti' ? 'active' : '' }}">
                <span class="mobile-tab-icon">📊</span>
                <span class="mobile-tab-label">Movimenti</span>
            </a>
            <a href="{{ route('portal.wallet') }}"
               class="mobile-tab {{ ($activeNav ?? '') === 'wallet' ? 'active' : '' }}">
                <span class="mobile-tab-icon">💳</span>
                <span class="mobile-tab-label">Wallet</span>
            </a>
            <a href="{{ route('portal.requests') }}"
               class="mobile-tab {{ in_array($activeNav ?? '', ['richieste', 'richieste-text']) ? 'active' : '' }}">
                <span class="mobile-tab-icon">📋</span>
                <span class="mobile-tab-label">Richieste</span>
            </a>
            <button type="button" class="mobile-tab" onclick="toggleSidebar()" aria-label="Menu completo">
                <span class="mobile-tab-icon">☰</span>
                <span class="mobile-tab-label">Menu</span>
            </button>
        </div>
    </nav>
    @endif

    {{-- Legal Footer --}}
    <footer style="background:var(--navy-deep,#06152a);border-top:1px solid rgba(255,255,255,.07);padding:14px 24px;text-align:center;">
        <p style="margin:0;font-size:11px;color:rgba(255,255,255,.38);">
            &copy; {{ date('Y') }} KMoney &mdash;
            <a href="{{ route('legal.privacy') }}" style="color:rgba(255,255,255,.5);">Privacy</a> &middot;
            <a href="{{ route('legal.terms') }}" style="color:rgba(255,255,255,.5);">Termini</a> &middot;
            <a href="{{ route('legal.contract') }}" style="color:rgba(255,255,255,.5);">Contratto</a> &middot;
            <a href="{{ route('legal.limits') }}" style="color:rgba(255,255,255,.5);">Limiti</a> &middot;
            <a href="{{ route('legal.aml-kyc') }}" style="color:rgba(255,255,255,.5);">AML/KYC</a> &middot;
            <a href="{{ route('legal.complaints') }}" style="color:rgba(255,255,255,.5);">Reclami</a>
        </p>
    </footer>
</body>
</html>