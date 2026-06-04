@extends('layouts.portal')

@section('content')
<div class="portal-grid" style="max-width:600px;">
  <div class="stack">

    @if(!isset($pr))
    {{-- ============================================================
         FORM: inserimento importo
         ============================================================ --}}
    <section class="card card-pad">
      <h2 style="margin:0 0 4px;font-size:20px;font-weight:800;">Incassa con Suono</h2>
      <p style="color:var(--ink-muted);font-size:14px;margin:0 0 24px;">
        Il tuo telefono suonerà un codice audio. Il cliente lo riceve con il microfono.
      </p>

      @if(session('portal_error'))
        <div class="alert alert-danger" style="margin-bottom:16px;">{{ session('portal_error') }}</div>
      @endif

      <form method="POST" action="{{ route('portal.incasso-sonic.store') }}">
        @csrf
        <div style="margin-bottom:16px;">
          <label class="form-label" for="amount">Importo (KY)</label>
          <input type="number" id="amount" name="amount" class="form-input"
                 min="1" max="9999999" step="1" required autofocus
                 value="{{ old('amount') }}"
                 placeholder="es. 50">
          @error('amount')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div style="margin-bottom:24px;">
          <label class="form-label" for="description">Causale (opzionale)</label>
          <input type="text" id="description" name="description" class="form-input"
                 maxlength="200" value="{{ old('description') }}" placeholder="es. Ordine #42">
        </div>
        <button type="submit" class="cta" style="width:100%;">Genera codice audio</button>
      </form>
    </section>

    @else
    {{-- ============================================================
         SHOW: encoder audio + polling
         ============================================================ --}}
    <section class="card card-pad" id="sonic-card">

      {{-- PENDING --}}
      <div id="state-pending">
        <div style="text-align:center;padding:10px 0 20px;">
          <div id="sonic-icon" style="font-size:64px;margin-bottom:12px;">&#128266;</div>
          <div style="font-size:32px;font-weight:800;color:var(--ink);">
            {{ ky_format($pr->amount) }} KY
          </div>
          @if($pr->description)
            <div style="color:var(--ink-muted);font-size:14px;margin-top:4px;">{{ $pr->description }}</div>
          @endif

          <div style="margin:24px auto 0;max-width:340px;">
            {{-- Visualizzatore onde audio --}}
            <div style="position:relative;height:60px;background:var(--surface-soft);border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:16px;">
              <canvas id="wave-canvas" width="340" height="60" style="width:100%;height:100%;"></canvas>
              <div id="wave-label" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:var(--ink-muted);">
                Premi per suonare
              </div>
            </div>

            {{-- Status bar --}}
            <div id="sonic-status" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:var(--ink-muted);text-align:center;margin-bottom:12px;">
              Pronto
            </div>

            <button type="button" id="play-btn" class="cta" style="width:100%;font-size:15px;padding:12px;">
              &#9654; Suona codice audio
            </button>

            <div style="margin-top:8px;font-size:11px;color:var(--ink-muted);text-align:center;">
              Avvicina i due telefoni e premi "Suona".<br>
              Il cliente apre <strong>Paga con Suono</strong> e ascolta.
            </div>

            {{-- Countdown --}}
            <div style="margin-top:16px;font-size:12px;color:var(--ink-muted);">
              Scade tra <span id="countdown" style="font-weight:700;color:var(--ink);">5:00</span>
            </div>
            <div style="margin-top:6px;height:4px;background:var(--surface-soft);border-radius:2px;overflow:hidden;">
              <div id="timer-bar" style="height:100%;background:var(--primary);transition:width .5s linear;width:100%;"></div>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:20px;justify-content:center;">
          <form method="POST" action="{{ route('portal.incasso-sonic.cancel', $pr->token) }}">
            @csrf
            <button type="submit" class="cta secondary" style="font-size:13px;padding:8px 18px;">Annulla</button>
          </form>
        </div>
      </div>

      {{-- PAID --}}
      <div id="state-paid" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:64px;margin-bottom:16px;">&#10003;</div>
        <div style="font-size:24px;font-weight:800;color:var(--success,#16a34a);">Pagamento ricevuto!</div>
        <div style="font-size:32px;font-weight:700;margin:8px 0;">
          {{ ky_format($pr->amount) }} KY
        </div>
        <div id="paid-payer" style="font-size:14px;color:var(--ink-muted);"></div>
        <div id="paid-time"  style="font-size:13px;color:var(--ink-muted);margin-top:4px;"></div>
        <a href="{{ route('portal.incasso-sonic.form') }}" class="cta" style="margin-top:24px;display:inline-block;">
          Nuova richiesta Sonic
        </a>
      </div>

      {{-- EXPIRED --}}
      <div id="state-expired" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:48px;margin-bottom:12px;">&#9203;</div>
        <div style="font-size:20px;font-weight:700;color:var(--danger);">Richiesta scaduta</div>
        <div style="color:var(--ink-muted);font-size:14px;margin-top:6px;">Il cliente non ha pagato entro 5 minuti.</div>
        <a href="{{ route('portal.incasso-sonic.form') }}" class="cta" style="margin-top:20px;display:inline-block;">
          Nuova richiesta
        </a>
      </div>

    </section>
    @endif

  </div>
