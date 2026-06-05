@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="max-width:680px;">
    <form method="POST" action="{{ route('portal.scheduled-payments.store') }}">
        @csrf

        {{-- Destinatario --}}
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Azienda destinataria *</label>
            <select name="to_account_id" class="form-control" required>
                <option value="">— Seleziona un'azienda —</option>
                @foreach($counterparties as $acc)
                    <option value="{{ $acc->id }}" {{ old('to_account_id') == $acc->id ? 'selected' : '' }}>
                        {{ $acc->company?->name ?? $acc->display_name }}
                        ({{ $acc->account_number ?? '#'.$acc->id }})
                    </option>
                @endforeach
            </select>
            @error('to_account_id')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        {{-- Importo --}}
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Importo (KY) *</label>
            <div style="display:flex;align-items:center;gap:8px;">
                <input type="number" name="amount" value="{{ old('amount') }}"
                       min="0.01" max="9999999" step="0.01" placeholder="es. 100,00"
                       class="form-control" style="max-width:200px;" required>
                <span style="font-size:13px;color:var(--ink-muted);">KY</span>
            </div>
            @error('amount')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        {{-- Descrizione --}}
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Descrizione / Causale *</label>
            <input type="text" name="description" value="{{ old('description') }}"
                   maxlength="500" placeholder="es. Saldo acconto contratto novembre"
                   class="form-control" required>
            @error('description')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        {{-- Riga data + ricorrenza (affiancati su desktop, sovrapposti su mobile) --}}
        <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;margin-bottom:24px;
                    padding:14px 16px;background:var(--surface-soft);border-radius:10px;">

            {{-- Colonna sinistra: data + toggle --}}
            <div style="flex:0 0 auto;min-width:220px;">
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" id="date-label">Data e ora di esecuzione *</label>
                    <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                           value="{{ old('scheduled_at') }}"
                           min="{{ now()->addMinutes(5)->format('Y-m-d\TH:i') }}"
                           class="form-control" required>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;" id="date-hint">
                        Minimo 5 minuti nel futuro.
                    </div>
                    @error('scheduled_at')<div class="form-error">{{ $message }}</div>@enderror
                </div>

                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:14px;">
                    <input type="checkbox" name="is_recurring" id="is_recurring" value="1"
                           {{ old('is_recurring') ? 'checked' : '' }}
                           onchange="toggleRecurring(this.checked)"
                           style="width:16px;height:16px;accent-color:var(--primary);">
                    Pagamento ricorrente
                </label>
                <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;margin-left:24px;">
                    Genera più rate automaticamente.
                </div>
            </div>

            {{-- Colonna destra: opzioni ricorrenza (visibile solo se toggle attivo) --}}
            <div id="recurrence-fields"
                 style="display:none;flex:1;min-width:200px;border-left:2px solid var(--border);padding-left:16px;">

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Frequenza</label>
                    <select name="recurrence_type" id="recurrence_type" class="form-control">
                        <option value="monthly"  {{ old('recurrence_type', 'monthly') === 'monthly'  ? 'selected' : '' }}>Mensile</option>
                        <option value="weekly"   {{ old('recurrence_type') === 'weekly'   ? 'selected' : '' }}>Settimanale</option>
                        <option value="biweekly" {{ old('recurrence_type') === 'biweekly' ? 'selected' : '' }}>Bisettimanale</option>
                    </select>
                    @error('recurrence_type')<div class="form-error">{{ $message }}</div>@enderror
                </div>

                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label">Numero di rate *</label>
                    <input type="number" name="recurrence_count" id="recurrence_count"
                           value="{{ old('recurrence_count', 3) }}"
                           min="2" max="60" step="1"
                           class="form-control" style="max-width:120px;">
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">Min 2, max 60</div>
                    @error('recurrence_count')<div class="form-error">{{ $message }}</div>@enderror
                </div>

                <div id="rate-preview" style="font-size:13px;font-weight:600;display:none;padding:6px 10px;border-radius:6px;background:var(--primary-soft,#ede9fe);color:var(--primary);margin-bottom:10px;">
                    <!-- aggiornato via JS -->
                </div>

                {{-- Tabella dettaglio rate --}}
                <div id="rate-table" style="display:none;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary);margin-bottom:5px;">Piano rate</div>
                    <div style="max-height:200px;overflow-y:auto;border-radius:8px;border:1px solid var(--border);">
                        <table style="width:100%;border-collapse:collapse;font-size:12px;">
                            <thead>
                                <tr style="background:var(--primary-soft,#ede9fe);position:sticky;top:0;">
                                    <th style="padding:5px 8px;text-align:left;font-weight:700;color:var(--primary);">#</th>
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

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">Programma pagamento</button>
            <a href="{{ route('portal.scheduled-payments.index') }}" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</div>

