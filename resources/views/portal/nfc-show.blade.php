@extends('layouts.portal')

@section('content')

    <div class="portal-grid" style="max-width:600px;">
        <div class="stack">
            {{-- Card stato principale --}}
            <section class="card card-pad" id="nfc-status-card">

                {{-- Stato: in attesa --}}
                <div id="state-pending">
                    <div style="text-align:center;padding:10px 0 20px;">
                        {{-- Animazione NFC --}}
                        <div id="nfc-icon" style="font-size:64px;margin-bottom:12px;animation:nfcPulse 1.6s ease-in-out infinite;">&#128246;</div>
                        <div style="font-size:32px;font-weight:800;color:var(--ink);">
                            {{ ky_format($pr->amount) }} KY
                        </div>
                        @if($pr->description)
                            <div style="color:var(--ink-muted);font-size:14px;margin-top:4px;">{{ $pr->description }}</div>
                        @endif

                        <div style="margin:20px auto 0;max-width:300px;">
                            <div style="font-size:13px;color:var(--ink-muted);margin-bottom:8px;">
                                Avvicina lo smartphone del cliente
                            </div>

                            {{-- Barra NFC status --}}
                            <div id="nfc-status-bar" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:var(--ink-muted);text-align:center;">
                                Inizializzazione NFC...
                            </div>

                            <button type="button" id="nfc-start-button" class="cta" style="margin-top:10px;width:100%;font-size:13px;padding:9px 14px;display:none;">
                                Attiva NFC
                            </button>

                            {{-- Countdown --}}
                            <div style="margin-top:14px;font-size:12px;color:var(--ink-muted);">
                                Scade tra <span id="countdown" style="font-weight:700;color:var(--ink);">5:00</span>
                            </div>

                            {{-- Progress bar scadenza --}}
                            <div style="margin-top:8px;height:4px;background:var(--surface-soft);border-radius:2px;overflow:hidden;">
                                <div id="timer-bar" style="height:100%;background:var(--primary);transition:width .5s linear;width:100%;"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Separatore QR --}}
                    <div style="display:flex;align-items:center;gap:12px;margin:20px 0;">
                        <div style="flex:1;height:1px;background:var(--line);"></div>
                        <span style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.08em;">oppure scansiona il QR</span>
                        <div style="flex:1;height:1px;background:var(--line);"></div>
                    </div>

                    {{-- QR fallback --}}
                    <div style="text-align:center;">
                        <div id="qr-container" style="display:inline-block;padding:12px;background:#fff;border:1px solid var(--line);border-radius:12px;"></div>
                        <div style="font-size:11px;color:var(--ink-muted);margin-top:8px;">
                            Il cliente inquadra il QR con la fotocamera
                        </div>
                    </div>

                    {{-- Separatore CARD NFC --}}
                    <div style="display:flex;align-items:center;gap:12px;margin:20px 0 12px;">
                        <div style="flex:1;height:1px;background:var(--line);"></div>
                        <span style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.08em;">oppure card nfc fisica</span>
                        <div style="flex:1;height:1px;background:var(--line);"></div>
                    </div>

                    {{-- CARD NFC fisica --}}
                    <div style="text-align:center;">
                        <div id="card-nfc-status" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:var(--ink-muted);text-align:center;margin-bottom:10px;display:none;">
                            Inizializzazione...
                        </div>
                        <button type="button" id="card-nfc-btn" class="cta secondary" style="width:100%;font-size:13px;padding:10px;" onclick="startCardScan()">
                            &#128246; Richiedi pagamento CARD NFC
                        </button>
                        <div id="card-nfc-info" style="display:none;margin-top:12px;padding:14px;background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;text-align:left;">
                            <div style="font-size:11px;color:var(--ink-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em;">Card riconosciuta</div>
                            <div id="card-owner-name" style="font-size:15px;font-weight:800;color:var(--ink);margin-bottom:2px;"></div>
                            <div id="card-owner-label" style="font-size:11px;color:var(--ink-muted);margin-bottom:14px;"></div>
                            <button type="button" class="cta" style="width:100%;font-size:13px;" onclick="sendCardRequest()">
                                Invia richiesta di pagamento
                            </button>
                        </div>
                    </div>

                    {{-- Azioni --}}
                    <div style="display:flex;gap:10px;margin-top:24px;justify-content:center;">
                        <form method="POST" action="{{ route('portal.incasso-nfc.cancel', $pr->token) }}">
                            @csrf
                            <button type="submit" class="cta secondary" style="font-size:13px;padding:8px 18px;">
                                Annulla
                            </button>
                        </form>
                    </div>
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
    </style>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
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
                await ndef.write({ records: [{ recordType: 'url', data: PAY_URL }] });

                nfcBar.textContent = 'NFC pronto — avvicina lo smartphone del cliente';
                nfcBar.style.background = 'var(--success-soft, #dcfce7)';
                nfcBar.style.color      = 'var(--success, #16a34a)';
                nfcBar.style.border     = '1px solid #bbf7d0';
                if (nfcStartButton) nfcStartButton.style.display = 'none';
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
                            const res = await fetch('{{ route('nfc.card.identify') }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ uuid, sig }),
                            });

                            const data = await res.json();

                            if (!res.ok) {
                                statusEl.textContent = '✕ ' + (data.error || 'Errore verifica card');
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
                            statusEl.textContent = 'Errore di rete. Riprova.';
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
        const amount      = @json($pr->amount);
        const description = @json($pr->description ?? '');

        statusEl.textContent = 'Invio richiesta al cliente...';
        statusEl.style.background = '';
        statusEl.style.color      = 'var(--ink)';
        statusEl.style.border     = '1px solid var(--line)';

        try {
            const res = await fetch('{{ route('nfc.card.request') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    card_uuid:   scannedCardUuid,
                    amount:      amount,
                    description: description,
                }),
            });

            const data = await res.json();

            if (!res.ok) {
                statusEl.textContent = '✕ ' + (data.error || 'Errore invio richiesta');
                statusEl.style.color = 'var(--danger)';
                return;
            }

            document.getElementById('card-nfc-info').style.display = 'none';
            statusEl.textContent   = '⏳ In attesa che il cliente autorizzi con PIN...';
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
            statusEl.textContent = 'Errore di rete. Riprova.';
            statusEl.style.color = 'var(--danger)';
        }
    }
    </script>
@endsection
