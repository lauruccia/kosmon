@extends('layouts.portal')

@section('content')
<style>
/* ── Wizard container ─────────────────────────────────────── */
.send-wizard {
    max-width: 520px;
    margin: 0 auto;
    padding: 0 0 80px;
}

/* Steps */
.wiz-step { display: none; }
.wiz-step.active { display: block; }

/* Progress bar */
.wiz-progress {
    display: flex;
    gap: 6px;
    margin-bottom: 28px;
}
.wiz-progress-dot {
    flex: 1;
    height: 4px;
    border-radius: 99px;
    background: var(--line);
    transition: background .3s;
}
.wiz-progress-dot.done { background: var(--primary); }
.wiz-progress-dot.active { background: var(--primary); opacity: .5; }

/* Card-style step panels */
.wiz-panel {
    background: var(--surface);
    border-radius: 20px;
    box-shadow: var(--shadow-sm);
    padding: 24px 20px;
    margin-bottom: 16px;
}
.wiz-eyebrow {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ink-muted);
    margin-bottom: 6px;
}
.wiz-title {
    font-size: 22px;
    font-weight: 900;
    color: var(--ink);
    margin: 0 0 20px;
    line-height: 1.2;
}

/* ── Beneficiary / recipient chips ───────────────────────── */
.contact-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
    margin-bottom: 20px;
}
.contact-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 7px;
    padding: 14px 8px 12px;
    border: 1.5px solid var(--line);
    border-radius: 14px;
    background: var(--surface);
    cursor: pointer;
    transition: border-color .15s, background .15s, transform .1s;
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--ink);
}
.contact-chip:hover { border-color: var(--primary); background: var(--primary-light); transform: translateY(-1px); }
.contact-chip.selected { border-color: var(--primary); background: #dbeafe; color: #1d4ed8; }
.contact-avatar {
    width: 42px; height: 42px; border-radius: 12px;
    background: linear-gradient(135deg, var(--primary), #6366f1);
    color: #fff; font-size: 15px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.contact-chip.selected .contact-avatar { background: #2563eb; }
.contact-name { line-height: 1.3; word-break: break-word; max-width: 100%; }

/* ── Search input ────────────────────────────────────────── */
.search-box-wrapper { position: relative; }
.search-box-wrapper input {
    width: 100%;
    padding: 14px 14px 14px 44px;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    font-size: 15px;
    background: var(--surface-soft);
    color: var(--ink);
    box-sizing: border-box;
    outline: none;
    transition: border-color .2s;
}
.search-box-wrapper input:focus { border-color: var(--primary); background: var(--surface); }
.search-box-icon {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: var(--ink-muted); pointer-events: none; font-size: 17px;
}
.search-dropdown {
    position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 300;
    background: var(--surface); border: 1.5px solid var(--primary);
    border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,.13);
    max-height: 260px; overflow-y: auto; display: none;
}
.search-dropdown.open { display: block; }
.search-option {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; cursor: pointer; transition: background .1s;
}
.search-option:hover { background: var(--primary-light); }
.search-option-avatar {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--primary), #6366f1);
    color: #fff; font-size: 13px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
}
.search-option-info strong { font-size: 13.5px; font-weight: 700; color: var(--ink); }
.search-option-info small { font-size: 11.5px; color: var(--ink-muted); display: block; }
.search-no-result { padding: 16px; text-align: center; color: var(--ink-muted); font-size: 13px; }
.search-loading { padding: 16px; text-align: center; color: var(--ink-muted); font-size: 13px; }

/* Selected recipient banner */
.selected-recipient-banner {
    display: none;
    align-items: center; gap: 14px;
    background: var(--success-soft); border: 1.5px solid #6ee7b7;
    border-radius: 14px; padding: 14px 16px; margin-top: 14px;
}
.selected-recipient-banner.visible { display: flex; }
.srb-name { font-weight: 700; font-size: 14px; color: var(--success); }
.srb-number { font-size: 12px; color: var(--ink-muted); }
.srb-change { margin-left: auto; font-size: 12px; font-weight: 600; color: var(--primary); cursor: pointer; flex-shrink: 0; }

/* ── Amount step ──────────────────────────────────────────── */
.quick-amounts {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}
.qa-btn {
    padding: 14px 8px;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    background: var(--surface);
    font-size: 14px;
    font-weight: 700;
    color: var(--ink);
    cursor: pointer;
    text-align: center;
    transition: border-color .15s, background .15s, transform .1s;
}
.qa-btn:hover { border-color: var(--primary); background: var(--primary-light); color: var(--primary); transform: translateY(-1px); }
.qa-btn.selected { border-color: var(--primary); background: #dbeafe; color: #1d4ed8; }

.amount-display {
    font-size: 48px;
    font-weight: 900;
    color: var(--ink);
    text-align: center;
    padding: 16px 0 8px;
    letter-spacing: -1px;
    min-height: 72px;
    transition: color .2s;
}
.amount-display.has-value { color: var(--primary); }
.amount-display .ky-suffix { font-size: 24px; font-weight: 600; color: var(--ink-muted); margin-left: 6px; }

.amount-input-wrapper { position: relative; margin-top: 4px; }
.amount-input-wrapper input[type="number"] {
    width: 100%; padding: 14px; box-sizing: border-box;
    border: 1.5px solid var(--line); border-radius: 12px;
    font-size: 18px; font-weight: 700; text-align: center;
    background: var(--surface-soft); color: var(--ink);
    outline: none;
    transition: border-color .2s;
    -moz-appearance: textfield;
}
.amount-input-wrapper input[type="number"]::-webkit-outer-spin-button,
.amount-input-wrapper input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.amount-input-wrapper input[type="number"]:focus { border-color: var(--primary); background: var(--surface); }

.description-field { margin-top: 16px; }
.description-field label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-muted); display: block; margin-bottom: 6px; }
.description-field textarea {
    width: 100%; padding: 12px 14px; box-sizing: border-box;
    border: 1.5px solid var(--line); border-radius: 12px;
    font-size: 14px; color: var(--ink); background: var(--surface-soft);
    resize: none; outline: none; transition: border-color .2s;
    font-family: inherit;
}
.description-field textarea:focus { border-color: var(--primary); background: var(--surface); }
.char-count { font-size: 11px; color: var(--ink-muted); text-align: right; margin-top: 4px; }

