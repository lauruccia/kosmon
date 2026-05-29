@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('admin.dashboard') }}">← Dashboard</a>
@endsection




@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif

{{-- Filtri + contatori in una sola riga --}}
<section class="card light-card" style="margin-bottom:10px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px;">
        <form method="GET" action="{{ route('admin.kyc.index') }}" style="display:flex;gap:8px;align-items:flex-end;flex:1;min-width:300px;">
            <div style="flex:1;">
                <label class="form-label" style="margin-bottom:3px;">Cerca</label>
                <input type="text" name="q" value="{{ $searchQuery }}" placeholder="Nome azienda o P.IVA…" class="form-control">
            </div>
            <div>
                <label class="form-label" style="margin-bottom:3px;">Stato KYC</label>
                <select name="status" class="form-control">
                    <option value="">Tutti gli stati</option>
                    @foreach($kycStatuses as $slug => $label)
                        <option value="{{ $slug }}" @selected($selectedStatus === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filtra</button>
            @if($searchQuery || $selectedStatus)
                <a href="{{ route('admin.kyc.index') }}" class="btn btn-secondary">Reset</a>
            @endif
        </form>
    </div>

    {{-- Contatori rapidi --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;">
        @foreach($kycStatuses as $slug => $label)
        @php
            $icon = match($slug) { 'pending' => '📋', 'under_review' => '🔍', 'approved' => '✅', 'rejected' => '❌', default => '•' };
            $bg = match($slug) { 'approved' => 'background:#dcfce7;color:#166534', 'rejected' => 'background:#fef2f2;color:#991b1b', 'under_review' => 'background:#dbeafe;color:#1d4ed8', default => 'background:#f1f5f9;color:#475569' };
        @endphp
        <a href="{{ route('admin.kyc.index', ['status' => $slug]) }}"
           style="padding:8px 12px;border-radius:8px;{{ $bg }};text-decoration:none;display:flex;align-items:center;gap:8px;border:1.5px solid transparent;font-size:13px;{{ $selectedStatus === $slug ? 'border-color:currentColor;' : '' }}">
            <span>{{ $icon }}</span>
            <span style="font-weight:700;">{{ $label }}</span>
        </a>
        @endforeach
    </div>
</section>

{{-- Tabella aziende --}}
<section class="card light-card">
    @if($companies->isEmpty())
        <div class="empty-state"><strong>Nessuna azienda trovata.</strong></div>
    @else
    <div style="overflow-x:auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Azienda</th>
                    <th>P.IVA / CF</th>
                    <th>Stato KYC</th>
                    <th>Documenti</th>
                    <th>Aggiornato</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($companies as $company)
                @php
                    $badge = match($company->kyc_status) { 'approved' => 'success', 'rejected' => 'pink', 'under_review' => 'info', default => '' };
                    $pendingDocs = $company->pending_docs_count ?? 0;
                @endphp
                <tr>
                    <td><strong>{{ $company->name }}</strong>@if($company->sector)<div class="table-muted">{{ $company->sector }}</div>@endif</td>
                    <td><div>{{ $company->vat_number ?? '—' }}</div>@if($company->fiscal_code)<div class="table-muted">{{ $company->fiscal_code }}</div>@endif</td>
                    <td><span class="chip {{ $badge }}">{{ $company->kyc_status_label }}</span></td>
                    <td><div style="font-weight:600;">{{ $company->kyc_documents_count }}</div>@if($pendingDocs > 0)<div class="table-muted" style="color:#d97706;">{{ $pendingDocs }} da revisionare</div>@endif</td>
                    <td>{{ $company->updated_at->locale('it')->isoFormat('D MMM YYYY') }}</td>
                    <td><a href="{{ route('admin.kyc.show', $company) }}" class="cta secondary" style="padding:5px 12px;font-size:12px;">Esamina →</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($companies->hasPages())
        <div style="margin-top:10px;display:flex;justify-content:center;">{{ $companies->links() }}</div>
    @endif
    @endif
</section>
@endsection
