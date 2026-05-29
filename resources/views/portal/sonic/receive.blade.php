@extends('layouts.portal')

@section('content')
<div class="portal-grid" style="max-width:600px;">
  <div class="stack">

    <section class="card card-pad" id="sonic-receive-card">

      {{-- IDLE: in ascolto --}}
      <div id="state-idle">
        <div style="text-align:center;padding:10px 0 20px;">
          <div id="mic-icon" style="font-size:64px;margin-bottom:12px;">&#127908;</div>
          <h2 style="margin:0 0 6px;font-size:20px;font-weight:800;">Paga con Suono</h2>
          <p style="color:var(--ink-muted);font-size:14px;margin:0 0 24px;">
            Avvicina il telefono al cassa e ascolta il codice audio.
          </p>

          <div style="margin:0 auto;max-width:340px;">
            {{-- Visualizzatore FFT --}}
            <div style="position:relative;height:60px;background:var(--surface-soft);border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:16px;">
              <canvas id="fft-canvas" width="340" height="60" style="width:100%;height:100%;"></canvas>
              <div id="fft-label" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:var(--ink-muted);">
                Avvia microfono
              </div>
            </div>

            {{-- Barra progresso decodifica --}}
            <div id="decode-progress" style="display:none;margin-bottom:14px;">
              <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:700;color:var(--ink-muted);margin-bottom:4px;">
                <span>Decodifica</span>
                <span id="chars-count">0 / 8</span>
              </div>
              <div style="height:6px;background:var(--surface-soft);border-radius:3px;overflow:hidden;">
                <div id="chars-bar" style="height:100%;background:var(--primary);width:0%;transition:width .1s;"></div>
              </div>
            </div>

            {{-- Status bar --}}
            <div id="listen-status" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:var(--ink-muted);text-align:center;margin-bottom:12px;">
              Pronto
            </div>

            <button type="button" id="listen-btn" class="cta" style="width:100%;font-size:15px;padding:12px;">
              &#127908; Avvia microfono
            </button>

            <div style="margin-top:8px;font-size:11px;color:var(--ink-muted);text-align:center;">
              Tieni il telefono vicino all'altoparlante del cassa.<br>
              Il codice viene catturato automaticamente.
            </div>
          </div>
        </div>
      </div>

      {{-- PREVIEW: conferma pagamento --}}
      <div id="state-preview" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:48px;margin-bottom:12px;">&#128266;</div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:4px;">
          Pagamento a
        </div>
        <div id="preview-merchant" style="font-size:20px;font-weight:800;color:var(--ink);margin-bottom:8px;"></div>
        <div id="preview-amount" style="font-size:40px;font-weight:900;color:var(--primary);"></div>
        <div style="font-size:13px;color:var(--ink-muted);margin-top:2px;">KY</div>
        <div id="preview-desc" style="font-size:13px;color:var(--ink-muted);margin-top:6px;"></div>
        <div id="preview-expire" style="font-size:12px;color:var(--ink-muted);margin-top:4px;"></div>

        <div style="display:flex;gap:10px;margin-top:28px;justify-content:center;">
          <button type="button" id="confirm-btn" class="cta" style="min-width:120px;">
            Paga
          </button>
          <button type="button" id="cancel-preview-btn" class="cta secondary" style="min-width:100px;">
            Annulla
          </button>
        </div>
      </div>

      {{-- SUCCESS --}}
      <div id="state-success" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:64px;margin-bottom:16px;">&#10003;</div>
        <div style="font-size:24px;font-weight:800;color:var(--success,#16a34a);">Pagamento inviato!</div>
        <div id="success-amount" style="font-size:32px;font-weight:700;margin:8px 0;"></div>
        <div id="success-merchant" style="font-size:14px;color:var(--ink-muted);"></div>
        <div style="display:flex;gap:10px;margin-top:24px;justify-content:center;">
          <a href="{{ route('portal.paga-sonic.form') }}" class="cta secondary">Nuovo pagamento</a>
          <a href="{{ route('portal.movements') }}" class="cta">Vedi movimenti</a>
        </div>
      </div>

      {{-- ERROR --}}
      <div id="state-error" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:48px;margin-bottom:12px;">&#9888;</div>
        <div style="font-size:20px;font-weight:700;color:var(--danger);">Errore</div>
        <div id="error-msg" style="color:var(--ink-muted);font-size:14px;margin-top:6px;"></div>
        <button type="button" id="retry-btn" class="cta secondary" style="margin-top:20px;">Riprova</button>
      </div>

    </section>

  </div>
