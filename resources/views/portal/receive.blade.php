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

<div class="summary-grid" style="margin-bottom:24px;">

    {{-- Form richiesta pagamento --}}
    <section class="card light-card">
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
