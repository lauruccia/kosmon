@extends('layouts.portal')

@section('content')

<section class="card light-card">
    <div class="section-head" style="margin-bottom:16px;">
        <div>
            <span class="eyebrow">Nuovo pagamento</span>
            <h3 class="section-title">Programma un pagamento</h3>
        </div>
        <a href="{{ route('portal.scheduled-payments.index') }}" class="cta secondary">Indietro</a>
    </div>

    @if($errors->any())
        <div style="background:#ffe4e6;border-radius:10px;padding:10px 14px;margin-bottom:14px;">
            <strong style="color:#9f1239;font-size:12px;">Correggi i seguenti errori:</strong>
            <ul style="margin:4px 0 0;padding-left:16px;font-size:12px;color:#9f1239;">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('portal.scheduled-payments.store') }}" id="sp-form">
        @csrf

        {{-- Riga principale --}}
        <div class="sp-grid">

            {{-- Destinatario --}}
            <div class="sp-col-dest">
                <label class="sp-label">Destinatario <span class="sp-req">*</span></label>
                <select name="to_account_id" required class="sp-input">
                    <option value="">— Seleziona —</option>
                    @foreach($counterparties as $acc)
                        <option value="{{ $acc->id }}" {{ old('to_account_id') == $acc->id ? 'selected' : '' }}>
                            {{ $acc->company?->name ?? $acc->display_name }} ({{ $acc->account_number ?? '#'.$acc->id }})
                        </option>
                    @endforeach
                </select>
                @error('to_account_id')<div class="sp-err">{{ $message }}</div>@enderror
            </div>

            {{-- Importo --}}
            <div class="sp-col-amt">
                <label class="sp-label">Importo <span class="sp-req">*</span></label>
                <div style="position:relative;">
                    <input type="number" name="amount" id="amount" value="{{ old('amount') }}"
                           min="0.01" max="9999999" step="0.01" placeholder="0,00" required class="sp-input"
                           style="padding-right:34px;">
                    <span style="position:absolute;right:9px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:700;color:var(--ink-muted);pointer-events:none;">KY</span>
                </div>
                @error('amount')<div class="sp-err">{{ $message }}</div>@enderror
            </div>

            {{-- Descrizione --}}
            <div class="sp-col-desc">
                <label class="sp-label">Causale <span class="sp-req">*</span></label>
                <input type="text" name="description" value="{{ old('description') }}"
                       maxlength="480" placeholder="es. Fornitura servizi ottobre" required class="sp-input">
                @error('description')<div class="sp-err">{{ $message }}</div>@enderror
            </div>

            {{-- Data --}}
            <div class="sp-col-date">
                <label class="sp-label" id="date-label">Data e ora <span class="sp-req">*</span></label>
                <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                       value="{{ old('scheduled_at') }}"
                       min="{{ now()->addMinutes(5)->format('Y-m-d\TH:i') }}" required class="sp-input">
                <div class="sp-hint" id="date-hint">Min. 5 min. nel futuro.</div>
                @error('scheduled_at')<div class="sp-err">{{ $message }}</div>@enderror
            </div>

            {{-- Toggle ricorrente --}}
            <div class="sp-col-toggle" style="display:flex;align-items:center;gap:8px;padding-top:22px;">
                <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:600;font-size:13px;white-space:nowrap;user-select:none;">
                    <input type="checkbox" name="is_recurring" id="is_recurring" value="1"
                           {{ old('is_recurring') ? 'checked' : '' }}
                           onchange="toggleRecurring(this.checked)"
                           style="width:15px;height:15px;accent-color:var(--primary);flex-shrink:0;">
                    Ricorrente
                </label>
            </div>
        </div>

        {{-- Pannello ricorrenza (espandibile) --}}
        <div id="recurrence-panel" style="display:none;margin-top:12px;background:var(--surface-soft,#f8f9fb);border-radius:10px;padding:14px 16px;border:1px solid var(--line);">

            <div class="sp-grid-rec">
                {{-- Frequenza --}}
                <div>
                    <label class="sp-label">Frequenza <span class="sp-req">*</span></label>
                    <select name="recurrence_type" id="recurrence_type" class="sp-input">
                        <option value="monthly"  {{ old('recurrence_type','monthly') === 'monthly'  ? 'selected':'' }}>Mensile</option>
                        <option value="weekly"   {{ old('recurrence_type') === 'weekly'   ? 'selected':'' }}>Settimanale</option>
                        <option value="biweekly" {{ old('recurrence_type') === 'biweekly' ? 'selected':'' }}>Bisettimanale</option>
                    </select>
                    @error('recurrence_type')<div class="sp-err">{{ $message }}</div>@enderror
                </div>

                {{-- N. rate --}}
                <div>
                    <label class="sp-label">N. rate <span class="sp-req">*</span></label>
                    <input type="number" name="recurrence_count" id="recurrence_count"
                           value="{{ old('recurrence_count', 3) }}" min="2" max="60" step="1" class="sp-input">
                    <div class="sp-hint">Min 2 · Max 60</div>
                    @error('recurrence_count')<div class="sp-err">{{ $message }}</div>@enderror
                </div>

                {{-- Riepilogo testuale --}}
                <div style="display:flex;align-items:flex-end;">
                    <div id="rate-preview" style="display:none;font-size:13px;font-weight:600;padding:7px 12px;border-radius:8px;background:var(--primary-soft,#ede9fe);color:var(--primary);width:100%;line-height:1.4;"></div>
                </div>
            </div>

            {{-- Tabella rate --}}
            <div id="rate-table" style="display:none;margin-top:12px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:6px;">Piano rate dettagliato</div>
                <div style="max-height:220px;overflow-y:auto;border-radius:8px;border:1px solid var(--line);">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:var(--primary-soft,#ede9fe);position:sticky;top:0;">
                                <th style="padding:6px 10px;text-align:left;font-weight:700;color:var(--primary);width:32px;">#</th>
                                <th style="padding:6px 10px;text-align:left;font-weight:700;color:var(--primary);">Data scadenza</th>
                                <th style="padding:6px 10px;text-align:right;font-weight:700;color:var(--primary);">Importo (KY)</th>
                            </tr>
                        </thead>
                        <tbody id="rate-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- CTA --}}
        <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">
            <button type="submit" class="cta" style="padding:10px 28px;font-size:14px;">Programma pagamento</button>
            <a href="{{ route('portal.scheduled-payments.index') }}" class="cta secondary" style="padding:10px 20px;font-size:14px;">Annulla</a>
        </div>
    </form>
</section>

<style>
.sp-grid {
    display: grid;
    grid-template-columns: 2fr 120px 2fr 180px auto;
    gap: 10px;
    align-items: start;
}
.sp-grid-rec {
    display: grid;
    grid-template-columns: 160px 110px 1fr;
    gap: 10px;
    align-items: start;
}
.sp-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: var(--ink-muted);
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 4px;
    white-space: nowrap;
}
.sp-req { color: #dc2626; }
.sp-input {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    background: var(--bg);
    color: var(--ink);
    font-size: 13px;
    box-sizing: border-box;
}
.sp-input:focus { outline: none; border-color: var(--primary); }
.sp-hint { font-size: 11px; color: var(--ink-muted); margin-top: 3px; }
.sp-err  { font-size: 11px; color: #dc2626; margin-top: 3px; }

/* Mobile: tutto in colonna */
@media (max-width: 700px) {
    .sp-grid {
        grid-template-columns: 1fr 1fr;
    }
    .sp-col-dest  { grid-column: 1 / -1; }
    .sp-col-desc  { grid-column: 1 / -1; }
    .sp-col-date  { grid-column: 1 / 2; }
    .sp-col-toggle { grid-column: 2 / 3; }
    .sp-grid-rec  { grid-template-columns: 1fr 1fr; }
    .sp-grid-rec > div:last-child { grid-column: 1 / -1; }
}
@media (max-width: 420px) {
    .sp-grid       { grid-template-columns: 1fr; }
    .sp-col-date   { grid-column: auto; }
    .sp-col-toggle { grid-column: auto; padding-top: 0; }
    .sp-grid-rec   { grid-template-columns: 1fr; }
    .sp-grid-rec > div:last-child { grid-column: auto; }
}
</style>

<script>
function toggleRecurring(active) {
    const panel    = document.getElementById('recurrence-panel');
    const dateLabel = document.getElementById('date-label');
    const dateHint  = document.getElementById('date-hint');
    const recType   = document.getElementById('recurrence_type');
    const countIn   = document.getElementById('recurrence_count');

    panel.style.display = active ? '' : 'none';
    dateLabel.innerHTML = active
        ? 'Data prima rata <span class="sp-req">*</span>'
        : 'Data e ora <span class="sp-req">*</span>';
    dateHint.textContent = active ? 'Data/ora della prima rata.' : 'Min. 5 min. nel futuro.';
    recType.required = active;
    countIn.required = active;

    if (active) updatePreview();
}

function updatePreview() {
    const startVal  = document.getElementById('scheduled_at').value;
    const count     = parseInt(document.getElementById('recurrence_count').value) || 0;
    const type      = document.getElementById('recurrence_type').value;
    const amountVal = parseFloat(document.getElementById('amount').value) || 0;
    const preview   = document.getElementById('rate-preview');
    const tableWrap = document.getElementById('rate-table');
    const tbody     = document.getElementById('rate-table-body');

    if (!startVal || count < 2) {
        preview.style.display = tableWrap.style.display = 'none';
        return;
    }
    if (count > 60) {
        preview.textContent = 'Max 60 rate.';
        preview.style.color = '#dc2626';
        preview.style.background = '#fee2e2';
        preview.style.display = 'block';
        tableWrap.style.display = 'none';
        return;
    }

    let cur = new Date(startVal);
    const dates = [new Date(cur)];
    for (let i = 1; i < count; i++) {
        cur = type === 'monthly'  ? addMonths(cur, 1)
            : type === 'weekly'   ? new Date(cur.getTime() + 7*86400000)
            :                       new Date(cur.getTime() + 14*86400000);
        dates.push(new Date(cur));
    }

    const fmt    = d => d.toLocaleDateString('it-IT', {day:'2-digit',month:'2-digit',year:'numeric'});
    const amtFmt = v => v.toLocaleString('it-IT', {minimumFractionDigits:2,maximumFractionDigits:2});
    const label  = {monthly:'mensili',weekly:'settimanali',biweekly:'bisettimanali'}[type]||'';

    preview.innerHTML = `<strong>${count}</strong> rate ${label} &nbsp;·&nbsp; prima il <strong>${fmt(dates[0])}</strong> &nbsp;·&nbsp; ultima il <strong>${fmt(dates[dates.length-1])}</strong>`;
    preview.style.color = 'var(--primary)';
    preview.style.background = 'var(--primary-soft,#ede9fe)';
    preview.style.display = 'block';

    tbody.innerHTML = '';
    dates.forEach((d, i) => {
        const tr = document.createElement('tr');
        tr.style.background = i % 2 === 0 ? '#faf5ff' : '#f3e8ff';
        tr.innerHTML = `<td style="padding:5px 10px;color:#64748b;">${i+1}</td>`
            + `<td style="padding:5px 10px;">${fmt(d)}</td>`
            + `<td style="padding:5px 10px;text-align:right;font-weight:600;">${amountVal > 0 ? amtFmt(amountVal) : '—'}</td>`;
        tbody.appendChild(tr);
    });
    tableWrap.style.display = 'block';
}

function addMonths(date, n) {
    const d = new Date(date), day = d.getDate();
    d.setMonth(d.getMonth() + n);
    if (d.getDate() < day) d.setDate(0);
    return d;
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('is_recurring').checked) toggleRecurring(true);
    ['scheduled_at','recurrence_count','recurrence_type','amount'].forEach(id =>
        document.getElementById(id)?.addEventListener('input', updatePreview)
    );
});
</script>
@endsection