</div>

<style>
  @keyframes micPulse {
    0%,100% { transform:scale(1);   opacity:1;  }
    50%      { transform:scale(1.15); opacity:.7; }
  }
  .mic-listening { animation: micPulse 0.8s ease-in-out infinite; }
</style>

<script>
(function(){
  const VERIFY_URL  = @json(route('portal.paga-sonic.verify'));
  const CSRF        = document.querySelector('meta[name=csrf-token]')?.content || '';

  // ── Protocollo Sonic (deve combaciare con encoder) ──────────────────────────
  const FREQ_PREAMBLE = 700;
  const FREQ_STOP     = 400;
  const FREQ_MAP = {
    1000:'0',1200:'1',1400:'2',1600:'3',
    1800:'4',2000:'5',2200:'6',2400:'7',
    2600:'8',2800:'9',3000:'a',3200:'b',
    3400:'c',3600:'d',3800:'e',4000:'f',
  };
  const TOLERANCE    = 120;   // Hz: tolleranza riconoscimento frequenza
  const MIN_DETECTS  = 3;     // rilevazioni consecutive per confermare un tono
  const TOKEN_LEN    = 8;

  // ── DOM refs ────────────────────────────────────────────────────────────────
  const listenBtn    = document.getElementById('listen-btn');
  const statusEl     = document.getElementById('listen-status');
  const micIcon      = document.getElementById('mic-icon');
  const fftCanvas    = document.getElementById('fft-canvas');
  const fftCtx       = fftCanvas.getContext('2d');
  const fftLabel     = document.getElementById('fft-label');
  const decodeBar    = document.getElementById('decode-progress');
  const charsCount   = document.getElementById('chars-count');
  const charsBar     = document.getElementById('chars-bar');

  let audioCtx, analyser, stream;
  let animFrame;
  let listening = false;

  // ── Decoder state machine ──────────────────────────────────────────────────
  // States: IDLE → PREAMBLE → DECODING → DONE
  let state         = 'IDLE';
  let preambleCount = 0;
  let lastFreqName  = null;
  let detectCount   = 0;
  let decoded       = [];
  let lastConfirmed = null;

  function resetDecoder() {
    state         = 'IDLE';
    preambleCount = 0;
    lastFreqName  = null;
    detectCount   = 0;
    decoded       = [];
    lastConfirmed = null;
    decodeBar.style.display = 'none';
    charsCount.textContent  = '0 / ' + TOKEN_LEN;
    charsBar.style.width    = '0%';
    statusEl.textContent    = 'In ascolto...';
    statusEl.style.background = '';
    statusEl.style.color      = '';
  }

  function closestFreq(hz) {
    // Controlla preamble e stop
    if (Math.abs(hz - FREQ_PREAMBLE) <= TOLERANCE) return 'preamble';
    if (Math.abs(hz - FREQ_STOP)     <= TOLERANCE) return 'stop';
    // Controlla dati
    for (const [f, ch] of Object.entries(FREQ_MAP)) {
      if (Math.abs(hz - Number(f)) <= TOLERANCE) return ch;
    }
    return null;
  }

  function onFreqDetected(freqName) {
    if (freqName === lastFreqName) {
      detectCount++;
    } else {
      lastFreqName = freqName;
      detectCount  = 1;
    }

    // Solo dopo MIN_DETECTS ripetizioni consecutive
    if (detectCount < MIN_DETECTS) return;
    // Non riprocessare lo stesso tono
    if (freqName === lastConfirmed) return;
    lastConfirmed = freqName;

    switch (state) {
      case 'IDLE':
        if (freqName === 'preamble') {
          state = 'PREAMBLE';
          preambleCount = 1;
          statusEl.textContent = 'Preamble rilevato (1)...';
        }
        break;

      case 'PREAMBLE':
        if (freqName === 'preamble') {
          preambleCount++;
          statusEl.textContent = 'Preamble ' + preambleCount + '/3...';
          if (preambleCount >= 3) {
            state = 'DECODING';
            decoded = [];
            decodeBar.style.display = 'block';
            statusEl.textContent = 'Ricezione cifre...';
            statusEl.style.background = 'var(--primary-soft,#ede9fe)';
            statusEl.style.color      = 'var(--primary)';
          }
        } else {
          // Preamble interrotto, riparti
          state = 'IDLE';
          preambleCount = 0;
        }
        break;

      case 'DECODING':
        if (freqName === 'preamble') break; // ignora preamble ripetuti
        if (freqName === 'stop') {
          // Fine prematura — token incompleto, riparti
          if (decoded.length < TOKEN_LEN) {
            setStatus('Ricezione incompleta, riprovo...', 'var(--warning,#d97706)');
            resetDecoder();
          }
          break;
        }
        decoded.push(freqName);
        const pct = (decoded.length / TOKEN_LEN * 100).toFixed(0);
        charsCount.textContent = decoded.length + ' / ' + TOKEN_LEN;
        charsBar.style.width   = pct + '%';

        if (decoded.length === TOKEN_LEN) {
          state = 'DONE';
          const token = decoded.join('');
          stopListening();
          fetchPreview(token);
        }
        break;
    }
  }

  // ── FFT analysis loop ──────────────────────────────────────────────────────
  function analysisLoop() {
    animFrame = requestAnimationFrame(analysisLoop);
    if (!analyser) return;

    const bufLen   = analyser.frequencyBinCount;
    const dataArr  = new Uint8Array(bufLen);
    analyser.getByteFrequencyData(dataArr);

    // Disegna FFT
    fftCtx.clearRect(0,0,340,60);
    const barW = 340 / bufLen * 2;
    let x = 0;
    for (let i=0; i<bufLen; i++) {
      const h = dataArr[i] / 255 * 60;
      fftCtx.fillStyle = state === 'DECODING'
        ? `hsl(${140 + i*0.3},70%,50%)`
        : `hsl(${200 + i*0.3},50%,55%)`;
      fftCtx.fillRect(x, 60-h, Math.max(1,barW-1), h);
      x += barW;
    }

    // Trova frequenza dominante
    let maxVal = 0, maxIdx = 0;
    for (let i=0; i<bufLen; i++) {
      if (dataArr[i] > maxVal) { maxVal = dataArr[i]; maxIdx = i; }
    }

    if (maxVal < 30) {
      // Silenzio: resetta last confirmed per permettere nuove detection
      lastConfirmed = null;
      return;
    }

    const sampleRate = audioCtx.sampleRate;
    const hz = maxIdx * sampleRate / (analyser.fftSize);
    const freqName = closestFreq(hz);

    if (freqName) onFreqDetected(freqName);
  }

  // ── Start / Stop listener ──────────────────────────────────────────────────
  async function startListening() {
    try {
      stream    = await navigator.mediaDevices.getUserMedia({ audio: true });
      audioCtx  = new (window.AudioContext || window.webkitAudioContext)();
      analyser  = audioCtx.createAnalyser();
      analyser.fftSize = 4096;

      const source = audioCtx.createMediaStreamSource(stream);
      source.connect(analyser);

      listening = true;
      fftLabel.style.display = 'none';
      listenBtn.textContent  = '&#9726; Ferma';
      micIcon.classList.add('mic-listening');
      setStatus('In ascolto...', '');

      resetDecoder();
      analysisLoop();

    } catch (err) {
      let msg = 'Impossibile accedere al microfono.';
      if (err.name === 'NotAllowedError') msg = 'Permesso microfono negato.';
      if (err.name === 'NotFoundError')   msg = 'Nessun microfono trovato.';
      setStatus(msg, 'var(--danger)');
    }
  }

  function stopListening() {
    listening = false;
    cancelAnimationFrame(animFrame);
    if (stream) stream.getTracks().forEach(t => t.stop());
    if (audioCtx) audioCtx.close().catch(()=>{});
    analyser = null; audioCtx = null; stream = null;
    micIcon.classList.remove('mic-listening');
    fftCtx.clearRect(0,0,340,60);
    fftLabel.style.display = 'flex';
    listenBtn.textContent = '&#127908; Avvia microfono';
  }

  listenBtn.addEventListener('click', () => {
    if (listening) { stopListening(); setStatus('Fermato.', ''); }
    else           { startListening(); }
  });

  // ── Preview & conferma ─────────────────────────────────────────────────────
  let pendingToken = null;

  async function fetchPreview(token) {
    pendingToken = token;
    setStatus('Codice ricevuto! Verifica...', 'var(--primary)');

    try {
      const res  = await fetch(VERIFY_URL + '?preview=1', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept':        'application/json',
          'X-CSRF-TOKEN':  CSRF,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ token, preview: true }),
      });
      const data = await res.json();

      if (!res.ok) {
        showError(data.error || 'Codice non valido.');
        return;
      }

      // Mostra preview
      document.getElementById('state-idle').style.display    = 'none';
      document.getElementById('state-preview').style.display = 'block';
      document.getElementById('preview-merchant').textContent = data.merchant;
      document.getElementById('preview-amount').textContent   = Number(data.amount).toLocaleString('it-IT');
      if (data.description) document.getElementById('preview-desc').textContent = data.description;
      document.getElementById('preview-expire').textContent   = 'Scade tra ' + data.seconds_left + ' s';

    } catch(e) {
      showError('Errore di rete. Riprova.');
    }
  }

  document.getElementById('confirm-btn').addEventListener('click', async () => {
    if (!pendingToken) return;
    const btn = document.getElementById('confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Pagamento in corso...';

    try {
      const res  = await fetch(VERIFY_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept':        'application/json',
          'X-CSRF-TOKEN':  CSRF,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ token: pendingToken, confirm: true, preview: false }),
      });
      const data = await res.json();

      if (!res.ok) {
        showError(data.error || 'Pagamento fallito.');
        btn.disabled = false; btn.textContent = 'Paga';
        return;
      }

      document.getElementById('state-preview').style.display = 'none';
      document.getElementById('state-success').style.display = 'block';
      document.getElementById('success-amount').textContent  = Number(data.amount).toLocaleString('it-IT') + ' KY';
      document.getElementById('success-merchant').textContent = 'A: ' + data.merchant;

    } catch(e) {
      showError('Errore di rete. Riprova.');
      btn.disabled = false; btn.textContent = 'Paga';
    }
  });

  document.getElementById('cancel-preview-btn').addEventListener('click', () => {
    pendingToken = null;
    document.getElementById('state-preview').style.display = 'none';
    document.getElementById('state-idle').style.display    = 'block';
    resetDecoder();
  });

  document.getElementById('retry-btn').addEventListener('click', () => {
    document.getElementById('state-error').style.display = 'none';
    document.getElementById('state-idle').style.display  = 'block';
    resetDecoder();
  });

  // ── Helpers ────────────────────────────────────────────────────────────────
  function setStatus(msg, color) {
    statusEl.textContent = msg;
    statusEl.style.color = color || '';
    statusEl.style.background = color ? 'var(--surface-soft)' : '';
  }

  function showError(msg) {
    stopListening();
    document.getElementById('state-idle').style.display    = 'none';
    document.getElementById('state-preview').style.display = 'none';
    document.getElementById('state-error').style.display   = 'block';
    document.getElementById('error-msg').textContent = msg;
  }
})();
</script>

@endsection
