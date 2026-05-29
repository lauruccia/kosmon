@extends('layouts.portal')

@section('content')
    <div class="page-actions">
        <a class="cta secondary" href="{{ route('portal.payment-plans.index') }}">Indietro</a>
    </div>
</section>

@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<div class="summary-grid" style="margin-bottom:24px;">

    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Configura la proposta</span>
                <h3 class="section-title">Parametri del piano rateale</h3>
            </div>
            <span style="font-size:22px;">📅</span>
        </div>

        @if($errors->any())
            <div style="background:#ffe4e6;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                <strong style="color:#9f1239;font-size:13px;">Correggi i seguenti errori:</strong>
                <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#9f1239;">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('portal.payment-plans.store') }}" id="planForm">
            @csrf
            <input type="hidden" name="initiator_role" id="initiator_role" value="{{ old('initiator_role', request('role', 'debtor')) }}">

            {{-- Toggle ruolo --}}
            <div style="margin-bottom:24px;">
                <div style="font-size:13px;font-weight:700;margin-bottom:10px;">Qual e' il tuo ruolo? <span style="color:#dc2626;">*</span></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <label id="role-debtor-label" style="cursor:pointer;border:2px solid var(--primary);border-radius:12px;padding:14px;display:block;transition:.15s;">
                        <input type="radio" name="_role" value="debtor" style="display:none;" {{ old('initiator_role', request('role', 'debtor')) === 'debtor' ? 'checked' : '' }}>
                        <div style="font-size:20px;margin-bottom:6px;">🛒</div>
                        <div style="font-size:13px;font-weight:700;margin-bottom:3px;">Sono l'acquirente</div>
                        <div style="font-size:12px;color:var(--text-muted);line-height:1.4;">Chiedo di pagare a rate. Il venditore deve accettare.</div>
                    </label>
                    <label id="role-creditor-label" style="cursor:pointer;border:2px solid var(--border);border-radius:12px;padding:14px;display:block;transition:.15s;">
                        <input type="radio" name="_role" value="creditor" style="display:none;" {{ (old('initiator_role', request('role', 'debtor'))) === 'creditor' ? 'checked' : '' }}>
                        <div style="font-size:20px;margin-bottom:6px;">🏪</div>
                        <div style="font-size:13px;font-weight:700;margin-bottom:3px;">Sono il venditore</div>
                        <div style="font-size:12px;color:var(--text-muted);line-height:1.4;">Offro la rateizzazione al cliente. Lui deve accettare.</div>
                    </label>
                </div>
            </div>

            {{-- Controparte --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="counterparty_id">
                    <span id="counterparty-label">Venditore (chi riceve i pagamenti)</span> <span style="color:#dc2626;">*</span>
                </label>
                <select name="counterparty_id" id="counterparty_id" required
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;">
                    <option value="">— Seleziona azienda —</option>
                    @foreach($counterpartyAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('counterparty_id') == $account->id)>
                            {{ $account->display_name }} ({{ $account->account_number }})
                        </option>
                    @endforeach
                </select>
                <div style="margin-top:5px;font-size:12px;color:var(--text-muted);" id="counterparty-hint">
                    Il venditore ricevera' le rate nel suo conto.
                </div>
            </div>

            {{-- Importo totale --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="total_amount">
                    Importo totale (KY) <span style="color:#dc2626;">*</span>
                </label>
                <div style="position:relative;">
                    <input type="number" name="total_amount" id="total_amount" required
                        min="2" step="1" value="{{ old('total_amount') }}" placeholder="es. 1200"
                        style="width:100%;padding:11px 60px 11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:18px;font-weight:700;">
                    <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-weight:700;color:var(--ink-muted);font-size:14px;">KY</span>
                </div>
            </div>

            {{-- Numero rate + frequenza --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
                <div>
                    <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="installments_count">
                        N. rate <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" name="installments_count" id="installments_count" required
                        min="2" max="60" step="1" value="{{ old('installments_count', 3) }}"
                        style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:16px;font-weight:700;">
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">Min 2, Max 60</div>
                </div>
                <div>
                    <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="frequency">
                        Frequenza <span style="color:#dc2626;">*</span>
                    </label>
                    <select name="frequency" id="frequency" required
                        style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;">
                        <option value="monthly"  @selected(old('frequency','monthly') === 'monthly')>Mensile</option>
                        <option value="biweekly" @selected(old('frequency') === 'biweekly')>Bisettimanale</option>
                        <option value="weekly"   @selected(old('frequency') === 'weekly')>Settimanale</option>
                    </select>
                </div>
            </div>

            {{-- Prima scadenza --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="first_due_date">
                    Data prima rata <span style="color:#dc2626;">*</span>
                </label>
                <input type="date" name="first_due_date" id="first_due_date" required
                    min="{{ now()->toDateString() }}"
                    value="{{ old('first_due_date', now()->addMonth()->format('Y-m-d')) }}"
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;">
            </div>

            {{-- Descrizione --}}
            <div style="margin-bottom:22px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="description">
                    Descrizione / causale
                </label>
                <textarea name="description" id="description" rows="2"
                    placeholder="Es: Fornitura annuale servizi, pagamento dilazionato"
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;resize:vertical;">{{ old('description') }}</textarea>
            </div>

            {{-- Preview --}}
            <div id="preview" style="display:none;background:#dbeafe;border-radius:12px;padding:16px 18px;margin-bottom:18px;border:1px solid #bfdbfe;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#1d4ed8;margin-bottom:10px;">Anteprima piano</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:13px;color:#1e40af;">
                    <div><div style="font-weight:700;font-size:18px;" id="pvImportoRata">-</div><div>KY / rata</div></div>
                    <div><div style="font-weight:700;font-size:18px;" id="pvNRate">-</div><div>rate</div></div>
                    <div><div style="font-weight:700;font-size:18px;" id="pvTotale">-</div><div>KY totali</div></div>
                </div>
                <div style="margin-top:10px;font-size:12px;color:#1d4ed8;" id="pvNote"></div>
            </div>

            {{-- Avviso approvazione --}}
            <div style="background:#fef9c3;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#854d0e;display:flex;gap:10px;align-items:flex-start;">
                <span>⏳</span>
                <span>Dopo l'invio, la controparte ricevera' una notifica e potra' <strong>accettare o rifiutare</strong>. Le rate partono solo dopo l'accettazione.</span>
            </div>

            <button type="submit" class="cta" style="width:100%;font-size:16px;padding:14px;" id="submit-btn">
                Invia proposta rateale
            </button>
        </form>
    </section>

    {{-- Spiegazione --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Come funziona</span>
                <h3 class="section-title" id="how-title">Acquirente chiede le rate</h3>
            </div>
        </div>

        <div id="how-debtor" style="display:grid;gap:16px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#f59e0b;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</div>
                <div><strong style="display:block;font-size:13px;margin-bottom:3px;">Proponi il piano</strong>
                <span style="font-size:13px;color:var(--ink-soft);">Scegli importo, rate e frequenza. Seleziona il venditore.</span></div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#0284c7;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</div>
                <div><strong style="display:block;font-size:13px;margin-bottom:3px;">Il venditore approva</strong>
                <span style="font-size:13px;color:var(--ink-soft);">Riceve una notifica e puo' accettare o rifiutare. Senza approvazione le rate non partono.</span></div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#16a34a;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</div>
                <div><strong style="display:block;font-size:13px;margin-bottom:3px;">Rate automatiche</strong>
                <span style="font-size:13px;color:var(--ink-soft);">Accettato il piano, le rate vengono addebitate alle scadenze automaticamente.</span></div>
            </div>
        </div>

        <div id="how-creditor" style="display:none;gap:16px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#6d28d9;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</div>
                <div><strong style="display:block;font-size:13px;margin-bottom:3px;">Offri la rateizzazione</strong>
                <span style="font-size:13px;color:var(--ink-soft);">Scegli il cliente e definisci le condizioni: importo, rate, frequenza.</span></div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#0284c7;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</div>
                <div><strong style="display:block;font-size:13px;margin-bottom:3px;">Il cliente accetta</strong>
                <span style="font-size:13px;color:var(--ink-soft);">Riceve la proposta e puo' accettare o rifiutare. Il piano parte solo con l'accettazione.</span></div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#16a34a;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</div>
                <div><strong style="display:block;font-size:13px;margin-bottom:3px;">Incassi automaticamente</strong>
                <span style="font-size:13px;color:var(--ink-soft);">Le rate vengono addebitate al cliente e accreditate sul tuo conto alle scadenze.</span></div>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:20px 0;">

        <div style="text-align:center;padding:14px;background:var(--bg);border-radius:12px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:4px;" id="your-role-label">Il tuo conto (pagante)</div>
            <div style="font-size:18px;font-weight:800;">{{ $currentAccount->display_name }}</div>
            <div style="font-size:13px;color:var(--ink-muted);">{{ $currentAccount->account_number }}</div>
        </div>
    </section>

</div>

<script>
(function() {
    var radios = document.querySelectorAll('input[name="_role"]');
    var hidden = document.getElementById('initiator_role');
    var labelDebtor   = document.getElementById('role-debtor-label');
    var labelCreditor = document.getElementById('role-creditor-label');
    var cpLabel  = document.getElementById('counterparty-label');
    var cpHint   = document.getElementById('counterparty-hint');
    var howDebtor   = document.getElementById('how-debtor');
    var howCreditor = document.getElementById('how-creditor');
    var howTitle    = document.getElementById('how-title');
    var yourRoleLabel = document.getElementById('your-role-label');
    var submitBtn   = document.getElementById('submit-btn');

    function applyRole(role) {
        hidden.value = role;
        if (role === 'debtor') {
            labelDebtor.style.borderColor   = 'var(--primary)';
            labelCreditor.style.borderColor = 'var(--border, #ddd)';
            cpLabel.textContent  = 'Venditore (chi riceve i pagamenti)';
            cpHint.textContent   = 'Il venditore ricevera\' le rate nel suo conto.';
            howTitle.textContent = 'Acquirente chiede le rate';
            howDebtor.style.display   = 'grid';
            howCreditor.style.display = 'none';
            yourRoleLabel.textContent = 'Il tuo conto (pagante)';
            submitBtn.textContent = 'Chiedi rateizzazione al venditore';
        } else {
            labelCreditor.style.borderColor = 'var(--primary)';
            labelDebtor.style.borderColor   = 'var(--border, #ddd)';
            cpLabel.textContent  = 'Cliente (chi pagherà le rate)';
            cpHint.textContent   = 'Il cliente pagherà le rate dal suo conto al tuo.';
            howTitle.textContent = 'Venditore offre le rate';
            howCreditor.style.display = 'grid';
            howDebtor.style.display   = 'none';
            yourRoleLabel.textContent = 'Il tuo conto (creditore/incassante)';
            submitBtn.textContent = 'Proponi piano rateale al cliente';
        }
    }

    radios.forEach(function(r) {
        r.addEventListener('change', function() { applyRole(r.value); });
    });
    applyRole(hidden.value); // inizializzato da URL ?role= o old()

    var totalInput = document.getElementById('total_amount');
    var countInput = document.getElementById('installments_count');
    var preview = document.getElementById('preview');

    function updatePreview() {
        var total = parseInt(totalInput.value) || 0;
        var count = parseInt(countInput.value) || 0;
        if (total > 0 && count >= 2) {
            var base = Math.floor(total / count);
            var rem  = total - base * count;
            preview.style.display = 'block';
            document.getElementById('pvImportoRata').textContent = base.toLocaleString('it-IT');
            document.getElementById('pvNRate').textContent = count.toLocaleString('it-IT');
            document.getElementById('pvTotale').textContent = total.toLocaleString('it-IT');
            document.getElementById('pvNote').textContent = rem > 0
                ? 'Ultima rata: ' + (base + rem).toLocaleString('it-IT') + ' KY (resto assorbito)'
                : 'Tutte le rate sono uguali.';
        } else {
            preview.style.display = 'none';
        }
    }
    totalInput.addEventListener('input', updatePreview);
    countInput.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

@endsection
