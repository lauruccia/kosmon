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

{{-- ── Riepilogo Cassa Circuito ──────────────────────────────────────────── --}}
<section class="hero-strip">
    <article class="stat-card" style="border-left:4px solid #6d28d9;">
        <div class="eyebrow">Cassa Circuito</div>
        <div class="section-title" style="word-break:break-word;font-size:15px;">{{ $systemAccount->account_number }}</div>
        <div class="table-muted">Conto riserva sovrana</div>
    </article>
    <article class="stat-card" style="border-left:4px solid {{ $systemAccount->available_balance < 0 ? '#dc2626' : '#16a34a' }};">
        <div class="eyebrow">Saldo Cassa</div>
        <div class="section-title" style="color:{{ $systemAccount->available_balance < 0 ? '#dc2626' : '#16a34a' }};">
            {{ number_format($systemAccount->available_balance, 2, ',', '.') }} KY
        </div>
        <div class="table-muted">Negativo = KY in circolazione</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #0f52c4;">
        <div class="eyebrow">KY Totale Emesso</div>
        <div class="section-title" style="color:#0f52c4;">
            {{ number_format($totalEmitted, 2, ',', '.') }} KY
        </div>
        <div class="table-muted">Storico emissioni</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #0284c7;">
        <div class="eyebrow">Ultime emissioni</div>
        <div class="section-title" style="color:#0284c7;">{{ $recentEmissions->count() }}</div>
        <div class="table-muted">In questa pagina</div>
    </article>
</section>

<div class="summary-grid" style="margin-bottom:12px;">

    {{-- ── Form emissione ──────────────────────────────────────────────────── --}}
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

            {{-- Conto destinatario --}}
            <div style="margin-bottom:10px;">
                <label class="form-label" style="margin-bottom:4px;">Conto destinatario *</label>
                <select name="to_account_id" id="to_account_id" required class="form-control">
                    <option value="">— Seleziona conto —</option>
                    @foreach($targetAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('to_account_id') == $account->id)>
                            {{ $account->display_name }} ({{ $account->account_number }}) — {{ number_format($account->available_balance, 2, ',', '.') }} KY
                            @if($account->company?->kyc_status === 'approved') ✓ @else [non verif.] @endif
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Importo + Note affiancati --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Importo da emettere (KY) *</label>
                    <div style="position:relative;">
                        <input type="number" name="amount" id="amount" required
                            min="1" max="10000000" step="1"
                            value="{{ old('amount') }}" placeholder="es. 1000"
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

            {{-- Riepilogo prima del submit --}}
            <div id="preview" style="display:none;background:#dbeafe;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:13px;color:#1d4ed8;border:1px solid #bfdbfe;">
                <strong>Riepilogo:</strong> Emissione di <strong id="previewAmount">—</strong> KY su <strong id="previewAccount">—</strong>. Irreversibile senza storno manuale.
            </div>

            <button type="submit" class="cta" id="submitBtn" style="width:100%;padding:10px;"
                onclick="return confirm('Confermi l\'emissione di ' + document.getElementById(\'amount\').value + ' KY? L\'operazione verrà registrata nel libro mastro.')">
                🏦 Emetti KY
            </button>
        </form>
    </section>

    {{-- ── Info riserva ──────────────────────────────────────────────────── --}}
    <section class="card light-card">
        <div class="section-head">
            <span class="eyebrow">Logica emissione</span>
        </div>

        <div style="display:grid;gap:8px;">
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:16px;flex-shrink:0;">📉</span>
                <div><strong style="font-size:12px;">Cassa si indebita</strong><br><span style="font-size:12px;color:var(--ink-soft);">Saldo negativo = KY totale circolante.</span></div>
            </div>
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:16px;flex-shrink:0;">📒</span>
                <div><strong style="font-size:12px;">Double-entry ledger</strong><br><span style="font-size:12px;color:var(--ink-soft);">Debito Cassa + credito destinatario. Somma = 0.</span></div>
            </div>
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:16px;flex-shrink:0;">🔒</span>
                <div><strong style="font-size:12px;">Solo super admin</strong><br><span style="font-size:12px;color:var(--ink-soft);">Registrato in audit log con IP e timestamp.</span></div>
            </div>
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <span style="font-size:16px;flex-shrink:0;">⚠️</span>
                <div><strong style="font-size:12px;">Irreversibile</strong><br><span style="font-size:12px;color:var(--ink-soft);">Per annullare usa "Storno" nel dettaglio movimento.</span></div>
            </div>
        </div>

        {{-- Stato cassa circuito --}}
        <div style="margin-top:12px;padding:12px;background:{{ $systemAccount->available_balance < 0 ? '#fef2f2' : '#f0fdf4' }};border-radius:10px;text-align:center;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:4px;">KY in circolazione</div>
            <div style="font-size:24px;font-weight:800;color:{{ $systemAccount->available_balance < 0 ? '#dc2626' : '#16a34a' }};">
                {{ number_format(abs($systemAccount->available_balance), 2, ',', '.') }} KY
            </div>
        </div>
    </section>

