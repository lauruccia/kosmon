@extends('layouts.portal')

@section('content')
<div class="portal-grid" style="max-width:420px;">
  <div class="stack">

    <section class="card card-pad" id="code-pay-card">

      {{-- KEYPAD --}}
      <div id="state-keypad">
        <h2 style="margin:0 0 4px;font-size:20px;font-weight:800;text-align:center;">Paga con Codice</h2>
        <p style="color:var(--ink-muted);font-size:14px;text-align:center;margin:0 0 24px;">
          Inserisci il codice a 6 cifre mostrato dal cassa
        </p>

        {{-- Display cifre --}}
        <div style="display:flex;gap:10px;justify-content:center;margin-bottom:24px;" id="digit-display">
          <div class="digit-box" id="d0"></div>
          <div class="digit-box" id="d1"></div>
          <div class="digit-box" id="d2"></div>
          <div style="width:8px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;color:var(--ink-muted);">·</div>
          <div class="digit-box" id="d3"></div>
          <div class="digit-box" id="d4"></div>
          <div class="digit-box" id="d5"></div>
        </div>

        {{-- Keypad --}}
        <div id="keypad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:300px;margin:0 auto 20px;">
          @foreach([1,2,3,4,5,6,7,8,9] as $n)
            <button type="button" class="key-btn" onclick="pressKey('{{ $n }}')">{{ $n }}</button>
          @endforeach
          <button type="button" class="key-btn key-clear" onclick="clearLast()">&#8592;</button>
          <button type="button" class="key-btn" onclick="pressKey('0')">0</button>
          <button type="button" class="key-btn key-clear" onclick="clearAll()">C</button>
        </div>

        <div id="keypad-status" style="min-height:20px;text-align:center;font-size:13px;color:var(--danger);margin-bottom:12px;"></div>

        <button type="button" id="verify-btn" class="cta" style="width:100%;font-size:16px;padding:14px;opacity:.4;" disabled>
          Verifica codice
        </button>
      </div>

      {{-- PREVIEW --}}
      <div id="state-preview" style="display:none;text-align:center;padding:10px 0;">
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:8px;">Conferma pagamento</div>
        <div id="prev-merchant" style="font-size:18px;font-weight:800;color:var(--ink);margin-bottom:6px;"></div>
        <div id="prev-amount"   style="font-size:48px;font-weight:900;color:var(--primary);line-height:1;"></div>
        <div style="font-size:16px;color:var(--ink-muted);margin-bottom:4px;">KY</div>
        <div id="prev-desc"     style="font-size:13px;color:var(--ink-muted);margin-bottom:4px;"></div>
        <div id="prev-expire"   style="font-size:12px;color:var(--ink-muted);margin-bottom:24px;"></div>
        <div style="display:flex;gap:10px;justify-content:center;">
          <button type="button" id="confirm-btn" class="cta" style="min-width:120px;">Paga ora</button>
          <button type="button" id="back-btn" class="cta secondary" style="min-width:100px;">Indietro</button>
        </div>
        <div id="confirm-status" style="margin-top:10px;font-size:13px;color:var(--danger);min-height:20px;"></div>
      </div>

      {{-- SUCCESS --}}
      <div id="state-success" style="display:none;text-align:center;padding:20px 0;">
        <div style="font-size:64px;margin-bottom:16px;">&#10003;</div>
        <div style="font-size:22px;font-weight:800;color:var(--success,#16a34a);">Pagamento inviato!</div>
        <div id="succ-amount"   style="font-size:36px;font-weight:900;margin:8px 0;"></div>
        <div id="succ-merchant" style="font-size:14px;color:var(--ink-muted);"></div>
        <div style="display:flex;gap:10px;justify-content:center;margin-top:24px;">
          <a href="{{ route('portal.paga-codice.form') }}" class="cta secondary">Nuovo pagamento</a>
          <a href="{{ route('portal.movements') }}" class="cta">Movimenti</a>
        </div>
      </div>

    </section>
  </div>
</div>

