<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Pagamento KMoney</title>
    <link rel="stylesheet" href="{{ asset('build/assets/' . Vite::asset('resources/css/app.css')) }}" onerror="this.remove()">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f9fafb;color:#111;padding:20px;min-height:100vh;display:flex;align-items:center;justify-content:center;}
        .card{background:#fff;border-radius:16px;padding:28px 24px;max-width:360px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.08);}
        .amount{font-size:48px;font-weight:900;letter-spacing:-1.5px;text-align:center;color:#6d28d9;margin:16px 0 4px;}
        .currency{font-size:20px;font-weight:600;color:#7c3aed;text-align:center;margin-bottom:6px;}
        .merchant{font-size:14px;color:#6b7280;text-align:center;margin-bottom:24px;}
        .btn{display:block;width:100%;padding:14px;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;transition:opacity .15s;}
        .btn-primary{background:#6d28d9;color:#fff;margin-bottom:10px;}
        .btn-primary:hover{opacity:.9}
        .btn-primary:disabled{opacity:.5;cursor:not-allowed}
        .btn-secondary{background:#f3f4f6;color:#374151;}
        .status{text-align:center;font-size:13px;color:#6b7280;margin-top:14px;min-height:20px;}
        .logo{text-align:center;margin-bottom:20px;font-size:22px;font-weight:800;color:#6d28d9;letter-spacing:-.5px;}
        .divider{border-top:1px solid #e5e7eb;margin:16px 0;}
        .detail-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;}
        .detail-label{color:#6b7280;}
        .detail-value{font-weight:600;color:#111;}
        .balance-row{background:#f5f3ff;border-radius:10px;padding:12px 14px;margin-bottom:20px;}
    </style>
</head>
<body>
<div class="card" id="card-loading">
    <div class="logo">KMoney</div>
    <div style="text-align:center;color:#6b7280;font-size:14px;padding:20px 0;">Caricamento...</div>
</div>

<div class="card" id="card-main" style="display:none;">
    <div class="logo">KMoney</div>
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;text-align:center;margin-bottom:8px;">Conferma pagamento</div>
    <div class="amount" id="h-amount">-</div>
    <div class="currency">KY</div>
    <div class="merchant" id="h-label">-</div>

    <div class="balance-row">
        <div class="detail-row" style="margin-bottom:0">
            <span class="detail-label">Saldo disponibile</span>
            <span class="detail-value" id="h-balance">-</span>
        </div>
    </div>

    <div class="divider"></div>

    <div id="h-account-row" class="detail-row">
        <span class="detail-label">Dal tuo conto</span>
        <span class="detail-value" id="h-account">-</span>
    </div>

    <div style="margin-top:4px;margin-bottom:20px;font-size:12px;color:#9ca3af;text-align:right;" id="h-expire"></div>

    <button class="btn btn-primary" id="btn-confirm">Paga ora</button>
    <button class="btn btn-secondary" id="btn-cancel">Annulla</button>
    <div class="status" id="h-status"></div>
</div>

<div class="card" id="card-error" style="display:none;">
    <div class="logo">KMoney</div>
    <div style="text-align:center;padding:16px 0;">
        <div style="font-size:48px;margin-bottom:12px;">⚠️</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:8px;color:#dc2626;">Errore</div>
        <div id="error-msg" style="font-size:13px;color:#6b7280;"></div>
    </div>
    <button class="btn btn-secondary" onclick="sendCancel()">Chiudi</button>
</div>

<div class="card" id="card-success" style="display:none;">
    <div class="logo">KMoney</div>
    <div style="text-align:center;padding:16px 0;">
        <div style="font-size:56px;margin-bottom:12px;">✓</div>
        <div style="font-size:18px;font-weight:700;color:#059669;">Pagamento inviato!</div>
        <div id="success-msg" style="font-size:13px;color:#6b7280;margin-top:6px;"></div>
    </div>
</div>

<script>
(function(){
    const params    = new URLSearchParams(window.location.search);
    const amount    = params.get('amount')   || '0';
    const label     = params.get('label')    || 'Pagamento';
    const prId      = params.get('pr_id')    || '';
    const CSRF      = document.querySelector('meta[name=csrf-token]')?.content || '';
    const VERIFY    = '/paga/handler/pay';
    const METHOD    = '/paga/handler';   // payment method identifier

    let accountInfo = null;

    // ── Carica dati account utente ─────────────────────────────────────────
    async function loadAccount() {
        try {
            const r = await fetch('/api/v1/me', {
                headers:{ 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' }
            });
            if (!r.ok) throw new Error('Non autenticato');
            const d = await r.json();
            accountInfo = d;

            document.getElementById('h-amount').textContent  = Number(amount).toLocaleString('it-IT');
            document.getElementById('h-label').textContent   = label;
            document.getElementById('h-balance').textContent = (d.available_balance ?? '?') + ' KY';
            document.getElementById('h-account').textContent = d.account_number ?? '-';

            const remaining = params.get('seconds_left');
            if (remaining) {
                document.getElementById('h-expire').textContent = 'Scade tra ' + remaining + ' s';
            }

            document.getElementById('card-loading').style.display = 'none';
            document.getElementById('card-main').style.display    = 'block';
        } catch(e) {
            showError('Non sei autenticato su KMoney. Apri prima l\'app.');
        }
    }

    // ── Conferma pagamento ─────────────────────────────────────────────────
    document.getElementById('btn-confirm').addEventListener('click', async () => {
        const btn = document.getElementById('btn-confirm');
        btn.disabled = true;
        btn.textContent = 'Pagamento in corso...';
        document.getElementById('h-status').textContent = '';

        const pr_token = params.get('pr_token') || '';

        try {
            const r = await fetch(VERIFY, {
                method: 'POST',
                headers:{
                    'Content-Type':'application/json',
                    'Accept':'application/json',
                    'X-CSRF-TOKEN': CSRF,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ pr_token, pr_id: prId }),
            });
            const d = await r.json();

            if (!r.ok) {
                document.getElementById('h-status').textContent = d.error || 'Errore pagamento.';
                document.getElementById('h-status').style.color = '#dc2626';
                btn.disabled = false; btn.textContent = 'Paga ora';
                return;
            }

            // Notifica il SW per risolvere la PaymentRequest
            await sendConfirm(d.transferUuid);

            document.getElementById('card-main').style.display   = 'none';
            document.getElementById('card-success').style.display = 'block';
            document.getElementById('success-msg').textContent    =
                Number(amount).toLocaleString('it-IT') + ' KY inviati';

        } catch(e) {
            document.getElementById('h-status').textContent = 'Errore di rete. Riprova.';
            document.getElementById('h-status').style.color = '#dc2626';
            btn.disabled = false; btn.textContent = 'Paga ora';
        }
    });

    document.getElementById('btn-cancel').addEventListener('click', sendCancel);

    // ── Messaggi al SW ─────────────────────────────────────────────────────
    async function sendConfirm(transferUuid) {
        if (!navigator.serviceWorker?.controller) return;
        navigator.serviceWorker.controller.postMessage({
            type:         'ky-payment-confirm',
            pr_id:        prId,
            methodName:   METHOD,
            transferUuid: transferUuid ?? null,
        });
    }

    function sendCancel() {
        if (navigator.serviceWorker?.controller) {
            navigator.serviceWorker.controller.postMessage({
                type:  'ky-payment-cancel',
                pr_id: prId,
            });
        }
        window.close();
    }

    function showError(msg) {
        document.getElementById('card-loading').style.display = 'none';
        document.getElementById('card-main').style.display    = 'none';
        document.getElementById('card-error').style.display   = 'block';
        document.getElementById('error-msg').textContent      = msg;
    }

    loadAccount();
})();
</script>
</body>
</html>
