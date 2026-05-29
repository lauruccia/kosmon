@extends('layouts.portal')

@section('content')
<div style="width:100%;">

    @if ($errors->any())
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:13px;">
            <strong>Correggi gli errori:</strong>
            <ul style="margin:4px 0 0;padding-left:18px;">
                @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ isset($rule) ? route('admin.cashback.update', $rule) : route('admin.cashback.store') }}">
        @csrf
        @if(isset($rule)) @method('PUT') @endif

        <div class="card" style="padding:16px 20px;">

            {{-- Riga 1: Nome + Date + Stato --}}
            <div style="display:grid;grid-template-columns:1fr auto auto auto;gap:12px 16px;align-items:end;margin-bottom:12px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Nome regola *</label>
                    <input type="text" name="name"
                           value="{{ old('name', $rule->name ?? '') }}"
                           placeholder="es. Cashback 2% ottobre 2026"
                           class="form-control" required>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Valida dal</label>
                    <input type="date" name="valid_from"
                           value="{{ old('valid_from', $rule->valid_from?->format('Y-m-d') ?? '') }}"
                           class="form-control" style="min-width:140px;">
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Valida fino al</label>
                    <input type="date" name="valid_until"
                           value="{{ old('valid_until', $rule->valid_until?->format('Y-m-d') ?? '') }}"
                           class="form-control" style="min-width:140px;">
                </div>
                <div style="padding-bottom:2px;">
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;font-weight:600;white-space:nowrap;">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                               {{ old('is_active', $rule->is_active ?? true) ? 'checked' : '' }}
                               style="width:15px;height:15px;accent-color:var(--primary);">
                        Attiva
                    </label>
                </div>
            </div>

            {{-- Riga 2: Soglia + Percentuale + Cap --}}
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px 16px;margin-bottom:12px;">
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Soglia minima (KY) *</label>
                    <input type="number" name="min_amount"
                           value="{{ old('min_amount', $rule->min_amount ?? 0) }}"
                           min="0" step="1" class="form-control" required>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">Min. per applicare la regola</div>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Percentuale (%) *</label>
                    <input type="number" name="percentage"
                           value="{{ old('percentage', isset($rule) ? number_format($rule->percentage, 2, '.', '') : '') }}"
                           min="0.01" max="100" step="0.01"
                           placeholder="es. 2.50" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" style="margin-bottom:4px;">Cap massimo (KY)</label>
                    <input type="number" name="max_cashback"
                           value="{{ old('max_cashback', $rule->max_cashback ?? '') }}"
                           min="1" step="1" placeholder="Vuoto = illimitato" class="form-control">
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">Limite per singola transazione</div>
                </div>
            </div>

            {{-- Riga 3: Tipi trasferimento + Target --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;">
                <div>
                    <label class="form-label" style="margin-bottom:6px;">Tipi di trasferimento validi *</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                        @php $selectedKinds = old('applicable_kinds', $rule->applicable_kinds ?? []); @endphp
                        @foreach($kindOptions as $value => $label)
                            <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;
                                          background:var(--surface-soft);border:1px solid var(--line);border-radius:6px;padding:5px 10px;
                                          {{ in_array($value, $selectedKinds) ? 'border-color:var(--primary);background:#eff6ff;' : '' }}">
                                <input type="checkbox" name="applicable_kinds[]" value="{{ $value }}"
                                       {{ in_array($value, $selectedKinds) ? 'checked' : '' }}
                                       style="accent-color:var(--primary);">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('applicable_kinds')
                        <div style="color:var(--danger);font-size:11px;margin-top:3px;">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label" style="margin-bottom:6px;">Applica a *</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
                        @php $selectedTarget = old('target_type', $rule->target_type ?? 'all'); @endphp
                        @foreach($targetTypeOptions as $value => $label)
                            <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;
                                          background:var(--surface-soft);border:1px solid var(--line);border-radius:6px;padding:5px 10px;
                                          {{ $selectedTarget === $value ? 'border-color:var(--primary);background:#eff6ff;' : '' }}">
                                <input type="radio" name="target_type" value="{{ $value }}"
                                       {{ $selectedTarget === $value ? 'checked' : '' }}
                                       onchange="toggleUserPicker()"
                                       style="accent-color:var(--primary);">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div id="user-picker-wrap" style="display:{{ $selectedTarget === 'specific_user' ? 'block' : 'none' }};">
                        <select name="target_user_id" class="form-control">
                            <option value="">— Seleziona utente —</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}"
                                        {{ (int) old('target_user_id', $rule->target_user_id ?? '') === $u->id ? 'selected' : '' }}>
                                    {{ $u->name }} ({{ $u->email }})
                                </option>
                            @endforeach
                        </select>
                        @error('target_user_id')
                            <div style="color:var(--danger);font-size:11px;margin-top:3px;">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

        </div>

        <div style="margin-top:10px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">
                {{ isset($rule) ? 'Salva modifiche' : 'Crea regola' }}
            </button>
            <a href="{{ route('admin.cashback.index') }}" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</div>

<script>
function toggleUserPicker() {
    const val = document.querySelector('input[name="target_type"]:checked')?.value;
    document.getElementById('user-picker-wrap').style.display = val === 'specific_user' ? 'block' : 'none';
}
</script>
@endsection
