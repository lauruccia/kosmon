@extends('layouts.portal')

@section('content')
<style>
.scanner-shell {
    display: grid;
    gap: 14px;
    max-width: 560px;
    margin: 0 auto;
}
.scanner-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 14px;
    box-shadow: var(--shadow);
}
.scanner-frame {
    position: relative;
    overflow: hidden;
    border-radius: 12px;
    background: #050b14;
    aspect-ratio: 3 / 4;
    min-height: 420px;
}
.scanner-frame video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.scanner-reticle {
    position: absolute;
    inset: 18%;
    border: 2px solid rgba(255,255,255,.86);
    border-radius: 18px;
    box-shadow: 0 0 0 999px rgba(0,0,0,.28);
    pointer-events: none;
}
.scanner-status {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(6,15,32,.82);
    color: #fff;
    font-size: 13px;
    text-align: center;
}
.scanner-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.scanner-manual {
    display: grid;
    gap: 10px;
}
@media (max-width: 768px) {
    .scanner-shell { max-width: none; }
    .scanner-card { padding: 12px; }
    .scanner-frame { min-height: min(68vh, 560px); }
    .scanner-actions { grid-template-columns: 1fr; }
}
</style>

<div class="scanner-shell">
    <section class="scanner-card">
        <div class="scanner-frame">
            <video id="scanner-video" playsinline muted></video>
            <div class="scanner-reticle"></div>
            <div class="scanner-status" id="scanner-status">Avvia la fotocamera per scansionare un QR KMoney.</div>
        </div>
    </section>

    <section class="scanner-card scanner-manual">
        <div class="scanner-actions">
            <button type="button" class="cta" id="start-scan" style="justify-content:center;">Avvia scanner</button>
            <a class="cta secondary" href="{{ route('portal.invia') }}" style="justify-content:center;">Inserisci destinatario</a>
        </div>
        <form id="manual-scan-form" class="scanner-manual">
            <label class="form-label" for="manual-code">Link o codice QR</label>
            <input class="form-control" id="manual-code" type="text" inputmode="url" autocomplete="off" placeholder="Incolla qui il contenuto del QR">
            <button type="submit" class="cta secondary" style="justify-content:center;">Apri contenuto</button>
        </form>
    </section>
</div>

<script>
(function () {
    var video = document.getElementById('scanner-video');
    var statusEl = document.getElementById('scanner-status');
    var startBtn = document.getElementById('start-scan');
    var manualForm = document.getElementById('manual-scan-form');
    var manualCode = document.getElementById('manual-code');
    var detector = null;
    var stream = null;
    var scanTimer = null;
    var lastValue = '';

    function setStatus(text) {
        statusEl.textContent = text;
    }

    function resolveQrValue(value) {
        var raw = (value || '').trim();
        if (!raw || raw === lastValue) return;
        lastValue = raw;

        try {
            var url = new URL(raw, window.location.origin);
            var sameHost = url.host === window.location.host;
            var supportedPath = url.pathname.indexOf('/pay/') === 0
                || url.pathname.indexOf('/paga/qr/') === 0
                || url.pathname.indexOf('/nfc/') === 0;

            if (sameHost && supportedPath) {
                setStatus('QR riconosciuto. Apertura pagamento...');
                window.location.href = url.href;
                return;
            }

            if (sameHost) {
                setStatus('Link KMoney riconosciuto. Apertura...');
                window.location.href = url.href;
                return;
            }
        } catch (e) {
            // Fall through to manual account handling.
        }

        if (/^[A-Z0-9-]{6,}$/i.test(raw)) {
            window.location.href = @json(url('/paga/qr')) + '/' + encodeURIComponent(raw);
            return;
        }

        setStatus('QR non riconosciuto. Usa un QR KMoney o incolla un link valido.');
        setTimeout(function () { lastValue = ''; }, 1800);
    }

    function stopScanner() {
        if (scanTimer) clearInterval(scanTimer);
        scanTimer = null;
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
    }

    async function scanLoop() {
        if (!detector || !video.srcObject) return;
        try {
            var codes = await detector.detect(video);
            if (codes && codes.length) {
                resolveQrValue(codes[0].rawValue || '');
            }
        } catch (e) {
            setStatus('Scanner non disponibile su questo browser. Incolla il link del QR.');
            stopScanner();
        }
    }

    startBtn.addEventListener('click', async function () {
        if (!('BarcodeDetector' in window)) {
            setStatus('Il browser non supporta lo scanner QR integrato. Incolla il link del QR.');
            return;
        }

        try {
            detector = new BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false
            });
            video.srcObject = stream;
            await video.play();
            setStatus('Inquadra il QR KMoney dentro il riquadro.');
            scanTimer = setInterval(scanLoop, 500);
            startBtn.textContent = 'Scanner attivo';
            startBtn.disabled = true;
        } catch (e) {
            setStatus('Autorizza la fotocamera o incolla il link del QR.');
        }
    });

    manualForm.addEventListener('submit', function (event) {
        event.preventDefault();
        resolveQrValue(manualCode.value);
    });

    window.addEventListener('pagehide', stopScanner);
})();
</script>
@endsection
