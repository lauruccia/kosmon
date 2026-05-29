@extends('layouts.portal')

@section('content')

<div style="max-width:760px; margin:0 auto; padding:8px 0 48px;">

    {{-- Flash --}}
    @if(session('success'))
        <div style="background:var(--success-soft);border:1px solid #a7f3d0;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:var(--success);">
            ✓ {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div style="background:var(--danger-soft);border:1px solid #fecdd3;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:var(--danger);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Header --}}
    <div style="margin-bottom:28px;">
        <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin-bottom:4px;">Brand &amp; Identità</h1>
        <p style="font-size:14px;color:var(--ink-muted);">
            Logo, colori e testi utilizzati in tutte le email di sistema, nei PDF e nelle pagine pubbliche.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')

        {{-- ── Identità circuito ── --}}
        <div class="card card-pad" style="margin-bottom:20px;">
            <h2 style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:18px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:18px;">🏷️</span> Identità circuito
            </h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">
                        Nome circuito <span style="color:var(--danger)">*</span>
                    </label>
                    <input type="text" name="circuit_name" value="{{ old('circuit_name', $branding->circuit_name) }}"
                           required maxlength="80"
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;">
                    <p style="font-size:11px;color:var(--ink-muted);margin-top:4px;">Appare nel subject e header di tutte le email.</p>
                </div>

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Tagline</label>
                    <input type="text" name="circuit_tagline" value="{{ old('circuit_tagline', $branding->circuit_tagline) }}"
                           maxlength="160"
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;">
                    <p style="font-size:11px;color:var(--ink-muted);margin-top:4px;">Sottotitolo nel footer email.</p>
                </div>

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Email di contatto</label>
                    <input type="email" name="contact_email" value="{{ old('contact_email', $branding->contact_email) }}"
                           maxlength="120"
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;">
                </div>

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Telefono</label>
                    <input type="text" name="contact_phone" value="{{ old('contact_phone', $branding->contact_phone) }}"
                           maxlength="40"
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;">
                </div>

                <div style="grid-column:1/-1;">
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Sito web</label>
                    <input type="url" name="website_url" value="{{ old('website_url', $branding->website_url) }}"
                           maxlength="200" placeholder="https://..."
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;">
                </div>

                <div style="grid-column:1/-1;">
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">Testo footer email</label>
                    <input type="text" name="footer_text" value="{{ old('footer_text', $branding->footer_text) }}"
                           maxlength="255" placeholder="es. © 2025 KNM S.R.L. — P.IVA 13273091002"
                           style="width:100%;border:2px solid var(--line);border-radius:8px;padding:10px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);box-sizing:border-box;">
                </div>
            </div>
        </div>

        {{-- ── Logo ── --}}
        <div class="card card-pad" style="margin-bottom:20px;">
            <h2 style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:18px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:18px;">🖼️</span> Logo
            </h2>
            <div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap;">
                <div style="flex-shrink:0;">
                    @if($branding->logoUrl())
                        <img src="{{ $branding->logoUrl() }}" alt="Logo attuale"
                             style="height:64px;max-width:200px;object-fit:contain;border:1px solid var(--line);border-radius:10px;padding:8px;background:var(--surface-soft);">
                    @else
                        <div style="width:120px;height:64px;border:2px dashed var(--line);border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--surface-soft);">
                            <span style="font-size:24px;">🖼️</span>
                        </div>
                    @endif
                </div>
                <div style="flex:1;min-width:200px;">
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;">
                        {{ $branding->logoUrl() ? 'Sostituisci logo' : 'Carica logo' }}
                    </label>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml"
                           style="font-size:13px;color:var(--ink);">
                    <p style="font-size:11px;color:var(--ink-muted);margin-top:6px;">PNG, JPG o SVG &mdash; max 1 MB. Il logo appare nell'header di tutte le email.</p>
                </div>
            </div>
        </div>

        {{-- ── Colori ── --}}
        <div class="card card-pad" style="margin-bottom:28px;">
            <h2 style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:18px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:18px;">🎨</span> Colori brand
            </h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:8px;">
                        Colore primario
                    </label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="color"
                               value="{{ old('primary_color', $branding->primary_color) }}"
                               id="primary_color_picker"
                               style="width:44px;height:44px;border:none;padding:0;cursor:pointer;border-radius:8px;">
                        <input type="text" id="primary_color_text"
                               value="{{ old('primary_color', $branding->primary_color) }}"
                               maxlength="7" placeholder="#3d5566"
                               style="width:90px;border:2px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;font-family:monospace;background:var(--surface-soft);color:var(--ink);"
                               oninput="syncColor(this,'primary_color_picker','primary_color')">
                        <input type="hidden" name="primary_color" id="primary_color" value="{{ old('primary_color', $branding->primary_color) }}">
                    </div>
                    <p style="font-size:11px;color:var(--ink-muted);margin-top:6px;">Usato per header, pulsanti e testo chiave nelle email.</p>
                </div>

                <div>
                    <label style="font-size:13px;font-weight:600;color:var(--ink);display:block;margin-bottom:8px;">
                        Colore accent
                    </label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="color"
                               value="{{ old('accent_color', $branding->accent_color) }}"
                               id="accent_color_picker"
                               style="width:44px;height:44px;border:none;padding:0;cursor:pointer;border-radius:8px;">
                        <input type="text" id="accent_color_text"
                               value="{{ old('accent_color', $branding->accent_color) }}"
                               maxlength="7" placeholder="#4d7a52"
                               style="width:90px;border:2px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;font-family:monospace;background:var(--surface-soft);color:var(--ink);"
                               oninput="syncColor(this,'accent_color_picker','accent_color')">
                        <input type="hidden" name="accent_color" id="accent_color" value="{{ old('accent_color', $branding->accent_color) }}">
                    </div>
                    <p style="font-size:11px;color:var(--ink-muted);margin-top:6px;">Usato per bordi, evidenziazioni e call-to-action secondari.</p>
                </div>
            </div>

            {{-- Preview email --}}
            <div style="margin-top:24px;">
                <div style="font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;">Anteprima email</div>
                <div id="email-preview" style="border:1px solid var(--line);border-radius:12px;overflow:hidden;max-width:480px;">
                    <div id="prev-header" style="background:#3d5566;padding:18px 24px;display:flex;align-items:center;gap:10px;">
                        <span style="font-size:22px;font-weight:800;color:#fff;font-family:serif;" id="prev-name">KMoney</span>
                    </div>
                    <div style="padding:20px 24px;background:#fff;">
                        <div style="font-size:14px;color:#374151;margin-bottom:8px;">Ciao <strong>Nome Utente</strong>,</div>
                        <div style="font-size:13px;color:#6b7280;margin-bottom:16px;">Questo è un esempio di come appariranno le email di notifica del circuito.</div>
                        <div id="prev-btn" style="display:inline-block;background:#3d5566;color:#fff;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:700;">Vai al portale</div>
                    </div>
                    <div id="prev-footer" style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:12px 24px;font-size:11px;color:#9ca3af;" id="prev-footer-text">
                        KMoney &mdash; La moneta complementare del Gruppo Kosmos
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:12px;">
            <button type="submit" class="cta" style="padding:12px 28px;">
                💾 Salva impostazioni brand
            </button>
            <a href="{{ route('admin.dashboard') }}" class="cta secondary" style="padding:12px 24px;">
                Annulla
            </a>
        </div>
    </form>
</div>

@push('scripts')
<script>
// Sync color picker ↔ text input ↔ hidden input
document.querySelectorAll('input[type="color"]').forEach(picker => {
    picker.addEventListener('input', function() {
        const base = this.id.replace('_picker', '');
        document.getElementById(base + '_text').value = this.value;
        document.getElementById(base).value = this.value;
        updatePreview();
    });
});

function syncColor(textInput, pickerId, hiddenId) {
    const val = textInput.value;
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
        document.getElementById(pickerId).value = val;
        document.getElementById(hiddenId).value = val;
        updatePreview();
    }
}

function updatePreview() {
    const primary = document.getElementById('primary_color').value || '#3d5566';
    const name    = document.querySelector('input[name="circuit_name"]').value || 'KMoney';
    const tagline = document.querySelector('input[name="circuit_tagline"]').value || '';
    document.getElementById('prev-header').style.background = primary;
    document.getElementById('prev-btn').style.background    = primary;
    document.getElementById('prev-name').textContent        = name;
    document.getElementById('prev-footer').textContent      = name + (tagline ? ' — ' + tagline : '');
}

// Live preview on name/tagline change
document.querySelector('input[name="circuit_name"]').addEventListener('input', updatePreview);
document.querySelector('input[name="circuit_tagline"]').addEventListener('input', updatePreview);

updatePreview();
</script>
@endpush

@endsection
