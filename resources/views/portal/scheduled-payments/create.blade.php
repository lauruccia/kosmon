@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="max-width:540px;">
    <form method="POST" action="{{ route('portal.scheduled-payments.store') }}" id="scheduled-form">
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
                       min="1" max="9999999" step="1" placeholder="es. 10000"
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

        {{-- Data prima rata / data unica --}}
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label" id="date-label">Data e ora di esecuzione *</label>
            <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                   value="{{ old('scheduled_at') }}"
                   min="{{ now()->addMinutes(5)->format('Y-m-d\TH:i') }}"
                   class="form-control" style="max-width:260px;" required>
            <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;" id="date-hint">
                Minimo 5 minuti nel futuro. Il pagamento viene eseguito automaticamente entro il minuto successivo alla scadenza.
            </div>
            @error('scheduled_at')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        {{-- Toggle ricorrente --}}
        <div style="margin-bottom:20px;padding:14px 16px;background:var(--surface-soft);border-radius:10px;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;font-size:14px;">
                <input type="checkbox" name="is_recurring" id="is_recurring" value="1"
                       {{ old('is_recurring') ? 'checked' : '' }}
                       onchange="toggleRecurring(this.checked)"
                       style="width:16px;height:16px;accent-color:var(--primary);">
                Pagamento ricorrente
            </label>
            <div style="font-size:12px;color:var(--ink-muted);margin-top:4px;margin-left:26px;">
                Genera automaticamente più rate alle date indicate.
            </div>
        </div>

        {{-- Sezione ricorrenza (visibile solo se toggle attivo) --}}
        <div id="recurrence-section" style="display:none;margin-bottom:24px;padding:16px;border:1.5px solid var(--border);border-radius:10px;">
            <div style="font-size:13px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px;">
                Impostazioni ricorrenza
            </div>

            {{-- Frequenza --}}
            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Frequenza</label>
                <select name="recurrence_type" id="recurrence_type" class="form-control" style="max-width:220px;">
                    <option value="monthly"  {{ old('recurrence_type', 'monthly') === 'monthly'  ? 'selected' : '' }}>Mensile</option>
                    <option value="weekly"   {{ old('recurrence_type') === 'weekly'   ? 'selected' : '' }}>Settimanale</option>
                    <option value="biweekly" {{ old('recurrence_type') === 'biweekly' ? 'selected' : '' }}>Bisettimanale (ogni 2 settimane)</option>
                </select>
                @error('recurrence_type')<div class="form-error">{{ $message }}</div>@enderror
            </div>

            {{-- Data fine --}}
            <div class="form-group" style="margin-bottom:4px;">
                <label class="form-label">Data ultima rata *</label>
                <input type="date" name="recurrence_end_date" id="recurrence_end_date"
                       value="{{ old('recurrence_end_date') }}"
                       class="form-control" style="max-width:200px;">
                <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;">
                    Le rate vengono generate dalla data di inizio fino a questa data inclusa.
                </div>
                @error('recurrence_end_date')<div class="form-error">{{ $message }}</div>@enderror
            </div>

            {{-- Preview conteggio rate --}}
            <div id="rate-preview" style="margin-top:12px;font-size:13px;color:var(--primary);font-weight:600;display:none;">
                <!-- aggiornato via JS -->
            </div>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Programma pagamento</button>
            <a href="{{ route('portal.scheduled-payments.index') }}" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</div>

<script>
function toggleRecurring(active) {
    const section = document.getElementById('recurrence-section');
    const dateLabel = document.getElementById('date-label');
    const dateHint  = document.getElementById('date-hint');
    const endDate   = document.getElementById('recurrence_end_date');
    const recType   = document.getElementById('recurrence_type');

    section.style.display = active ? 'block' : 'none';
    dateLabel.textContent = active ? 'Data prima rata *' : 'Data e ora di esecuzione *';
    dateHint.textContent  = active
        ? 'Data e ora della prima rata. Le successive saranno generate automaticamente.'
        : 'Minimo 5 minuti nel futuro. Il pagamento viene eseguito automaticamente entro il minuto successivo alla scadenza.';

    endDate.required = active;
    recType.required = active;

    if (active) updatePreview();
}

function updatePreview() {
    const startVal = document.getElementById('scheduled_at').value;
    const endVal   = document.getElementById('recurrence_end_date').value;
    const type     = document.getElementById('recurrence_type').value;
    const preview  = document.getElementById('rate-preview');

    if (!startVal || !endVal) { preview.style.display = 'none'; return; }

    const start = new Date(startVal);
    const end   = new Date(endVal + 'T23:59:59');
    if (start >= end) { preview.style.display = 'none'; return; }

    let count = 0;
    let cur = new Date(start);

    while (cur <= end && count < 61) {
        count++;
        if (type === 'monthly') {
            cur = addMonths(cur, 1);
        } else if (type === 'weekly') {
            cur = new Date(cur.getTime() + 7 * 86400000);
        } else {
            cur = new Date(cur.getTime() + 14 * 86400000);
        }
    }

    if (count === 0) {
        preview.style.display = 'none';
        return;
    }

    const label = { monthly: 'mensile', weekly: 'settimanale', biweekly: 'bisettimanale' }[type] || '';
    preview.textContent = count <= 60
        ? `Verranno create ${count} rate (${label}).`
        : 'Troppo intervallo: massimo 60 rate.';
    preview.style.color = count > 60 ? 'var(--danger)' : 'var(--primary)';
    preview.style.display = 'block';
}

function addMonths(date, n) {
    const d = new Date(date);
    const day = d.getDate();
    d.setMonth(d.getMonth() + n);
    // gestione overflow mese (es. 31 gen → 28 feb)
    if (d.getDate() < day) d.setDate(0);
    return d;
}

// Inizializzazione al caricamento
document.addEventListener('DOMContentLoaded', () => {
    const recurring = document.getElementById('is_recurring');
    if (recurring.checked) toggleRecurring(true);

    ['scheduled_at', 'recurrence_end_date', 'recurrence_type'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', updatePreview);
    });
});
</script>
@endsection
