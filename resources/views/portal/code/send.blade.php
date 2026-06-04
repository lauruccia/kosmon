@extends('layouts.portal')

@section('content')
<div class="portal-grid" style="max-width:520px;">
  <div class="stack">

    @if(!isset($pr))
    {{-- FORM --}}
    <section class="card card-pad">
      <h2 style="margin:0 0 4px;font-size:20px;font-weight:800;">Incassa con Codice</h2>
      <p style="color:var(--ink-muted);font-size:14px;margin:0 0 24px;">
        Genera un codice a 6 cifre. Il cliente lo inserisce sul suo telefono e paga.
      </p>
      @if(session('portal_error'))
        <div class="alert alert-danger" style="margin-bottom:16px;">{{ session('portal_error') }}</div>
      @endif
      <form method="POST" action="{{ route('portal.incasso-codice.store') }}">
        @csrf
        <div style="margin-bottom:16px;">
          <label class="form-label" for="amount">Importo (KY)</label>
          <input type="number" id="amount" name="amount" class="form-input"
                 min="1" max="9999999" step="1" required autofocus
                 value="{{ old('amount') }}" placeholder="es. 50">
          @error('amount')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div style="margin-bottom:24px;">
          <label class="form-label" for="description">Causale (opzionale)</label>
          <input type="text" id="description" name="description" class="form-input"
                 maxlength="200" value="{{ old('description') }}" placeholder="es. Ordine #42">
        </div>
        <button type="submit" class="cta" style="width:100%;">Genera codice</button>
      </form>
    </section>

    @else
    {{-- DISPLAY CODICE --}}
    <section class="card card-pad" id="code-card">

      <div id="state-pending">
        <div style="text-align:center;padding:8px 0 16px;">
          <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:16px;">
            Codice di pagamento
          </div>

          {{-- Codice grande --}}
          <div id="code-display" style="
            font-size:72px;font-weight:900;letter-spacing:12px;
            font-family:'Courier New',monospace;color:var(--ink);
            background:var(--surface-soft);border:2px dashed var(--line);
            border-radius:16px;padding:20px 16px;margin:0 auto 8px;
            max-width:340px;line-height:1.1;user-select:all;">
            {{ substr($pr->token, 0, 3) }}&nbsp;{{ substr($pr->token, 3, 3) }}
          </div>

          <div style="font-size:13px;color:var(--ink-muted);margin-bottom:20px;">
            Mostra questo codice al cliente
          </div>

          <div style="font-size:28px;font-weight:800;color:var(--ink);">
            {{ ky_format($pr->amount) }} KY
          </div>
          @if($pr->description)
            <div style="color:var(--ink-muted);font-size:13px;margin-top:4px;">{{ $pr->description }}</div>
          @endif

          <div style="margin:20px auto 0;max-width:320px;">
            {{-- Status --}}
            <div id="code-status" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:var(--ink-muted);text-align:center;margin-bottom:14px;">
              In attesa che il cliente inserisca il codice...
            </div>

            {{-- Countdown --}}
            <div style="font-size:12px;color:var(--ink-muted);">
              Scade tra <span id="countdown" style="font-weight:700;color:var(--ink);">5:00</span>
            </div>
            <div style="margin-top:6px;height:5px;background:var(--surface-soft);border-radius:3px;overflow:hidden;">
              <div id="timer-bar" style="height:100%;background:var(--primary);transition:width .5s linear;width:100%;"></div>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:20px;justify-content:center;">
          <form method="POST" action="{{ route('portal.incasso-codice.cancel', $pr->token) }}">
            @csrf
            <button type="submit" class="cta secondary" style="font-size:13px;padding:8px 18px;">Annulla</button>
          </form>
        </div>
      </div>

      {{-- PAID --}}
      <div id="state-paid" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:64px;margin-bottom:16px;">&#10003;</div>
        <div style="font-size:24px;font-weight:800;color:var(--success,#16a34a);">Pagamento ricevuto!</div>
        <div style="font-size:36px;font-weight:900;margin:10px 0;">{{ ky_format($pr->amount) }} KY</div>
        <div id="paid-payer" style="font-size:14px;color:var(--ink-muted);"></div>
        <div id="paid-time"  style="font-size:13px;color:var(--ink-muted);margin-top:4px;"></div>
        <a href="{{ route('portal.incasso-codice.form') }}" class="cta" style="margin-top:24px;display:inline-block;">Nuovo codice</a>
      </div>

      {{-- EXPIRED --}}
      <div id="state-expired" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:48px;margin-bottom:12px;">&#9203;</div>
        <div style="font-size:20px;font-weight:700;color:var(--danger);">Codice scaduto</div>
        <div style="color:var(--ink-muted);font-size:14px;margin-top:6px;">Il cliente non ha inserito il codice entro 5 minuti.</div>
        <a href="{{ route('portal.incasso-codice.form') }}" class="cta" style="margin-top:20px;display:inline-block;">Genera nuovo codice</a>
      </div>

    </section>
    @endif

  </div>
</div>

@isset($pr)
<script>
(function(){
  const STATUS_URL = @json(route('portal.incasso-codice.status', $pr->token));
  const EXPIRE_AT  = new Date({{ $pr->expires_at->valueOf() }});
  const TOTAL_MS   = {{ max(0, $pr->expires_at->diffInMilliseconds(now())) }};

  const countdownEl = document.getElementById('countdown');
  const timerBar    = document.getElementById('timer-bar');

  function updateCountdown() {
    const rem = Math.max(0, EXPIRE_AT - Date.now());
    const s   = Math.floor(rem / 1000);
    if (countdownEl) countdownEl.textContent = Math.floor(s/60) + ':' + String(s%60).padStart(2,'0');
    if (timerBar)    timerBar.style.width = (TOTAL_MS > 0 ? rem/TOTAL_MS*100 : 0).toFixed(1) + '%';
    if (rem <= 30000) {
      if(countdownEl) countdownEl.style.color = 'var(--danger)';
      if(timerBar)    timerBar.style.background = 'var(--danger)';
    }
  }
  setInterval(updateCountdown, 500);
  updateCountdown();

  const poll = setInterval(async () => {
    try {
      const r = await fetch(STATUS_URL, {headers:{'X-Requested-With':'XMLHttpRequest'}});
      const d = await r.json();
      if (d.is_paid) {
        clearInterval(poll);
        document.getElementById('state-pending').style.display = 'none';
        document.getElementById('state-paid').style.display    = 'block';
        if (d.payer_name) document.getElementById('paid-payer').textContent = 'Da: ' + d.payer_name;
        if (d.paid_at)    document.getElementById('paid-time').textContent  = 'Ricevuto alle ' + d.paid_at;
      } else if (d.is_expired || d.status === 'cancelled') {
        clearInterval(poll);
        document.getElementById('state-pending').style.display = 'none';
        document.getElementById('state-expired').style.display = 'block';
      }
    } catch(e){}
  }, 2500);
})();
</script>
@endisset
@endsection
