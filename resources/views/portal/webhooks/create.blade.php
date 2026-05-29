@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="max-width:560px;">
    <form method="POST" action="{{ route('portal.webhooks.store') }}">
        @csrf

        <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">URL endpoint *</label>
            <input type="url" name="url" value="{{ old('url') }}" required
                   placeholder="https://tuosistema.com/kmoney/webhook"
                   class="form-control">
            <div style="font-size:11px;color:var(--ink-muted);margin-top:4px;">
                Deve essere HTTPS in produzione. Rispondere con HTTP 2xx entro 10 secondi.
            </div>
            @error('url')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-group" style="margin-bottom:24px;">
            <label class="form-label">Eventi da ascoltare *</label>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                @foreach($eventOptions as $key => $label)
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" name="events[]" value="{{ $key }}"
                               {{ in_array($key, old('events', [])) ? 'checked' : '' }}
                               style="width:15px;height:15px;accent-color:var(--primary);">
                        <span>
                            <strong style="font-family:monospace;font-size:12px;color:var(--primary);">{{ $key }}</strong>
                            <span style="color:var(--ink-muted);margin-left:6px;">{{ $label }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('events')<div class="form-error">{{ $message }}</div>@enderror
        </div>

        <div style="background:var(--surface-soft);border-radius:8px;padding:12px 14px;margin-bottom:20px;font-size:12px;color:var(--ink-muted);">
            <strong style="color:var(--ink);">Firma HMAC</strong> — Ogni richiesta include l'header
            <code style="font-family:monospace;font-size:11px;">X-KMoney-Signature: sha256=&lt;hash&gt;</code>.
            Il segreto di firma viene generato automaticamente e mostrato una sola volta dopo la creazione.
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Crea webhook</button>
            <a href="{{ route('portal.webhooks.index') }}" class="btn btn-secondary">Annulla</a>
        </div>
    </form>
</div>
@endsection