</div>

{{-- ── Storico emissioni ───────────────────────────────────────────────────── --}}
@if($recentEmissions->isNotEmpty())
<section class="card light-card" style="margin-bottom:24px;">
    <div class="section-head">
        <div>
            <span class="eyebrow">Storico</span>
            <h3 class="section-title">Ultime {{ $recentEmissions->count() }} emissioni</h3>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Conto destinatario</th>
                    <th>Importo</th>
                    <th>Note</th>
                    <th>Emesso da</th>
                    <th>Riferimento</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentEmissions as $emission)
                <tr>
                    <td class="table-muted" style="white-space:nowrap;">
                        {{ $emission->booked_at?->format('d/m/Y H:i') ?? '—' }}
                    </td>
                    <td>
                        <div style="font-weight:600;">{{ $emission->toAccount?->display_name ?? '—' }}</div>
                        <div class="table-muted" style="font-size:11px;">{{ $emission->toAccount?->account_number }}</div>
                    </td>
                    <td>
                        <span style="font-weight:700;font-size:15px;color:#0f52c4;">
                            +{{ number_format($emission->amount, 2, ',', '.') }} KY
                        </span>
                    </td>
                    <td class="table-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        {{ $emission->description ?: '—' }}
                    </td>
                    <td class="table-muted">{{ $emission->initiator?->name ?? '—' }}</td>
                    <td>
                        <code style="font-size:11px;background:var(--bg);padding:2px 6px;border-radius:4px;">
                            {{ $emission->reference ?? substr($emission->id, 0, 8) }}
                        </code>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@else
<section class="card light-card" style="text-align:center;padding:40px;color:var(--ink-muted);">
    <div style="font-size:36px;margin-bottom:12px;">🏦</div>
    <div style="font-weight:600;margin-bottom:4px;">Nessuna emissione effettuata</div>
    <div style="font-size:13px;">Le emissioni KY appariranno qui non appena ne eseguirai una.</div>
</section>
@endif

<script>
// Preview dinamico nel form
const amountInput = document.getElementById('amount');
const accountSelect = document.getElementById('to_account_id');
const preview = document.getElementById('preview');
const previewAmount = document.getElementById('previewAmount');
const previewAccount = document.getElementById('previewAccount');

function updatePreview() {
    const amount = amountInput.value;
    const accountText = accountSelect.options[accountSelect.selectedIndex]?.text ?? '';
    if (amount && accountSelect.value) {
        preview.style.display = 'block';
        previewAmount.textContent = parseInt(amount).toLocaleString('it-IT');
        // Estrai solo il nome (prima delle parentesi)
        previewAccount.textContent = accountText.split('(')[0].trim();
    } else {
        preview.style.display = 'none';
    }
}

amountInput.addEventListener('input', updatePreview);
accountSelect.addEventListener('change', updatePreview);
</script>

@endsection
