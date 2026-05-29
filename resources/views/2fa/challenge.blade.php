<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifica identita - KMoney</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg, #edf1f8);
            font-family: system-ui, sans-serif;
        }
        .challenge-card {
            background: var(--surface, #fff);
            border-radius: 16px;
            box-shadow: 0 4px 32px rgba(10,30,60,.12);
            padding: 40px 36px;
            width: 100%;
            max-width: 400px;
        }
        .shield-icon {
            width: 56px; height: 56px;
            background: #e6effc;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
        }
        h1 { font-size: 20px; font-weight: 700; text-align: center; color: var(--ink, #0d1c30); margin-bottom: 6px; }
        p { font-size: 14px; color: var(--ink-soft, #4a637d); text-align: center; margin-bottom: 28px; }
        label { font-size: 13px; font-weight: 600; color: var(--ink, #0d1c30); display: block; margin-bottom: 6px; }
        input[name="code"] {
            width: 100%;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 8px;
            text-align: center;
            border: 2px solid var(--line, #dde6f0);
            border-radius: 10px;
            padding: 14px 10px;
            color: var(--ink, #0d1c30);
            background: var(--surface-soft, #f8fafc);
            outline: none;
            transition: border-color .15s;
            box-sizing: border-box;
        }
        input[name="code"]:focus { border-color: var(--primary, #0f52c4); }
        .error-msg { color: #9f1239; font-size: 13px; margin-top: 8px; }
        .btn-primary {
            width: 100%;
            margin-top: 20px;
            background: var(--primary, #0f52c4);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-primary:hover { background: var(--primary-strong, #0b3f9a); }
        .logout-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: var(--ink-muted, #7a95aa);
            text-decoration: none;
        }
        .logout-link:hover { color: var(--ink-soft, #4a637d); }
    </style>
</head>
<body>
    <div class="challenge-card">
        <div class="shield-icon">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#0f52c4" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>

        <h1>Verifica identita</h1>
        <p>Inserisci il codice a 6 cifre dalla tua<br>app di autenticazione (es. Google Authenticator).</p>

        <form method="POST" action="{{ route('2fa.verify') }}" autocomplete="off">
            @csrf

            <label for="code">Codice OTP</label>
            <input
                id="code"
                type="text"
                name="code"
                inputmode="numeric"
                maxlength="6"
                pattern="\d{6}"
                placeholder="000000"
                autofocus
                required
            >

            @error('code')
                <div class="error-msg">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn-primary">Verifica e accedi</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer;width:100%;">
                Non sei tu? Esci
            </button>
        </form>

        {{-- Toggle codice di recupero --}}
        <div style="margin-top:18px;text-align:center;">
            <button
                type="button"
                onclick="toggleRecovery()"
                id="recovery-toggle"
                style="background:none;border:none;cursor:pointer;font-size:12px;color:var(--ink-muted,#7a95aa);text-decoration:underline;"
            >
                Non hai accesso all'app? Usa un codice di recupero
            </button>
        </div>

        <div id="recovery-section" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--line,#dde6f0);">
            <form method="POST" action="{{ route('2fa.verify') }}" autocomplete="off">
                @csrf
                <label for="recovery-input">Codice di recupero</label>
                <input
                    id="recovery-input"
                    type="text"
                    name="recovery_code"
                    placeholder="xxxxx-xxxxx"
                    autocomplete="off"
                    style="width:100%;font-size:16px;font-weight:600;letter-spacing:2px;text-align:center;border:2px solid var(--line,#dde6f0);border-radius:10px;padding:12px 10px;color:var(--ink,#0d1c30);background:var(--surface-soft,#f8fafc);outline:none;box-sizing:border-box;"
                >
                @error('code')
                    <div class="error-msg">{{ $message }}</div>
                @enderror
                <button type="submit" class="btn-primary" style="margin-top:14px;">
                    Accedi con codice di recupero
                </button>
            </form>
            <button
                type="button"
                onclick="toggleRecovery()"
                style="width:100%;margin-top:12px;background:none;border:none;cursor:pointer;font-size:12px;color:var(--ink-muted,#7a95aa);text-decoration:underline;"
            >
                Torna al codice OTP
            </button>
        </div>
    </div>

    <script>
        const input = document.getElementById('code');
        input.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value.length === 6) {
                this.closest('form').submit();
            }
        });

        function toggleRecovery() {
            const section = document.getElementById('recovery-section');
            const toggle  = document.getElementById('recovery-toggle');
            const visible = section.style.display !== 'none';
            section.style.display = visible ? 'none' : 'block';
            toggle.style.display  = visible ? '' : 'none';
            if (!visible) document.getElementById('recovery-input').focus();
        }
    </script>
</body>
</html>
