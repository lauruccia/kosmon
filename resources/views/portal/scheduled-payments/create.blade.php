@extends('layouts.portal')

@section('content')

<section class="card light-card">
    <div class="section-head" style="margin-bottom:18px;">
        <div>
            <span class="eyebrow">Nuovo pagamento</span>
            <h3 class="section-title">Programma un pagamento</h3>
        </div>
        <a href="{{ route('portal.scheduled-payments.index') }}" class="cta secondary">Indietro</a>
    </div>

    @if($errors->any())
        <div style="background:#ffe4e6;border-radius:10px;padding:12px 16px;margin-bottom:16px;">
            <strong style="color:#9f1239;font-size:13px;">Correggi i seguenti errori:</strong>
            <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#9f1239;">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('portal.scheduled-payments.store') }}" id="sp-form">
        @csrf

        {{-- Riga 1: destinatario (piena larghezza) --}}
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">
                Azienda destinataria <span style="color:#dc2626;">*</span>
            </label>
            <select name="to_account_id" required
                style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font-size:14px;">
                <option value="">— Seleziona —</option>
                @foreach($counterparties as $acc)
                    <option value="{{ $acc->id }}" {{ old('to_account_id') == $acc->id ? 'selected' : '' }}>
                        {{ $acc->company?->name ?? $acc->display_name }} ({{ $acc->account_number ?? '#'.$acc->id }})
                    </option>
                @endforeach
            </select>
            @error('to_account_id')<div style="color:#dc2626;font-size:12px;margin-top:3px;">{{ $message }}</div>@enderror
        </div>

        {{-- Riga 2: importo + descrizione --}}
        <div style="display:grid;grid-template-columns:160px 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">
                    Importo (KY) <span style="color:#dc2626;">*</span>
                </label>
                <div style="position:relative;">
                    <input type="number" name="amount" id="amount" value="{{ old('amount') }}"
                           min="0.01" max="9999999" step="0.01" placeholder="0,00" required
                           style="width:100%;padding:9px 38px 9px 12px;border:1.5px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font-size:15px;font-weight:700;">
                    <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:700;color:var(--ink-muted);">KY</span>
                </div>
                @error('amount')<div style="color:#dc2626;font-size:12px;margin-top:3px;">{{ $message }}</div>@enderror
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">
                    Descrizione / Causale <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" name="description" value="{{ old('description') }}"
                       maxlength="480" placeholder="es. Saldo acconto contratto" required
                       style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font-size:14px;">
                @error('description')<div style="color:#dc2626;font-size:12px;margin-top:3px;">{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Riga 3: data + ricorrente toggle + frequenza + n.rate --}}
        <div style="background:var(--surface-soft,#f8f9fb);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
            <div style="display:grid;grid-template-columns:200px auto 1fr 120px;gap:14px;align-items:start;">

                {{-- Data --}}
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;" id="date-label">
                        Data e ora <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                           value="{{ old('scheduled_at') }}"
                           min="{{ now()->addMinutes(5)->format('Y-m-d\TH:i') }}" required
                           style="width:100%;padding:9px 10px;border:1.5px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font-size:13px;">
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;" id="date-hint">Min. 5 min. nel futuro.</div>
                    @error('scheduled_at')<div style="color:#dc2626;font-size:12px;margin-top:3px;">{{ $message }}</div>@enderror
                </div>

                {{-- Toggle ricorrente --}}
                <div style="padding-top:28px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;font-weight:600;font-size:13px;">
                        <input type="checkbox" name="is_recurring" id="is_recurring" value="1"
                               {{ old('is_recurring') ? 'checked' : '' }}
                               onchange="toggleRecurring(this.checked)"
                               style="width:16px;height:16px;accent-color:var(--primary);flex-shrink:0;">
                        Ricorrente
                    </label>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;margin-left:24px;">Più rate auto.</div>
                </div>

                {{-- Frequenza (visibile solo se ricorrente) --}}
                <div id="field-freq" style="display:none;">
                    <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Frequenza</label>
                    <select name="recurrence_type" id="recurrence_type"
                        style="width:100%;padding:9px 10px;border:1.5px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font-size:14px;">
                        <option value="monthly"  {{ old('recurrence_type','monthly') === 'monthly'  ? 'selected' : '' }}>Mensile</option>
                        <option value="weekly"   {{ old('recurrence_type') === 'weekly'   ? 'selected' : '' }}>Settimanale</option>
                        <option value="biweekly" {{ old('recurrence_type') === 'biweekly' ? 'selected' : '' }}>Bisettimanale</option>
                    </select>
                    @error('recurrence_type')<div style="color:#dc2626;font-size:12px;margin-top:3px;">{{ $message }}</div>@enderror
                </div>

                {{-- N. rate (visibile solo se ricorrente) --}}
                <div id="field-count" style="display:none;">
                    <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">
                        N. rate <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" name="recurrence_count" id="recurrence_count"
                           value="{{ old('recurrence_count', 3) }}" min="2" max="60" step="1"
                           style="width:100%;padding:9px 10px;border:1.5px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font-size:15px;font-weight:700;">
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">Min 2 · Max 60</div>
                    @error('recurrence_count')<div style="color:#dc2626;font-size:12px;margin-top:3px;">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Riepilogo + tabella (visibile solo se ricorrente) --}}
            <div id="rate-summary" style="display:none;margin-top:12px;">
                <div id="rate-preview" style="font-size:13px;font-weight:600;padding:6px 10px;border-radius:6px;background:var(--primary-soft,#ede9fe);color:var(--primary);margin-bottom:8px;display:none;"></div>
                <div id="rate-table" style="display:none;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:5px;">Piano rate</div>
                    <div style="max-height:200px;overflow-y:auto;border-radius:8px;border:1px solid var(--line);">
                        <table style="width:100%;border-collapse:collapse;font-size:12px;">
                            <thead>
                                <tr style="background:var(--primary-soft,#ede9fe);position:sticky;top:0;">
                                    <th style="padding:5px 8px;text-align:left;font-weight:700;color:var(--primary);width:36px;">#</th>
                                    <th style="padding:5px 8px;text-align:left;font-weight:700;color:var(--primary);">Data</th>
                                    <th style="padding:5px 8px;text-align:right;font-weight:700;color:var(--primary);">Importo (KY)</th>
                                </tr>
                            </thead>
                            <tbody id="rate-table-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- CTA --}}
        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="cta" style="padding:10px 28px;font-size:14px;">Programma pagamento</button>
            <a href="{{ route('portal.scheduled-payments.index') }}" class="cta secondary" style="padding:10px 20px;font-size:14px;">Annulla</a>
        </div>
    </form>
