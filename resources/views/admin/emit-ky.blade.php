@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('admin.accounts.index') }}">Tutti i conti</a>
<a class="cta secondary" href="{{ route('admin.transfers.index') }}">Movimenti</a>
@endsection

@section('content')

{{-- Alert sessione --}}
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     BANNER INTEGRITÀ CIRCUITO
══════════════════════════════════════════════════════════════════════════ --}}
@if($circuitIsHealthy)
<div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:14px;margin-bottom:20px;">
    <span style="font-size:24px;">✅</span>
    <div>
        <strong style="color:#15803d;font-size:14px;">Circuito chiuso — Integrità verificata</strong>
        <div style="font-size:12px;color:#166534;margin-top:2px;">
            Somma di tutti i saldi = <strong>0,00 KY</strong>. Ogni KY emesso è contabilizzato. Nessuna discrepanza.
        </div>
    </div>
    <div style="margin-left:auto;text-align:right;flex-shrink:0;">
        <div style="font-size:11px;color:#166534;font-weight:700;text-transform:uppercase;">SOMMA CIRCUITO</div>
        <div style="font-size:20px;font-weight:800;color:#15803d;">0,00 KY ✅</div>
    </div>
</div>
@else
<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:14px;margin-bottom:20px;">
    <span style="font-size:24px;">🚨</span>
    <div>
        <strong style="color:#dc2626;font-size:14px;">ANOMALIA — Circuito NON bilanciato</strong>
        <div style="font-size:12px;color:#991b1b;margin-top:2px;">
            La somma di tutti i saldi non è zero. Investigare immediatamente.
            Delta: <strong>{{ ky_format($circuitDelta) }} KY</strong>
        </div>
    </div>
    <div style="margin-left:auto;text-align:right;flex-shrink:0;">
        <div style="font-size:11px;color:#dc2626;font-weight:700;text-transform:uppercase;">DELTA ANOMALIA</div>
        <div style="font-size:20px;font-weight:800;color:#dc2626;">{{ ky_format($circuitDelta) }} KY ❌</div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     HERO METRICS — 6 KPI
══════════════════════════════════════════════════════════════════════════ --}}
<section class="hero-strip" style="margin-bottom:16px;">

    {{-- KY in circolazione (DATO PRINCIPALE) --}}
    <article class="stat-card" style="border-left:4px solid #7c3aed;">
        <div class="eyebrow">KY in circolazione</div>
        <div class="section-title" style="color:#7c3aed;font-size:22px;">
            {{ ky_format($kyInCirculation) }} KY
        </div>
        <div class="table-muted" style="font-size:11px;">= |saldo Cassa Circuito|</div>
    </article>

    {{-- Saldo Cassa Circuito --}}
    <article class="stat-card" style="border-left:4px solid {{ $systemAccount->available_balance < 0 ? '#dc2626' : '#16a34a' }};">
        <div class="eyebrow">Saldo Cassa Circuito</div>
        <div class="section-title" style="color:{{ $systemAccount->available_balance < 0 ? '#dc2626' : '#16a34a' }};font-size:18px;">
            {{ ky_format($systemAccount->available_balance) }} KY
        </div>
        <div class="table-muted" style="font-size:11px;">{{ $systemAccount->account_number }}</div>
    </article>

    {{-- KY su conto riserva operativa (MAIN Knm srl) --}}
    @if($mainReserveAccount)
    <article class="stat-card" style="border-left:4px solid #0284c7;">
        <div class="eyebrow">Riserva operativa</div>
        <div class="section-title" style="color:#0284c7;font-size:18px;">
            {{ ky_format($kyOnMainReserve) }} KY
        </div>
        <div class="table-muted" style="font-size:11px;">{{ $mainReserveAccount->company?->name ?? $mainReserveAccount->display_name }} (MAIN)</div>
    </article>
    @endif

    {{-- KY effettivamente circolanti su conti membri --}}
    <article class="stat-card" style="border-left:4px solid #059669;">
        <div class="eyebrow">KY su conti membri</div>
        <div class="section-title" style="color:#059669;font-size:18px;">
            {{ ky_format($kyOnOtherAccounts) }} KY
        </div>
        <div class="table-muted" style="font-size:11px;">{{ $accountsPositive }} conti con saldo positivo</div>
    </article>

    {{-- Uscite dal sistema (via trasferimenti tracciati) --}}
    <article class="stat-card" style="border-left:4px solid #ea580c;">
        <div class="eyebrow">Emessi via trasf.</div>
        <div class="section-title" style="color:#ea580c;font-size:18px;">
            {{ ky_format($totalOutFromSystem) }} KY
        </div>
        <div class="table-muted" style="font-size:11px;">rientrati: {{ ky_format($totalReturnedToSystem) }} KY</div>
    </article>

    {{-- Fidi attivi --}}
    <article class="stat-card" style="border-left:4px solid #d97706;">
        <div class="eyebrow">Fidi attivi</div>
        <div class="section-title" style="color:#d97706;font-size:18px;">
            {{ ky_format($activeCreditLimitsTotal) }} KY
        </div>
        <div class="table-muted" style="font-size:11px;">{{ $accountsNegative }} conti in rosso</div>
    </article>

