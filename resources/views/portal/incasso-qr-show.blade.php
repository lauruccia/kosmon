@extends('layouts.portal')

@push('head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
@endpush

@section('content')

{{-- Banner stato --}}
<div id="status-banner" style="display:none;margin-bottom:24px;"></div>

<div class="portal-grid" style="--grid-cols:2;">

    {{-- QR Card --}}
    <div class="stack">
        <section class="card card-pad" style="text-align:center;" id="qr-section">

            {{-- Importo --}}
            <div style="margin-bottom:16px;">
                <div style="font-size:42px;font-weight:800;letter-spacing:-1px;line-height:1;">
                    {{ number_format($pr->amount, 2, ',', '.') }} <span style="font-size:22px;font-weight:600;color:var(--text-muted);">KY</span>
                </div>
                @if($pr->description)
                    <div style="margin-top:6px;font-size:14px;color:var(--text-muted);">{{ $pr->description }}</div>
                @endif
            </div>

            {{-- QR Code --}}
            <div style="display:inline-block;background:#fff;border-radius:16px;padding:20px;box-shadow:0 4px 20px rgba(0,0,0,.10);margin-bottom:16px;">
                <div id="qr-code"></div>
            </div>

            {{-- Countdown --}}
            <div id="countdown-wrap" style="margin-bottom:16px;">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">QR valido per</div>
                <div id="countdown-display" style="font-size:28px;font-weight:800;font-variant-numeric:tabular-nums;letter-spacing:-.5px;">--:--</div>
                <div style="margin-top:8px;height:6px;background:rgba(0,0,0,.08);border-radius:99px;overflow:hidden;">
                    <div id="countdown-bar" style="height:100%;background:var(--primary);border-radius:99px;transition:width .9s linear;"></div>
                </div>
            </div>

            {{-- Azioni --}}
            <div class="quick-actions" style="justify-content:center;flex-wrap:wrap;gap:8px;" id="action-buttons">
                <form method="POST" action="{{ route('portal.incasso-qr.cancel', $pr->token) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="cta secondary" onclick="return confirm('Annullare la richiesta QR?')">Annulla</button>
                </form>
                <a href="{{ route('portal.incasso-qr.form') }}" class="cta secondary">Nuovo QR</a>
            </div>
        </section>
    </div>

    {{-- Info laterale --}}
    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Istruzioni per il cliente</div>
            <div style="margin-top:16px;display:grid;gap:14px;font-size:14px;line-height:1.6;color:var(--text-muted);">
                <p><strong style="color:var(--text);">1. Scansiona il QR</strong><br>
                Con la fotocamera del telefono (senza app) oppure con il lettore QR nativo.</p>
                <p><strong style="color:var(--text);">2. Accedi al portale KMoney</strong><br>
                Il link apre direttamente la pagina di conferma pagamento.</p>
                <p><strong style="color:var(--text);">3. Conferma il pagamento</strong><br>
                Un tap su "Paga ora" e il trasferimento avviene istantaneamente.</p>
            </div>
        </section>

        <section class="card light-card card-pad" id="merchant-info">
            <div class="k-tag">Destinatario (tu)</div>
            <div style="margin-top:12px;">
                <div style="font-size:15px;font-weight:700;">{{ $account->company?->name ?? $account->display_name }}</div>
                <div style="font-size:12px;color:var(--text-muted);font-family:monospace;margin-top:4px;">{{ $account->account_number }}</div>
            </div>
        </section>

        <section class="card light-card card-pad">
            <div class="k-tag">QR dinamico</div>
            <div style="margin-top:10px;font-size:13px;color:var(--text-muted);line-height:1.6;">
                Ogni QR e' unico e scade automaticamente. Anche se qualcuno fotografa il QR, non puo' riusarlo dopo la scadenza o dopo il pagamento.
            </div>
        </section>
    </div>

</div>

@push('scripts')
<script>
(function () {
    const PAY_URL     = @json($payUrl);
    const STATUS_URL  = @json(route('portal.incasso-qr.status', $pr->token));
    const EXPIRES_AT  = {{ $pr->expires_at->timestamp }};
    const TOTAL_SECS  = {{ max(1, $pr->expires_at->diffInSeconds(now())) }};
    const CURRENT_STATUS = @json($pr->status);

    // ─── Genera QR ─────────────────────────────────────────────────────────
    new QRCode(document.getElementById('qr-code'), {
        text: PAY_URL,
        width: 220,
        height: 220,
        colorDark: '#1a1a2e',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M,
    });

    // ─── Se già chiuso, mostra subito lo stato ──────────────────────────────
    if (CURRENT_STATUS !== 'pending') {
        handleStatus(CURRENT_STATUS, null, null);
    }

    // ─── Countdown ──────────────────────────────────────────────────────────
    const display = document.getElementById('countdown-display');
    const bar     = document.getElementById('countdown-bar');

    function updateCountdown() {
        const left = Math.max(0, EXPIRES_AT - Math.floor(Date.now() / 1000));
        const mm   = String(Math.floor(left / 60)).padStart(2, '0');
        const ss   = String(left % 60).padStart(2, '0');
        display.textContent = mm + ':' + ss;

        const pct = (left / TOTAL_SECS) * 100;
        bar.style.width = pct + '%';

        // Colore urgenza
        if (left <= 60) {
            bar.style.background = '#dc2626';
            display.style.color  = '#dc2626';
        } else if (left <= 120) {
            bar.style.background = '#d97706';
            display.style.color  = '#d97706';
        }

        return left;
    }

    // ─── Real-time (Echo) + fallback polling ────────────────────────────────
    let pollInterval = null;
    let countdownInterval = null;
    let echoListening = false;

    function startCountdown() {
        countdownInterval = setInterval(() => {
            const left = updateCountdown();
            if (left <= 0) stopAll();
        }, 1000);
        updateCountdown();
    }

    function stopAll() {
        clearInterval(pollInterval);
        clearInterval(countdownInterval);
        if (echoListening && window.Echo) {
            window.Echo.leaveChannel('payment-request.' + @json($pr->token));
            echoListening = false;
        }
    }

    function tryEcho() {
        if (!window.Echo) return false;
        window.Echo.channel('payment-request.' + @json($pr->token))
            .listen('.status.updated', (data) => {
                handleStatus(data.status, data.payer_name, data.paid_at, data.seconds_left);
            });
        echoListening = true;
        return true;
    }

    function startPolling() {
        startCountdown();
        // Prova Echo prima, polling come fallback
        if (!tryEcho()) {
            pollInterval = setInterval(fetchStatus, 3000);
        }
    }

    // Echo potrebbe non essere ancora pronto al DOMContentLoaded
    window.addEventListener('echo-ready', () => {
        if (echoListening || !pollInterval) return; // gia' ok
        // Passa da polling a Echo
        clearInterval(pollInterval);
        pollInterval = null;
        tryEcho();
    });

    async function fetchStatus() {
        try {
            const res  = await fetch(STATUS_URL, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await res.json();
            handleStatus(data.status, data.payer_name, data.paid_at, data.seconds_left);
        } catch (e) {
            // ignora errori di rete, riprova al prossimo tick
        }
    }

    function handleStatus(status, payerName, paidAt, secondsLeft) {
        const banner  = document.getElementById('status-banner');
        const section = document.getElementById('qr-section');
        const heading = document.getElementById('page-heading');
        const subtitle= document.getElementById('page-subtitle');
        const actBtns = document.getElementById('action-buttons');

        if (status === 'paid') {
            stopAll();
            const from = payerName ? ' da <strong>' + payerName + '</strong>' : '';
            banner.innerHTML = `
                <div style="background:#d1fae5;border:1.5px solid #6ee7b7;border-radius:12px;padding:20px 24px;display:flex;align-items:center;gap:16px;">
                    <div style="font-size:40px;line-height:1;">✅</div>
                    <div>
                        <div style="font-size:18px;font-weight:800;color:#065f46;">Pagamento ricevuto!</div>
                        <div style="font-size:14px;color:#047857;margin-top:4px;">
                            Il trasferimento${from} e' avvenuto con successo${paidAt ? ' alle ' + paidAt : ''}.
                        </div>
                    </div>
                </div>`;
            banner.style.display = 'block';
            heading.textContent = 'Pagamento ricevuto!';
            subtitle.textContent = 'Il saldo del tuo conto e\' stato aggiornato.';
            document.getElementById('countdown-wrap').style.display = 'none';
            actBtns.innerHTML = '<a href="{{ route("portal.incasso-qr.form") }}" class="cta">Nuovo QR</a> <a href="{{ route("portal.movements") }}" class="cta secondary">Vedi movimenti</a>';
        }

        if (status === 'expired' || (secondsLeft !== undefined && secondsLeft <= 0)) {
            stopAll();
            banner.innerHTML = `
                <div style="background:#fef3c7;border:1.5px solid #fcd34d;border-radius:12px;padding:16px 20px;display:flex;gap:12px;align-items:center;">
                    <div style="font-size:28px;">⏰</div>
                    <div>
                        <strong style="color:#92400e;">QR scaduto</strong>
                        <div style="font-size:13px;color:#b45309;margin-top:2px;">Il codice QR non e' piu' valido. Genera un nuovo QR per incassare.</div>
                    </div>
                </div>`;
            banner.style.display = 'block';
            heading.textContent = 'QR scaduto';
            document.getElementById('countdown-wrap').style.display = 'none';
            actBtns.innerHTML = '<a href="{{ route("portal.incasso-qr.form") }}" class="cta">Genera nuovo QR</a>';
        }

        if (status === 'cancelled') {
            stopAll();
            heading.textContent = 'Richiesta annullata';
        }
    }

    // Avvia solo se pending
    if (CURRENT_STATUS === 'pending') {
        startPolling();
    }
})();
</script>
@endpush
@endsecti