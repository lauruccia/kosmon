@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="max-width:540px;">
    <form method="POST" action="{{ route('portal.text-requests.store') }}">
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
            @error('to_account_id')
                <div class="form-error">{{ $message }}</div>
            @enderror
        </div>

        {{-- Importo --}}
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Importo (KY) *</label>
            <div style="display:flex;align-items:center;gap:8px;">
                <input type="number" name="amount" value="{{ old('amount') }}"
                       min="0.01" max="9999999" step="0.01"
                       placeholder="es. 50,00"
                       class="form-control" style="max-width:200px;" required>
                <span style="font-size:13px;color:var(--ink-muted);">KY</span>
            </div>
            @error('amount')
                <div class="form-error">{{ $message }}</div>
            @enderror
        </div>

        {{-- Causale --}}
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Causale *</label>
            <input type="text" name="causale" value="{{ old('causale') }}"
                   maxlength="500" placeholder="es. Acconto fornitura materiali ottobre"
                   class="form-control" required>
            @error('causale')
                <div class="form-error">{{ $message }}</div>
            @enderror
        </div>

        {{-- Scadenza --}}
        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Scadenza (opzionale)</label>
            <input type="date" name="due_date" value="{{ old('due_date') }}"
                   min="{{ now()->addDay()->format('Y-m-d') }}"
                   class="form-control" style="max-width:200px;">
            <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;">
                Se impostata, la richiesta risulterà scaduta dopo questa data.
            </div>
            @error('due_date')
                <div class="form-error">{{ $message }}</div>
            @enderror
        </div>

        {{-- Note --}}
        <div class="form-group" style="margin-bottom:24px;">
            <label class="form-label">Note aggiuntive (opzionale)</label>
            <textarea name="note" rows="3" maxlength="1000"
                      placeholder="Informazioni aggiuntive per il destinatario..."
                      class="form-control">{{ old('note') }}</textarea>
            @error('note')
                <div class="form-error">{{ $message }}</div>
            @enderror
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Invia richiesta</button>
            <a href="{{ route('portal.text-requests.index') }}" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</div>
@endsection