</div>

<style>
  @keyframes sonicPulse {
    0%,100% { transform:scale(1);   opacity:1;  }
    50%      { transform:scale(1.2); opacity:.6; }
  }
  .sonic-playing { animation: sonicPulse 0.4s ease-in-out infinite; }
</style>

@isset($pr)
<script>
(function(){
  // ── Configurazione protocollo Sonic ─────────────────────────────────────────
  const TOKEN       = @json($pr->token);   // 8 hex chars
  const STATUS_URL  = @json(route('portal.incasso-sonic.status', $pr->token));
  const EXPIRE_AT   = new Date({{ $pr->expires_at->valueOf() }});
  const TOTAL_MS    = {{ max(0, $pr->expires_at->diffInMilliseconds(now())) }};

  // Mappa hex → frequenza
  const FREQ = {
    'preamble': 700,  // usato x3 come header
    'stop':     400,  // segnale fine
    '0':1000,'1':1200,'2':1400,'3':1600,
    '4':1800,'5':2000,'6':2200,'7':2400,
    '8':2600,'9':2800,'a':3000,'b':3200,
    'c':3400,'d':3600,'e':3800,'f':4000,
  };
  const TONE_MS  = 200;  // durata singolo tono
  const GAP_MS   = 50;   // silenzio tra toni
  const PRE_REPS = 3;    // ripetizioni preamble

  // ── Encoder audio ────────────────────────────────────────────────────────────
  let audioCtx = null;
  let isPlaying = false;
  const playBtn    = document.getElementById('play-btn');
  const statusEl   = document.getElementById('sonic-status');
  const iconEl     = document.getElementById('sonic-icon');
  const waveLbl    = document.getElementById('wave-label');
  const waveCanvas = document.getElementById('wave-canvas');
  const waveCtx    = waveCanvas.getContext('2d');

  function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

  async function playTone(freq, durationMs) {
    if (!audioCtx) return;
    const osc  = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.type      = 'sine';
    osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
    // Envelope: fade in 10ms, full, fade out 10ms
    gain.gain.setValueAtTime(0, audioCtx.currentTime);
    gain.gain.linearRampToValueAtTime(0.5, audioCtx.currentTime + 0.01);
    gain.gain.setValueAtTime(0.5, audioCtx.currentTime + (durationMs - 10) / 1000);
    gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + durationMs / 1000);
    osc.start(audioCtx.currentTime);
    osc.stop(audioCtx.currentTime + durationMs / 1000);
    await sleep(durationMs + GAP_MS);
  }

  async function playSonic() {
    if (isPlaying) return;
    isPlaying = true;
    playBtn.disabled   = true;
    playBtn.textContent = '🔊 Trasmissione in corso...';
    iconEl.classList.add('sonic-playing');
    waveLbl.style.display = 'none';
    statusEl.textContent  = 'Trasmissione in corso...';
    statusEl.style.background = 'var(--primary-soft,#ede9fe)';
    statusEl.style.color      = 'var(--primary)';

    audioCtx = new (window.AudioContext || window.webkitAudioContext)();

    // Visualizzatore onda (semplice barre animate)
    let vizFrame;
    const analyser = audioCtx.createAnalyser();
    analyser.fftSize = 256;
    const bufLen = analyser.frequencyBinCount;
    const dataArr = new Uint8Array(bufLen);
    function drawWave() {
      vizFrame = requestAnimationFrame(drawWave);
      analyser.getByteFrequencyData(dataArr);
      waveCtx.clearRect(0,0,340,60);
      const barW = 340 / bufLen * 2.5;
      let x = 0;
      for (let i=0; i<bufLen; i++) {
        const h = dataArr[i] / 255 * 60;
        waveCtx.fillStyle = `hsl(${260 + i*0.5},70%,60%)`;
        waveCtx.fillRect(x, 60-h, barW-1, h);
        x += barW;
      }
    }
    drawWave();

    // Preamble (3×)
    for (let i=0; i<PRE_REPS; i++) {
      statusEl.textContent = `Preamble ${i+1}/${PRE_REPS}...`;
      await playTone(FREQ.preamble, TONE_MS);
    }

    // Dati token
    for (let i=0; i<TOKEN.length; i++) {
      const ch = TOKEN[i];
      statusEl.textContent = `Trasmissione cifra ${i+1}/${TOKEN.length}...`;
      await playTone(FREQ[ch], TONE_MS);
    }

    // Stop
    statusEl.textContent = 'Fine trasmissione...';
    await playTone(FREQ.stop, 300);

    cancelAnimationFrame(vizFrame);
    waveCtx.clearRect(0,0,340,60);
    waveLbl.style.display = 'flex';

    await audioCtx.close();
    audioCtx = null;

    iconEl.classList.remove('sonic-playing');
    statusEl.textContent  = 'Trasmissione completata. In attesa di conferma...';
    statusEl.style.background = '#dcfce7';
    statusEl.style.color      = '#166534';
    playBtn.disabled    = false;
    playBtn.textContent = '&#9654; Risuona';
    isPlaying = false;
  }

  playBtn.addEventListener('click', playSonic);

  // ── Countdown ──────────────────────────────────────────────────────────────
  const countdownEl = document.getElementById('countdown');
  const timerBar    = document.getElementById('timer-bar');
  function updateCountdown() {
    const rem = Math.max(0, EXPIRE_AT - Date.now());
    const s   = Math.floor(rem / 1000);
    if (countdownEl) countdownEl.textContent = Math.floor(s/60) + ':' + String(s%60).padStart(2,'0');
    if (timerBar)    timerBar.style.width = (rem / TOTAL_MS * 100).toFixed(1) + '%';
  }
  setInterval(updateCountdown, 500);
  updateCountdown();

  // ── Polling stato ──────────────────────────────────────────────────────────
  const poll = setInterval(async ()=>{
    try {
      const r = await fetch(STATUS_URL, { headers:{'X-Requested-With':'XMLHttpRequest'} });
      const d = await r.json();
      if (d.is_paid) {
        clearInterval(poll);
        document.getElementById('state-pending').style.display = 'none';
        document.getElementById('state-paid').style.display    = 'block';
        if (d.payer_name) document.getElementById('paid-payer').textContent = 'Da: ' + d.payer_name;
        if (d.paid_at)    document.getElementById('paid-time').textContent  = 'Ricevuto alle ' + d.paid_at;
      } else if (d.is_expired || d.status === 'cancelled') {
        clearInterval(poll);
        document.getElementById('state-pending').style.display  = 'none';
        document.getElementById('state-expired').style.display  = 'block';
      }
    } catch(e){}
  }, 2500);
})();
</script>
@endisset

@endsection
