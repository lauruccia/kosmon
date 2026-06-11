@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('portal.requests') }}">Le mie richieste</a>
<a class="cta secondary" href="{{ route('portal.movements') }}">Movimenti</a>
@endsection




@section('content')
{{-- Alert sessione --}}
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<style>
.receive-hub {
    display: grid;
    gap: 14px;
    margin-bottom: 18px;
}
.receive-hero {
    background: linear-gradient(135deg, #0f172a 0%, #10305e 62%, #0284c7 100%);
    color: #fff;
    border-radius: var(--radius);
    padding: 22px;
}
.receive-hero h2 {
    margin: 0;
    font-size: 28px;
    line-height: 1.05;
    letter-spacing: 0;
}
.receive-hero p {
    margin: 8px 0 0;
    color: rgba(255,255,255,.72);
    max-width: 620px;
}
.receive-method-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}
.receive-method {
    display: grid;
    gap: 10px;
    align-content: start;
    min-height: 148px;
    padding: 14px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: var(--shadow-xs);
}
.receive-method__icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    background: var(--primary-light);
    color: var(--primary);
}
.receive-method strong {
    font-size: 14px;
    color: var(--ink);
}
.receive-method span {
    font-size: 12.5px;
    color: var(--ink-soft);
    line-height: 1.4;
}
@media (max-width: 920px) {
    .receive-method-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 768px) {

    .topbar > div[style*="display:flex"] { display: none !important; }
    .receive-hero { padding: 18px 16px; border-radius: 14px; }
    .receive-hero h2 { font-size: 24px; }
    .receive-hub,
    .summary-grid {
        max-width: calc(100vw - 20px);
    }
    .receive-method-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        max-width: calc(100vw - 20px);
    }
    .receive-method { min-height: 136px; padding: 12px; min-width: 0; }
    .receive-method * { min-width: 0; }
}
@media (max-width: 480px) {
    .receive-method-grid { grid-template-columns: 1fr; }
}
</style>

<section class="receive-hub">
    <div class="receive-hero">
        <h2>Ricevi KY</h2>
        <p>Scegli il modo piu rapido per incassare: QR locale al banco, link condivisibile, WhatsApp o richiesta importo a un conto del circuito.</p>
    </div>

    <div class="receive-method-grid">
        <a class="receive-method" href="{{ route('portal.incasso-qr.form') }}">
            <span class="receive-method__icon">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v6h-6"/></svg>
            </span>
            <strong>QR locale</strong>
            <span>Genera un QR con importo per incasso immediato da mostrare al cliente.</span>
        </a>
        <a class="receive-method" href="{{ route('portal.payment-links.create') }}">
            <span class="receive-method__icon">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            </span>
            <strong>Link</strong>
            <span>Crea un link valido fino a 90 giorni da copiare o inviare al cliente.</span>
        </a>
        <a class="receive-method" href="{{ route('portal.payment-links.create') }}">
            <span class="receive-method__icon">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-1.9 5.3 8.5 8.5 0 0 1-6.6 3.2 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.2a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 3.2-6.6 8.38 8.38 0 0 1 5.3-1.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            </span>
            <strong>WhatsApp</strong>
            <span>Prima generi un link, poi lo condividi direttamente da mobile.</span>
        </a>
        <a class="receive-method" href="#request-amount">
            <span class="receive-method__icon">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/></svg>
            </span>
            <strong>Richiesta importo</strong>
            <span>Invia una richiesta formale a un conto KMoney e attendi conferma.</span>
        </a>
    </div>
</section>

