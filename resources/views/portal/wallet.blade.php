@extends('layouts.portal')

@section('content')

{{-- ============================================================
     KY WALLET — carta virtuale stile Google Wallet
     ============================================================ --}}

<div class="portal-grid" style="max-width:480px;">
  <div class="stack">

    {{-- ── CARTA VIRTUALE ─────────────────────────────────────── --}}
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

    {{-- ── AGGIUNGI AL BROWSER ─────────────────────────────────── --}}
    <section class="card card-pad" style="padding:16px 20px;">
      <div style="display:flex;align-items:center;gap:14px;">
        <div style="font-size:32px;">&#128274;</div>
        <div style="flex:1;">
          <div style="font-weight:700;font-size:14px;margin-bottom:2px;">Aggiungi al browser</div>
          <div style="font-size:12px;color:var(--ink-muted);">
            Su Chrome Android, la carta KY appare nel payment sheet nativo come Google Pay.
          </div>
        </div>
        <button type="button" id="add-wallet-btn" class="cta" style="font-size:13px;padding:8px 16px;white-space:nowrap;">
          Aggiungi
        </button>
      </div>
      <div id="wallet-status" style="font-size:12px;margin-top:8px;color:var(--ink-muted);display:none;"></div>
    </section>

    {{-- ── METODI DI PAGAMENTO ─────────────────────────────────── --}}
    <section class="card card-pad">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:16px;">
        Come vuoi pagare?
      </div>

      <div style="display:grid;gap:10px;">

        {{-- Codice --}}
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

        {{-- QR --}}
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

        {{-- NFC --}}
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

        {{-- Link di pagamento --}}
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

      </div>
    </section>

    {{-- ── RICEVI PAGAMENTO ────────────────────────────────────── --}}
    <section class="card card-pad">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:16px;">
        Come vuoi ricevere?
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <a href="{{ route('portal.incasso-codice.form') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#128290;</div>
          <div style="font-weight:700;font-size:13px;">Codice</div>
        </a>
        <a href="{{ route('portal.incasso-qr.form') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#9638;</div>
          <div style="font-weight:700;font-size:13px;">QR dinamico</div>
        </a>
        <a href="{{ route('portal.incasso-nfc.form') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#128246;</div>
          <div style="font-weight:700;font-size:13px;">NFC</div>
        </a>
        <a href="{{ route('portal.payment-links.index') }}" class="wallet-receive-btn">
          <div style="font-size:28px;margin-bottom:6px;">&#128279;</div>
          <div style="font-weight:700;font-size:13px;">Link</div>
        </a>
      </div>
    </section>

  </div>
</div>

<style>
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
  background: rgba(255,255,255,.06);
  border-radius: 50%;
}
.ky-card::after {
  content: '';
  position: absolute; bottom: -60px; left: -20px;
  width: 160px; height: 160px;
  background: rgba(255,255,255,.04);
  border-radius: 50%;
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
  padding:14px; background:var(--surface-soft);
  border:1px solid var(--line); border-radius:14px;
  text-decoration:none; color:var(--ink);
  transition:background .15s, transform .1s;
}
.wallet-method-btn:hover { background:var(--surface); transform:translateX(2px); }
.wallet-method-icon {
  width:48px; height:48px; border-radius:14px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; color:#fff;
}
.wallet-method-title { font-weight:700; font-size:14px; margin-bottom:2px; }
.wallet-method-sub   { font-size:12px; color:var(--ink-muted); }
.wallet-method-arrow { margin-left:auto; font-size:20px; color:var(--ink-muted); padding-left:8px; }

/* ── Ricevi ─────────────────────────────────────────────────── */
.wallet-receive-btn {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  padding:20px 12px; background:var(--surface-soft);
  border:1px solid var(--line); border-radius:14px;
  text-decoration:none; color:var(--ink);
  transition:background .15s;
}
.wallet-receive-btn:hover { background:var(--surface); }
</style>

<script>
(function(){
  const KY_METHOD = window.location.origin + '/paga/handler';
  const btn       = document.getElementById('add-wallet-btn');
  const statusEl  = document.getElementById('wallet-status');

  async function checkWalletStatus() {
    if (!navigator.serviceWorker) {
      btn.textContent = 'Non supportato';
      btn.disabled = true; btn.style.opacity = '.5';
      return;
    }
    try {
      const reg = await navigator.serviceWorker.ready;
      if ('paymentManager' in reg) {
        // Controlla se già registrato
        const instruments = await reg.paymentManager.instruments.keys();
        if (instruments.includes('ky-default')) {
          btn.textContent = '✓ Aggiunto';
          btn.style.background = '#059669'; btn.disabled = true;
          statusEl.textContent = 'KY Wallet è registrato nel tuo browser.';
          statusEl.style.display = 'block'; statusEl.style.color = '#059669';
        }
      } else {
        btn.textContent = 'Non supportato';
        btn.disabled = true; btn.style.opacity = '.5';
        statusEl.textContent = 'Il tuo browser non supporta i payment handler. Usa Chrome su Android.';
        statusEl.style.display = 'block';
      }
    } catch(e) {}
  }

  btn.addEventListener('click', async () => {
    if (!navigator.serviceWorker) return;
    btn.disabled = true; btn.textContent = 'Registrazione...';
    try {
      const reg = await navigator.serviceWorker.ready;
      if ('paymentManager' in reg) {
        await reg.paymentManager.instruments.set('ky-default', {
          name:   'KMoney KY',
          icons:  [{ src: '/assets/brand/icon-192.png', sizes: '192x192', type: 'image/png' }],
          method: KY_METHOD,
        });
        btn.textContent = '✓ Aggiunto';
        btn.style.background = '#059669';
        statusEl.textContent = 'KY Wallet aggiunto! Appare ora nel payment sheet del browser.';
        statusEl.style.display = 'block'; statusEl.style.color = '#059669';
      } else {
        btn.textContent = 'Non supportato'; btn.style.opacity = '.5';
        statusEl.textContent = 'Il tuo browser non supporta i payment handler.';
        statusEl.style.display = 'block';
      }
    } catch(e) {
      btn.disabled = false; btn.textContent = 'Aggiungi';
      statusEl.textContent = 'Errore: ' + (e.message || e.name);
      statusEl.style.display = 'block'; statusEl.style.color = 'var(--danger)';
    }
  });

  checkWalletStatus();
})();
</script>

@endsection
