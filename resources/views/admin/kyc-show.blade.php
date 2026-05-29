@extends('layouts.portal')

@section('content')
<div style="margin-bottom:16px;">
    <a href="{{ route('admin.kyc.index') }}" style="color:#64748b;text-decoration:none;font-size:14px;">← Torna alla lista KYC</a>
</div>

@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<section class="page-intro" style="padding-bottom:16px;">
    <span class="eyebrow">Revisione KYC</span>
    <h2>{{ $company->name }}</h2>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;align-items:center;">
        @php
            $badge = match($company->kyc_status) {
                'approved'     => 'success',
                'rejected'     => 'pink',
                'under_review' => 'info',
                default        => '',
            };
        @endphp
        <span class="chip {{ $badge }}" style="font-size:13px;">{{ $company->kyc_status_label }}</span>
        @if($company->vat_number)
            <span class="chip">P.IVA: {{ $company->vat_number }}</span>
        @endif
        @if($company->email)
            <span class="chip">{{ $company->email }}</span>
        @endif
    </div>
</section>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

{{-- Colonna principale: documenti --}}
<div class="stack">

    <section class="card light-card">
        <div class="section-head">
            <div><span class="eyebrow">Caricati dall'azienda</span><h3 class="section-title">Documenti KYC</h3></div>
            <span class="pill">{{ $documents->count() }} totali</span>
        </div>

        @if($documents->isEmpty())
            <div class="empty-state" style="margin-top:16px;">
                <strong>Nessun documento caricato</strong>
                <p>L'azienda non ha ancora inviato documenti.</p>
            </div>
        @else
        <div style="display:flex;flex-direction:column;gap:14px;margin-top:16px;">
            @foreach($documents as $doc)
            @php
                $docBadge = match($doc->status) {
                    'accepted' => ['chip' => 'success', 'label' => '✓ Accettato'],
                    'rejected' => ['chip' => 'pink',    'label' => '✕ Rifiutato'],
                    default    => ['chip' => 'warn',    'label' => '… In revisione'],
                };
            @endphp
            <div style="border:1.5px solid #e2e8f0;border-radius:14px;padding:16px 18px;background:#fafafa;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="font-size:32px;">📄</div>
                        <div>
                            <div style="font-weight:700;font-size:14px;color:#10263d;">{{ $doc->type_label }}</div>
                            <div style="font-size:12px;color:#64748b;margin-top:2px;">{{ $doc->original_name }}</div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:2px;">
                                {{ $doc->file_size_human }} ·
                                Caricato da <strong>{{ $doc->uploadedByUser->name ?? '—' }}</strong> il
                                {{ $doc->created_at->locale('it')->isoFormat('D MMM YYYY, HH:mm') }}
                            </div>
                            @if($doc->reviewed_at)
                                <div style="font-size:11px;color:#94a3b8;margin-top:1px;">
                                    Revisionato da {{ $doc->reviewedByUser->name ?? '—' }} il {{ $doc->reviewed_at->locale('it')->isoFormat('D MMM YYYY') }}
                                </div>
                            @endif
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                        <span class="chip {{ $docBadge['chip'] }}">{{ $docBadge['label'] }}</span>
                        <a href="{{ route('portal.kyc.download', $doc) }}"
                           target="_blank"
                           style="padding:6px 14px;border:1.5px solid #d1d9e0;border-radius:8px;font-size:12px;font-weight:600;color:#475569;text-decoration:none;">
                            ↓ Scarica
                        </a>
                    </div>
                </div>

                @if($doc->admin_notes)
                    <div style="margin-top:10px;padding:10px 14px;background:#fff7ed;border-left:3px solid #f59e0b;border-radius:6px;font-size:13px;color:#78350f;">
                        <strong>Note:</strong> {{ $doc->admin_notes }}
                    </div>
                @endif
            </div>
            @endforeach
        </div>
        @endif
    </section>

    {{-- Info azienda --}}
    <section class="card light-card">
        <div class="section-head" style="margin-bottom:14px;">
            <div><span class="eyebrow">Anagrafica</span><h3 class="section-title">Dati azienda</h3></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="metric"><div class="metric-label">Ragione sociale</div><div class="metric-value">{{ $company->name }}</div></div>
            <div class="metric"><div class="metric-label">Settore</div><div class="metric-value">{{ $company->sector ?? '—' }}</div></div>
            <div class="metric"><div class="metric-label">P.IVA</div><div class="metric-value">{{ $company->vat_number ?? '—' }}</div></div>
            <div class="metric"><div class="metric-label">Codice fiscale</div><div class="metric-value">{{ $company->fiscal_code ?? '—' }}</div></div>
            <div class="metric"><div class="metric-label">Email</div><div class="metric-value">{{ $company->email ?? '—' }}</div></div>
            <div class="metric"><div class="metric-label">Stato profilo</div><div class="metric-value">{{ $company->status }}</div></div>
        </div>
        @if($company->users->isNotEmpty())
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;">
            <div class="eyebrow" style="margin-bottom:8px;">Utenti associati</div>
            @foreach($company->users as $u)
                <div style="font-size:13px;color:#334155;padding:3px 0;">
                    {{ $u->name }} <span style="color:#94a3b8;">· {{ $u->email }}</span>
                    @if($u->is_super_admin) <span class="chip">Admin</span> @endif
                </div>
            @endforeach
        </div>
        @endif
    </section>

