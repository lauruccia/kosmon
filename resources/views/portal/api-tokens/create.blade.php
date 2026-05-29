@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="max-width:480px;">
    <form method="POST" action="{{ route('portal.api-tokens.store') }}">
        @csrf

        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Nome descrittivo *</label>
            <input type="text" name="name" value="{{ old('name') }}" required
                   placeholder="es. Integrazione gestionale"
                   maxlength="100" class="form-control">
            @error('name')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Permessi *</label>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;">
                    <input type="checkbox" name="abilities[]" value="read"
                           {{ in_array('read', old('abilities', ['read'])) ? 'checked' : '' }}
                           style="width:15px;height:15px;margin-top:2px;accent-color:var(--primary);">
                    <span>
                        <strong>read</strong>
                        <span style="color:var(--ink-muted);margin-left:4px;">— Legge saldo e lista trasferimenti</span>
                    </span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;">
                    <input type="checkbox" name="abilities[]" value="write"
                           {{ in_array('write', old('abilities', [])) ? 'checked' : '' }}
                           style="width:15px;height:15px;margin-top:2px;accent-color:var(--primary);">
                    <span>
                        <strong>write</strong>
                        <span style="color:var(--ink-muted);margin-left:4px;">— Avvia pagamenti via API (richiede anche <em>read</em>)</span>
                    </span>
                </label>
            </div>
            @error('abilities')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-group" style="margin-bottom:24px;">
            <label class="form-label">Scadenza (opzionale)</label>
            <input type="date" name="expires_at" value="{{ old('expires_at') }}"
                   min="{{ now()->addDay()->format('Y-m-d') }}"
                   class="form-control" style="max-width:200px;">
            <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;">Lascia vuoto per nessuna scadenza.</div>
            @error('expires_at')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Genera token</button>
            <a href="{{ route('portal.api-tokens.index') }}" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</div>
@endsection
