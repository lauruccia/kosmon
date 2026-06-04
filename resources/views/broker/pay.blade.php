@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('broker.clients.show', $company) }}" class="cta secondary">← Scheda {{ $company->name }}</a>
@endsection

@section('content')
{{-- Banner conto mittente --}}
<div class="card light-card card-pad" style="margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;border-left:4px solid var(--primary);">
    <div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:2px;">Conto mittente</div>
        <div style="font-weight:700;font-size:15px;">{{ $company->name }}</div>
        <div style="font-size:12px;color:var(--text-muted);font-family:monospace;margin-top:2px;">{{ $fromAccount->account_number }}</div>
    </div>
    <div style="margin-left:auto;text-align:right;">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:2px;">Disponibile</div>
        <div style="font-size:22px;font-weight:800;color:{{ $fromAccount->saldoDisponibile() >= 0 ? 'var(--ink)' : '#dc2626' }};">
            {{ ky_format($fromAccount->saldoDisponibile()) }} <span style="font-size:13px;font-weight:600;">KY</span>
        </div>
    </div>
</div>

@if(session('portal_error'))
    <div class="alert alert-error" style="margin-bottom:16px;padding:12px 16px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:13px;">
        {{ session('portal_error') }}
    </div>
@endif

<div class="portal-grid" style="--grid-cols:2;">

    {{-- Form pagamento --}}
    <section class="card light-card card-pad">
        <div class="eyebrow" style="margin-bottom:14px;">Dettagli pagamento</div>

        <form method="POST" action="{{ route('broker.pay.submit', $company) }}" id="brokerPayForm">
            @csrf

            {{-- Destinatario --}}
            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--text);">
                    Destinatario <span style="color:#dc2626;">*</span>
                </label>
                <select name="to_account_id" required
                    style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--surface);color:var(--text);appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E\");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;">
                    <option value="">— Seleziona destinatario —</option>
                    @foreach($counterpartyAccounts as $acc)
                        <option value="{{ $acc->id }}" {{ old('to_account_id') == $acc->id ? 'selected' : '' }}>
                            {{ $acc->display_name }} — {{ $acc->account_number }}
                        </option>
                    @endforeach
                </select>
                @error('to_account_id')
                    <span style="font-size:11px;color:#dc2626;margin-top:4px;display:block;">{{ $message }}</span>
                @enderror
            </div>

            {{-- Importo --}}
            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--text);">
                    Importo (KY) <span style="color:#dc2626;">*</span>
                </label>
                <div style="position:relative;">
                    <input type="number" name="amount" id="amount" min="0.01" step="0.01"
                        value="{{ old('amount') }}"
                        required placeholder="es. 10,00"
                        style="width:100%;padding:10px 44px 10px 12px;border:1px solid var(--line);border-radius:8px;font-size:15px;font-weight:700;background:var(--surface);color:var(--text);box-sizing:border-box;">
                    <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:700;color:var(--teal-strong);">KY</span>
                </div>
                <div id="amountHint" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                    Massimo disponibile: <strong>{{ ky_format($fromAccount->saldoDisponibile()) }} KY</strong>
                </div>
                @error('amount')
                    <span style="font-size:11px;color:#dc2626;margin-top:4px;display:block;">{{ $message }}</span>
                @enderror
            </div>

            {{-- Causale --}}
            <div style="margin-bottom:20px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--text);">
                    Causale
                </label>
                <textarea name="description" rows="3" maxlength="500"
                    placeholder="Descrizione del pagamento (facoltativa)..."
                    style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--surface);color:var(--text);resize:vertical;box-sizing:border-box;">{{ old('description') }}</textarea>
                @error('description')
                    <span style="font-size:11px;color:#dc2626;margin-top:4px;display:block;">{{ $message }}</span>
                @enderror
            </div>

            {{-- Riepilogo --}}
            <div id="recap" style="display:none;background:var(--bg-alt, #f8f9fa);border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;border:1px solid var(--line);">
                <div style="font-weight:700;margin-bottom:6px;color:var(--text);">Riepilogo operazione</div>
                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                    <span style="color:var(--text-muted);">Da:</span>
                    <span>{{ $company->name }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                    <span style="color:var(--text-muted);">A:</span>
                    <span id="recapDest">—</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-weight:700;margin-top:6px;padding-top:6px;border-top:1px solid var(--line);">
                    <span>Importo:</span>
                    <span id="recapAmount" style="color:var(--primary);">— KY</span>
                </div>
            </div>

            <button type="submit" class="cta" style="width:100%;justify-content:center;font-size:15px;min-height:44px;" id="submitBtn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:6px;"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                Conferma pagamento
            </button>

            <p style="font-size:11px;color:var(--text-muted);text-align:center;margin-top:10px;">
                Stai agendo come operatore broker. L'operazione sarà registrata con il tuo nome.
            </p>
        </form>
    </section>

    {{-- Info di contesto --}}
    <div class="stack">

        <section class="card light-card card-pad">
            <div class="eyebrow" style="margin-bottom:10px;">Note operative</div>
            <ul style="margin:0;padding-left:18px;font-size:13px;color:var(--text-muted);line-height:1.7;">
                <li>Il pagamento viene <strong style="color:var(--text);">addebitato immediatamente</strong> sul conto di {{ $company->name }}</li>
                <li>L'operazione è <strong style="color:var(--text);">irreversibile</strong> — verifica destinatario e importo</li>
                <li>La causale sarà visibile al destinatario e nei movimenti del cliente</li>
                <li>Il pagamento sarà registrato come <em>operato da broker</em></li>
            </ul>
        </section>

        <section class="card light-card card-pad">
            <div class="eyebrow" style="margin-bottom:10px;">Stato del conto cliente</div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:8px;">
                <span style="color:var(--text-muted);">Saldo attuale</span>
                <strong style="color:{{ $fromAccount->available_balance >= 0 ? 'var(--ink)' : '#dc2626' }};">
                    {{ ky_format($fromAccount->available_balance) }} KY
                </strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:8px;">
                <span style="color:var(--text-muted);">Disponibile</span>
                <strong style="color:var(--teal-strong);">{{ ky_format($fromAccount->saldoDisponibile()) }} KY</strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">
                <span style="color:var(--text-muted);">Massimale fido</span>
                <strong>{{ ky_format($fromAccount->creditLimits->sum('limit_amount')) }} KY</strong>
            </div>
        </section>

    </div>

</div>

<script>
(function () {
    const amountInput  = document.getElementById('amount');
    const destSelect   = document.querySelector('select[name="to_account_id"]');
    const recap        = document.getElementById('recap');
    const recapDest    = document.getElementById('recapDest');
    const recapAmount  = document.getElementById('recapAmount');

    function updateRecap() {
        const amt  = parseFloat(amountInput.value);
        const dest = destSelect.options[destSelect.selectedIndex];
        if (amt > 0 && destSelect.value) {
            recap.style.display = 'block';
            recapDest.textContent   = dest.text.split('—')[0].trim();
            recapAmount.textContent = new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amt) + ' KY';
        } else {
            recap.style.display = 'none';
        }
    }

    amountInput.addEventListener('input', updateRecap);
    destSelect.addEventListener('change', updateRecap);

    // Prevent double-submit
    document.getElementById('brokerPayForm').addEventListener('submit', function () {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Elaborazione…';
    });
})();
</script>
@endsection