<script>
function toggleRecurring(active) {
    const fields    = document.getElementById('recurrence-fields');
    const dateLabel = document.getElementById('date-label');
    const dateHint  = document.getElementById('date-hint');
    const countInput = document.getElementById('recurrence_count');
    const recType    = document.getElementById('recurrence_type');

    fields.style.display = active ? 'block' : 'none';

    dateLabel.textContent = active ? 'Data prima rata *' : 'Data e ora di esecuzione *';
    dateHint.textContent  = active
        ? 'Data e ora della prima rata.'
        : 'Minimo 5 minuti nel futuro.';

    countInput.required = active;
    recType.required = active;

    if (active) updatePreview();
}

function updatePreview() {
    const startVal  = document.getElementById('scheduled_at').value;
    const count     = parseInt(document.getElementById('recurrence_count').value) || 0;
    const type      = document.getElementById('recurrence_type').value;
    const amountVal = parseFloat(document.getElementById('amount')?.value) || 0;
    const preview   = document.getElementById('rate-preview');
    const tableWrap = document.getElementById('rate-table');
    const tbody     = document.getElementById('rate-table-body');

    if (!startVal || count < 2) {
        preview.style.display = 'none';
        tableWrap.style.display = 'none';
        return;
    }
    if (count > 60) {
        preview.textContent = 'Max 60 rate.';
        preview.style.color = 'var(--danger)';
        preview.style.background = 'var(--danger-soft,#fee2e2)';
        preview.style.display = 'block';
        tableWrap.style.display = 'none';
        return;
    }

    // Riepilogo testuale
    let cur = new Date(startVal);
    const dates = [new Date(cur)];
    for (let i = 1; i < count; i++) {
        cur = type === 'monthly'  ? addMonths(cur, 1)
            : type === 'weekly'   ? new Date(cur.getTime() + 7  * 86400000)
            :                       new Date(cur.getTime() + 14 * 86400000);
        dates.push(new Date(cur));
    }
    const lastDate = dates[dates.length - 1].toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const label = { monthly: 'mensili', weekly: 'settimanali', biweekly: 'bisettimanali' }[type] || '';
    preview.textContent   = `${count} rate ${label} — ultima il ${lastDate}`;
    preview.style.color   = 'var(--primary)';
    preview.style.background = 'var(--primary-soft,#ede9fe)';
    preview.style.display = 'block';

    // Tabella dettaglio
    tbody.innerHTML = '';
    const amtFmt = (v) => v.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    dates.forEach((d, i) => {
        const tr = document.createElement('tr');
        tr.style.background = i % 2 === 0 ? '#faf5ff' : '#f3e8ff';
        const dateStr = d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
        const amtStr  = amountVal > 0 ? amtFmt(amountVal) : '—';
        tr.innerHTML = `<td style="padding:4px 8px;">${i+1}</td>`
            + `<td style="padding:4px 8px;">${dateStr}</td>`
            + `<td style="padding:4px 8px;text-align:right;font-weight:600;">${amtStr}</td>`;
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
    ['scheduled_at', 'recurrence_count', 'recurrence_type', 'amount'].forEach(id =>
        document.getElementById(id)?.addEventListener('input', updatePreview)
    );
});
</script>
@endsection
