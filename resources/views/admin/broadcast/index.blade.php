@extends('layouts.admin')
@section('content')
<div style="max-width:700px;margin:0 auto;padding:0 16px 48px;">
    <div style="margin-bottom:24px;">
        <div class="eyebrow">Admin</div>
        <h1 class="page-title">Comunicazione massiva</h1>
        <p class="subtle">Invia un messaggio email e/o in-app a un segmento di aziende.</p>
    </div>

    @if(session('success'))
        <div class="alert success" style="margin-bottom:20px;">{{ session('success') }}</div>
    @endif

    <section class="card card-pad">
        <form method="POST" action="{{ route('admin.broadcast.send') }}" onsubmit="return confirmSend()">
            @csrf

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Segmento destinatari</label>
                <select name="segment" id="segment_select" class="form-control">
                    @foreach($segments as $value => $label)
                        <option value="{{ $value }}" {{ old('segment') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <div id="segment_preview" style="margin-top:8px;font-size:13px;color:var(--ink-soft);min-height:18px;"></div>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Canali</label>
                <div style="display:flex;gap:20px;margin-top:6px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="channels[]" value="email" checked> Email
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="channels[]" value="in_app" checked> Notifica in-app
                    </label>
                </div>
                @error('channels')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Oggetto</label>
                <input type="text" name="subject" class="form-control @error('subject') is-invalid @enderror"
                       value="{{ old('subject') }}" required maxlength="200">
                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label">Messaggio</label>
                <textarea name="body" class="form-control @error('body') is-invalid @enderror"
                          rows="8" required maxlength="5000">{{ old('body') }}</textarea>
                <div style="text-align:right;font-size:12px;color:var(--ink-muted);margin-top:4px;">
                    <span id="char_count">0</span>/5000 caratteri
                </div>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13.5px;">
                ⚠️ <strong>Attenzione:</strong> Questa azione invierà messaggi reali agli utenti. Verifica il segmento prima di inviare.
            </div>

            <button type="submit" class="cta" style="background:#dc2626;">Invia comunicazione</button>
        </form>
    </section>
</div>

<script>
var previewUrl = '{{ route('admin.broadcast.preview') }}';
var segmentEl = document.getElementById('segment_select');
var previewEl = document.getElementById('segment_preview');
var bodyEl    = document.querySelector('textarea[name=body]');
var countEl   = document.getElementById('char_count');

function loadPreview() {
    var seg = segmentEl.value;
    previewEl.textContent = 'Caricamento...';
    fetch(previewUrl + '?segment=' + encodeURIComponent(seg))
        .then(r => r.json())
        .then(d => {
            previewEl.innerHTML = '<strong>' + d.count + '</strong> aziende selezionate'
                + (d.preview.length ? ' (' + d.preview.join(', ') + (d.count > 5 ? '...' : '') + ')' : '');
        })
        .catch(() => { previewEl.textContent = ''; });
}

segmentEl.addEventListener('change', loadPreview);
loadPreview();

bodyEl.addEventListener('input', function() {
    countEl.textContent = this.value.length;
});

function confirmSend() {
    var seg = segmentEl.value;
    return confirm('Confermi l\'invio del messaggio al segmento selezionato?');
}
</script>
@endsection
