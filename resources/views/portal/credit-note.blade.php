@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('portal.movements') }}">← Movimenti</a>
@endsection




@section('content')
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<div class="summary-grid" style="margin-bottom:24px;">

    {{-- Form nota di credito --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Nuova nota</span>
                <h3 class="section-title">Nota di credito</h3>
            </div>
            <span style="font-size:22px;">📋</span>
        </div>

        @if($errors->any())
            <div style="background:#ffe4e6;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                <strong style="color:#9f1239;font-size:13px;">Correggi i seguenti errori:</strong>
                <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#9f1239;">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        {{-- Se collegata a un movimento specifico, mostra riepilogo --}}
        @if($linkedTransfer)
        <div style="background:#fef9c3;border:1.5px solid #fde047;border-radius:12px;padding:14px 16px;margin-bottom:18px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#854d0e;margin-bottom:8px;">
                Collegata al movimento
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <div>
                    <div style="font-weight:600;font-size:14px;">{{ $linkedTransfer->fromAccount?->display_name ?? '—' }}</div>
                    <div style="font-size:12px;color:#713f12;">
                        Rif. <code style="background:#fef3c7;padding:1px 5px;border-radius:3px;">{{ $linkedTransfer->reference }}</code>
                        · {{ $linkedTransfer->booked_at?->format('d/m/Y') }}
                    </div>
                </div>
                <div style="font-weight:800;font-size:18px;color:#0f52c4;">
                    {{ ky_format($linkedTransfer->amount) }} KY
                </div>
            </div>
            <input type="hidden" name="original_transfer_id_prefill" value="{{ $linkedTransfer->id }}">
        </div>
        @endif

        <form method="POST" action="{{ route('portal.credit-note.submit') }}" id="cnForm">
            @csrf

            {{-- Conto destinatario --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="to_account_id">
                    Azienda beneficiaria <span style="color:#dc2626;">*</span>
                </label>
                <select name="to_account_id" id="to_account_id" required
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;">
                    <option value="">— Seleziona azienda —</option>
                    @foreach($counterpartyAccounts as $account)
                        <option value="{{ $account->id }}"
                            @selected(old('to_account_id') == $account->id || ($linkedTransfer && $linkedTransfer->from_account_id == $account->id))>
                            {{ $account->display_name }} ({{ $account->account_number }})
                        </option>
                    @endforeach
                </select>
                <div style="font-size:12px;color:var(--ink-muted);margin-top:4px;">
                    Chi riceve il credito. Il tuo conto <strong>{{ $currentAccount->display_name }}</strong> viene addebitato.
                </div>
            </div>

            {{-- Importo --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="amount">
                    Importo nota di credito (KY) <span style="color:#dc2626;">*</span>
                </label>
                <div style="position:relative;">
                    <input type="number" name="amount" id="amount" required
                        min="1" step="1"
                        value="{{ old('amount') }}"
                        placeholder="es. 200"
                        style="width:100%;padding:11px 60px 11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:18px;font-weight:700;">
                    <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-weight:700;color:var(--ink-muted);font-size:14px;">KY</span>
                </div>
                <div style="font-size:12px;color:var(--ink-muted);margin-top:4px;">
                    L'importo è libero — non è vincolato al movimento originale.
                </div>
            </div>

            {{-- Riferimento movimento originale (opzionale) --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="original_transfer_id">
                    Riferimento movimento originale
                    <span style="font-weight:400;color:var(--ink-muted);">(opzionale)</span>
                </label>
                <input type="number" name="original_transfer_id" id="original_transfer_id"
                    value="{{ old('original_transfer_id', $linkedTransfer?->id) }}"
                    placeholder="ID del movimento da rettificare (es. 42)"
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;">
                <div style="font-size:12px;color:var(--ink-muted);margin-top:4px;">
                    Collega questa nota a un movimento contabilizzato per tracciabilità contabile.
                </div>
            </div>

            {{-- Causale --}}
            <div style="margin-bottom:22px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="description">
                    Causale / motivazione <span style="color:#dc2626;">*</span>
                </label>
                <textarea name="description" id="description" rows="3" required
                    placeholder="Es: Sconto 10% per reso parziale, penale per ritardo consegna, rettifica fattura n.12"
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;resize:vertical;">{{ old('description') }}</textarea>
            </div>

            {{-- Preview --}}
            <div id="preview" style="display:none;background:#dbeafe;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:14px;color:#1d4ed8;border:1px solid #bfdbfe;">
                <strong>Riepilogo:</strong> Stai emettendo una nota di credito di
                <strong id="previewAmount">—</strong> KY
                a <strong id="previewAccount">—</strong>.
                Il tuo saldo si ridurrà di quell'importo.
            </div>

            <button type="submit" class="cta" style="width:100%;font-size:16px;padding:14px;"
                onclick="return confirm('Confermi l\'emissione della nota di credito? L\'operazione è immediata e irreversibile.')">
                📋 Emetti nota di credito
            </button>
        </form>
    </section>

    {{-- Info + differenza rimborso --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Guida</span>
                <h3 class="section-title">Nota di credito vs Rimborso</h3>
            </div>
        </div>

        {{-- Confronto --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border:1.5px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:20px;font-size:13px;">
            <div style="padding:12px 14px;background:#fef2f2;border-right:1px solid var(--line);">
                <div style="font-weight:700;margin-bottom:6px;color:#991b1b;">↩️ Rimborso</div>
                <ul style="margin:0;padding-left:16px;color:var(--ink-soft);line-height:1.7;">
                    <li>Restituisce un pagamento già ricevuto</li>
                    <li>Importo ≤ originale</li>
                    <li>Sempre collegato al mov. originale</li>
                    <li>Motivazione: reso, errore incasso</li>
                </ul>
            </div>
            <div style="padding:12px 14px;background:#eff6ff;">
                <div style="font-weight:700;margin-bottom:6px;color:#1d4ed8;">📋 Nota di credito</div>
                <ul style="margin:0;padding-left:16px;color:var(--ink-soft);line-height:1.7;">
                    <li>Rettifica commerciale autonoma</li>
                    <li>Importo libero</li>
                    <li>Collegamento opzionale</li>
                    <li>Motivazione: sconto, penale, aggiustamento</li>
                </ul>
            </div>
        </div>

        <div style="display:grid;gap:14px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">📒</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Registrata nel ledger</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Genera due voci contabili: debito sul tuo conto e credito sul beneficiario. La somma rimane sempre zero nel circuito.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">⚡</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Immediata e definitiva</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Non richiede conferma dal destinatario. Una volta emessa, non è annullabile dal portale — contatta l'admin per storni eccezionali.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;flex-shrink:0;">🔔</span>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Notifica automatica</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Il beneficiario riceve notifica email e in-app con causale e importo.</span>
                </div>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:20px 0;">

        <div style="text-align:center;padding:14px;background:var(--bg);border-radius:12px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:4px;">Il tuo conto (emittente)</div>
            <div style="font-size:18px;font-weight:800;">{{ $currentAccount->display_name }}</div>
            <div style="font-size:13px;color:var(--ink-muted);">{{ $currentAccount->account_number }}</div>
        </div>
    </section>

</div>

<script>
const amountInput   = document.getElementById('amount');
const accountSelect = document.getElementById('to_account_id');
const preview       = document.getElementById('preview');
const previewAmount = document.getElementById('previewAmount');
const previewAccount= document.getElementById('previewAccount');

function updatePreview() {
    const val  = parseInt(amountInput.value);
    const text = accountSelect.options[accountSelect.selectedIndex]?.text ?? '';
    if (val > 0 && accountSelect.value) {
        preview.style.display = 'block';
        previewAmount.textContent  = val.toLocaleString('it-IT');
        previewAccount.textContent = text.split('(')[0].trim();
    } else {
        preview.style.display = 'none';
    }
}
amountInput.addEventListener('input', updatePreview);
accountSelect.addEventListener('change', updatePreview);
</script>

@endsection
