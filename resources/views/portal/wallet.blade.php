@extends('layouts.portal')

@section('content')

{{-- ============================================================
     KY WALLET — carta virtuale stile Google Wallet
     ============================================================ --}}

<div class="wallet-grid">

  {{-- ══ COLONNA 1 — Carta + Browser ══════════════════════════ --}}
  <div class="stack">

    <div class="ky-card-outer">
      <div class="ky-card" id="ky-card">
        <div class="ky-card-top">
          <div class="ky-card-logo">KY</div>
          <div class="ky-card-chip">&#9632;&#9632;&#9632;&#9632;</div>
        </div>
        <div class="ky-card-balance-label">Saldo disponibile</div>
        <div class="ky-card-balance">
          {{ number_format($account->available_balance ?? 0, 2, ',', '.') }}
          <span class="ky-card-currency">KY</span>
        </div>
        <div class="ky-card-bottom">
          <div>
            <div class="ky-card-holder">{{ $company?->name ?? $account->display_name }}</div>
            <div class="ky-card-number">{{ $account->account_number }}</div>
          </div>
          <div class="ky-card-nfc-icon" title="NFC abilitato">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
              <path d="M20 12a8 8 0 01-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              <path d="M17 12a5 5 0 01-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              <path d="M14 12a2 2 0 01-2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
        </div>
      </div>
    </div>

    {{-- Saldo effettivo + fido --}}
    @php
      $bal    = $account->balance ?? 0;
      $avail  = $account->available_balance ?? 0;
      $fido   = $account->company?->credit_limit ?? 0;
    @endphp
    <div class="wallet-stat-row">
      <div class="wallet-stat-box">
        <div class="wallet-stat-label">Saldo effettivo</div>
        <div class="wallet-stat-value {{ $bal >= 0 ? 'positive' : 'negative' }}">
          {{ $bal >= 0 ? '+' : '' }}{{ number_format($bal, 2, ',', '.') }} KY
        </div>
      </div>
      <div class="wallet-stat-box">
        <div class="wallet-stat-label">Fido disponibile</div>
        <div class="wallet-stat-value">
          {{ $fido > 0 ? number_format($fido, 2, ',', '.') . ' KY' : '—' }}
        </div>
      </div>
    </div>

    {{-- Aggiungi al browser --}}
    <section class="card card-pad" style="padding:16px 20px;">
      <div style="display:flex;align-items:center;gap:14px;">
        <div style="font-size:28px;">&#128274;</div>
        <div style="flex:1;">
          <div style="font-weight:700;font-size:13px;margin-bottom:2px;">Installa KMoney</div>
          <div style="font-size:11.5px;color:var(--ink-muted);">
            Aggiungi l'app alla schermata Home per accesso rapido e notifiche.
          </div>
        </div>
        <button type="button" id="add-wallet-btn" class="cta" style="font-size:12px;padding:7px 14px;white-space:nowrap;">
          Aggiungi
        </button>
      </div>
      <div id="wallet-status" style="font-size:12px;margin-top:8px;color:var(--ink-muted);display:none;"></div>
    </section>

  </div>

  {{-- ══ COLONNA 2 — Pagare ════════════════════════════════════ --}}
  <section class="card card-pad wallet-col-card">
    <div class="wallet-col-title">Come vuoi pagare?</div>
    <div style="display:grid;gap:10px;">

      <a href="{{ route('portal.paga-codice.form') }}" class="wallet-method-btn">
        <div class="wallet-method-icon" style="background:#6d28d9;">
          <span style="font-size:22px;font-weight:900;font-family:monospace;letter-spacing:2px;">123</span>
        </div>
        <div>
          <div class="wallet-method-title">Codice a 6 cifre</div>
          <div class="wallet-method-sub">Inserisci il codice mostrato dal cassa</div>
        </div>
        <div class="wallet-method-arrow">&#8250;</div>
      </a>

      <a href="{{ route('portal.pay.form') }}" class="wallet-method-btn">
        <div class="wallet-method-icon" style="background:#0ea5e9;">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <rect x="3" y="3" width="8" height="8" rx="1" stroke="white" stroke-width="2"/>
            <rect x="13" y="3" width="8" height="8" rx="1" stroke="white" stroke-width="2"/>
            <rect x="3" y="13" width="8" height="8" rx="1" stroke="white" stroke-width="2"/>
            <rect x="13" y="13" width="4" height="4" rx=".5" fill="white"/>
            <rect x="19" y="13" width="2" height="2" rx=".5" fill="white"/>
            <rect x="13" y="19" width="2" height="2" rx=".5" fill="white"/>
            <rect x="17" y="17" width="2" height="4" rx=".5" fill="white"/>
          </svg>
        </div>
        <div>
          <div class="wallet-method-title">Scansiona QR</div>
          <div class="wallet-method-sub">Inquadra il QR del cassa</div>
        </div>
        <div class="wallet-method-arrow">&#8250;</div>
      </a>

      <a href="{{ route('portal.incasso-nfc.form') }}" class="wallet-method-btn">
        <div class="wallet-method-icon" style="background:#10b981;">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M20 12a8 8 0 01-8 8M17 12a5 5 0 01-5 5M14 12a2 2 0 01-2 2" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="12" cy="12" r="2" fill="white"/>
          </svg>
        </div>
        <div>
          <div class="wallet-method-title">NFC tap-to-pay</div>
          <div class="wallet-method-sub">Avvicina i telefoni</div>
        </div>
        <div class="wallet-method-arrow">&#8250;</div>
      </a>

      <a href="{{ route('portal.payment-links.index') }}" class="wallet-method-btn">
        <div class="wallet-method-icon" style="background:#f59e0b;">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71" stroke="white" stroke-width="2" stroke-linecap="round"/>
            <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71" stroke="white" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <div>
          <div class="wallet-method-title">Link di pagamento</div>
          <div class="wallet-method-sub">Condividi un link per ricevere</div>
        </div>
        <div class="wallet-method-arrow">&#8250;</div>
      </a>

      <a href="{{ route('portal.paga-sonic.form') }}" class="wallet-method-btn">
        <div class="wallet-method-icon" style="background:#8b5cf6;">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M9 18V5l12-2v13" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="6" cy="18" r="3" stroke="white" stroke-width="2"/>
            <circle cx="18" cy="16" r="3" stroke="white" stroke-width="2"/>
          </svg>
        </div>
        <div>
          <div class="wallet-method-title">Paga Sonic</div>
          <div class="wallet-method-sub">Pagamento via ultrasuoni</div>
        </div>
        <div class="wallet-method-arrow">&#8250;</div>
      </a>

    </div>
  </section>

  {{-- ══ COLONNA 3 — Ricevere + Link rapidi ═══════════════════ --}}
  <div class="stack">

    <section class="card card-pad wallet-col-card">
      <div class="wallet-col-title">Come vuoi ricevere?</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <a href="{{ route('portal.incasso-codice.form') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#128290;</div>
          <div style="font-weight:700;font-size:13px;">Codice</div>
          <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">6 cifre</div>
        </a>
        <a href="{{ route('portal.incasso-qr.form') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#9638;</div>
          <div style="font-weight:700;font-size:13px;">QR dinamico</div>
          <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">Scansionabile</div>
        </a>
        <a href="{{ route('portal.incasso-nfc.form') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#128246;</div>
          <div style="font-weight:700;font-size:13px;">NFC</div>
          <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">Tap fisico</div>
        </a>
        <a href="{{ route('portal.incasso-sonic.form') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#127908;</div>
          <div style="font-weight:700;font-size:13px;">Sonic</div>
          <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">Ultrasuoni</div>
        </a>
      </div>
    </section>

    {{-- Link rapidi --}}
    <section class="card card-pad wallet-col-card">
      <div class="wallet-col-title">Accesso rapido</div>
      <div style="display:grid;gap:8px;">
        <a href="{{ route('portal.movements') }}" class="wallet-quick-link">
          <span style="font-size:16px;">📊</span>
          <span>Storico movimenti</span>
          <span class="wallet-method-arrow">&#8250;</span>
        </a>
        <a href="{{ route('portal.payment-links.index') }}" class="wallet-quick-link">
          <span style="font-size:16px;">🔗</span>
          <span>I miei link pagamento</span>
          <span class="wallet-method-arrow">&#8250;</span>
        </a>
        <a href="{{ route('portal.nfc-cards.index') }}" class="wallet-quick-link">
          <span style="font-size:16px;">💳</span>
          <span>Le mie Card NFC</span>
          <span class="wallet-method-arrow">&#8250;</span>
        </a>
        <a href="{{ route('portal.scheduled-payments.index') }}" class="wallet-quick-link">
          <span style="font-size:16px;">⏰</span>
          <span>Pagamenti programmati</span>
          <span class="wallet-method-arrow">&#8250;</span>
        </a>
      </div>
    </section>

  </div>

