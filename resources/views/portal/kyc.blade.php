@extends('layouts.portal')

@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- ── Stepper stato ──────────────────────────────────────────────────────── --}}
<section class="card light-card" style="margin-bottom:22px;">
    @php
        $steps = [
            'pending'      => ['label' => 'Documenti richiesti', 'icon' => '📋'],
            'under_review' => ['label' => 'Revisione in corso',  'icon' => '🔍'],
            'approved'     => ['label' => 'Verificata',          'icon' => '✅'],
        ];
        $statusOrder = ['pending' => 0, 'under_review' => 1, 'approved' => 2, 'rejected' => 2];
        $currentOrder = $statusOrder[$company->kyc_status] ?? 0;
    @endphp

    <div style="display:flex;align-items:center;gap:0;margin-bottom:20px;overflow-x:auto;">
        @foreach($steps as $key => $step)
        @php
            $order = $statusOrder[$key] ?? 0;
            $isDone = $company->kyc_status === 'approved' && $key !== 'rejected';
            $isActive = ($company->kyc_status === $key)
                || ($company->kyc_status === 'rejected' && $key === 'approved');
            $isPast = $order < $currentOrder && $company->kyc_status !== 'rejected';
        @endphp
        <div style="display:flex;align-items:center;flex:1;min-width:120px;">
            <div style="display:flex;flex-direction:column;align-items:center;flex:1;text-align:center;gap:6px;">
                <div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;
                    {{ ($isPast || $company->kyc_status === 'approved') ? 'background:#dcfce7;' : ($isActive ? 'background:#dbeafe;box-shadow:0 0 0 3px #bfdbfe;' : 'background:#f1f5f9;') }}">
                    {{ $step['icon'] }}
                </div>
                <div style="font-size:12px;font-weight:{{ $isActive ? '700' : '500' }};color:{{ $isActive ? '#0c4a86' : '#64748b' }};">
                    {{ $step['label'] }}
                </div>
            </div>
            @if(! $loop->last)
            <div style="flex:1;height:2px;background:{{ $isPast || $company->kyc_status === 'approved' ? '#86efac' : '#e2e8f0' }};margin:0 4px 18px;"></div>
            @endif
        </div>
        @endforeach
    </div>

    {{-- Badge stato corrente --}}
    @php
        $badgeColor = match($company->kyc_status) {
            'approved'     => '#dcfce7;color:#166534',
            'rejected'     => '#fef2f2;color:#991b1b',
            'under_review' => '#dbeafe;color:#1d4ed8',
            default        => '#f1f5f9;color:#475569',
        };
    @endphp
    <div style="display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:12px;background:{{ $badgeColor }};">
        <div style="font-size:22px;">
            @if($company->kyc_status === 'approved') ✅
            @elseif($company->kyc_status === 'rejected') ❌
            @elseif($company->kyc_status === 'under_review') 🔍
            @else 📋
            @endif
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;">{{ $company->kyc_status_label }}</div>
            @if($company->kyc_notes)
                <div style="font-size:13px;margin-top:3px;opacity:.85;">{{ $company->kyc_notes }}</div>
            @endif
        </div>
    </div>
</section>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