/* ── Confirm step ────────────────────────────────────────── */
.confirm-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 0; border-bottom: 1px solid var(--line);
}
.confirm-row:last-child { border-bottom: none; }
.confirm-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-muted); }
.confirm-value { font-size: 14px; font-weight: 600; color: var(--ink); text-align: right; max-width: 60%; }
.confirm-amount { font-size: 28px; font-weight: 900; color: var(--primary); }

/* ── Recipient verify card ────────────────────────────────── */
.recipient-verify-card {
    display: flex; align-items: center; gap: 16px;
    background: var(--surface-soft); border: 2px solid var(--primary);
    border-radius: 16px; padding: 16px 18px; margin-bottom: 16px;
}
.rvc-avatar {
    width: 56px; height: 56px; border-radius: 14px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--primary), #6366f1);
    color: #fff; font-size: 22px; font-weight: 900;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
}
.rvc-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; }
.rvc-info { flex: 1; min-width: 0; }
.rvc-name { font-size: 16px; font-weight: 800; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rvc-number { font-size: 12px; color: var(--ink-muted); margin-top: 2px; font-family: monospace; letter-spacing: .04em; }
.rvc-badge { font-size: 11px; font-weight: 700; color: var(--primary); background: var(--primary-light); border-radius: 6px; padding: 2px 8px; display: inline-block; margin-top: 4px; }

/* ── Alert primo pagamento ───────────────────────────────── */
.first-payment-alert {
    display: none; align-items: flex-start; gap: 12px;
    background: #fffbeb; border: 1px solid #fbbf24;
    border-radius: 12px; padding: 14px 16px; margin-bottom: 16px;
    font-size: 13px; color: #92400e;
}
.first-payment-alert.visible { display: flex; }
.first-payment-alert strong { display: block; font-size: 13.5px; font-weight: 700; margin-bottom: 2px; color: #78350f; }

/* ── PIN step ────────────────────────────────────────────── */
.pin-dots {
    display: flex; gap: 16px; justify-content: center; margin: 24px 0 20px;
}
.pin-dot {
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid var(--line-strong);
    background: var(--surface); transition: background .15s, border-color .15s;
}
.pin-dot.filled { background: var(--primary); border-color: var(--primary); }
.pin-dot.error { background: var(--danger); border-color: var(--danger); }

.numpad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    max-width: 280px;
    margin: 0 auto;
}
.numpad-key {
    padding: 18px 10px;
    border: 1.5px solid var(--line);
    border-radius: 14px;
    background: var(--surface);
    font-size: 20px;
    font-weight: 700;
    color: var(--ink);
    cursor: pointer;
    text-align: center;
    transition: background .12s, transform .1s;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}
.numpad-key:hover, .numpad-key:active { background: var(--primary-light); color: var(--primary); transform: scale(.96); }
.numpad-key.backspace { color: var(--ink-muted); font-size: 22px; }
.numpad-key.empty { border: none; background: none; cursor: default; pointer-events: none; }

.pin-hint { text-align: center; font-size: 13px; color: var(--ink-muted); margin-top: 8px; }
.pin-error { display: none; text-align: center; font-size: 13px; color: var(--danger); margin-top: 8px; font-weight: 600; }
.pin-error.visible { display: block; }

/* ── Navigation buttons ──────────────────────────────────── */
.wiz-nav {
    display: flex;
    gap: 12px;
    margin-top: 20px;
}
.wiz-nav .cta { flex: 1; justify-content: center; font-size: 15px; padding: 14px; }
.wiz-nav .cta:not(.secondary) { font-size: 16px; font-weight: 800; }

/* ── Balance card (sticky top) ───────────────────────────── */
.balance-strip {
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    border-radius: 16px; padding: 16px 20px;
    margin-bottom: 20px; color: #fff;
    display: flex; align-items: center; justify-content: space-between;
}
.balance-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; opacity: .7; }
.balance-value { font-size: 22px; font-weight: 900; margin-top: 2px; }
.balance-account { font-size: 12px; opacity: .65; margin-top: 2px; }