</div>

<style>
/* ── Layout 3 colonne ───────────────────────────────────────── */
.wallet-grid {
  display: grid;
  grid-template-columns: 340px 1fr 1fr;
  gap: 16px;
  align-items: start;
}
@media (max-width: 1100px) {
  .wallet-grid { grid-template-columns: 320px 1fr; }
  .wallet-grid > div:last-child { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
}
@media (max-width: 768px) {
  .wallet-grid { grid-template-columns: 1fr; }
  .wallet-grid > div:last-child { grid-column: auto; display: block; }
}

/* ── Titolo sezione colonna ─────────────────────────────────── */
.wallet-col-title {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--ink-muted); margin-bottom: 16px;
}
.wallet-col-card { height: 100%; box-sizing: border-box; }

/* ── Stat row sotto la carta ────────────────────────────────── */
.wallet-stat-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
}
.wallet-stat-box {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: 12px; padding: 12px 14px;
}
.wallet-stat-label {
  font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--ink-muted); margin-bottom: 4px;
}
.wallet-stat-value {
  font-size: 15px; font-weight: 800;
  letter-spacing: -.01em; color: var(--ink);
}
.wallet-stat-value.positive { color: var(--success); }
.wallet-stat-value.negative { color: var(--danger); }

/* ── Carta virtuale ─────────────────────────────────────────── */
.ky-card-outer { padding: 4px; }
.ky-card {
  background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 45%, #7c3aed 70%, #a78bfa 100%);
  border-radius: 20px;
  padding: 24px 24px 20px;
  color: #fff;
  box-shadow: 0 8px 32px rgba(109,40,217,.35), 0 2px 8px rgba(0,0,0,.15);
  position: relative; overflow: hidden;
  min-height: 200px;
}
.ky-card::before {
  content: '';
  position: absolute; top: -40px; right: -40px;
  width: 200px; height: 200px;
  background: rgba(255,255,255,.06); border-radius: 50%;
}
.ky-card::after {
  content: '';
  position: absolute; bottom: -60px; left: -20px;
  width: 160px; height: 160px;
  background: rgba(255,255,255,.04); border-radius: 50%;
}
.ky-card-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
.ky-card-logo { font-size:22px; font-weight:900; letter-spacing:2px; opacity:.95; }
.ky-card-chip { font-size:10px; letter-spacing:3px; opacity:.6; }
.ky-card-balance-label { font-size:11px; text-transform:uppercase; letter-spacing:.1em; opacity:.7; margin-bottom:4px; }
.ky-card-balance { font-size:42px; font-weight:900; letter-spacing:-1px; line-height:1; margin-bottom:24px; }
.ky-card-currency { font-size:18px; font-weight:600; opacity:.8; }
.ky-card-bottom { display:flex; justify-content:space-between; align-items:flex-end; }
.ky-card-holder { font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
.ky-card-number { font-size:12px; opacity:.65; font-family:'Courier New',monospace; letter-spacing:1px; }
.ky-card-nfc-icon { opacity:.7; }

/* ── Metodi pagamento ───────────────────────────────────────── */
.wallet-method-btn {
  display:flex; align-items:center; gap:14px;
  padding:13px 14px; background:var(--surface-soft);
  border:1px solid var(--line); border-radius:12px;
  text-decoration:none; color:var(--ink);
  transition:background .15s, border-color .15s, transform .1s;
}
.wallet-method-btn:hover { background:var(--surface-hover); border-color:var(--line-strong); transform:translateX(2px); }
.wallet-method-icon {
  width:46px; height:46px; border-radius:13px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; color:#fff;
}
.wallet-method-title { font-weight:700; font-size:13.5px; margin-bottom:2px; }
.wallet-method-sub   { font-size:11.5px; color:var(--ink-muted); }
.wallet-method-arrow { margin-left:auto; font-size:20px; color:var(--ink-muted); padding-left:8px; flex-shrink:0; }

/* ── Ricevi ─────────────────────────────────────────────────── */
.wallet-receive-btn {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  padding:18px 10px; background:var(--surface-soft);
  border:1px solid var(--line); border-radius:12px;
  text-decoration:none; color:var(--ink);
  transition:background .15s, border-color .15s;
  text-align:center;
}
.wallet-receive-btn:hover { background:var(--surface-hover); border-color:var(--line-strong); }

/* ── Link rapidi ────────────────────────────────────────────── */
.wallet-quick-link {
  display:flex; align-items:center; gap:12px;
  padding:11px 14px; background:var(--surface-soft);
  border:1px solid var(--line); border-radius:10px;
  text-decoration:none; color:var(--ink); font-size:13.5px; font-weight:600;
  transition:background .15s, border-color .15s;
}
.wallet-quick-link:hover { background:var(--surface-hover); border-color:var(--line-strong); }
</style>

<script>
(function(){
  const btn      = document.getElementById('add-wallet-btn');
  const statusEl = document.getElementById('wallet-status');
  const isIOS    = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;

  function showStatus(msg, color) {
    statusEl.textContent = msg;
    statusEl.style.display = 'block';
    statusEl.style.color = color || 'var(--ink-muted)';
  }

  // Già installata come PWA
  if (isStandalone) {
    btn.textContent = '✓ App installata';
    btn.style.background = '#059669';
    btn.disabled = true;
    showStatus('KMoney è già installata sul tuo dispositivo.', '#059669');
    return;
  }

  // iOS: non supporta beforeinstallprompt — mostra istruzioni manuali
  if (isIOS) {
    btn.textContent = 'Come installare';
    btn.addEventListener('click', function() {
      showStatus('Su Safari: tocca ↑ Condividi → "Aggiungi a schermata Home"', 'var(--ink-soft)');
    });
    return;
  }

  // Android/Chrome: usa beforeinstallprompt se disponibile
  // Il prompt può essere già catturato dal layout (window._kmInstallPrompt)
  // oppure arriva dopo questo script — ascoltiamo entrambi i casi
  function tryInstall() {
    const prompt = window._kmInstallPrompt;
    if (!prompt) {
      showStatus('Apri il menu Chrome → "Aggiungi a schermata Home" per installare l\'app.', 'var(--ink-soft)');
      btn.disabled = false;
      btn.textContent = 'Come installare';
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Installazione...';
    prompt.prompt();
    prompt.userChoice.then(function(choice) {
      if (choice.outcome === 'accepted') {
        btn.textContent = '✓ Installata';
        btn.style.background = '#059669';
        showStatus('KMoney è stata aggiunta alla schermata Home!', '#059669');
        window._kmInstallPrompt = null;
      } else {
        btn.disabled = false;
        btn.textContent = 'Installa app';
        showStatus('Installazione annullata.', 'var(--ink-muted)');
      }
    });
  }

  // Se il prompt è già disponibile mostra subito "Installa app"
  // altrimenti aspetta l'evento (può arrivare dopo il caricamento)
  function initBtn() {
    if (window._kmInstallPrompt) {
      btn.textContent = 'Installa app';
    } else {
      // Aspetta fino a 3s che arrivi il prompt
      var waited = 0;
      var check = setInterval(function() {
        waited += 200;
        if (window._kmInstallPrompt) {
          btn.textContent = 'Installa app';
          clearInterval(check);
        } else if (waited >= 3000) {
          btn.textContent = 'Come installare';
          clearInterval(check);
        }
      }, 200);
    }
  }

  btn.addEventListener('click', tryInstall);
  initBtn();
})();
</script>

@endsection