{{-- Colonna principale: documenti + upload --}}
<div class="stack">

    {{-- Documenti caricati --}}
    @if($documents->isNotEmpty())
    <section class="card light-card">
        <div class="section-head">
            <div><span class="eyebrow">Caricati da te</span><h3 class="section-title">Documenti inviati</h3></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:12px;margin-top:12px;">
            @foreach($documents as $doc)
            @php
                $docBadge = match($doc->status) {
                    'accepted' => ['bg' => '#dcfce7', 'color' => '#166534', 'label' => 'Accettato', 'icon' => '✓'],
                    'rejected' => ['bg' => '#fef2f2', 'color' => '#991b1b', 'label' => 'Rifiutato', 'icon' => '✕'],
                    default    => ['bg' => '#fef9c3', 'color' => '#854d0e', 'label' => 'In revisione', 'icon' => '…'],
                };
            @endphp
            <div style="display:flex;align-items:center;gap:14px;padding:14px 16px;border:1.5px solid #e2e8f0;border-radius:12px;background:#fafafa;">
                <div style="font-size:28px;">📄</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:14px;color:#10263d;">{{ $doc->type_label }}</div>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $doc->original_name }} · {{ $doc->file_size_human }}</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Caricato {{ $doc->created_at->locale('it')->diffForHumans() }}</div>
                    @if($doc->admin_notes && $doc->status === 'rejected')
                        <div style="font-size:12px;color:#991b1b;margin-top:4px;font-style:italic;">Note: {{ $doc->admin_notes }}</div>
                    @endif
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <span style="padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;background:{{ $docBadge['bg'] }};color:{{ $docBadge['color'] }};">
                        {{ $docBadge['icon'] }} {{ $docBadge['label'] }}
                    </span>
                    <a href="{{ route('portal.kyc.download', $doc) }}"
                       style="padding:6px 12px;border:1.5px solid #d1d9e0;border-radius:8px;font-size:12px;color:#475569;text-decoration:none;font-weight:600;">
                        ↓ Scarica
                    </a>
                </div>
            </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- Form upload nuovo documento --}}
    @if($company->kyc_status !== 'approved')
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Carica documento</span>
                <h3 class="section-title">Aggiungi un documento</h3>
            </div>
        </div>

        @if($errors->any())
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;margin:14px 0;">
            @foreach($errors->all() as $error)
                <p style="color:#991b1b;font-size:13px;margin:2px 0;">• {{ $error }}</p>
            @endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('portal.kyc.upload') }}" enctype="multipart/form-data" style="margin-top:16px;">
            @csrf
            <div style="display:grid;gap:16px;">
                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Tipo di documento *</label>
                    <select name="type" required style="width:100%;padding:10px 14px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:14px;color:#10263d;background:#fff;">
                        @foreach($docTypes as $slug => $label)
                            <option value="{{ $slug }}" @selected(old('type') === $slug)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">File *</label>
                    <div id="kyc-drop-zone" style="border:2px dashed #d1d9e0;border-radius:12px;padding:24px 20px;text-align:center;cursor:pointer;transition:border-color .2s;">
                        <div style="font-size:28px;margin-bottom:6px;">📎</div>
                        <div style="font-size:14px;color:#475569;font-weight:600;">
                            Trascina il file qui o
                            <span style="color:#0c4a86;text-decoration:underline;cursor:pointer;" onclick="document.getElementById('kyc-file').click()">clicca per scegliere</span>
                        </div>
                        <div style="font-size:12px;color:#94a3b8;margin-top:4px;">PDF, JPG, PNG, WebP · max 10 MB</div>
                        <input id="kyc-file" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;" required>
                        <div id="kyc-filename" style="margin-top:10px;font-size:13px;color:#0c4a86;font-weight:600;display:none;"></div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="cta">Carica documento</button>
                </div>
            </div>
        </form>
    </section>
    @endif

</div>

{{-- Sidebar info --}}
<div class="stack">
    <section class="card light-card">
        <h3 class="card-title" style="margin-bottom:12px;">Documenti richiesti</h3>
        <div style="display:flex;flex-direction:column;gap:10px;">
            @foreach($docTypes as $slug => $label)
            @php
                $uploaded = $documents->where('type', $slug)->isNotEmpty();
                $accepted = $documents->where('type', $slug)->where('status', 'accepted')->isNotEmpty();
            @endphp
            <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:{{ $accepted ? '#f0fdf4' : ($uploaded ? '#fffbeb' : '#f8fafc') }};">
                <span style="font-size:18px;">{{ $accepted ? '✅' : ($uploaded ? '⏳' : '⬜') }}</span>
                <div>
                    <div style="font-size:13px;font-weight:600;color:#10263d;">{{ $label }}</div>
                    <div style="font-size:11px;color:#94a3b8;">
                        @if($accepted) Accettato
                        @elseif($uploaded) In revisione
                        @else Non ancora caricato
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </section>

    <section class="card light-card">
        <h3 class="card-title" style="margin-bottom:10px;">Come funziona</h3>
        <ol style="padding-left:18px;font-size:13px;color:#475569;line-height:1.8;margin:0;">
            <li>Carica almeno la visura camerale e il documento d'identità</li>
            <li>Il nostro team esamina i documenti entro 2-3 giorni lavorativi</li>
            <li>Ricevi una notifica email con l'esito della verifica</li>
            <li>Se approvata, puoi operare pienamente nel circuito KMoney</li>
        </ol>
    </section>
</div>

</div>
@endsection

<script>
(function () {
    const dropZone = document.getElementById('kyc-drop-zone');
    const fileInput = document.getElementById('kyc-file');
    const fileNameEl = document.getElementById('kyc-filename');
    if (!dropZone || !fileInput) return;

    function showName(name) {
        fileNameEl.textContent = '📎 ' + name;
        fileNameEl.style.display = 'block';
        dropZone.style.borderColor = '#0c4a86';
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) showName(fileInput.files[0].name);
    });
    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = '#0c4a86'; });
    dropZone.addEventListener('dragleave', () => dropZone.style.borderColor = '#d1d9e0');
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        const file = e.dataTransfer.files[0];
        if (file) {
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            showName(file.name);
        }
    });
})();
</script>
