@extends('layouts.portal')

@section('content')

    <div class="portal-grid" style="grid-template-columns:1fr;">
        <div class="stack">
            {{-- Card stato principale --}}
            <section class="card card-pad" id="nfc-status-card">

                {{-- Stato: in attesa --}}
                <div id="state-pending">

                    {{-- Grid 3 colonne --}}
                    <div class="nfc-cols">

                        {{-- Colonna 1: importo + countdown --}}
                        <div class="nfc-col-info">
                            <div id="nfc-icon" style="font-size:48px;margin-bottom:10px;animation:nfcPulse 1.6s ease-in-out infinite;">&#128246;</div>
                            <div style="font-size:32px;font-weight:800;color:var(--ink);line-height:1.1;">
                                {{ ky_format($pr->amount) }} KY
                            </div>
                            @if($pr->description)
                                <div style="color:var(--ink-muted);font-size:14px;margin-top:6px;">{{ $pr->description }}</div>
                            @endif
                            <div style="margin-top:16px;font-size:12px;color:var(--ink-muted);">
                                Scade tra <span id="countdown" style="font-weight:700;color:var(--ink);">5:00</span>
                            </div>
                            <div style="margin-top:6px;height:4px;background:var(--surface-soft);border-radius:2px;overflow:hidden;">
                                <div id="timer-bar" style="height:100%;background:var(--primary);transition:width .5s linear;width:100%;"></div>
                            </div>
                            <div style="margin-top:20px;">
                                <form method="POST" action="{{ route('portal.incasso-nfc.cancel', $pr->token) }}">
                                    @csrf
                                    <button type="submit" class="cta secondary" style="font-size:12px;padding:7px 16px;width:100%;">
                                        Annulla
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- Divisore --}}
                        <div class="nfc-divider"></div>

                        {{-- Colonna 2: QR + NFC smartphone --}}
                        <div class="nfc-col-qr">
                            <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Scansiona il QR</div>
                            <div id="qr-container" style="display:inline-block;padding:10px;background:#fff;border:1px solid var(--line);border-radius:10px;"></div>
                            <div style="font-size:11px;color:var(--ink-muted);margin-top:6px;">Il cliente inquadra con la fotocamera</div>

                            <div style="margin-top:14px;">
                                <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Avvicina smartphone</div>
                                <div id="nfc-status-bar" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:8px;padding:9px 12px;font-size:12px;font-weight:600;color:var(--ink-muted);text-align:center;">
                                    Inizializzazione NFC...
                                </div>
                                <button type="button" id="nfc-start-button" class="cta" style="margin-top:6px;width:100%;font-size:12px;padding:8px 12px;display:none;">
                                    Attiva NFC
                                </button>
                            </div>
                        </div>

                        {{-- Divisore --}}
                        <div class="nfc-divider"></div>

                        {{-- Colonna 3: Card NFC fisica (azione principale) --}}
                        <div class="nfc-col-card">
                            <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Card NFC fisica</div>
                            <div id="card-nfc-status" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:8px;padding:9px 12px;font-size:12px;font-weight:600;color:var(--ink-muted);text-align:center;margin-bottom:8px;display:none;">
                                Inizializzazione...
                            </div>
                            <button type="button" id="card-nfc-btn" class="cta" style="width:100%;font-size:14px;padding:16px 10px;" onclick="startCardScan()">
                                &#128179; Richiedi pagamento CARD NFC
                            </button>
                            <div id="card-nfc-info" style="display:none;margin-top:10px;padding:12px;background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;text-align:left;">
                                <div style="font-size:11px;color:var(--ink-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em;">Card riconosciuta</div>
                                <div id="card-owner-name" style="font-size:15px;font-weight:800;color:var(--ink);margin-bottom:2px;"></div>
                                <div id="card-owner-label" style="font-size:11px;color:var(--ink-muted);margin-bottom:12px;"></div>
                                <button type="button" class="cta" style="width:100%;font-size:13px;" onclick="sendCardRequest()">
                                    Invia richiesta di pagamento
                                </button>
                            </div>
                        </div>

                    </div>{{-- /nfc-cols --}}

                </div>

                {{-- Stato: pagato --}}
                <div id="state-paid" style="display:none;text-align:center;padding:20px 0;">
                    <div style="font-size:64px;margin-bottom:16px;">&#10003;</div>
                    <div style="font-size:24px;font-weight:800;color:var(--success, #16a34a);">Pagamento ricevuto!</div>
                    <div id="paid-amount" style="font-size:32px;font-weight:700;margin:8px 0;">
                        {{ ky_format($pr->amount) }} KY
                    </div>
                    <div id="paid-payer" style="font-size:14px;color:var(--ink-muted);"></div>
                    <div id="paid-time" style="font-size:13px;color:var(--ink-muted);margin-top:4px;"></div>
                    <a href="{{ route('portal.incasso-nfc.form') }}" class="cta" style="margin-top:24px;display:inline-block;">
                        Nuova richiesta NFC
                    </a>
                </div>

                {{-- Stato: scaduto --}}
                <div id="state-expired" style="display:none;text-align:center;padding:20px 0;">
                    <div style="font-size:48px;margin-bottom:12px;">&#9203;</div>
                    <div style="font-size:20px;font-weight:700;color:var(--danger);">Richiesta scaduta</div>
                    <div style="color:var(--ink-muted);font-size:14px;margin-top:6px;">Il cliente non ha pagato entro 5 minuti.</div>
                    <a href="{{ route('portal.incasso-nfc.form') }}" class="cta" style="margin-top:20px;display:inline-block;">
                        Nuova richiesta
                    </a>
                </div>

            </section>
        </div>
    </div>

    <style>
        @keyframes nfcPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%       { transform: scale(1.12); opacity: .7; }
        }

        /* Layout 3 colonne desktop */
        .nfc-cols {
            display: flex;
            gap: 0;
            align-items: stretch;
        }
        .nfc-col-info { flex: 0 0 200px; padding: 4px 24px 4px 4px; display: flex; flex-direction: column; }
        .nfc-col-qr   { flex: 1; padding: 4px 24px; text-align: center; }
        .nfc-col-card { flex: 1; padding: 4px 4px 4px 24px; display: flex; flex-direction: column; }
        .nfc-divider  { width: 1px; background: var(--line); flex-shrink: 0; }

        /* Mobile: colonna singola */
        @media (max-width: 700px) {
            .nfc-cols                          { flex-direction: column; }
            .nfc-col-info, .nfc-col-qr,
            .nfc-col-card                      { flex: none; padding: 16px 0; text-align: left; }
            .nfc-col-qr                        { text-align: center; }
            .nfc-divider                       { width: auto; height: 1px; }
        }
    </style>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
    // Helper: POST JSON con refresh automatico CSRF se sessione scaduta (419)
    async function postJson(url, body) {
        const csrfMeta = () => document.querySelector('meta[name=csrf-token]')?.content || '';
        let res;
        try {
            res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfMeta(), 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
        } catch (netErr) {
            // Fetch ha lanciato eccezione (nessuna connessione, SSL, CORS, ecc.)
            throw new Error('FETCH_EXCEPTION: ' + (netErr.message || netErr.name || String(netErr)) + ' | URL: ' + url);
        }
        if (res.status === 419) {
            // Sessione scaduta — refresha il CSRF token e riprova una volta
            const refreshRes = await fetch('/csrf-refresh', { headers: { 'Accept': 'application/json' } });
            const refreshData = await refreshRes.json().catch(() => ({}));
            if (refreshData.token) {
                const meta = document.querySelector('meta[name=csrf-token]');
                if (meta) meta.setAttribute('content', refreshData.token);
            }
            res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfMeta(), 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
        }
        return res;
    }
    (function () {
        const PAY_URL     = @json($payUrl);
        const STATUS_URL  = @json(route('portal.incasso-nfc.status', $pr->token));
        const TOTAL_SECS  = {{ $pr->expires_at->diffInSeconds(now()) > 0 ? $pr->expires_at->diffInSeconds(now()) : 0 }};
        const EXPIRE_AT   = new Date({{ $pr->expires_at->valueOf() }});

        // ── QR fallback ──────────────────────────────────────────────────────
        new QRCode(document.getElementById('qr-container'), {
            text:   PAY_URL,
            width:  180,
            height: 180,
            correctLevel: QRCode.CorrectLevel.M,
        });

        // ── Countdown ────────────────────────────────────────────────────────
        const countdownEl = document.getElementById('countdown');
        const timerBar    = document.getElementById('timer-bar');

        function updateCountdown() {
            const remaining = Math.max(0, EXPIRE_AT - Date.now());
            const secs      = Math.floor(remaining / 1000);
            const m = Math.floor(secs / 60);
            const s = secs % 60;
            if (countdownEl) countdownEl.textContent = m + ':' + String(s).padStart(2, '0');
            if (timerBar)    timerBar.style.width = (remaining / (TOTAL_SECS * 1000) * 100) + '%';
        }
        setInterval(updateCountdown, 500);
        updateCountdown();

        // ── Web NFC (scrittura URL per QR-NFC standard) ──────────────────────
        const nfcBar         = document.getElementById('nfc-status-bar');
        const nfcStartButton = document.getElementById('nfc-start-button');
        let   nfcStarting    = false;

        async function initNfc() {
            if (!('NDEFReader' in window)) {
                nfcBar.textContent = 'NFC non disponibile su questo browser. Usa il QR code.';
                nfcBar.style.color = 'var(--ink-muted)';
                if (nfcStartButton) nfcStartButton.style.display = 'none';
                return;
            }
            try {
                nfcStarting = true;
                if (nfcStartButton) {
                    nfcStartButton.disabled = true;
                    nfcStartButton.textContent = 'Avvicina il dispositivo...';
                }
                nfcBar.textContent = 'Avvicina lo smartphone o il tag NFC...';

                const ndef = new NDEFReader();

                const resetWriteBtn = () => {
                    if (nfcStartButton) {
                        nfcStartButton.disabled = false;
                        nfcStartButton.textContent = 'Riprova NFC';
                        nfcStartButton.style.display = 'block';
                    }
                };

                const doWrite = async () => {
                    try {
                        await ndef.write({ records: [{ recordType: 'url', data: PAY_URL }] });
                        nfcBar.textContent = 'NFC scritto. Il cliente puo\' ora aprire il link per pagare.';
                        nfcBar.style.background = 'var(--success-soft, #dcfce7)';
                        nfcBar.style.color      = 'var(--success, #16a34a)';
                        nfcBar.style.border     = '1px solid #bbf7d0';
                        if (nfcStartButton) nfcStartButton.style.display = 'none';
                    } catch (e) {
                        nfcBar.textContent = 'Scrittura NFC fallita: ' + (e.message || e.name) + '. Usa il QR code.';
                        nfcBar.style.color = 'var(--danger)';
                        resetWriteBtn();
                    }
                };

                let writeHandled = false;

                // Prima LEGGIAMO il tag: non sovrascriviamo mai una card KMoney senza conferma.
                await ndef.scan();

                ndef.onreading = async ({ message }) => {
                    if (writeHandled) return;

                    let isKmoneyCard = false;
                    for (const record of message.records) {
                        if (record.recordType === 'url') {
                            const existing = new TextDecoder().decode(record.data);
                            if (existing.includes('/nfc/')) isKmoneyCard = true;
                        }
                    }

                    if (isKmoneyCard && ! confirm('ATTENZIONE: questo tag contiene GIA\' una card NFC KMoney. Sovrascriverla la rendera\' inutilizzabile come card di pagamento. Vuoi davvero sovrascriverla?')) {
                        nfcBar.textContent = 'Operazione annullata: la card non e\' stata toccata.';
                        nfcBar.style.color = 'var(--ink-muted)';
                        resetWriteBtn();
                        return;
                    }

                    writeHandled = true;
                    await doWrite();
                };

                // Tag vuoto o non leggibile come NDEF (non e\' una card KMoney): scrivibile senza rischio.
                ndef.onreadingerror = async () => {
                    if (writeHandled) return;
                    writeHandled = true;
                    await doWrite();
                };

                nfcBar.textContent = 'Avvicina il tag NFC del cliente...';
            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    nfcBar.textContent = 'NFC non autorizzato. Tocca "Attiva NFC" e conferma il permesso.';
                } else {
                    nfcBar.textContent = 'NFC: ' + (err.message || 'errore') + '. Usa il QR code.';
                }
                nfcBar.style.color = 'var(--danger)';
                if (nfcStartButton) {
                    nfcStartButton.disabled = false;
                    nfcStartButton.textContent = 'Riprova NFC';
                    nfcStartButton.style.display = 'block';
                }
            } finally {
                nfcStarting = false;
            }
        }

        // ── Real-time (Echo) + polling ───────────────────────────────────────
        let pollInterval = null;
        let echoListening = false;

        function stopAll() {
            clearInterval(pollInterval);
            if (echoListening && window.Echo) {
                window.Echo.leaveChannel('payment-request.' + @json($pr->token));
                echoListening = false;
            }
        }

        function handleEchoData(data) {
            if (data.status === 'paid') {
                stopAll();
                showPaid({ payer_name: data.payer_name, paid_at: data.paid_at });
            } else if (data.status === 'expired' || data.status === 'cancelled') {
                stopAll();
                showExpired();
            }
        }

        function tryEcho() {
            if (!window.Echo) return false;
            window.Echo.channel('payment-request.' + @json($pr->token))
                .listen('.status.updated', handleEchoData);
            echoListening = true;
            return true;
        }

        if (!tryEcho()) {
            pollInterval = setInterval(async function () {
                try {
                    const res  = await fetch(STATUS_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const data = await res.json();
                    if (data.is_paid) {
                        clearInterval(pollInterval); pollInterval = null;
                        showPaid(data);
                    } else if (data.is_expired || data.status === 'cancelled') {
                        clearInterval(pollInterval); pollInterval = null;
                        showExpired();
                    }
                } catch (e) { /* ignora errori di rete transitori */ }
            }, 2500);
        }

        window.addEventListener('echo-ready', () => {
            if (echoListening) return;
            clearInterval(pollInterval); pollInterval = null;
            tryEcho();
        });

        function showPaid(data) {
            document.getElementById('state-pending').style.display = 'none';
            document.getElementById('state-paid').style.display    = 'block';
            if (data.payer_name) document.getElementById('paid-payer').textContent = 'Da: ' + data.payer_name;
            if (data.paid_at)    document.getElementById('paid-time').textContent  = 'Ricevuto alle ' + data.paid_at;
        }

        function showExpired() {
            document.getElementById('state-pending').style.display = 'none';
            document.getElementById('state-expired').style.display = 'block';
        }

        if (window.location.protocol === 'https:' || window.location.hostname === 'localhost') {
            if (!('NDEFReader' in window)) {
                nfcBar.textContent = 'NFC non disponibile su questo browser. Usa il QR code.';
                nfcBar.style.color = 'var(--ink-muted)';
            } else if (nfcStartButton) {
                nfcBar.textContent = 'Tocca "Attiva NFC" per autorizzare il browser.';
                nfcStartButton.style.display = 'block';
                nfcStartButton.addEventListener('click', function () {
                    if (!nfcStarting) initNfc();
                });
            }
        } else {
            nfcBar.textContent = 'NFC richiede HTTPS. Usa il QR code.';
            nfcBar.style.color = 'var(--ink-muted)';
        }
    })();

    // ─── Card NFC fisica (Opzione A) ─────────────────────────────────────────
    let scannedCardUuid = null;

    async function startCardScan() {
        const statusEl = document.getElementById('card-nfc-status');
        const btn      = document.getElementById('card-nfc-btn');
        const infoEl   = document.getElementById('card-nfc-info');

        statusEl.style.display = 'block';

        if (!('NDEFReader' in window)) {
            statusEl.textContent = 'NFC non supportato su questo browser. Usa Chrome su Android.';
            statusEl.style.color = 'var(--danger)';
            return;
        }

        btn.disabled = true;
        btn.textContent = '⏳ Avvicina la card NFC...';
        statusEl.textContent = 'Avvicina la card NFC fisica del cliente al dispositivo...';
        statusEl.style.color = 'var(--ink)';

        try {
            const ndef = new NDEFReader();
            await ndef.scan();

            ndef.onreading = async ({ message }) => {
                for (const record of message.records) {
                    if (record.recordType === 'url') {
                        const url     = new TextDecoder().decode(record.data);
                        const urlObj  = new URL(url);
                        const parts   = urlObj.pathname.split('/');
                        const uuid    = parts[parts.length - 1];
                        const sig     = urlObj.searchParams.get('sig');

                        if (!uuid || !sig) {
                            statusEl.textContent = 'Tag non valido (non e\' una card KMoney).';
                            statusEl.style.color = 'var(--danger)';
                            resetCardBtn();
                            return;
                        }

                        statusEl.textContent = 'Verifica card in corso...';

                        try {
                            const res = await postJson('{{ route('nfc.card.identify') }}', { uuid, sig });

                            let data;
                            try { data = await res.json(); } catch (_) { data = {}; }

                            if (!res.ok) {
                                statusEl.textContent = '✕ ' + (data.error || ('Errore server (' + res.status + ')'));
                                statusEl.style.color = 'var(--danger)';
                                resetCardBtn();
                                return;
                            }

                            scannedCardUuid = uuid;
                            statusEl.textContent = '✓ Card riconosciuta';
                            statusEl.style.background = '#dcfce7';
                            statusEl.style.color      = '#166534';
                            statusEl.style.border     = '1px solid #bbf7d0';

                            document.getElementById('card-owner-name').textContent  = data.owner_name;
                            document.getElementById('card-owner-label').textContent = 'Card: ' + data.card_label;
                            infoEl.style.display = 'block';
                            btn.style.display    = 'none';

                        } catch (e) {
                            statusEl.textContent = '⚠ ' + (e.message || String(e));
                            statusEl.style.color = 'var(--danger)';
                            resetCardBtn();
                        }
                        return;
                    }
                }
                statusEl.textContent = 'Tag NFC letto ma non e\' una card KMoney.';
                statusEl.style.color = 'var(--danger)';
                resetCardBtn();
            };

        } catch (err) {
            let msg = 'Errore NFC: ' + (err.message || err.name);
            if (err.name === 'NotAllowedError')   msg = 'Permesso NFC negato. Controlla le impostazioni.';
            if (err.name === 'NotSupportedError') msg = 'NFC non supportato su questo dispositivo.';
            statusEl.textContent = msg;
            statusEl.style.color = 'var(--danger)';
            resetCardBtn();
        }
    }

    function resetCardBtn() {
        const btn = document.getElementById('card-nfc-btn');
        btn.disabled = false;
        btn.style.display = 'block';
        btn.textContent = '📶 Richiedi pagamento CARD NFC';
    }

    async function sendCardRequest() {
        if (!scannedCardUuid) return;

        const statusEl = document.getElementById('card-nfc-status');
        const amount      = @json($pr->amount / 100);
        const description = @json($pr->description ?? '');

        statusEl.textContent = 'Invio richiesta al cliente...';
        statusEl.style.background = '';
        statusEl.style.color      = 'var(--ink)';
        statusEl.style.border     = '1px solid var(--line)';

        try {
            const res = await postJson('{{ route('nfc.card.request') }}', {
                card_uuid:   scannedCardUuid,
                amount:      amount,
                description: description,
            });

            let data;
            try { data = await res.json(); } catch (_) { data = {}; }

            if (!res.ok) {
                statusEl.textContent = '✕ ' + (data.error || ('Errore server (' + res.status + ')'));
                statusEl.style.color = 'var(--danger)';
                return;
            }

            document.getElementById('card-nfc-info').style.display = 'none';
            statusEl.textContent   = '⏳ Notifica inviata. In attesa che il cliente autorizzi il pagamento...';
            statusEl.style.background = '#fef9c3';
            statusEl.style.color      = '#854d0e';
            statusEl.style.border     = '1px solid #fde68a';

            // Polling autorizzazione
            const statusUrl = data.status_url;
            const poll = setInterval(async () => {
                try {
                    const r = await fetch(statusUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const d = await r.json();
                    if (d.is_authorized) {
                        clearInterval(poll);
                        document.getElementById('state-pending').style.display = 'none';
                        document.getElementById('state-paid').style.display    = 'block';
                    } else if (d.is_expired) {
                        clearInterval(poll);
                        statusEl.textContent      = '✕ Richiesta scaduta. Il cliente non ha autorizzato in tempo.';
                        statusEl.style.background = '#fee2e2';
                        statusEl.style.color      = '#991b1b';
                        statusEl.style.border     = '1px solid #fecaca';
                        resetCardBtn();
                    }
                } catch (e) { /* transiente */ }
            }, 2000);

        } catch (e) {
            statusEl.textContent = '⚠ ' + (e.message || String(e));
            statusEl.style.color = 'var(--danger)';
        }
    }
    </script>
@endsection
