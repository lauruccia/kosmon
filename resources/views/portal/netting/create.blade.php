@extends('layouts.portal')

@section('content')
    <div class="page-actions">
        <a class="cta secondary" href="{{ route('portal.netting.index') }}">← Le mie compensazioni</a>
    </div>
</section>

@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<div class="summary-grid" style="margin-bottom:24px;">

    {{-- Form principale --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Configura la compensazione</span>
                <h3 class="section-title">Seleziona crediti da compensare</h3>
            </div>
            <span style="font-size:22px;">🔄</span>
        </div>

        @if($errors->any())
            <div style="background:#ffe4e6;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                <strong style="color:#9f1239;font-size:13px;">Correggi i seguenti errori:</strong>
                <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#9f1239;">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('portal.netting.store') }}" id="nettingForm">
            @csrf

            {{-- Selezione controparte --}}
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="counterparty_account_id">
                    Controparte <span style="color:#dc2626;">*</span>
                </label>
                <select name="counterparty_account_id" id="counterparty_account_id" required
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;">
                    <option value="">— Seleziona azienda —</option>
                    @foreach($counterpartyAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('counterparty_account_id') == $account->id)>
                            {{ $account->display_name }} ({{ $account->account_number }})
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Crediti in sospeso — caricati via JS --}}
            <div id="transfersSection" style="display:none;">

                <div id="loadingSpinner" style="text-align:center;padding:20px;color:var(--ink-muted);display:none;">
                    <div style="font-size:13px;">Caricamento crediti in sospeso...</div>
                </div>

                <div id="noMutualCredits" style="display:none;background:#f0fdf4;border-radius:10px;padding:16px;margin-bottom:18px;border:1px solid #bbf7d0;">
                    <div style="color:#15803d;font-weight:600;font-size:13px;">⚖️ Nessun credito incrociato in sospeso con questa azienda.</div>
                    <div style="color:#15803d;font-size:12px;margin-top:4px;">Perché ci sia una compensazione servono richieste di pagamento in sospeso da entrambe le parti.</div>
                </div>

                {{-- Crediti del proposer (tuoi crediti verso la controparte) --}}
                <div id="proposerSection" style="margin-bottom:20px;">
                    <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--ink);">
                        📤 I tuoi crediti verso la controparte
                        <span style="font-weight:400;color:var(--ink-muted);font-size:12px;">(seleziona quelli da compensare)</span>
                    </div>
                    <div id="proposerTransfers" style="display:grid;gap:8px;"></div>
                    <div id="proposerEmpty" style="display:none;font-size:13px;color:var(--ink-muted);padding:10px;background:var(--bg);border-radius:8px;">
                        Nessun credito in sospeso verso questa azienda.
                    </div>
                </div>

                {{-- Crediti della controparte (loro crediti verso di te) --}}
                <div id="counterpartySection" style="margin-bottom:20px;">
                    <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--ink);">
                        📥 Crediti della controparte verso di te
                        <span style="font-weight:400;color:var(--ink-muted);font-size:12px;">(seleziona quelli da compensare)</span>
                    </div>
                    <div id="counterpartyTransfers" style="display:grid;gap:8px;"></div>
                    <div id="counterpartyEmpty" style="display:none;font-size:13px;color:var(--ink-muted);padding:10px;background:var(--bg);border-radius:8px;">
                        Nessun credito in sospeso della controparte verso di te.
                    </div>
                </div>

                {{-- Preview saldo netto --}}
                <div id="netPreview" style="display:none;background:#dbeafe;border-radius:12px;padding:16px 18px;margin-bottom:18px;border:1px solid #bfdbfe;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#1d4ed8;margin-bottom:10px;">Anteprima compensazione</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:13px;color:#1e40af;">
                        <div><div style="font-weight:700;font-size:18px;" id="pvProposer">0</div><div>KY tuoi crediti</div></div>
                        <div><div style="font-weight:700;font-size:18px;" id="pvCounterparty">0</div><div>KY loro crediti</div></div>
                        <div><div style="font-weight:700;font-size:18px;" id="pvNet">0</div><div id="pvNetLabel">KY saldo netto</div></div>
                    </div>
                    <div id="pvNetNote" style="margin-top:10px;font-size:12px;color:#1d4ed8;"></div>
                </div>
            </div>

            {{-- Descrizione --}}
            <div style="margin-bottom:22px;">
                <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;" for="description">
                    Descrizione / causale
                </label>
                <textarea name="description" id="description" rows="2"
                    placeholder="Es: Compensazione fatture Q1 2026"
                    style="width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font-size:14px;resize:vertical;">{{ old('description') }}</textarea>
            </div>

            <button type="submit" id="submitBtn" class="cta" style="width:100%;font-size:16px;padding:14px;" disabled>
                🔄 Invia proposta di compensazione
            </button>
        </form>
    </section>

    {{-- Info box --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Come funziona</span>
                <h3 class="section-title">Compensazione crediti incrociati</h3>
            </div>
        </div>

        <div style="display:grid;gap:16px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#0f52c4;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Selezioni i crediti incrociati</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Scegli i crediti in sospeso che hai verso la controparte e quelli che la controparte ha verso di te.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#0284c7;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">La controparte accetta o rifiuta</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">La proposta viene inviata all'altra azienda che può accettarla o rifiutarla entro 7 giorni.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#16a34a;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">I crediti vengono cancellati</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Se accettata, tutti i crediti selezionati vengono annullati e al loro posto viene generato un solo trasferimento per il saldo netto.</span>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div style="width:28px;height:28px;border-radius:50%;background:#f59e0b;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">4</div>
                <div>
                    <strong style="display:block;font-size:13px;margin-bottom:3px;">Pareggio perfetto = zero movimenti</strong>
                    <span style="font-size:13px;color:var(--ink-soft);">Se i crediti si bilanciano esattamente, nessun trasferimento viene generato: i debiti reciproci spariscono senza spostare liquidità.</span>
                </div>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--line);margin:20px 0;">

        <div style="background:#fef9c3;border-radius:10px;padding:14px 16px;font-size:13px;color:#854d0e;">
            <strong>⚠️ Solo crediti pending:</strong> possono essere compensati solo i trasferimenti ancora in stato <em>in attesa</em>. Le operazioni già contabilizzate non possono essere incluse in una compensazione.
        </div>

        <div style="margin-top:16px;text-align:center;padding:14px;background:var(--bg);border-radius:12px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:4px;">Il tuo conto</div>
            <div style="font-size:18px;font-weight:800;">{{ $currentAccount->display_name }}</div>
            <div style="font-size:13px;color:var(--ink-muted);">{{ $currentAccount->account_number }}</div>
        </div>
    </section>

</div>

<script>
const counterpartySelect = document.getElementById('counterparty_account_id');
const transfersSection   = document.getElementById('transfersSection');
const loadingSpinner     = document.getElementById('loadingSpinner');
const noMutualCredits    = document.getElementById('noMutualCredits');
const proposerSection    = document.getElementById('proposerSection');
const counterpartySection = document.getElementById('counterpartySection');
const proposerDiv        = document.getElementById('proposerTransfers');
const counterpartyDiv    = document.getElementById('counterpartyTransfers');
const proposerEmpty      = document.getElementById('proposerEmpty');
const counterpartyEmpty  = document.getElementById('counterpartyEmpty');
const netPreview         = document.getElementById('netPreview');
const pvProposer         = document.getElementById('pvProposer');
const pvCounterparty     = document.getElementById('pvCounterparty');
const pvNet              = document.getElementById('pvNet');
const pvNetLabel         = document.getElementById('pvNetLabel');
const pvNetNote          = document.getElementById('pvNetNote');
const submitBtn          = document.getElementById('submitBtn');

let proposerData     = [];
let counterpartyData = [];

function fmt(n) { return n.toLocaleString('it-IT'); }

function updatePreview() {
    const checkedProposer     = [...document.querySelectorAll('.t-proposer:checked')].map(cb => parseInt(cb.dataset.amount));
    const checkedCounterparty = [...document.querySelectorAll('.t-counterparty:checked')].map(cb => parseInt(cb.dataset.amount));

    const totalP = checkedProposer.reduce((a, b) => a + b, 0);
    const totalC = checkedCounterparty.reduce((a, b) => a + b, 0);

    pvProposer.textContent     = fmt(totalP);
    pvCounterparty.textContent = fmt(totalC);

    const net     = Math.abs(totalP - totalC);
    pvNet.textContent = fmt(net);

    if (net === 0 && (checkedProposer.length > 0 || checkedCounterparty.length > 0)) {
        pvNetLabel.textContent  = 'Pareggio perfetto';
        pvNetNote.textContent   = '⚖️ Nessun pagamento netto richiesto. Tutti i debiti reciproci spariscono.';
    } else if (totalP > totalC) {
        pvNetLabel.textContent = 'KY ricevi tu';
        pvNetNote.textContent  = 'La controparte ti pagherà ' + fmt(net) + ' KY come saldo netto.';
    } else if (totalC > totalP) {
        pvNetLabel.textContent = 'KY paghi tu';
        pvNetNote.textContent  = 'Pagherai ' + fmt(net) + ' KY come saldo netto alla controparte.';
    } else {
        pvNetLabel.textContent = 'KY saldo netto';
        pvNetNote.textContent  = '';
    }

    const hasSelection = checkedProposer.length > 0 || checkedCounterparty.length > 0;
    netPreview.style.display = hasSelection ? 'block' : 'none';
    submitBtn.disabled       = !hasSelection;
}

function buildTransferCard(t, className) {
    return `
    <label style="display:flex;gap:12px;align-items:center;padding:12px 14px;border:1.5px solid var(--line);border-radius:10px;cursor:pointer;background:var(--bg);transition:border-color .15s;"
           onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--line)'">
        <input type="checkbox" name="${className === 't-proposer' ? 'proposer_transfer_ids[]' : 'counterparty_transfer_ids[]'}"
               value="${t.id}" class="${className}" data-amount="${t.amount}"
               onchange="updatePreview()"
               style="width:16px;height:16px;accent-color:var(--accent);flex-shrink:0;">
        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:13px;">${t.description || '—'}</div>
            <div style="font-size:11px;color:var(--ink-muted);">Rif. ${t.reference} · ${t.created_at}</div>
        </div>
        <div style="font-weight:700;font-size:16px;color:#0f52c4;white-space:nowrap;">${parseInt(t.amount).toLocaleString('it-IT')} KY</div>
    </label>`;
}

counterpartySelect.addEventListener('change', async function () {
    const cid = this.value;

    proposerDiv.innerHTML      = '';
    counterpartyDiv.innerHTML  = '';
    proposerEmpty.style.display      = 'none';
    counterpartyEmpty.style.display  = 'none';
    noMutualCredits.style.display    = 'none';
    netPreview.style.display         = 'none';
    submitBtn.disabled               = true;

    if (!cid) {
        transfersSection.style.display = 'none';
        return;
    }

    transfersSection.style.display   = 'block';
    loadingSpinner.style.display     = 'block';
    proposerSection.style.display    = 'none';
    counterpartySection.style.display = 'none';

    try {
        const resp = await fetch(`{{ route('portal.netting.load-transfers') }}?counterparty_account_id=${cid}`);
        const data = await resp.json();

        proposerData     = data.proposer     || [];
        counterpartyData = data.counterparty || [];

        loadingSpinner.style.display = 'none';

        if (proposerData.length === 0 && counterpartyData.length === 0) {
            noMutualCredits.style.display = 'block';
            return;
        }

        proposerSection.style.display    = 'block';
        counterpartySection.style.display = 'block';

        if (proposerData.length > 0) {
            proposerDiv.innerHTML = proposerData.map(t => buildTransferCard(t, 't-proposer')).join('');
        } else {
            proposerEmpty.style.display = 'block';
        }

        if (counterpartyData.length > 0) {
            counterpartyDiv.innerHTML = counterpartyData.map(t => buildTransferCard(t, 't-counterparty')).join('');
        } else {
            counterpartyEmpty.style.display = 'block';
        }

    } catch (e) {
        loadingSpinner.style.display = 'none';
        noMutualCredits.style.display = 'block';
    }
});

// Ripristina vecchi valori old() se presenti
(function restoreOldValues() {
    @if(old('counterparty_account_id'))
        counterpartySelect.value = '{{ old('counterparty_account_id') }}';
        counterpartySelect.dispatchEvent(new Event('change'));
    @endif
})();
</script>

@endsection
