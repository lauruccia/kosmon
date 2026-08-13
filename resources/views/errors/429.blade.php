@php
    $retryAfterSeconds = 0;

    if (isset($exception) && method_exists($exception, 'getHeaders')) {
        $retryAfterSeconds = (int) ($exception->getHeaders()['Retry-After'] ?? 0);
    }

    $retryMinutes = $retryAfterSeconds > 0 ? (int) ceil($retryAfterSeconds / 60) : null;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Troppe richieste — KMoney</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .topnav {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 14px 24px;
        }
        .topnav-brand {
            font-weight: 800;
            font-size: 1.2rem;
            color: #0f766e;
            text-decoration: none;
        }
        .wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 40px 36px;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: #ecfdf5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        h1 {
            font-size: 1.3rem;
            font-weight: 800;
            margin: 0 0 10px;
        }
        p.lead {
            font-size: 14.5px;
            color: #64748b;
            line-height: 1.6;
            margin: 0 0 20px;
        }
        .countdown-box {
            background: #f0fdfa;
            border: 1px solid #99f6e4;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 24px;
        }
        .countdown-label {
            font-size: 12.5px;
            color: #0f766e;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 4px;
        }
        #countdown {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f766e;
            font-variant-numeric: tabular-nums;
        }
        .btn {
            display: inline-block;
            width: 100%;
            padding: 12px 20px;
            border-radius: 10px;
            border: none;
            background: #0f766e;
            color: #fff;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            margin-bottom: 12px;
        }
        .btn:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }
        .btn-link {
            display: block;
            font-size: 13.5px;
            color: #64748b;
            text-decoration: none;
        }
        .btn-link:hover { color: #0f766e; }
    </style>
</head>
<body>

<nav class="topnav">
    <a href="{{ route('home') }}" class="topnav-brand">KMoney</a>
</nav>

<div class="wrap">
    <div class="card">
        <div class="icon">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
        </div>

        <h1>Troppe richieste</h1>
        <p class="lead">Per la tua sicurezza abbiamo messo in pausa questa operazione dopo alcuni tentativi ravvicinati. Non è successo nulla di grave: basta attendere qualche minuto e riprovare.</p>

        <div class="countdown-box">
            <div class="countdown-label" id="retry-status">Puoi riprovare tra</div>
            <div id="countdown">
                @if($retryMinutes)
                    circa {{ $retryMinutes }} {{ $retryMinutes === 1 ? 'minuto' : 'minuti' }}
                @else
                    qualche minuto
                @endif
            </div>
        </div>

        <button type="button" class="btn" id="retry-btn">Riprova ora</button>

        @auth
            <a href="{{ route('portal.dashboard') }}" class="btn-link">Torna alla dashboard</a>
        @else
            <a href="{{ route('home') }}" class="btn-link">Torna alla home</a>
        @endauth
    </div>
</div>

<script>
(function () {
    var retryAfter = {{ (int) $retryAfterSeconds }};
    var countdownEl = document.getElementById('countdown');
    var statusEl = document.getElementById('retry-status');
    var btn = document.getElementById('retry-btn');

    btn.addEventListener('click', function () {
        window.location.reload();
    });

    if (retryAfter <= 0) {
        return;
    }

    btn.disabled = true;
    var target = Date.now() + retryAfter * 1000;

    function render() {
        var remaining = Math.max(0, Math.round((target - Date.now()) / 1000));

        if (remaining <= 0) {
            statusEl.textContent = 'Ora puoi riprovare';
            countdownEl.textContent = '';
            btn.disabled = false;
            clearInterval(timer);
            return;
        }

        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        countdownEl.textContent = m > 0
            ? m + ':' + (s < 10 ? '0' : '') + s + ' min'
            : s + ' sec';
    }

    render();
    var timer = setInterval(render, 1000);
})();
</script>

</body>
</html>