@media (max-width: 520px) {
    .wiz-panel { padding: 20px 16px; }
    .quick-amounts { grid-template-columns: repeat(4, 1fr); }
    .amount-display { font-size: 40px; }
    .contact-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>

<div class="send-wizard">

    {{-- Striscia saldo ─────────────────────────────────────────────────── --}}
    <div class="balance-strip">
        <div>
            <div class="balance-label">Saldo disponibile</div>
            <div class="balance-value">{{ ky_format($currentAccount->saldoDisponibile()) }} KY</div>
            <div class="balance-account">{{ $currentAccount->display_name }}</div>
        </div>
        <div style="font-size:32px;opacity:.5;">💳</div>
    </div>

    {{-- Progress dots ───────────────────────────────────────────────────── --}}
    <div class="wiz-progress" id="wizProgress">
        <div class="wiz-progress-dot active" id="pd1"></div>
        <div class="wiz-progress-dot" id="pd2"></div>
        <div class="wiz-progress-dot" id="pd3"></div>
        @if($hasPin && $pinThreshold !== null)
        <div class="wiz-progress-dot" id="pd4"></div>
        @endif
    </div>

    {{-- ═══ STEP 1 — Destinatario ═════════════════════════════════════════ --}}
    <div class="wiz-step active" id="step1">
        <div class="wiz-panel">
            <div class="wiz-eyebrow">Passo 1 di {{ ($hasPin && $pinThreshold !== null) ? 4 : 3 }}</div>
            <h2 class="wiz-title">Chi vuoi pagare?</h2>

            {{-- Beneficiari salvati --}}
            @if($savedBeneficiaries->isNotEmpty())
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:10px;">Contatti salvati</div>
            <div class="contact-grid" id="savedGrid">
                @foreach($savedBeneficiaries as $ben)
                @php $acc = $ben->beneficiaryAccount; @endphp
                @if($acc)
                <div class="contact-chip"
                     data-id="{{ $acc->id }}"
                     data-name="{{ $acc->display_name }}"
                     data-number="{{ $acc->ky_account_number }}">
                    <div class="contact-avatar">{{ mb_strtoupper(mb_substr($acc->display_name, 0, 1)) }}</div>
                    <span class="contact-name">{{ Str::limit($acc->display_name, 18) }}</span>
                </div>
                @endif
                @endforeach
            </div>
            @endif

            {{-- Ultimi destinatari --}}
            @if($recentRecipients->isNotEmpty())
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:10px;{{ $savedBeneficiaries->isNotEmpty() ? 'margin-top:18px;' : '' }}">Inviato di recente</div>
            <div class="contact-grid" id="recentGrid">
                @foreach($recentRecipients as $rec)
                <div class="contact-chip"
                     data-id="{{ $rec->id }}"
                     data-name="{{ $rec->display_name }}"
                     data-number="{{ $rec->ky_account_number }}">
                    <div class="contact-avatar">{{ mb_strtoupper(mb_substr($rec->display_name, 0, 1)) }}</div>
                    <span class="contact-name">{{ Str::limit($rec->display_name, 18) }}</span>
                </div>
                @endforeach
            </div>
            @endif

            {{-- Ricerca libera --}}
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:10px;{{ ($savedBeneficiaries->isNotEmpty() || $recentRecipients->isNotEmpty()) ? 'margin-top:20px;' : '' }}">Cerca per nome, email, numero conto</div>
            <div class="search-box-wrapper">
                <span class="search-box-icon">🔍</span>
                <input type="text" id="recipientSearch" autocomplete="off" placeholder="Es. Mario Rossi o KYB0001234…">
                <div class="search-dropdown" id="searchDropdown"></div>
            </div>

            {{-- Banner destinatario selezionato --}}
            <div class="selected-recipient-banner" id="selectedBanner">
                <div class="contact-avatar" id="selectedAvatar">?</div>
                <div>
                    <div class="srb-name" id="selectedName">—</div>
                    <div class="srb-number" id="selectedNumber"></div>
                </div>
                <span class="srb-change" id="changeBtnStep1">Cambia</span>
            </div>
        </div>

        <div class="wiz-nav">
            <a href="{{ route('portal.pagamenti-hub') }}" class="cta secondary">Annulla</a>
            <button class="cta" id="toStep2Btn" disabled onclick="goTo(2)">Avanti →</button>
        </div>
    </div>

    {{-- ═══ STEP 2 — Importo ═══════════════════════════════════════════════ --}}
    <div class="wiz-step" id="step2">
        <div class="wiz-panel">
            <div class="wiz-eyebrow">Passo 2 di {{ ($hasPin && $pinThreshold !== null) ? 4 : 3 }}</div>
            <h2 class="wiz-title">Quanto vuoi inviare?</h2>

            {{-- Display importo --}}
            <div class="amount-display" id="amountDisplay">
                <span id="amountDisplayVal">0,00</span><span class="ky-suffix">KY</span>
            </div>

            {{-- Quick buttons --}}
            <div class="quick-amounts">
                <button class="qa-btn" data-amount="500" onclick="setQuickAmount(this)">5 KY</button>
                <button class="qa-btn" data-amount="1000" onclick="setQuickAmount(this)">10 KY</button>
                <button class="qa-btn" data-amount="2000" onclick="setQuickAmount(this)">20 KY</button>
                <button class="qa-btn" data-amount="5000" onclick="setQuickAmount(this)">50 KY</button>
            </div>

            {{-- Input numerico --}}
            <div class="amount-input-wrapper">
                <input type="number" id="amountInput" min="0.01" step="0.01" placeholder="Oppure inserisci un importo">
            </div>

            {{-- Causale --}}
            <div class="description-field">
                <label>Causale (opzionale)</label>
                <textarea id="descriptionInput" maxlength="200" rows="2" placeholder="Es. Fattura n. 42, servizio di consulenza…"></textarea>
                <div class="char-count"><span id="descCount">0</span>/200</div>
            </div>
        </div>

        <div class="wiz-nav">
            <button class="cta secondary" onclick="goTo(1)">← Indietro</button>
            <button class="cta" id="toStep3Btn" disabled onclick="goTo(3)">Avanti →</button>
        </div>
    </div>

    {{-- ═══ STEP 3 — Conferma ══════════════════════════════════════════════ --}}
    <div class="wiz-step" id="step3">

        {{-- Card verifica destinatario ─────────────────────────────────────── --}}
        <div class="recipient-verify-card" id="recipientVerifyCard">
            <div class="rvc-avatar" id="rvcAvatar">?</div>
            <div class="rvc-info">
                <div class="rvc-name" id="rvcName">—</div>
                <div class="rvc-number" id="rvcNumber"></div>
                <span class="rvc-badge" id="rvcType"></span>
            </div>
            <div style="color:var(--success);font-size:24px;" title="Destinatario verificato">✓</div>
        </div>

        {{-- Alert primo pagamento ───────────────────────────────────────────── --}}
        <div class="first-payment-alert" id="firstPaymentAlert">
            <div style="font-size:20px;flex-shrink:0;">⚠️</div>
            <div>
                <strong>Primo pagamento a questo utente</strong>
                Stai inviando KY a questo destinatario per la prima volta. Verifica che il numero conto sia corretto prima di confermare.
            </div>
        </div>

        <div class="wiz-panel">
            <div class="wiz-eyebrow">Passo 3 di {{ ($hasPin && $pinThreshold !== null) ? 4 : 3 }}</div>
            <h2 class="wiz-title">Riepilogo</h2>

            <div class="confirm-row">
                <span class="confirm-label">Destinatario</span>
                <span class="confirm-value" id="confirmName">—</span>
            </div>
            <div class="confirm-row">
                <span class="confirm-label">Numero conto</span>
                <span class="confirm-value" id="confirmNumber" style="font-family:monospace;font-size:12px;letter-spacing:.04em;">—</span>
            </div>
            <div class="confirm-row">
                <span class="confirm-label">Importo</span>
                <span class="confirm-value confirm-amount" id="confirmAmount">—</span>
            </div>
            <div class="confirm-row" id="confirmDescRow" style="display:none;">
                <span class="confirm-label">Causale</span>
                <span class="confirm-value" id="confirmDesc">—</span>
            </div>
            <div class="confirm-row">
                <span class="confirm-label">Da</span>
                <span class="confirm-value">{{ $currentAccount->display_name }}</span>
            </div>
        </div>

        <div style="background:var(--warning-soft);border:1px solid #fde68a;border-radius:12px;padding:12px 14px;font-size:13px;color:var(--warning);margin-bottom:16px;">
            ⚠️ L'operazione è <strong>immediata e irreversibile</strong> una volta confermata.
        </div>

        <div class="wiz-nav">
            <button class="cta secondary" onclick="goTo(2)">← Modifica</button>
            <button class="cta" id="confirmBtn" onclick="handleConfirm()">
                @if($hasPin && $pinThreshold !== null)
                    Continua →
                @else
                    ✓ Conferma pagamento
                @endif
            </button>
        </div>
    </div>

    {{-- ═══ STEP 4 — PIN (solo se configurato) ════════════════════════════ --}}
    @if($hasPin && $pinThreshold !== null)
    <div class="wiz-step" id="step4">
        <div class="wiz-panel" style="text-align:center;">
            <div class="wiz-eyebrow">Passo 4 di 4</div>
            <h2 class="wiz-title">Inserisci il PIN</h2>
            <p style="font-size:14px;color:var(--ink-muted);margin:-8px 0 0;">Conferma il pagamento con il tuo PIN di 6 cifre.</p>

            <div class="pin-dots" id="pinDots">
                <div class="pin-dot" id="dot0"></div>
                <div class="pin-dot" id="dot1"></div>
                <div class="pin-dot" id="dot2"></div>
                <div class="pin-dot" id="dot3"></div>
                <div class="pin-dot" id="dot4"></div>
                <div class="pin-dot" id="dot5"></div>
            </div>

            <div class="pin-error" id="pinError">PIN errato. Riprova.</div>
            <div class="pin-hint" id="pinHint">Inserisci le 6 cifre</div>

            <div class="numpad">
                @foreach([1,2,3,4,5,6,7,8,9,'','0','⌫'] as $k)
                    @if($k === '')
                        <div class="numpad-key empty"></div>
                    @elseif($k === '⌫')
                        <div class="numpad-key backspace" onclick="pinBack()">⌫</div>
                    @else
                        <div class="numpad-key" onclick="pinPress('{{ $k }}')">{{ $k }}</div>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="wiz-nav" style="margin-top:0;">
            <button class="cta secondary" onclick="goTo(3)">← Indietro</button>
        </div>
    </div>
    @endif

    {{-- Hidden form (submitted when confirmed) ──────────────────────────── --}}
    <form id="payForm" method="post" action="{{ route('portal.invia.esegui') }}" style="display:none;">
        @csrf
        <input type="hidden" id="f_to_account_id" name="to_account_id">
        <input type="hidden" id="f_amount" name="amount">
        <input type="hidden" id="f_description" name="description">
        <input type="hidden" id="f_pin_hash" name="pin_hash">
    </form>

</div>

@push('scripts')
<script>
(function () {
    // ── State ────────────────────────────────────────────────────────────────
    const state = {
        recipientId:     null,
        recipientName:   '',
        recipientNumber: '',
        amountCents:     0,
        description:     '',
        pinDigits:       [],
    };

    const PIN_STEPS           = {{ ($hasPin && $pinThreshold !== null) ? 'true' : 'false' }};
    const PIN_THRESHOLD       = {{ $pinThreshold ?? 'null' }};
    const HAS_PIN             = {{ $hasPin ? 'true' : 'false' }};
    const TOTAL_STEPS         = PIN_STEPS ? 4 : 3;
    const SEARCH_URL          = '{{ route("portal.invia.cerca") }}';
    const RECIPIENT_INFO_BASE = '{{ url("/invia/destinatario") }}';
    const CSRF                = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // ── Step navigation ─────────────────────────────────────────────────────
    window.goTo = function(n) {
        document.querySelectorAll('.wiz-step').forEach(s => s.classList.remove('active'));
        const el = document.getElementById('step' + n);
        if (el) { el.classList.add('active'); el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

        // Progress dots
        for (let i = 1; i <= TOTAL_STEPS; i++) {
            const dot = document.getElementById('pd' + i);
            if (!dot) continue;
            dot.classList.remove('done', 'active');
            if (i < n)       dot.classList.add('done');
            else if (i === n) dot.classList.add('active');
        }

        if (n === 3) { fillConfirm(); loadRecipientInfo(); }
    };

    // ── Step 1 — Recipient ───────────────────────────────────────────────────
    function selectRecipient(id, name, number) {
        state.recipientId     = id;
        state.recipientName   = name;
        state.recipientNumber = number;

        // Highlight chips
        document.querySelectorAll('.contact-chip').forEach(c => {
            c.classList.toggle('selected', parseInt(c.dataset.id) === id);
        });

        // Banner
        const banner = document.getElementById('selectedBanner');
        banner.classList.add('visible');
        document.getElementById('selectedAvatar').textContent = name.charAt(0).toUpperCase();
        document.getElementById('selectedName').textContent   = name;
        document.getElementById('selectedNumber').textContent = number || '';

        document.getElementById('toStep2Btn').disabled = false;

        // Hide dropdown
        closeDropdown();
        document.getElementById('recipientSearch').value = '';
    }

    // Chips click
    document.querySelectorAll('.contact-chip').forEach(chip => {
        chip.addEventListener('click', function () {
            selectRecipient(parseInt(this.dataset.id), this.dataset.name, this.dataset.number);
        });
    });

    // Change button
    document.getElementById('changeBtnStep1')?.addEventListener('click', function () {
        state.recipientId = null;
        document.querySelectorAll('.contact-chip').forEach(c => c.classList.remove('selected'));
        document.getElementById('selectedBanner').classList.remove('visible');
        document.getElementById('toStep2Btn').disabled = true;
        document.getElementById('recipientSearch').focus();
    });

    // AJAX search
    let searchTimer = null;
    const searchInput    = document.getElementById('recipientSearch');
    const searchDropdown = document.getElementById('searchDropdown');

    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        if (!q) { closeDropdown(); return; }
        showLoading();
        searchTimer = setTimeout(() => doSearch(q), 280);
    });

    function doSearch(q) {
        fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), {
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                searchDropdown.innerHTML = '<div class="search-no-result">Nessun risultato per "' + escHtml(q) + '"</div>';
            } else {
                searchDropdown.innerHTML = data.map(a =>
                    `<div class="search-option" data-id="${a.id}" data-name="${escHtml(a.name)}" data-number="${escHtml(a.number)}">
                        <div class="search-option-avatar">${escHtml(a.name.charAt(0).toUpperCase())}</div>
                        <div class="search-option-info">
                            <strong>${escHtml(a.name)}</strong>
                            <small>${escHtml(a.number)}</small>
                        </div>
                    </div>`
                ).join('');
                searchDropdown.querySelectorAll('.search-option').forEach(el => {
                    el.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        selectRecipient(parseInt(this.dataset.id), this.dataset.name, this.dataset.number);
                    });
                });
            }
            searchDropdown.classList.add('open');
        })
        .catch(() => closeDropdown());
    }

    function showLoading() {
        searchDropdown.innerHTML = '<div class="search-loading">Ricerca in corso…</div>';
        searchDropdown.classList.add('open');
    }
    function closeDropdown() { searchDropdown.classList.remove('open'); }
    document.addEventListener('click', e => { if (!e.target.closest('.search-box-wrapper')) closeDropdown(); });
    searchInput.addEventListener('focus', function () { if (this.value.trim()) this.dispatchEvent(new Event('input')); });

    // ── Step 2 — Amount ──────────────────────────────────────────────────────
    const amountInput   = document.getElementById('amountInput');
    const amountDisplay = document.getElementById('amountDisplay');
    const amountDisplayVal = document.getElementById('amountDisplayVal');
    const toStep3Btn    = document.getElementById('toStep3Btn');

    function formatKy(cents) {
        if (!cents) return '0,00';
        return (cents / 100).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function setAmountCents(cents) {
        state.amountCents = cents;
        amountDisplayVal.textContent = formatKy(cents);
        amountDisplay.classList.toggle('has-value', cents > 0);
        toStep3Btn.disabled = cents <= 0;
        // Sync input field
        if (document.activeElement !== amountInput) {
            amountInput.value = cents > 0 ? (cents / 100).toFixed(2) : '';
        }
        // Deselect quick buttons
        document.querySelectorAll('.qa-btn').forEach(b => b.classList.remove('selected'));
    }

    window.setQuickAmount = function (btn) {
        const cents = parseInt(btn.dataset.amount);
        document.querySelectorAll('.qa-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        state.amountCents = cents;
        amountDisplayVal.textContent = formatKy(cents);
        amountDisplay.classList.add('has-value');
        amountInput.value = (cents / 100).toFixed(2);
        toStep3Btn.disabled = false;
    };

    amountInput.addEventListener('input', function () {
        const v = parseFloat(this.value.replace(',', '.'));
        const cents = isNaN(v) || v <= 0 ? 0 : Math.round(v * 100);
        state.amountCents = cents;
        amountDisplayVal.textContent = formatKy(cents);
        amountDisplay.classList.toggle('has-value', cents > 0);
        toStep3Btn.disabled = cents <= 0;
        document.querySelectorAll('.qa-btn').forEach(b => b.classList.remove('selected'));
    });

    // Description char counter
    const descInput = document.getElementById('descriptionInput');
    const descCount = document.getElementById('descCount');
    descInput.addEventListener('input', function () {
        state.description = this.value;
        descCount.textContent = this.value.length;
    });

    // ── Step 3 — Confirm ─────────────────────────────────────────────────────
    function fillConfirm() {
        document.getElementById('confirmName').textContent   = state.recipientName;
        document.getElementById('confirmNumber').textContent = state.recipientNumber;
        document.getElementById('confirmAmount').textContent = formatKy(state.amountCents) + ' KY';
        const descRow = document.getElementById('confirmDescRow');
        if (state.description.trim()) {
            document.getElementById('confirmDesc').textContent = state.description;
            descRow.style.display = 'flex';
        } else {
            descRow.style.display = 'none';
        }
    }

    // ── Recipient verify card ─────────────────────────────────────────────────
    // Chiama /invia/destinatario/{id} e popola la card con nome, numero KY,
    // tipo di conto e flag "primo pagamento".
    function loadRecipientInfo() {
        if (!state.recipientId) return;

        // Popola immediatamente con i dati già noti (senza attendere la fetch)
        const initial = (state.recipientName || '?').charAt(0).toUpperCase();
        document.getElementById('rvcAvatar').innerHTML = initial;
        document.getElementById('rvcName').textContent   = state.recipientName;
        document.getElementById('rvcNumber').textContent = state.recipientNumber;
        document.getElementById('rvcType').textContent   = '';
        document.getElementById('firstPaymentAlert').classList.remove('visible');

        fetch(RECIPIENT_INFO_BASE + '/' + state.recipientId, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF,
            }
        })
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            if (!data) return;

            // Avatar: logo URL o iniziale
            const avatarEl = document.getElementById('rvcAvatar');
            if (data.logo_url) {
                avatarEl.innerHTML = `<img src="${escHtml(data.logo_url)}" alt="${escHtml(data.name)}">`;
            } else {
                avatarEl.textContent = (data.name || '?').charAt(0).toUpperCase();
            }

            document.getElementById('rvcName').textContent   = data.name;
            document.getElementById('rvcNumber').textContent = data.number;

            const typeLabel = data.type === 'private' ? '👤 Conto privato' : '🏢 Conto aziendale';
            document.getElementById('rvcType').textContent   = typeLabel;

            // Alert primo pagamento
            if (data.is_first) {
                document.getElementById('firstPaymentAlert').classList.add('visible');
            } else {
                document.getElementById('firstPaymentAlert').classList.remove('visible');
            }
        })
        .catch(() => { /* silent — la card di base è già popolata */ });
    }

    window.handleConfirm = function () {
        // Se PIN richiesto e importo sopra soglia
        if (HAS_PIN && PIN_THRESHOLD !== null && state.amountCents >= PIN_THRESHOLD) {
            goTo(4);
        } else {
            submitPayment(null);
        }
    };

    // ── Step 4 — PIN ─────────────────────────────────────────────────────────
    window.pinPress = function (digit) {
        if (state.pinDigits.length >= 6) return;
        state.pinDigits.push(digit);
        updatePinDots();
        if (state.pinDigits.length === 6) {
            setTimeout(() => hashAndSubmit(), 120);
        }
    };

    window.pinBack = function () {
        if (!state.pinDigits.length) return;
        state.pinDigits.pop();
        updatePinDots();
        document.getElementById('pinError').classList.remove('visible');
    };

    function updatePinDots() {
        for (let i = 0; i < 6; i++) {
            const dot = document.getElementById('dot' + i);
            dot.classList.toggle('filled', i < state.pinDigits.length);
            dot.classList.remove('error');
        }
    }

    async function hashAndSubmit() {
        const pinStr = state.pinDigits.join('');
        try {
            const encoder  = new TextEncoder();
            const data     = encoder.encode(pinStr);
            const hashBuf  = await crypto.subtle.digest('SHA-256', data);
            const hashArr  = Array.from(new Uint8Array(hashBuf));
            const hashHex  = hashArr.map(b => b.toString(16).padStart(2, '0')).join('');
            submitPayment(hashHex);
        } catch (e) {
            showPinError('Errore nel calcolo del PIN. Riprova.');
        }
    }

    function showPinError(msg) {
        const err = document.getElementById('pinError');
        err.textContent = msg;
        err.classList.add('visible');
        // Shake dots red
        for (let i = 0; i < 6; i++) {
            document.getElementById('dot' + i).classList.add('error');
        }
        setTimeout(() => {
            state.pinDigits = [];
            updatePinDots();
        }, 1200);
    }

    // ── Form submit ──────────────────────────────────────────────────────────
    function submitPayment(pinHash) {
        document.getElementById('f_to_account_id').value = state.recipientId;
        document.getElementById('f_amount').value        = (state.amountCents / 100).toFixed(2);
        document.getElementById('f_description').value   = state.description;
        document.getElementById('f_pin_hash').value      = pinHash ?? '';

        // Disable confirm button to prevent double-submit
        const btn = document.getElementById('confirmBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Invio in corso…'; }

        document.getElementById('payForm').submit();
    }

    // ── Keyboard support for PIN step ────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        const step4 = document.getElementById('step4');
        if (!step4 || !step4.classList.contains('active')) return;
        if (e.key >= '0' && e.key <= '9') pinPress(e.key);
        if (e.key === 'Backspace') pinBack();
    });

    // ── Utility ──────────────────────────────────────────────────────────────
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})();
</script>
@endpush

@endsection
