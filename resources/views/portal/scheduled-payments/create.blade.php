@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="max-width:540px;">
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

        {{-- Data e ora --}}
        <div class="form-group" style="margin-bottom:24px;">
            <label class="form-label">Data e ora di esecuzione *</label>
            <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}"
                   min="{{ now()->addMinutes(5)->format('Y-m-d\TH:i') }}"
                   class="form-control" style="max-width:260px;" required>
            <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;">
                Minimo 5 minuti nel futuro. Il pagamento viene eseguito automaticamente entro il minuto successivo alla scadenza.
            </div>
            @error('scheduled_at')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Programma pagamento</button>
            <a href="{{ route('portal.scheduled-payments.index') }}" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</div>
@endsection
