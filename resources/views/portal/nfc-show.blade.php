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
                            {{ number_format($pr->amount, 2, ',', '.') }} KY
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

                    {{-- Separatore --}}
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
                        {{ number_format($pr->amount, 2, ',', '.') }} KY
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
        @keyframes nfcRipple {
            0%   { transform: scale(.8); opacity: .6; }
            100% { transform: scale(1.5); opacity: 0; }
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
        let totalMs = EXPIRE_AT - Date.now();

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

        // ── Web NFC ──────────────────────────────────────────────────────────
        const nfcBar = document.getElementById('nfc-status-bar');
        const nfcStartButton = document.getElementById('nfc-start-button');
        let nfcStarting = false;

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
                nfcBar.style.color = 'var(--ink-muted)';

                const ndef = new NDEFReader();

                // Scrittura: quando il cliente avvicina il telefono, scriviamo l'URL
                await ndef.write({
                    records: [{ recordType: 'url', data: PAY_URL }]
                });

                nfcBar.textContent = 'NFC pronto — avvicina lo smartphone del cliente';
                nfcBar.style.background = 'var(--success-soft, #dcfce7)';
                nfcBar.style.color      = 'var(--success, #16a34a)';
                nfcBar.style.border     = '1px solid #bbf7d0';
                if (nfcStartButton) {
                    nfcStartButton.style.display = 'none';
                }

                // Dopo la scrittura, mantieni la sessione attiva per ulteriori tap
                keepNfcAlive(ndef);

            } catch (err) {
                if (err.name === 'NotAllowedError') {
                    nfcBar.textContent = 'NFC non autorizzato dal browser. Tocca "Attiva NFC" e conferma il permesso, oppure usa il QR code.';
                } else if (err.name === 'NotSupportedError') {
                    nfcBar.textContent = 'NFC non supportato su questo dispositivo. Usa il QR code.';
                } else {
                    nfcBar.textContent = 'NFC: ' + (err.message || 'errore sconosciuto') + '. Usa il QR code.';
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

        async function keepNfcAlive(ndef) {
            ndef.onwritingerror = () => {
                // errore silenzioso, il polling AJAX si occupa del pagamento
            };
        }

        // ── Real-time (Echo) + fallback polling AJAX ─────────────────────────
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

        // Avvia Echo o polling
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

        // Se Echo diventa disponibile dopo il caricamento, passa da polling a Echo
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

        // Avvia NFC solo su HTTPS (richiesto dalla Web NFC API)
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
    </script>
@endsection
