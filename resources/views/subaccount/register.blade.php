<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione delegato — KMoney</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.1); padding: 40px 36px; max-width: 460px; width: 100%; }
        .logo { font-size: 28px; font-weight: 800; color: #1a56db; margin-bottom: 4px; }
        h1 { margin: 12px 0 4px; font-size: 21px; }
        .subtext { color: #555; font-size: 14px; margin-bottom: 28px; }
        .field { margin-bottom: 16px; }
        label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 5px; color: #333; }
        input { width: 100%; padding: 11px 13px; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: 15px; outline: none; transition: border-color .2s; }
        input:focus { border-color: #1a56db; }
        .email-locked { background: #f4f6f9; color: #666; cursor: not-allowed; }
        .btn { width: 100%; padding: 13px; border-radius: 10px; background: #1a56db; color: #fff; font-size: 16px; font-weight: 600; border: none; cursor: pointer; margin-top: 8px; }
        .error { background: #fef2f2; color: #b91c1c; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 42px !important; }
        .pw-eye { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px; color: #9ca3af; display: flex; align-items: center; }
        .pw-eye:hover { color: #374151; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">KY</div>
        <h1>Crea il tuo accesso</h1>
        <p class="subtext">
            Stai per diventare gestore del sottoconto
            <strong>«{{ $subAccount->account_name }}»</strong>
            di <strong>{{ $ownerName }}</strong>.
        </p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('subaccount.invitation.register.post', $invitation->token) }}">
            @csrf
            <div class="field">
                <label>Email (non modificabile)</label>
                <input type="email" value="{{ $invitation->email }}" class="email-locked" readonly>
            </div>
            <div class="field">
                <label for="name">Il tuo nome</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="Mario Rossi" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Minimo 8 caratteri" required>
            </div>
            <div class="field">
                <label for="password_confirmation">Conferma password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required>
            </div>
            <button type="submit" class="btn">Registrati e accedi</button>
        </form>
    </div>
<script>
(function () {
    var eyeShow = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var eyeHide = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    document.querySelectorAll('input[type=password]').forEach(function (input) {
        var wrap = document.createElement('div');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Mostra/nascondi password');
        btn.className = 'pw-eye';
        btn.innerHTML = eyeShow;
        btn.addEventListener('click', function () {
            var visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.innerHTML = visible ? eyeShow : eyeHide;
        });
        wrap.appendChild(btn);
    });
})();
</script>
@include('partials.password-toggle')
</body>
</html>