</section>

{{-- Nota saldo implicito (se presente) --}}
@if($hasImplicitBalance)
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;font-size:13px;">
    <span style="font-size:18px;flex-shrink:0;">ℹ️</span>
    <div style="color:#92400e;">
        <strong>Nota contabile — Saldo iniziale impostato direttamente:</strong>
        {{ ky_format($implicitBalance) }} KY non risultano da trasferimenti registrati
        (saldo iniziale da seed o correzione contabile diretta). Il circuito è comunque bilanciato (somma circuito = 0,00 KY).
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     ANALISI EMISSIONI + RIENTRI
══════════════════════════════════════════════════════════════════════════ --}}
<div class="summary-grid" style="margin-bottom:16px;">

    {{-- Breakdown emissioni per tipo --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Flussi uscenti dalla Cassa</span>
                <h3 class="section-title">Emissioni per tipo di operazione</h3>
            </div>
            <span style="font-size:20px;">📤</span>
        </div>

        @if($emissionBreakdown->isNotEmpty())
        <table class="data-table" style="font-size:13px;">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th style="text-align:right;">N°</th>
                    <th style="text-align:right;">Totale KY</th>
                    <th style="text-align:right;">% sul circolante</th>
                </tr>
            </thead>
            <tbody>
                @foreach($emissionBreakdown as $row)
                @php
                    $pct = $kyInCirculation > 0 ? round(($row->total / $kyInCirculation) * 100, 1) : 0;
                    $kindLabel = match($row->kind) {
                        'ky_emission'        => '🏦 Emissione diretta',
                        'admin_credit'       => '👤 Accredito admin',
                        'kycard_topup'       => '💳 Ricarica KY Card',
                        'kycard_purchase'    => '💳 Acquisto KY Card',
                        'trade_payment'      => '🔄 Pagamento circuito',
                        'p2p_receive'        => '↔️ P2P ricevuto',
                        'bank_transfer'      => '🏧 Bonifico',
                        'referral_commission'=> '🎁 Commissione referral',
                        default              => $row->kind,
                    };
                @endphp
                <tr>
                    <td><span style="font-size:12px;">{{ $kindLabel }}</span></td>
                    <td style="text-align:right;color:var(--ink-muted);">{{ $row->cnt }}</td>
                    <td style="text-align:right;font-weight:700;color:#ea580c;">
                        {{ ky_format($row->total) }} KY
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;">
                            <div style="width:50px;height:5px;background:#fee2e2;border-radius:3px;overflow:hidden;">
                                <div style="width:{{ min(100,$pct) }}%;height:100%;background:#ea580c;border-radius:3px;"></div>
                            </div>
                            <span style="font-size:11px;color:var(--ink-muted);">{{ $pct }}%</span>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid var(--border);">
                    <td colspan="2"><strong style="font-size:12px;">Totale emissioni via trasf.</strong></td>
                    <td style="text-align:right;font-weight:800;color:#ea580c;">
                        {{ ky_format($totalOutFromSystem) }} KY
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        @else
        <div style="text-align:center;padding:24px;color:var(--ink-muted);font-size:13px;">Nessuna emissione tracciata via trasferimento.</div>
        @endif
    </section>

    {{-- Breakdown rientri per tipo --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Flussi entranti alla Cassa</span>
                <h3 class="section-title">Rientri per tipo di operazione</h3>
            </div>
            <span style="font-size:20px;">📥</span>
        </div>

        @if($returnBreakdown->isNotEmpty())
        <table class="data-table" style="font-size:13px;">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th style="text-align:right;">N°</th>
                    <th style="text-align:right;">Totale KY</th>
                    <th style="text-align:right;">% sul circolante</th>
                </tr>
            </thead>
            <tbody>
                @foreach($returnBreakdown as $row)
                @php
                    $pct = $kyInCirculation > 0 ? round(($row->total / $kyInCirculation) * 100, 1) : 0;
                    $kindLabel = match($row->kind) {
                        'admin_debit'        => '🔻 Addebito admin',
                        'admin_credit'       => '👤 Accredito admin',
                        'bank_transfer'      => '🏧 Bonifico in entrata',
                        'kycard_purchase'    => '💳 Acquisto KY Card',
                        'purchase'           => '🛒 Acquisto',
                        'trade_payment'      => '🔄 Pagamento circuito',
                        default              => $row->kind,
                    };
                @endphp
                <tr>
                    <td><span style="font-size:12px;">{{ $kindLabel }}</span></td>
                    <td style="text-align:right;color:var(--ink-muted);">{{ $row->cnt }}</td>
                    <td style="text-align:right;font-weight:700;color:#059669;">
                        {{ ky_format($row->total) }} KY
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;">
                            <div style="width:50px;height:5px;background:#dcfce7;border-radius:3px;overflow:hidden;">
                                <div style="width:{{ min(100,$pct) }}%;height:100%;background:#059669;border-radius:3px;"></div>
                            </div>
                            <span style="font-size:11px;color:var(--ink-muted);">{{ $pct }}%</span>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid var(--border);">
                    <td colspan="2"><strong style="font-size:12px;">Totale rientri via trasf.</strong></td>
                    <td style="text-align:right;font-weight:800;color:#059669;">
                        {{ ky_format($totalReturnedToSystem) }} KY
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        @else
        <div style="text-align:center;padding:24px;color:var(--ink-muted);font-size:13px;">Nessun rientro registrato.</div>
        @endif
    </section>

</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     FORM + LOGICA
══════════════════════════════════════════════════════════════════════════ --}}
<div class="summary-grid" style="margin-bottom:16px;">

    {{-- Form emissione --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Nuova emissione</span>
                <h3 class="section-title">Accredita KY su un conto</h3>
            </div>
            <span style="font-size:22px;">🏦</span>
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

        <form method="POST" action="{{ route('admin.ky.emit.submit') }}" id="emitForm">
            @csrf

            <div style="margin-bottom:10px;">
                <label class="form-label" style="margin-bottom:4px;">Conto destinatario *</label>
                <select name="to_account_id" id="to_account_id" required class="form-control">
                    <option value="">— Seleziona conto —</option>
                    @foreach($targetAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('to_account_id') == $account->id)>
                            {{ $account->display_name }} ({{ $account->account_number }}) — {{ ky_format($account->available_balance) }} KY
                            @if($account->company?->kyc_status === 'approved') ✓ @else [non verif.] @endif
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Importo KY *</label>
                    <div style="position:relative;">
                        <input type="number" name="amount" id="amount" required
                            min="0.01" max="10000000" step="0.01"
                            value="{{ old('amount') }}" placeholder="es. 1000,00"
                            class="form-control" style="font-size:16px;font-weight:700;padding-right:40px;">
                        <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-weight:700;color:var(--ink-muted);font-size:13px;">KY</span>
                    </div>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Note / motivazione</label>
                    <textarea name="description" id="description" rows="1"
                        placeholder="Es: Cauzione iniziale circuito 2026"
                        class="form-control" style="resize:none;min-height:0;">{{ old('description') }}</textarea>
                </div>
            </div>

            <div id="preview" style="display:none;background:#dbeafe;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:13px;color:#1d4ed8;border:1px solid #bfdbfe;">
                <strong>Riepilogo:</strong> Emissione di <strong id="previewAmount">—</strong> KY su <strong id="previewAccount">—</strong>.
                Il saldo Cassa passerà a <strong id="previewNewBalance">—</strong> KY.
                KY totali in circolazione: <strong id="previewCirculating">—</strong> KY.
            </div>

            <button type="submit" class="cta" id="submitBtn" style="width:100%;padding:10px;"
                onclick="return confirm('Confermi l\'emissione di ' + document.getElementById(\'amount\').value + ' KY? L\'operazione verrà registrata nel libro mastro.')">
                🏦 Emetti KY
            </button>
        </form>
    </section>

    {{-- Info riserva + stato --}}
    <section class="card light-card">
        <div class="section-head">
            <span class="eyebrow">Stato circuito</span>
        </div>

        {{-- Distribuzione KY --}}
        @if($kyInCirculation > 0)
        <div style="margin-bottom:14px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-muted);margin-bottom:8px;">Distribuzione KY in circolazione</div>
            @php
                $pctRiserva = round(($kyOnMainReserve / $kyInCirculation) * 100, 1);
                $pctMembri  = max(0, round(($kyOnOtherAccounts / $kyInCirculation) * 100, 1));
            @endphp

            <div style="margin-bottom:8px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">
                    <span>🏛 Riserva operativa (@if($mainReserveAccount){{ $mainReserveAccount->company?->name ?? 'MAIN' }}@endif)</span>
                    <strong>{{ $pctRiserva }}%</strong>
                </div>
                <div style="height:8px;background:#e0e7ff;border-radius:4px;overflow:hidden;">
                    <div style="width:{{ min(100,$pctRiserva) }}%;height:100%;background:#0284c7;border-radius:4px;"></div>
                </div>
                <div style="font-size:11px;color:var(--ink-muted);text-align:right;margin-top:2px;">{{ ky_format($kyOnMainReserve) }} KY</div>
            </div>

            <div>
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">
                    <span>🏪 Conti membri del circuito ({{ $accountsPositive }})</span>
                    <strong>{{ $pctMembri }}%</strong>
                </div>
                <div style="height:8px;background:#dcfce7;border-radius:4px;overflow:hidden;">
                    <div style="width:{{ min(100,$pctMembri) }}%;height:100%;background:#059669;border-radius:4px;"></div>
                </div>
                <div style="font-size:11px;color:var(--ink-muted);text-align:right;margin-top:2px;">{{ ky_format($kyOnOtherAccounts) }} KY</div>
            </div>
        </div>
        @endif

        <div style="display:grid;gap:8px;margin-bottom:12px;">
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:15px;flex-shrink:0;">📉</span>
                <div><strong style="font-size:12px;">Cassa si indebita</strong><br><span style="font-size:12px;color:var(--ink-soft);">Saldo negativo = KY netti circolanti.</span></div>
            </div>
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:15px;flex-shrink:0;">📒</span>
                <div><strong style="font-size:12px;">Double-entry ledger</strong><br><span style="font-size:12px;color:var(--ink-soft);">Debito Cassa + credito destinatario. Somma sempre = 0.</span></div>
            </div>
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:15px;flex-shrink:0;">🔒</span>
                <div><strong style="font-size:12px;">Solo super admin</strong><br><span style="font-size:12px;color:var(--ink-soft);">Registrato in audit log con IP e timestamp.</span></div>
            </div>
        </div>

        <div style="padding:12px;background:{{ $circuitIsHealthy ? '#f0fdf4' : '#fef2f2' }};border-radius:10px;text-align:center;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:4px;">KY in circolazione</div>
            <div style="font-size:26px;font-weight:800;color:#7c3aed;">
                {{ ky_format($kyInCirculation) }} KY
            </div>
            <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;">
                Somma circuito: <strong style="color:{{ $circuitIsHealthy ? '#15803d' : '#dc2626' }};">{{ $circuitIsHealthy ? '0,00 ✅' : ky_format($circuitDelta).' ❌' }} KY</strong>
            </div>
        </div>
    </section>

</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     STORICO — ultimi movimenti in uscita dalla Cassa
══════════════════════════════════════════════════════════════════════════ --}}
@if($recentEmissions->isNotEmpty())
<section class="card light-card" style="margin-bottom:24px;">
    <div class="section-head">
        <div>
            <span class="eyebrow">Storico</span>
            <h3 class="section-title">Ultimi {{ $recentEmissions->count() }} movimenti in uscita dalla Cassa</h3>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Conto destinatario</th>
                    <th>Importo</th>
                    <th>Note</th>
                    <th>Registrato da</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentEmissions as $emission)
                @php
                    $kindLabel = match($emission->kind) {
                        'ky_emission'        => '🏦 Emissione',
                        'admin_credit'       => '👤 Accredito admin',
                        'kycard_topup'       => '💳 KY Card top-up',
                        'kycard_purchase'    => '💳 KY Card acquisto',
                        'trade_payment'      => '🔄 Pagamento',
                        'p2p_receive'        => '↔️ P2P',
                        'referral_commission'=> '🎁 Referral',
                        default              => $emission->kind ?? '—',
                    };
                @endphp
                <tr>
                    <td class="table-muted" style="white-space:nowrap;">
                        {{ $emission->booked_at?->format('d/m/Y H:i') ?? '—' }}
                    </td>
                    <td>
                        <span style="font-size:12px;">{{ $kindLabel }}</span>
                    </td>
                    <td>
                        <div style="font-weight:600;">{{ $emission->toAccount?->display_name ?? '—' }}</div>
                        <div class="table-muted" style="font-size:11px;">{{ $emission->toAccount?->account_number }}</div>
                    </td>
                    <td>
                        <span style="font-weight:700;font-size:15px;color:#ea580c;">
                            +{{ ky_format($emission->amount) }} KY
                        </span>
                    </td>
                    <td class="table-muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        {{ $emission->description ?: '—' }}
                    </td>
                    <td class="table-muted">{{ $emission->initiator?->name ?? 'Sistema' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@else
<section class="card light-card" style="text-align:center;padding:40px;color:var(--ink-muted);margin-bottom:24px;">
    <div style="font-size:36px;margin-bottom:12px;">🏦</div>
    <div style="font-weight:600;margin-bottom:4px;">Nessun movimento in uscita dalla Cassa</div>
    <div style="font-size:13px;">Le emissioni KY tramite questo pannello appariranno qui.</div>
</section>
@endif

<script>
const amountInput    = document.getElementById('amount');
const accountSelect  = document.getElementById('to_account_id');
const preview        = document.getElementById('preview');
const previewAmount  = document.getElementById('previewAmount');
const previewAccount = document.getElementById('previewAccount');
const previewNewBal  = document.getElementById('previewNewBalance');
const previewCirc    = document.getElementById('previewCirculating');
const systemBalance  = {{ $systemAccount->available_balance }};   // centesimi (raw DB)
const kyCirculation  = {{ $kyInCirculation }};

function fmt(cents) {
    return (cents / 100).toLocaleString('it-IT', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function updatePreview() {
    const amount  = Math.round((parseFloat(amountInput.value) || 0) * 100);
    const acctTxt = accountSelect.options[accountSelect.selectedIndex]?.text ?? '';

    if (amount > 0 && accountSelect.value) {
        preview.style.display = 'block';
        previewAmount.textContent  = fmt(amount);
        previewAccount.textContent = acctTxt.split('(')[0].trim();
        previewNewBal.textContent  = fmt(systemBalance - amount);
        previewNewBal.style.color  = (systemBalance - amount) < 0 ? '#dc2626' : '#16a34a';
        previewCirc.textContent    = fmt(kyCirculation + amount);
    } else {
        preview.style.display = 'none';
    }
}

amountInput.addEventListener('input', updatePreview);
accountSelect.addEventListener('change', updatePreview);
</script>

@endsection