</div>

{{-- Sidebar azioni --}}
<div class="stack">

    {{-- Stato corrente --}}
    <section class="card light-card">
        <h3 class="card-title" style="margin-bottom:12px;">Stato verifica</h3>
        @php
            $statusColor = match($company->kyc_status) {
                'approved'     => '#dcfce7;color:#166534',
                'rejected'     => '#fef2f2;color:#991b1b',
                'under_review' => '#dbeafe;color:#1d4ed8',
                default        => '#f1f5f9;color:#475569',
            };
        @endphp
        <div style="padding:12px 14px;border-radius:10px;background:{{ $statusColor }};font-weight:700;font-size:14px;text-align:center;margin-bottom:12px;">
            {{ $company->kyc_status_label }}
        </div>
        @if($company->kyc_notes)
        <div style="font-size:13px;color:#64748b;line-height:1.6;margin-bottom:10px;">
            <strong>Note admin:</strong> {{ $company->kyc_notes }}
        </div>
        @endif
        @if($company->kyc_reviewed_by)
        <div style="font-size:12px;color:#94a3b8;">
            Revisionato da {{ $company->kycReviewedBy->name ?? '—' }}<br>
            il {{ $company->kyc_reviewed_at?->locale('it')->isoFormat('D MMM YYYY, HH:mm') }}
        </div>
        @endif
    </section>

    {{-- Azione: Approva --}}
    @if($company->kyc_status !== 'approved')
    <section class="card light-card" style="border-left:3px solid #059669;">
        <h3 class="card-title" style="color:#065f46;margin-bottom:10px;">✅ Approva verifica</h3>
        <p style="font-size:13px;color:#475569;margin-bottom:12px;">
            Approva la verifica KYC. L'azienda riceverà una notifica email e il profilo diventerà attivo.
        </p>
        <form method="POST" action="{{ route('admin.kyc.approve', $company) }}"
              onsubmit="return confirm('Approvare la verifica KYC per {{ addslashes($company->name) }}?')">
            @csrf
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Note (opzionali)</label>
                <textarea name="notes" rows="2" maxlength="1000" placeholder="es. Documenti verificati correttamente"
                    style="width:100%;padding:8px 12px;border:1.5px solid #d1d9e0;border-radius:8px;font-size:13px;resize:vertical;font-family:inherit;box-sizing:border-box;"></textarea>
            </div>
            <button type="submit" style="width:100%;padding:10px;background:#059669;color:#fff;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;">
                Approva KYC
            </button>
        </form>
    </section>
    @endif

    {{-- Azione: Richiedi altri documenti --}}
    @if(in_array($company->kyc_status, ['under_review', 'rejected']))
    <section class="card light-card" style="border-left:3px solid #f59e0b;">
        <h3 class="card-title" style="color:#78350f;margin-bottom:10px;">📎 Richiedi documenti aggiuntivi</h3>
        <form method="POST" action="{{ route('admin.kyc.request-docs', $company) }}">
            @csrf
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Messaggio per l'azienda *</label>
                <textarea name="notes" rows="3" required minlength="10" maxlength="1000"
                    placeholder="es. Carica una copia più leggibile della visura camerale"
                    style="width:100%;padding:8px 12px;border:1.5px solid #d1d9e0;border-radius:8px;font-size:13px;resize:vertical;font-family:inherit;box-sizing:border-box;"></textarea>
            </div>
            <button type="submit" style="width:100%;padding:10px;background:#f59e0b;color:#fff;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;">
                Richiedi documenti
            </button>
        </form>
    </section>
    @endif

    {{-- Azione: Rifiuta --}}
    @if($company->kyc_status !== 'rejected' && $company->kyc_status !== 'approved')
    <section class="card light-card" style="border-left:3px solid #dc2626;">
        <h3 class="card-title" style="color:#991b1b;margin-bottom:10px;">❌ Rifiuta verifica</h3>
        <p style="font-size:13px;color:#475569;margin-bottom:12px;">
            L'azienda riceverà una notifica con le motivazioni del rifiuto e potrà ricaricare i documenti corretti.
        </p>
        <form method="POST" action="{{ route('admin.kyc.reject', $company) }}"
              onsubmit="return confirm('Rifiutare la verifica KYC? L\'azienda verrà notificata.')">
            @csrf
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Motivazione * (min. 10 caratteri)</label>
                <textarea name="notes" rows="3" required minlength="10" maxlength="1000"
                    placeholder="es. La visura camerale è scaduta. Carica un documento aggiornato."
                    style="width:100%;padding:8px 12px;border:1.5px solid #fecaca;border-radius:8px;font-size:13px;resize:vertical;font-family:inherit;box-sizing:border-box;"></textarea>
            </div>
            <button type="submit" style="width:100%;padding:10px;background:#dc2626;color:#fff;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;">
                Rifiuta KYC
            </button>
        </form>
    </section>
    @endif

</div>
</div>
@endsection