</section>

<script>
function toggleRecurring(active) {
    const dateLabel  = document.getElementById('date-label');
    const dateHint   = document.getElementById('date-hint');
    const fieldFreq  = document.getElementById('field-freq');
    const fieldCount = document.getElementById('field-count');
    const summary    = document.getElementById('rate-summary');
    const recType    = document.getElementById('recurrence_type');
    const countInput = document.getElementById('recurrence_count');

    fieldFreq.style.display  = active ? '' : 'none';
    fieldCount.style.display = active ? '' : 'none';
    summary.style.display    = active ? '' : 'none';

    dateLabel.textContent = active ? 'Data prima rata *' : 'Data e ora *';
    dateHint.textContent  = active ? 'Data/ora della prima rata.' : 'Min. 5 min. nel futuro.';

    recType.required  = active;
    countInput.required = active;

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
        preview.style.color = 'var(--danger,#dc2626)';
        preview.style.background = '#fee2e2';
        preview.style.display = 'block';
        tableWrap.style.display = 'none';
        return;
    }

    // Calcola date
    let cur = new Date(startVal);
    const dates = [new Date(cur)];
    for (let i = 1; i < count; i++) {
        cur = type === 'monthly'  ? addMonths(cur, 1)
            : type === 'weekly'   ? new Date(cur.getTime() + 7*86400000)
            :                       new Date(cur.getTime() + 14*86400000);
        dates.push(new Date(cur));
    }
    const fmt = d => d.toLocaleDateString('it-IT', {day:'2-digit',month:'2-digit',year:'numeric'});
    const amtFmt = v => v.toLocaleString('it-IT', {minimumFractionDigits:2,maximumFractionDigits:2});
    const label = {monthly:'mensili',weekly:'settimanali',biweekly:'bisettimanali'}[type]||'';

    preview.textContent = `${count} rate ${label} — ultima il ${fmt(dates[dates.length-1])}`;
    preview.style.color = 'var(--primary)';
    preview.style.background = 'var(--primary-soft,#ede9fe)';
    preview.style.display = 'block';

    tbody.innerHTML = '';
    dates.forEach((d, i) => {
        const tr = document.createElement('tr');
        tr.style.background = i % 2 === 0 ? '#faf5ff' : '#f3e8ff';
        tr.innerHTML = `<td style="padding:4px 8px;">${i+1}</td>`
            + `<td style="padding:4px 8px;">${fmt(d)}</td>`
            + `<td style="padding:4px 8px;text-align:right;font-weight:600;">${amountVal > 0 ? amtFmt(amountVal) : '—'}</td>`;
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
