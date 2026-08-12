@extends('layouts.portal')

@section('content')
<style>
    .profile-edit-grid {
        display: grid;
        grid-template-columns: minmax(0,1fr) 280px;
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

    /* Avatar upload */
    .avatar-wrap {
        display: flex; align-items: center; gap: 20px;
        margin-bottom: 6px;
    }
    .avatar-zone {
        position: relative;
        width: 90px; height: 90px;
        border-radius: 50%;
        border: 2px dashed var(--line);
        background: var(--surface-soft);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 4px; cursor: pointer; flex-shrink: 0;
        transition: border-color .2s, background .2s;
        overflow: hidden;
    }
    .avatar-zone:hover { border-color: var(--primary); background: var(--primary-faint); }
    .avatar-zone input[type=file] {
        position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }
    .avatar-zone img {
        position: absolute; inset: 0; width: 100%; height: 100%;
        object-fit: cover; border-radius: 50%; pointer-events: none;
    }
    .avatar-zone-label { font-size: 10px; font-weight: 600; color: var(--ink-muted); text-align: center; line-height: 1.3; padding: 0 6px; }
    .avatar-remove {
        position: absolute; top: 2px; right: 2px; z-index: 2;
        width: 20px; height: 20px; border-radius: 50%;
        background: rgba(0,0,0,.55); border: none; cursor: pointer;
        display: none; align-items: center; justify-content: center;
        font-size: 10px; color: #fff; font-weight: 700;
    }
    .avatar-zone.has-image .avatar-remove { display: flex; }
    .avatar-zone.has-image .avatar-zone-label { display: none; }

    /* Completeness */
    .completeness-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-xs);
        padding: 20px;
        position: sticky;
        top: 24px;
    }
    .completeness-title { font-size: 13px; font-weight: 700; color: var(--ink); margin: 0 0 14px; }
    .completeness-percent { font-size: 28px; font-weight: 800; color: var(--ink); letter-spacing: -.03em; margin-bottom: 4px; }
    .completeness-bar-wrap { background: var(--line); border-radius: 99px; height: 8px; margin-bottom: 16px; overflow: hidden; }
    .completeness-bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #059669, #10b981); transition: width .4s ease; }
    .completeness-items { display: grid; gap: 8px; }
    .completeness-item { display: flex; align-items: center; gap: 9px; font-size: 12.5px; color: var(--ink-soft); }
    .completeness-item.done { color: #059669; }
    .completeness-item.done .ci-icon { background: #d1fae5; color: #059669; }
    .completeness-item .ci-icon {
        flex-shrink: 0; width: 20px; height: 20px; border-radius: 6px;
        background: var(--surface-soft); border: 1px solid var(--line);
        display: grid; place-items: center; font-size: 11px; font-weight: 700;
    }
</style>

<form method="POST" action="{{ route('portal.personal-profile.update') }}" id="profile-form" enctype="multipart/form-data">
    @csrf

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:20px;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error" style="margin-bottom:20px;">Controlla i campi evidenziati.</div>
    @endif

    @if($referredBy ?? null)
        <div style="margin-bottom:20px;padding:12px 16px;border-radius:10px;background:var(--surface-soft,#f8fafc);border:1px solid var(--line);font-size:13px;color:var(--ink-soft);">
            Sei stato invitato da <strong>{{ $referredBy->name }}</strong>.
        </div>
    @endif

    <div class="profile-edit-grid">

        {{-- COLONNA SINISTRA --}}
        <div style="display:grid;gap:20px;">

            {{-- Dati personali --}}
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon">👤</div>
                    <h2 class="profile-section-title">Dati personali</h2>
                </div>
                <div class="profile-section-body">

                    {{-- Avatar --}}
                    <div class="avatar-wrap">
                        <div class="avatar-zone {{ $currentUser->avatar_path ? 'has-image' : '' }}" id="avatar-zone">
                            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"
                                   onchange="previewAvatar(this)">
                            @if($currentUser->avatar_path)
                                <img id="avatar-preview" src="{{ Storage::disk('public')->url($currentUser->avatar_path) }}" alt="Avatar">
                            @else
                                <img id="avatar-preview" src="" alt="" style="display:none;">
                                <span class="avatar-zone-label">Foto profilo</span>
                            @endif
                            <button type="button" class="avatar-remove" onclick="removeAvatar(event)">✕</button>
                        </div>
                        <div>
                            <p style="font-size:12.5px;color:var(--ink-soft);margin:0 0 4px;">
                                Carica una foto profilo (JPEG, PNG, WebP · max 2 MB).
                            </p>
                            <p style="font-size:11px;color:var(--ink-muted);margin:0;">
                                Quadrata, min 200×200 px consigliata.
                            </p>
                        </div>
                    </div>
                    <input type="hidden" name="remove_avatar" id="remove_avatar" value="0">
                    @error('avatar')<span class="field-error">{{ $message }}</span>@enderror

                    <div class="field" style="margin-top:18px;">
                        <label for="name">Nome e cognome <span style="color:#dc2626">*</span></label>
                        <input type="text" id="name" name="name"
                               value="{{ old('name', $currentUser->name) }}"
                               required maxlength="255">
                        @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                </div>
            </div>

            {{-- Contatti --}}
            <div class="profile-section">
                <div class="profile-section-header">
                    <div class="profile-section-icon">📞</div>
                    <h2 class="profile-section-title">Contatti</h2>
                </div>
                <div class="profile-section-body">
                    <p style="font-size:12.5px;color:var(--ink-muted);margin:0 0 16px;">
                        Questi dati sono visibili agli altri membri del circuito.
                    </p>

                    <div class="field">
                        <label for="phone">Telefono</label>
                        <input type="text" id="phone" name="phone"
                               value="{{ old('phone', $currentUser->phone) }}"
                               placeholder="+39 333 1234567"
                               maxlength="30">
                        @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="field" style="margin-top:14px;">
                        <label>Email account</label>
                        <input type="email" value="{{ $currentUser->email }}" disabled
                               style="background:var(--surface-soft);color:var(--ink-muted);cursor:not-allowed;">
                        <span style="font-size:11px;color:var(--ink-muted);">
                            Per cambiare email vai in
                            <a href="{{ route('portal.email-change') }}" style="color:var(--primary);">Impostazioni email</a>.
                        </span>
                    </div>
                </div>
            </div>

            {{-- Indirizzo di spedizione (2026-07-29): compilato una volta sola
                 qui, riusato ad ogni acquisto di un prodotto "da spedire" nello
                 shop — vedi Account::hasShippingAddress(). --}}
            <div class="profile-section" id="shipping-address">
                <div class="profile-section-header">
                    <div class="profile-section-icon">📦</div>
                    <h2 class="profile-section-title">Indirizzo di spedizione</h2>
                </div>
                <div class="profile-section-body">
                    <p style="font-size:12.5px;color:var(--ink-muted);margin:0 0 16px;line-height:1.6;">
                        Usato quando acquisti nello shop un prodotto fisico "da spedire". Non serve per ritiro in sede o servizi.
                    </p>
                    <div class="field-row">
                        <div class="field">
                            <label for="shipping_recipient_name">Nome destinatario</label>
                            <input type="text" id="shipping_recipient_name" name="shipping_recipient_name"
                                   value="{{ old('shipping_recipient_name', $currentAccount->shipping_recipient_name) }}"
                                   placeholder="Nome e cognome di chi ritira il pacco" maxlength="150">
                            @error('shipping_recipient_name')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="field">
                            <label for="shipping_phone">Telefono per la consegna</label>
                            <input type="text" id="shipping_phone" name="shipping_phone"
                                   value="{{ old('shipping_phone', $currentAccount->shipping_phone) }}"
                                   placeholder="+39 333 1234567" maxlength="30">
                            @error('shipping_phone')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="field" style="margin-top:14px;">
                        <label for="shipping_address">Via e numero civico</label>
                        <input type="text" id="shipping_address" name="shipping_address"
                               value="{{ old('shipping_address', $currentAccount->shipping_address) }}"
                               placeholder="es. Via Roma 10" maxlength="255">
                        @error('shipping_address')<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="field-row" style="margin-top:14px;">
                        <div class="field">
                            <label for="shipping_city">Città</label>
                            <input type="text" id="shipping_city" name="shipping_city"
                                   value="{{ old('shipping_city', $currentAccount->shipping_city) }}"
                                   maxlength="100">
                            @error('shipping_city')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="field">
                            <label for="shipping_postal_code">CAP</label>
                            <input type="text" id="shipping_postal_code" name="shipping_postal_code"
                                   value="{{ old('shipping_postal_code', $currentAccount->shipping_postal_code) }}"
                                   maxlength="12">
                            @error('shipping_postal_code')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="field" style="margin-top:14px;max-width:220px;">
                        <label for="shipping_province">Provincia</label>
                        <input type="text" id="shipping_province" name="shipping_province"
                               value="{{ old('shipping_province', $currentAccount->shipping_province) }}"
                               placeholder="es. MI" maxlength="60">
                        @error('shipping_province')<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;">
                <button class="cta" type="submit">Salva modifiche</button>
            </div>

        </div>

        {{-- COLONNA DESTRA: completeness --}}
        <div>
            <div class="completeness-card">
                <p class="completeness-title">Completamento profilo</p>
                <div class="completeness-percent" id="cp-percent">0%</div>
                <div class="completeness-bar-wrap">
                    <div class="completeness-bar-fill" id="cp-bar" style="width:0%"></div>
                </div>
                <div class="completeness-items">
                    <div class="completeness-item {{ $currentUser->name ? 'done' : '' }}" data-field="name">
                        <div class="ci-icon">{{ $currentUser->name ? '✓' : '·' }}</div>
                        <span>Nome</span>
                    </div>
                    <div class="completeness-item {{ $currentUser->phone ? 'done' : '' }}" data-field="phone">
                        <div class="ci-icon">{{ $currentUser->phone ? '✓' : '·' }}</div>
                        <span>Telefono</span>
                    </div>
                    <div class="completeness-item {{ $currentUser->avatar_path ? 'done' : '' }}" data-field="avatar">
                        <div class="ci-icon" id="ci-avatar">{{ $currentUser->avatar_path ? '✓' : '·' }}</div>
                        <span>Foto profilo</span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

@push('scripts')
<script>
const CP_FIELDS = ['name','phone'];

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
    const total = CP_FIELDS.length + 1; // +1 avatar

    CP_FIELDS.forEach(f => {
        const el = document.getElementById(f);
        const item = document.querySelector('[data-field="' + f + '"]');
        const filled = el && el.value.trim().length > 0;
        if (filled) done++;
        if (item) {
            item.classList.toggle('done', filled);
            item.querySelector('.ci-icon').textContent = filled ? '✓' : '·';
        }
    });

    const avatarHas = document.getElementById('avatar-zone')?.classList.contains('has-image');
    if (avatarHas) done++;
    const avatarItem = document.querySelector('[data-field="avatar"]');
    if (avatarItem) {
        avatarItem.classList.toggle('done', !!avatarHas);
        avatarItem.querySelector('.ci-icon').textContent = avatarHas ? '✓' : '·';
    }

    const pct = Math.round((done / total) * 100);
    document.getElementById('cp-percent').textContent = pct + '%';
    document.getElementById('cp-bar').style.width = pct + '%';
}

function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const zone = document.getElementById('avatar-zone');
    const img  = document.getElementById('avatar-preview');
    const reader = new FileReader();
    reader.onload = e => {
        img.src = e.target.result;
        img.style.display = 'block';
        zone.classList.add('has-image');
        document.getElementById('remove_avatar').value = '0';
        recalcCompleteness();
    };
    reader.readAsDataURL(input.files[0]);
}

function removeAvatar(evt) {
    evt.preventDefault(); evt.stopPropagation();
    const zone = document.getElementById('avatar-zone');
    const img  = document.getElementById('avatar-preview');
    const fileInput = zone.querySelector('input[type=file]');
    if (fileInput) fileInput.value = '';
    img.src = ''; img.style.display = 'none';
    zone.classList.remove('has-image');
    document.getElementById('remove_avatar').value = '1';
    recalcCompleteness();
}

CP_FIELDS.forEach(f => {
    const el = document.getElementById(f);
    if (el) el.addEventListener('input', recalcCompleteness);
});

recalcCompleteness();
</script>
@endpush

@endsection
