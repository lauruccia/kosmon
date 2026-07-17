@extends('layouts.portal')

@section('content')
<style>
    .profile-edit-grid {
        display: grid;
        grid-template-columns: minmax(0,1fr) 320px;
        gap: 24px;
        align-items: start;
    }
    @media(max-width:960px) {
        .profile-edit-grid { grid-template-columns: 1fr; }
    }

    .profile-section {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-xs);
        overflow: hidden;
    }
    .profile-section-header {
        padding: 18px 22px 14px;
        border-bottom: 1px solid var(--line);
        display: flex; align-items: center; gap: 10px;
    }
    .profile-section-icon {
        width: 32px; height: 32px; border-radius: 9px;
        background: var(--primary-faint);
        display: grid; place-items: center;
        font-size: 14px;
    }
    .profile-section-title {
        font-size: 14px; font-weight: 700; color: var(--ink);
        margin: 0;
    }
    .profile-section-body { padding: 20px 22px; }

    .field-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }
    @media(max-width:620px) { .field-row { grid-template-columns: 1fr; } }

    .char-counter {
        font-size: 11px; color: var(--ink-muted);
        text-align: right; margin-top: 3px;
    }
    .char-counter.warn { color: #dc2626; }

    /* Completeness widget */
    .completeness-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-xs);
        padding: 20px;
        position: sticky;
        top: 24px;
    }
    .completeness-title {
        font-size: 13px; font-weight: 700; color: var(--ink);
        margin: 0 0 14px;
    }
    .completeness-bar-wrap {
        background: var(--line);
        border-radius: 99px;
        height: 8px;
        margin-bottom: 16px;
        overflow: hidden;
    }
    .completeness-bar-fill {
        height: 100%;
        border-radius: 99px;
        background: linear-gradient(90deg, #059669, #10b981);
        transition: width .4s ease;
    }
    .completeness-items { display: grid; gap: 8px; }
    .completeness-item {
        display: flex; align-items: center; gap: 9px;
        font-size: 12.5px; color: var(--ink-soft);
    }
    .completeness-item.done { color: #059669; }
    .completeness-item.done .ci-icon { background: #d1fae5; color: #059669; }
    .completeness-item .ci-icon {
        flex-shrink: 0;
        width: 20px; height: 20px; border-radius: 6px;
        background: var(--surface-soft); border: 1px solid var(--line);
        display: grid; place-items: center;
        font-size: 11px; font-weight: 700;
    }
    .completeness-percent {
        font-size: 28px; font-weight: 800; color: var(--ink);
        letter-spacing: -.03em;
        margin-bottom: 4px;
    }

    .preview-card {
        margin-top: 20px;
        border: 1px solid var(--line);
        border-radius: 14px;
        overflow: hidden;
        background: #fff;
    }
    .preview-cover {
        height: 80px;
        background: linear-gradient(150deg, #174d87 0%, #071d35 100%);
        position: relative; overflow: hidden;
    }
    .preview-cover-deco {
        position: absolute; top:-20px; right:-20px;
        width:80px; height:80px; border-radius:50%;
        background:radial-gradient(circle,rgba(255,255,255,.15),transparent 70%);
    }
    .preview-logo-ring { position:relative; height:0; z-index:3; }
    .preview-logo {
        position:absolute; top:-18px; left:12px;
        width:36px; height:36px; border-radius:50%;
        background:#fff; border:2px solid #fff;
        box-shadow:0 2px 8px rgba(0,0,0,.18);
        display:flex; align-items:center; justify-content:center;
        font-size:15px; font-weight:900; color:#174d87;
    }
    .preview-body {
        padding: 24px 12px 12px;
    }
    .preview-name { font-size:13px; font-weight:800; color:var(--ink); margin:0 0 2px; }
    .preview-sector { font-size:10px; font-weight:600; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.05em; }
    .preview-tagline { font-size:11px; color:var(--ink-soft); margin-top:4px; font-style:italic; }

    .social-prefix {
        display: flex; align-items: center;
        padding: 0 10px;
        background: var(--surface-soft);
        border: 1px solid var(--line);
        border-right: none;
        border-radius: var(--radius) 0 0 var(--radius);
        font-size: 11px; font-weight: 700; color: var(--ink-muted);
        white-space: nowrap; height: 100%;
    }
    .social-input-wrap {
        display: flex; align-items: stretch;
    }
    .social-input-wrap input {
        border-radius: 0 var(--radius) var(--radius) 0 !important;
    }

    /* ── Image upload zones ── */
    .upload-zone {
        position: relative;
        border: 2px dashed var(--line);
        border-radius: var(--radius);
        background: var(--surface-soft);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 8px; cursor: pointer;
        transition: border-color .2s, background .2s;
        overflow: hidden;
    }
    .upload-zone:hover { border-color: var(--primary); background: var(--primary-faint); }
    .upload-zone input[type=file] {
        position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }
    .upload-zone-icon { font-size: 22px; }
    .upload-zone-label { font-size: 12.5px; font-weight: 600; color: var(--ink-muted); }
    .upload-zone-hint  { font-size: 11px; color: var(--ink-muted); }
    .upload-preview {
        width: 100%; height: 100%; object-fit: cover;
        position: absolute; inset: 0; border-radius: calc(var(--radius) - 2px);
        pointer-events: none;
    }
    .upload-remove {
        position: absolute; top: 6px; right: 6px; z-index: 2;
        width: 22px; height: 22px; border-radius: 50%;
        background: rgba(0,0,0,.55); border: none; cursor: pointer;
        display: none; align-items: center; justify-content: center;
        font-size: 11px; color: #fff; font-weight: 700;
    }
    .upload-zone.has-image .upload-remove { display: flex; }
    .upload-zone.has-image .upload-zone-icon,
    .upload-zone.has-image .upload-zone-label,
    .upload-zone.has-image .upload-zone-hint { display: none; }

    .upload-grid {
        display: grid;
        grid-template-columns: 100px 1fr;
        gap: 16px;
        align-items: start;
    }
    .logo-zone { width: 100px; height: 100px; border-radius: 50% !important; }
    .banner-zone { height: 100px; }

    /* ── Accettazione Kmoney ── */
    .ky-pct-group { display:flex; gap:8px; flex-wrap:wrap; }
    .ky-pct-pill {
        position:relative; cursor:pointer;
        border:1.5px solid var(--line); border-radius:99px;
        padding:8px 18px; font-size:13px; font-weight:700; color:var(--ink-soft);
        background:var(--surface-soft);
        transition:all .15s;
    }
    .ky-pct-pill:hover { border-color:var(--primary); color:var(--primary); }
    .ky-pct-pill input { position:absolute; opacity:0; pointer-events:none; }
    .ky-pct-pill:has(input:checked) {
        background:var(--primary); border-color:var(--primary); color:#fff;
    }
</style>

<form method="POST" action="{{ route('portal.profile.update') }}" id="profile-form" enctype="multipart/form-data">
    @csrf

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:20px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-error" style="margin-bottom:20px;">
            Controlla i campi evidenziati.
        </div>
    @endif

    <div class="profile-edit-grid">

        {{-- ── COLONNA SINISTRA ── --}}
        <div style="display:grid;gap:20px;">

            {{-- Info base --}}
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon">🏢</div>
                    <h2 class="profile-section-title">Informazioni base</h2>
                </div>
                <div class="profile-section-body">

                    <div class="field-row">
                        <div class="field">
                            <label for="sector">Settore / categoria</label>
                            @php
                                $currentSector = old('sector', $company->sector);
                            @endphp
                            <select id="sector" name="sector">
                                <option value="">— Seleziona un settore —</option>
                                @foreach($sectors as $s)
                                    <option value="{{ $s['name'] }}" @selected($currentSector === $s['name'])>{{ $s['label'] }}</option>
                                @endforeach
                                @if($currentSector && ! collect($sectors)->contains('name', $currentSector))
                                    <option value="{{ $currentSector }}" selected>{{ $currentSector }}</option>
                                @endif
                            </select>
                            @error('sector')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="field">
                            <label for="city">Città / zona</label>
                            <input type="text" id="city" name="city"
                                   value="{{ old('city', $company->city) }}"
                                   placeholder="es. Milano, Napoli, Roma Sud…"
                                   maxlength="100">
                            @error('city')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="field" style="margin-top:14px;">
                        <label for="tagline">Tagline <span style="font-weight:400;color:var(--ink-muted)">(max 160 caratteri)</span></label>
                        <input type="text" id="tagline" name="tagline"
                               value="{{ old('tagline', $company->tagline) }}"
                               placeholder="Una frase che descrive la tua azienda in modo memorabile"
                               maxlength="160"
                               oninput="updateCounter('tagline','tagline-counter',160)">
                        <div class="char-counter" id="tagline-counter">{{ strlen($company->tagline ?? '') }}/160</div>
                        @error('tagline')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="field" style="margin-top:14px;">
                        <label for="description">Descrizione <span style="font-weight:400;color:var(--ink-muted)">(max 2000 caratteri)</span></label>
                        <textarea id="description" name="description" rows="5"
                                  placeholder="Racconta chi siete, cosa offrite e perché le altre aziende del circuito dovrebbero scegliervi."
                                  maxlength="2000"
                                  oninput="updateCounter('description','desc-counter',2000)"
                                  style="resize:vertical;">{{ old('description', $company->description) }}</textarea>
                        <div class="char-counter" id="desc-counter">{{ strlen($company->description ?? '') }}/2000</div>
                        @error('description')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                </div>
            </div>

            {{-- Accettazione Kmoney --}}
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon">💠</div>
                    <h2 class="profile-section-title">Accettazione Kmoney</h2>
                </div>
                <div class="profile-section-body">
                    @if($kyPercentageLocked)
                        <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px 14px;font-size:12.5px;line-height:1.6;">
                            ⚡ Il saldo del tuo conto è <strong>sotto zero</strong>: finché non torna positivo accetti sempre pagamenti al <strong>100% Kmoney</strong> e questa impostazione non è modificabile.
                        </div>
                    @else
                        <p style="font-size:12.5px;color:var(--ink-muted);margin:0 0 14px;line-height:1.6;">
                            Indica la percentuale del prezzo che accetti in <strong>Kmoney</strong> (il resto in euro).
                            La percentuale è visibile sulla tua card nella directory delle aziende:
                            chi accetta la % più alta ottiene <strong>maggiore visibilità</strong>.
                            Se nello shop carichi prodotti con una % più alta, sulla card viene mostrata in automatico la % migliore.
                        </p>
                        @php $currentKyPct = old('accepted_ky_percentage', $company->accepted_ky_percentage); @endphp
                        <div class="ky-pct-group">
                            @foreach($acceptedKyPercentages as $pct)
                                <label class="ky-pct-pill">
                                    <input type="radio" name="accepted_ky_percentage" value="{{ $pct }}"
                                           @checked($currentKyPct !== null && (int) $currentKyPct === $pct)>
                                    <span>{{ $pct }}%</span>
                                </label>
                            @endforeach
                        </div>
                        @error('accepted_ky_percentage')<span class="field-error">{{ $message }}</span>@enderror
                    @endif
                </div>
            </div>

            {{-- Contatti --}}
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon">📞</div>
                    <h2 class="profile-section-title">Contatti pubblici</h2>
                </div>
                <div class="profile-section-body">
                    <p style="font-size:12.5px;color:var(--ink-muted);margin:0 0 16px;">
                        Questi dati sono visibili alle altre aziende del circuito nella directory e nel tuo profilo pubblico.
                    </p>

                    <div class="field-row">
                        <div class="field">
                            <label for="website">Sito web</label>
                            <input type="url" id="website" name="website"
                                   value="{{ old('website', $company->website) }}"
                                   placeholder="https://www.esempio.it">
                            @error('website')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="field">
                            <label for="phone">Telefono</label>
                            <input type="text" id="phone" name="phone"
                                   value="{{ old('phone', $company->phone) }}"
                                   placeholder="+39 02 1234567">
                            @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="field" style="margin-top:14px;">
                        <label for="email">Email di contatto pubblico</label>
                        <input type="email" id="email" name="email"
                               value="{{ old('email', $company->email) }}"
                               placeholder="info@tuaazienda.it">
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                </div>
            </div>

            {{-- Social --}}
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon">🔗</div>
                    <h2 class="profile-section-title">Social &amp; web</h2>
                </div>
                <div class="profile-section-body">

                    <div class="field">
                        <label for="linkedin_url">LinkedIn</label>
                        <input type="url" id="linkedin_url" name="linkedin_url"
                               value="{{ old('linkedin_url', $company->linkedin_url) }}"
                               placeholder="https://www.linkedin.com/company/nome">
                        @error('linkedin_url')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="field" style="margin-top:14px;">
                        <label for="instagram_url">Instagram</label>
                        <input type="url" id="instagram_url" name="instagram_url"
                               value="{{ old('instagram_url', $company->instagram_url) }}"
                               placeholder="https://www.instagram.com/nomeprofilo">
                        @error('instagram_url')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="field" style="margin-top:14px;">
                        <label for="facebook_url">Facebook</label>
                        <input type="url" id="facebook_url" name="facebook_url"
                               value="{{ old('facebook_url', $company->facebook_url) }}"
                               placeholder="https://www.facebook.com/nomepagina">
                        @error('facebook_url')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                </div>
            </div>

            {{-- Immagini --}}
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon">🖼</div>
                    <h2 class="profile-section-title">Logo e banner</h2>
                </div>
                <div class="profile-section-body">
                    <p style="font-size:12.5px;color:var(--ink-muted);margin:0 0 18px;line-height:1.6;">
                        Il <strong>logo</strong> (quadrato, min 200×200px) sostituisce la lettera nella card.<br>
                        Il <strong>banner</strong> (orizzontale, min 800×200px) sostituisce il gradiente colorato.
                    </p>

                    <div class="upload-grid">
                        {{-- Logo --}}
                        <div>
                            <label style="font-size:12px;font-weight:700;color:var(--ink-muted);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;">Logo</label>
                            <div class="upload-zone logo-zone {{ $company->logo_path ? 'has-image' : '' }}" id="logo-zone">
                                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                                       onchange="previewImage(this,'logo-zone','logo-preview-img','logo-preview-cover')">
                                @if($company->logo_path)
                                    <img class="upload-preview" id="logo-preview-img"
                                         src="{{ Storage::disk('public')->url($company->logo_path) }}" alt="Logo">
                                @else
                                    <img class="upload-preview" id="logo-preview-img" src="" alt="" style="display:none;">
                                @endif
                                <span class="upload-zone-icon">👤</span>
                                <span class="upload-zone-label">Logo</span>
                                <button type="button" class="upload-remove" onclick="removeImage(event,'logo-zone','logo-preview-img','remove_logo','logo')">✕</button>
                            </div>
                            <input type="hidden" name="remove_logo" id="remove_logo" value="0">
                            @error('logo')<span class="field-error" style="font-size:11px;">{{ $message }}</span>@enderror
                        </div>

                        {{-- Banner --}}
                        <div>
                            <label style="font-size:12px;font-weight:700;color:var(--ink-muted);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;">Banner</label>
                            <div class="upload-zone banner-zone {{ $company->banner_path ? 'has-image' : '' }}" id="banner-zone">
                                <input type="file" name="banner" accept="image/jpeg,image/png,image/webp"
                                       onchange="previewImage(this,'banner-zone','banner-preview-img',null)">
                                @if($company->banner_path)
                                    <img class="upload-preview" id="banner-preview-img"
                                         src="{{ Storage::disk('public')->url($company->banner_path) }}" alt="Banner">
                                @else
                                    <img class="upload-preview" id="banner-preview-img" src="" alt="" style="display:none;">
                                @endif
                                <span class="upload-zone-icon">🖼</span>
                                <span class="upload-zone-label">Trascina o clicca</span>
                                <span class="upload-zone-hint">JPEG · PNG · WebP · max 4 MB</span>
                                <button type="button" class="upload-remove" onclick="removeImage(event,'banner-zone','banner-preview-img','remove_banner','banner')">✕</button>
                            </div>
                            <input type="hidden" name="remove_banner" id="remove_banner" value="0">
                            @error('banner')<span class="field-error" style="font-size:11px;">{{ $message }}</span>@enderror
                        </div>
                    </div>

                </div>
            </div>

            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <a href="{{ route('portal.companies.show', $company->slug) }}" class="cta secondary" target="_blank">
                    Vedi profilo pubblico ↗
                </a>
                <button class="cta" type="submit">Salva modifiche</button>
            </div>

        </div>

        {{-- ── COLONNA DESTRA: completeness + preview ── --}}
        <div>
            <div class="completeness-card">
                <p class="completeness-title">Completamento profilo</p>
                <div class="completeness-percent" id="cp-percent">0%</div>
                <div class="completeness-bar-wrap">
                    <div class="completeness-bar-fill" id="cp-bar" style="width:0%"></div>
                </div>
                <div class="completeness-items" id="cp-items">
                    <div class="completeness-item {{ $company->sector ? 'done' : '' }}" data-field="sector">
                        <div class="ci-icon">{{ $company->sector ? '✓' : '·' }}</div>
                        <span>Settore</span>
                    </div>
                    <div class="completeness-item {{ $company->city ? 'done' : '' }}" data-field="city">
                        <div class="ci-icon">{{ $company->city ? '✓' : '·' }}</div>
                        <span>Città</span>
                    </div>
                    <div class="completeness-item {{ $company->tagline ? 'done' : '' }}" data-field="tagline">
                        <div class="ci-icon">{{ $company->tagline ? '✓' : '·' }}</div>
                        <span>Tagline</span>
                    </div>
                    <div class="completeness-item {{ $company->description ? 'done' : '' }}" data-field="description">
                        <div class="ci-icon">{{ $company->description ? '✓' : '·' }}</div>
                        <span>Descrizione</span>
                    </div>
                    <div class="completeness-item {{ $company->website ? 'done' : '' }}" data-field="website">
                        <div class="ci-icon">{{ $company->website ? '✓' : '·' }}</div>
                        <span>Sito web</span>
                    </div>
                    <div class="completeness-item {{ $company->phone ? 'done' : '' }}" data-field="phone">
                        <div class="ci-icon">{{ $company->phone ? '✓' : '·' }}</div>
                        <span>Telefono</span>
                    </div>
                    <div class="completeness-item {{ $company->email ? 'done' : '' }}" data-field="email">
                        <div class="ci-icon">{{ $company->email ? '✓' : '·' }}</div>
                        <span>Email contatto</span>
                    </div>
                    <div class="completeness-item {{ ($company->linkedin_url || $company->instagram_url || $company->facebook_url) ? 'done' : '' }}" data-field="social">
                        <div class="ci-icon">{{ ($company->linkedin_url || $company->instagram_url || $company->facebook_url) ? '✓' : '·' }}</div>
                        <span>Almeno 1 social</span>
                    </div>
                    <div class="completeness-item {{ $company->logo_path ? 'done' : '' }}" data-field="logo">
                        <div class="ci-icon" id="ci-logo">{{ $company->logo_path ? '✓' : '·' }}</div>
                        <span>Logo</span>
                    </div>
                    <div class="completeness-item {{ $company->banner_path ? 'done' : '' }}" data-field="banner">
                        <div class="ci-icon" id="ci-banner">{{ $company->banner_path ? '✓' : '·' }}</div>
                        <span>Banner</span>
                    </div>
                </div>

                {{-- Mini card preview --}}
                <div class="preview-card" id="preview-card">
                    <div class="preview-cover" id="preview-cover"
                         style="{{ $company->banner_path ? 'background-image:url('.Storage::disk('public')->url($company->banner_path).');background-size:cover;background-position:center;' : 'background:linear-gradient(150deg,#174d87 0%,#071d35 100%);' }}">
                        <div class="preview-cover-deco"></div>
                    </div>
                    <div class="preview-logo-ring">
                        <div class="preview-logo" id="preview-logo-wrap">
                            @if($company->logo_path)
                                <img id="preview-logo-img" src="{{ Storage::disk('public')->url($company->logo_path) }}"
                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;" alt="">
                            @else
                                <span id="preview-logo-letter">{{ strtoupper(substr($company->name, 0, 1)) }}</span>
                                <img id="preview-logo-img" src="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%;" alt="">
                            @endif
                        </div>
                    </div>
                    <div class="preview-body">
                        <p class="preview-name">{{ $company->name }}</p>
                        <div class="preview-sector" id="preview-sector">{{ $company->sector ?? '—' }}</div>
                        <div class="preview-tagline" id="preview-tagline">{{ $company->tagline ? '"'.$company->tagline.'"' : 'Aggiungi una tagline…' }}</div>
                    </div>
                </div>

                <p style="font-size:11px;color:var(--ink-muted);margin:12px 0 0;text-align:center;">
                    Anteprima card nella directory
                </p>
            </div>
        </div>

    </div>
</form>

@push('scripts')
<script>
// ── Completeness live update ──────────────────────────────────────────────
const FIELDS = ['sector','city','tagline','description','website','phone','email'];
const SOCIAL = ['linkedin_url','instagram_url','facebook_url'];

function updateCounter(fieldId, counterId, max) {
    const el = document.getElementById(fieldId);
    const counter = document.getElementById(counterId);
    if (!el || !counter) return;
    const len = el.value.length;
    counter.textContent = len + '/' + max;
    counter.classList.toggle('warn', len > max * 0.9);
}

function recalcCompleteness() {
    let done = 0;
    const total = FIELDS.length + 3; // +1 social, +1 logo, +1 banner

    FIELDS.forEach(f => {
        const el = document.getElementById(f);
        const item = document.querySelector('[data-field="' + f + '"]');
        const filled = el && el.value.trim().length > 0;
        if (filled) done++;
        if (item) {
            item.classList.toggle('done', filled);
            item.querySelector('.ci-icon').textContent = filled ? '✓' : '·';
        }
    });

    // social: at least one
    const socialFilled = SOCIAL.some(f => {
        const el = document.getElementById(f);
        return el && el.value.trim().length > 0;
    });
    if (socialFilled) done++;
    const socialItem = document.querySelector('[data-field="social"]');
    if (socialItem) {
        socialItem.classList.toggle('done', socialFilled);
        socialItem.querySelector('.ci-icon').textContent = socialFilled ? '✓' : '·';
    }

    // logo
    const logoHasImage = document.getElementById('logo-zone')?.classList.contains('has-image');
    if (logoHasImage) done++;
    const logoItem = document.querySelector('[data-field="logo"]');
    if (logoItem) {
        logoItem.classList.toggle('done', !!logoHasImage);
        logoItem.querySelector('.ci-icon').textContent = logoHasImage ? '✓' : '·';
    }

    // banner
    const bannerHasImage = document.getElementById('banner-zone')?.classList.contains('has-image');
    if (bannerHasImage) done++;
    const bannerItem = document.querySelector('[data-field="banner"]');
    if (bannerItem) {
        bannerItem.classList.toggle('done', !!bannerHasImage);
        bannerItem.querySelector('.ci-icon').textContent = bannerHasImage ? '✓' : '·';
    }

    const pct = Math.round((done / total) * 100);
    document.getElementById('cp-percent').textContent = pct + '%';
    document.getElementById('cp-bar').style.width = pct + '%';
}

function updatePreview() {
    const sector = document.getElementById('sector')?.value.trim();
    const tagline = document.getElementById('tagline')?.value.trim();
    document.getElementById('preview-sector').textContent = sector || '—';
    document.getElementById('preview-tagline').textContent = tagline ? '"' + tagline + '"' : 'Aggiungi una tagline…';
}

// Wire up inputs
[...FIELDS, ...SOCIAL].forEach(f => {
    const el = document.getElementById(f);
    if (el) {
        el.addEventListener('input', () => {
            recalcCompleteness();
            updatePreview();
        });
    }
});

// ── Image upload helpers ────────────────────────────────────────────────
function previewImage(input, zoneId, imgId, coverPreviewId) {
    if (!input.files || !input.files[0]) return;
    const zone = document.getElementById(zoneId);
    const img  = document.getElementById(imgId);
    const reader = new FileReader();
    reader.onload = e => {
        img.src = e.target.result;
        img.style.display = 'block';
        if (imgId === 'logo-preview-img') {
            const letter = document.getElementById('preview-logo-letter');
            const previewImg = document.getElementById('preview-logo-img');
            if (letter) letter.style.display = 'none';
            if (previewImg) { previewImg.src = e.target.result; previewImg.style.display = 'block'; }
        }
        if (imgId === 'banner-preview-img') {
            const cover = document.getElementById('preview-cover');
            if (cover) { cover.style.backgroundImage = 'url(' + e.target.result + ')'; cover.style.backgroundSize = 'cover'; cover.style.backgroundPosition = 'center'; }
        }
        zone.classList.add('has-image');
        // Clear remove flag
        const removeField = zoneId === 'logo-zone' ? 'remove_logo' : 'remove_banner';
        const removeEl = document.getElementById(removeField);
        if (removeEl) removeEl.value = '0';
        recalcCompleteness();
    };
    reader.readAsDataURL(input.files[0]);
}

function removeImage(evt, zoneId, imgId, removeFieldId, fileInputName) {
    evt.preventDefault(); evt.stopPropagation();
    const zone = document.getElementById(zoneId);
    const img  = document.getElementById(imgId);
    // Reset file input
    const fileInput = zone.querySelector('input[type=file]');
    if (fileInput) { fileInput.value = ''; }
    img.src = ''; img.style.display = 'none';
    // Clear preview in mini card
    if (imgId === 'logo-preview-img') {
        const letter = document.getElementById('preview-logo-letter');
        const previewImg = document.getElementById('preview-logo-img');
        if (letter) letter.style.display = '';
        if (previewImg) { previewImg.src = ''; previewImg.style.display = 'none'; }
    }
    if (imgId === 'banner-preview-img') {
        const cover = document.getElementById('preview-cover');
        if (cover) { cover.style.backgroundImage = ''; cover.style.background = 'linear-gradient(150deg,#174d87 0%,#071d35 100%)'; }
    }
    zone.classList.remove('has-image');
    if (removeFieldId) {
        const el = document.getElementById(removeFieldId);
        if (el) el.value = '1';
    }
    recalcCompleteness();
}

// Init
recalcCompleteness();
updateCounter('tagline','tagline-counter',160);
updateCounter('description','desc-counter',2000);
</script>
@endpush

@endsection