<style>
.digit-box {
  width: 44px; height: 56px;
  border: 2px solid var(--line);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px; font-weight: 900; font-family: 'Courier New', monospace;
  color: var(--ink);
  transition: border-color .15s, background .15s;
}
.digit-box.filled { border-color: var(--primary); background: var(--primary-soft,#ede9fe); }
.digit-box.cursor { border-color: var(--primary); animation: blink .8s step-end infinite; }
@keyframes blink { 0%,100%{border-color:var(--primary)} 50%{border-color:var(--line)} }
.key-btn {
  background: var(--surface-soft); border: 1px solid var(--line);
  border-radius: 12px; font-size: 22px; font-weight: 700;
  padding: 14px; cursor: pointer; transition: background .1s, transform .08s;
  color: var(--ink);
}
.key-btn:active { background: var(--primary-soft,#ede9fe); transform: scale(.95); }
.key-clear { font-size: 16px; color: var(--ink-muted); }
</style>

<script>
(function(){
  const VERIFY_URL = @json(route('portal.paga-codice.verify'));
  const CSRF       = document.querySelector('meta[name=csrf-token]')?.content || '';

  let digits = [];

  function renderDigits() {
    for (let i = 0; i < 6; i++) {
      const el = document.getElementById('d' + i);
      el.textContent = digits[i] ?? '';
      el.classList.toggle('filled', digits[i] !== undefined);
      el.classList.toggle('cursor', i === digits.length && digits.length < 6);
    }
    const btn = document.getElementById('verify-btn');
    btn.disabled = digits.length < 6;
    btn.style.opacity = digits.length < 6 ? '.4' : '1';
  }

  window.pressKey = function(k) {
    if (digits.length >= 6) return;
    digits.push(k);
    renderDigits();
    document.getElementById('keypad-status').textContent = '';
    if (digits.length === 6) document.getElementById('verify-btn').focus();
  };

  window.clearLast = function() {
    digits.pop(); renderDigits();
  };

  window.clearAll = function() {
    digits = []; renderDigits();
  };

  // Tastiera fisica
  document.addEventListener('keydown', e => {
    if (document.getElementById('state-keypad').style.display === 'none') return;
    if (e.key >= '0' && e.key <= '9') pressKey(e.key);
    else if (e.key === 'Backspace') clearLast();
    else if (e.key === 'Enter' && digits.length === 6) doVerify();
  });

  renderDigits();

  // Verifica
  document.getElementById('verify-btn').addEventListener('click', doVerify);

  async function doVerify() {
    if (digits.length < 6) return;
    const code = digits.join('');
    const btn  = document.getElementById('verify-btn');
    btn.disabled = true; btn.textContent = 'Verifica...';

    try {
      const r = await fetch(VERIFY_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ code, confirm: false }),
      });
      const d = await r.json();

      if (!r.ok) {
        document.getElementById('keypad-status').textContent = d.error || 'Errore verifica.';
        btn.disabled = false; btn.textContent = 'Verifica codice';
        return;
      }

      // Mostra preview
      document.getElementById('state-keypad').style.display  = 'none';
      document.getElementById('state-preview').style.display = 'block';
      document.getElementById('prev-merchant').textContent   = d.merchant;
      document.getElementById('prev-amount').textContent     = (Number(d.amount) / 100).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      if (d.description) document.getElementById('prev-desc').textContent = '"' + d.description + '"';
      document.getElementById('prev-expire').textContent = 'Scade tra ' + d.seconds_left + ' s';

    } catch(e) {
      document.getElementById('keypad-status').textContent = 'Errore di rete. Riprova.';
      btn.disabled = false; btn.textContent = 'Verifica codice';
    }
  }

  // Conferma
  document.getElementById('confirm-btn').addEventListener('click', async () => {
    const code = digits.join('');
    const btn  = document.getElementById('confirm-btn');
    btn.disabled = true; btn.textContent = 'Pagamento...';

    try {
      const r = await fetch(VERIFY_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ code, confirm: true }),
      });
      const d = await r.json();

      if (!r.ok) {
        document.getElementById('confirm-status').textContent = d.error || 'Pagamento fallito.';
        btn.disabled = false; btn.textContent = 'Paga ora';
        return;
      }

      document.getElementById('state-preview').style.display = 'none';
      document.getElementById('state-success').style.display = 'block';
      document.getElementById('succ-amount').textContent     = (Number(d.amount) / 100).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' KY';
      document.getElementById('succ-merchant').textContent   = 'A: ' + d.merchant;

    } catch(e) {
      document.getElementById('confirm-status').textContent = 'Errore di rete. Riprova.';
      btn.disabled = false; btn.textContent = 'Paga ora';
    }
  });

  document.getElementById('back-btn').addEventListener('click', () => {
    document.getElementById('state-preview').style.display = 'none';
    document.getElementById('state-keypad').style.display  = 'block';
    const btn = document.getElementById('verify-btn');
    btn.disabled = false; btn.textContent = 'Verifica codice'; btn.style.opacity = '1';
  });
})();
</script>
@endsection
