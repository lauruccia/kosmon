<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invito sottoconto — KMoney</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.1); padding: 40px 36px; max-width: 440px; width: 100%; text-align: center; }
        .logo { font-size: 28px; font-weight: 800; color: #1a56db; margin-bottom: 8px; }
        h1 { margin: 20px 0 8px; font-size: 22px; }
        p { color: #555; margin: 0 0 24px; font-size: 15px; line-height: 1.5; }
        .badge { display: inline-block; background: #eff6ff; color: #1a56db; padding: 6px 14px; border-radius: 99px; font-weight: 600; font-size: 14px; margin-bottom: 20px; }
        .btn { display: block; width: 100%; padding: 13px; border-radius: 10px; font-size: 16px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; }
        .btn-primary { background: #1a56db; color: #fff; margin-bottom: 12px; }
        .btn-secondary { background: #f4f6f9; color: #444; }
        .expires { font-size: 12px; color: #999; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">KY</div>
        <div class="badge">{{ $ownerName }}</div>
        <h1>Sei stato invitato a gestire un sottoconto</h1>
        <p>
            <strong>{{ $ownerName }}</strong> ti ha assegnato la gestione del sottoconto
            <strong>«{{ $subAccount->account_name }}»</strong>.
        </p>
        <p>Potrai effettuare pagamenti in KY nei limiti stabiliti dal titolare.</p>

        <a href="{{ route('subaccount.invitation.register', $invitation->token) }}" class="btn btn-primary">
            Registrati e accetta
        </a>
        <a href="{{ route('login') }}" class="btn btn-secondary">
            Ho già un account — Accedi
        </a>

        <p class="expires">Invito valido fino al {{ $invitation->expires_at->format('d/m/Y') }}</p>
    </div>
</body>
</html>