<div class="summary-grid" style="margin-bottom:24px;">

    {{-- Form richiesta pagamento --}}
    <section class="card light-card" id="request-amount">
        <div class="section-head">
            <div>
                <span class="eyebrow">Nuova richiesta</span>
                <h3 class="section-title">Richiedi pagamento da un'azienda</h3>
            </div>
            <span class="pill">Request</span>
        </div>

        @if($errors->any())
            <div style="background:#ffe4e6;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                <strong style="color:#9f1239;font-size:13px;">Correggi i seguenti errori:</strong>
                <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#9f1239;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div style="background:#dbeafe;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#1d4ed8;border:1px solid #bfdbfe;">
            <strong>Come funziona:</strong> non viene addebitato nulla subito. L'azienda selezionata
            riceverà la richiesta e potrà confermare o rifiutare il pagamento.
        </div>

        <form method="POST" action="{{ route('portal.receive.submit') }}" id="receiveForm">
            @csrf

            {{-- Conto che deve pagare --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="from_account_id">
                    Conto a cui richiedere il pagamento <span style="color:#dc2626;">*</span>
                </label>
                <select name="from_account_id" id="from_account_id" required
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;">
                    <option value="">— Seleziona azienda —</option>
                    @foreach($counterpartyAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('from_account_id') == $account->id)>
                            {{ $account->display_name }}
                            ({{ $account->account_number }})
                            — {{ $account->currency_code }}
                        </option>
                    @endforeach
                </select>
                <div style="font-size:12px;color:var(--ink-muted);margin-top:4px;">
                    Chi deve pagarti. Il tuo conto <strong>{{ $currentAccount->display_name }}</strong> riceverà i KY.
                </div>
            </div>

            {{-- Importo --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="amount">
                    Importo richiesto (KY) <span style="color:#dc2626;">*</span>
                </label>
                <div style="position:relative;">
                    <input type="number" name="amount" id="amount" required
                        min="0.01" step="0.01"
                        value="{{ old('amount') }}"
                        placeholder="es. 50,00"
                        style="width:100%;padding:11px 60px 11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:18px;font-weight:700;">
                    <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-weight:700;color:var(--ink-muted);font-size:14px;">KY</span>
                </div>
            </div>

            {{-- Descrizione --}}
            <div style="margin-bottom:22px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="description">
                    Descrizione / causale
                </label>
                <textarea name="description" id="description" rows="3"
                    placeholder="Es: Consulenza marketing maggio 2026, fattura n. 42"
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;resize:vertical;">{{ old('description') }}</textarea>
                <div style="font-size:12px;color:var(--ink-muted);margin-top:4px;">
                    Sarà visibile all'azienda che dovrà pagare.
                </div>
            </div>

            {{-- Preview dinamico --}}
            <div id="preview" style="display:none;background:#d1fae5;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:14px;color:#065f46;border:1px solid #a7f3d0;">
                <strong>Riepilogo:</strong> Stai richiedendo
                <strong id="previewAmount">—</strong> KY
                a <strong id="previewAccount">—</strong>.
                Il pagamento sarà accreditato su <strong>{{ $currentAccount->display_name }}</strong>.
            </div>

            <button type="submit" class="cta" style="width:100%;font-size:16px;padding:14px;">
                Invia richiesta di pagamento
            </button>
        </form>
    </section>

    {{-- Info box --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Come funziona</span>
                <h3 class="section-title">Flusso della richiesta</h3>
            </div>
        </div>

        <div style="display:grid;gap:16px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#0f52c4;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Selezioni l'azienda e l'importo</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Compili il form indicando chi deve pagare e per quale importo.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#0284c7;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">L'azienda riceve la notifica</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Il destinatario vede la richiesta sul portale e nella propria email.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#16a34a;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Conferma o rifiuto</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Se confermata, i KY transitano automaticamente sul tuo conto. Se rifiutata, ricevi notifica.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#6d28d9;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">4</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Registrazione nel ledger</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Ogni movimento viene registrato nel libro mastro con riferimento univoco e timestamp.</span>
                </div>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:20px 0;">

        <div style="text-align:center;padding:16px;background:var(--bg);border-radius:12px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:6px;">
                Il tuo conto (incassa)
            </div>
            <div style="font-size:20px;font-weight:800;color:var(--ink);">
                {{ $currentAccount->display_name }}
            </div>
            <div style="font-size:13px;color:var(--ink-muted);margin-top:4px;">
                {{ $currentAccount->account_number }}
            </div>
        </div>

        <div style="margin-top:16px;">
            <a href="{{ route('portal.requests') }}" class="cta secondary" style="width:100%;justify-content:center;display:flex;">
                Vedi tutte le mie richieste →
            </a>
        </div>
    </section>

</div>

<script>
const amountInput    = document.getElementById('amount');
const accountSelect  = document.getElementById('from_account_id');
const preview        = document.getElementById('preview');
const previewAmount  = document.getElementById('previewAmount');
const previewAccount = document.getElementById('previewAccount');

function updatePreview() {
    const amount = amountInput.value;
    const accountText = accountSelect.options[accountSelect.selectedIndex]?.text ?? '';
    if (amount && accountSelect.value) {
        preview.style.display = 'block';
        previewAmount.textContent = parseFloat(amount).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        previewAccount.textContent = accountText.split('(')[0].trim();
    } else {
        preview.style.display = 'none';
    }
}

amountInput.addEventListener('input', updatePreview);
accountSelect.addEventListener('change', updatePreview);
</script>

@endsection
