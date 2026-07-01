@extends('layouts.portal')

@section('content')
<style>
    .adm-co-wrap { display:grid; gap:14px; }

    /* stat strip */
    .adm-stats {
        display:flex; flex-wrap:wrap;
        background:var(--surface); border:1px solid var(--line);
        border-radius:var(--radius-sm); box-shadow:var(--shadow);
        overflow:hidden;
    }
    .adm-stat {
        flex:1; min-width:130px;
        padding:16px 22px;
        border-right:1px solid var(--line);
        border-bottom:3px solid transparent;
    }
    .adm-stat:last-child { border-right:none; }
    .adm-stat:nth-child(1) { border-bottom-color:var(--primary); }
    .adm-stat:nth-child(2) { border-bottom-color:var(--success); }
    .adm-stat:nth-child(3) { border-bottom-color:var(--teal); }
    .adm-stat:nth-child(4) { border-bottom-color:var(--accent); }
    .adm-stat-val { font-size:26px; font-weight:800; color:var(--ink); letter-spacing:-.03em; line-height:1; font-variant-numeric:tabular-nums; }
    .adm-stat-lbl { font-size:11px; font-weight:800; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.08em; margin-top:5px; }

    /* filtri */
    .adm-filters {
        display:grid;
        grid-template-columns:minmax(0,1.8fr) repeat(4,minmax(0,1fr)) auto;
        gap:10px; align-items:end;
    }
    @media(max-width:1280px){ .adm-filters { grid-template-columns:repeat(3,minmax(0,1fr)); } }
    @media(max-width:760px)  { .adm-filters { grid-template-columns:1fr; } }

    /* tabella */
    .adm-table-wrap {
        border:1px solid var(--line); border-radius:var(--radius-sm);
        overflow:hidden; background:var(--surface);
        box-shadow:var(--shadow);
    }
    .adm-table { width:100%; border-collapse:separate; border-spacing:0; }
    .adm-table thead th {
        padding:12px 16px;
        background:linear-gradient(180deg, color-mix(in srgb, var(--surface-soft) 86%, #fff 14%), var(--surface-soft));
        border-bottom:1px solid var(--line);
        text-align:left;
        font-size:11.5px; font-weight:800;
        text-transform:uppercase; letter-spacing:.07em;
        color:var(--ink-soft);
        white-space:nowrap;
    }
    .adm-table tbody tr { border-bottom:1px solid var(--line); transition:background .12s; }
    .adm-table tbody tr:last-child { border-bottom:none; }
    .adm-table tbody tr:hover { background:var(--surface-soft); }
    .adm-table td { padding:12px 16px; vertical-align:middle; color:var(--ink); }

    /* nome */
    .adm-name { font-weight:800; font-size:14px; color:var(--ink); }
    .adm-sub  { font-size:12px; color:var(--ink-soft); margin-top:2px; }

    /* piano */
    .plan-badge {
        display:inline-flex; align-items:center;
        padding:4px 11px; border-radius:999px;
        font-size:11.5px; font-weight:800; white-space:nowrap;
        border:1.5px solid;
    }
    .plan-ecommerce  { background:#f5f3ff; border-color:#c4b5fd; color:#5b21b6; }
    .plan-vetrina    { background:#eff6ff; border-color:#93c5fd; color:#1e40af; }
    .plan-biglietto  { background:#ecfdf5; border-color:#6ee7b7; color:#065f46; }
    .plan-anagrafica { background:#f9fafb; border-color:#9ca3af; color:#374151; }
    .plan-none       { background:#f3f4f6; border-color:#d1d5db; color:#6b7280; font-style:italic; }

    /* stato */
    .badge {
        display:inline-flex; align-items:center; gap:5px;
        padding:3px 10px; border-radius:999px;
        font-size:11.5px; font-weight:700; white-space:nowrap;
        border:1.5px solid;
    }
    .badge-green  { background:#dcfce7; border-color:#86efac; color:#166534; }
    .badge-yellow { background:#fef9c3; border-color:#fde047; color:#713f12; }
    .badge-red    { background:#fee2e2; border-color:#fca5a5; color:#991b1b; }
    .badge-blue   { background:#dbeafe; border-color:#93c5fd; color:#1e3a8a; }
    .badge-gray   { background:#f3f4f6; border-color:#d1d5db; color:#374151; }
    .badge-dot { width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }

    /* azioni */
    .adm-act { display:flex; gap:6px; flex-wrap:wrap; }
    .adm-btn {
        display:inline-flex; align-items:center;
        padding:6px 13px; border-radius:8px;
        font-size:12.5px; font-weight:700;
        text-decoration:none; cursor:pointer;
        border:1.5px solid; transition:opacity .15s;
        line-height:1; white-space:nowrap;
        background:none;
    }
    .adm-btn:hover { opacity:.82; }
    .adm-btn-blue  { background:var(--primary); color:#fff; border-color:var(--primary); }
    .adm-btn-green { background:#059669; color:#fff; border-color:#059669; }
    .adm-btn-red   { background:var(--surface); color:#b91c1c; border-color:#fca5a5; }

    /* count cell */
    .cnt { text-align:center; font-weight:700; font-size:14px; }
    .cnt-0 { color:#9ca3af; }
    .cnt-pos-green { color:#059669; }
    .cnt-pos-blue  { color:#1d4ed8; }

    /* barra azioni in blocco */
    .bulk-bar {
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        margin:0 0 12px; padding:10px 14px;
        background:var(--surface); border:1px solid var(--line);
        border-radius:var(--radius-sm); box-shadow:var(--shadow);
    }
    .bulk-info { font-size:13px; color:var(--ink-soft); margin-right:auto; }
    .bulk-info strong { color:var(--ink); font-variant-numeric:tabular-nums; }
    .bulk-bar .adm-btn:disabled { opacity:.45; cursor:not-allowed; }
    .bulk-select {
        padding:7px 10px; border:1.5px solid var(--line); border-radius:8px;
        font-size:13px; font-weight:700; background:var(--surface); color:var(--ink);
    }
</style>

<div class="adm-co-wrap">

</section>

    @if(session('portal_success'))
        <div class="alert-banner success">{{ session('portal_success') }}</div>
    @endif
    @if(session('portal_error'))
        <div class="alert-banner error">{{ session('portal_error') }}</div>
    @endif

    {{-- KPI --}}
    <div class="adm-stats">
        <div class="adm-stat">
            <div class="adm-stat-val">{{ number_format($stats['total']) }}</div>
            <div class="adm-stat-lbl">Totale</div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-val" style="color:#059669;">{{ number_format($stats['active']) }}</div>
            <div class="adm-stat-lbl">Attive</div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-val">{{ number_format($stats['verified']) }}</div>
            <div class="adm-stat-lbl">KYC verificate</div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-val" style="color:#5b21b6;">{{ number_format($stats['plans']) }}</div>
            <div class="adm-stat-lbl">Con piano</div>
        </div>
    </div>

    {{-- Filtri --}}
    <section class="card light-card" style="padding:14px 16px;">
        <form method="get" action="{{ route('admin.companies.index') }}">
            <div class="adm-filters">
                <div class="field">
                    <label for="q">Cerca</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}"
                           placeholder="Nome, email, P.IVA, settore…">
                </div>
                <div class="field">
                    <label for="plan">Piano</label>
                    <select id="plan" name="plan">
                        <option value="">Tutti i piani</option>
                        @foreach(\App\Models\Company::SUBSCRIPTION_PLANS as $key => $label)
                            <option value="{{ $key }}" @selected($filters['plan'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Stato</label>
                    <select id="status" name="status">
                        <option value="">Tutti</option>
                        <option value="active"    @selected($filters['status'] === 'active')>Attive</option>
                        <option value="pending"   @selected($filters['status'] === 'pending')>Non attive</option>
                        <option value="suspended" @selected($filters['status'] === 'suspended')>Sospese</option>
                    </select>
                </div>
                <div class="field">
                    <label for="kyc_status">KYC</label>
                    <select id="kyc_status" name="kyc_status">
                        <option value="">Tutti</option>
                        <option value="approved"     @selected($filters['kyc_status'] === 'approved')>Approvato</option>
                        <option value="pending"      @selected($filters['kyc_status'] === 'pending')>In attesa</option>
                        <option value="under_review" @selected($filters['kyc_status'] === 'under_review')>In revisione</option>
                        <option value="rejected"     @selected($filters['kyc_status'] === 'rejected')>Rifiutato</option>
                    </select>
                </div>
                @if($sectorOptions->isNotEmpty())
                <div class="field">
                    <label for="sector">Settore</label>
                    <select id="sector" name="sector">
                        <option value="">Tutti i settori</option>
                        @foreach($sectorOptions as $s)
                            <option value="{{ $s }}" @selected($filters['sector'] === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="form-actions" style="margin-top:0;flex-wrap:nowrap;">
                    <button class="cta" type="submit">Filtra</button>
                    @if(collect($filters)->filter()->isNotEmpty())
                        <a href="{{ route('admin.companies.index') }}" class="cta secondary">Reset</a>
                    @endif
                </div>
            </div>
        </form>
    </section>

    {{-- Risultati --}}
    <div>
        <p style="font-size:13px;color:#4a637d;margin:0 0 10px;">
            <strong style="color:#0d1c30;">{{ number_format($companies->total()) }}</strong>
            {{ $companies->total() === 1 ? 'azienda trovata' : 'aziende trovate' }}
            @if($companies->lastPage() > 1)
                — pagina {{ $companies->currentPage() }} / {{ $companies->lastPage() }}
            @endif
        </p>

        @if($companies->isEmpty())
            <div class="empty-state">
                <strong>Nessuna azienda trovata.</strong>
                <p>Prova a modificare i filtri di ricerca.</p>
            </div>
        @else
        {{-- Barra azioni in blocco --}}
        <form id="bulkForm" method="POST"
              action="{{ route('admin.companies.bulk') }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}"
              class="bulk-bar">
            @csrf
            <input type="hidden" name="scope" id="bulk-scope" value="selected">

            <select name="action" id="bulk-action" class="bulk-select">
                <option value="activate">Attiva</option>
                <option value="deactivate">Disattiva</option>
                <option value="suspend">Sospendi</option>
                <option value="plan">Cambia piano…</option>
            </select>

            <select name="plan" id="bulk-plan" class="bulk-select" style="display:none;">
                @foreach(\App\Models\Company::SUBSCRIPTION_PLANS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
                <option value="none">Nessun piano</option>
            </select>

            <span class="bulk-info"><strong id="bulk-count">0</strong> selezionate</span>

            <button type="submit" id="btn-apply-selected" class="adm-btn adm-btn-green" disabled>
                Applica a selezionate
            </button>
            <button type="submit" id="btn-apply-all" class="adm-btn adm-btn-blue">
                Applica a tutte le filtrate
            </button>
        </form>

        <div class="adm-table-wrap">
            <table class="adm-table">
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center;">
                            <input type="checkbox" id="cb-all" title="Seleziona tutte (in pagina)">
                        </th>
                        <th>Azienda</th>
                        <th>Piano</th>
                        <th>Stato</th>
                        <th>KYC</th>
                        <th>Settore</th>
                        <th style="text-align:center;">Prodotti</th>
                        <th style="text-align:center;">Annunci</th>
                        <th style="text-align:center;">Utenti</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($companies as $company)
                    @php
                        $isActive  = $company->status === 'active' && $company->suspended_at === null;
                        $suspended = $company->suspended_at !== null;
                    @endphp
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox" class="bulk-cb" value="{{ $company->id }}"
                                   aria-label="Seleziona {{ $company->name }}">
                        </td>
                        <td style="min-width:200px;max-width:280px;">
                            <div class="adm-name">{{ $company->name }}</div>
                            @if($company->email)
                                <div class="adm-sub">{{ $company->email }}</div>
                            @endif
                            @if($company->vat_number)
                                <div class="adm-sub">P.IVA {{ $company->vat_number }}</div>
                            @endif
                        </td>

                        <td>
                            @if($company->subscription_plan)
                                <span class="plan-badge plan-{{ $company->subscription_plan }}">
                                    {{ $company->subscription_plan_label }}
                                </span>
                            @else
                                <span class="plan-badge plan-none">Nessuno</span>
                            @endif
                        </td>

                        <td>
                            @if($suspended)
                                <span class="badge badge-red"><span class="badge-dot"></span>Sospesa</span>
                            @elseif($isActive)
                                <span class="badge badge-green"><span class="badge-dot"></span>Attiva</span>
                            @else
                                <span class="badge badge-yellow"><span class="badge-dot"></span>Non attiva</span>
                            @endif
                        </td>

                        <td>
                            @php
                                $kycLabels = [
                                    'approved'     => ['badge-green', 'Verificata'],
                                    'pending'      => ['badge-yellow', 'In attesa'],
                                    'under_review' => ['badge-blue', 'In revisione'],
                                    'rejected'     => ['badge-red', 'Rifiutata'],
                                ];
                                [$kycClass, $kycLabel] = $kycLabels[$company->kyc_status] ?? ['badge-gray', $company->kyc_status];
                            @endphp
                            <span class="badge {{ $kycClass }}">{{ $kycLabel }}</span>
                        </td>

                        <td style="color:#4a637d;font-size:13px;">{{ $company->sector ?? '—' }}</td>

                        <td class="cnt {{ $company->listings_count > 0 ? 'cnt-pos-green' : 'cnt-0' }}">
                            {{ $company->listings_count }}
                        </td>
                        <td class="cnt {{ $company->announcements_count > 0 ? 'cnt-pos-blue' : 'cnt-0' }}">
                            {{ $company->announcements_count }}
                        </td>
                        <td class="cnt cnt-0">{{ $company->users_count }}</td>

                        <td>
                            <div class="adm-act">
                                <a href="{{ route('admin.companies.show', $company) }}"
                                   class="adm-btn adm-btn-blue">Gestisci</a>

                                @if(!$isActive && !$suspended)
                                    <form method="POST" action="{{ route('admin.companies.activate', $company) }}" style="margin:0;">
                                        @csrf
                                        <button type="submit" class="adm-btn adm-btn-green">Attiva</button>
                                    </form>
                                @elseif($isActive)
                                    <form method="POST" action="{{ route('admin.companies.deactivate', $company) }}" style="margin:0;">
                                        @csrf
                                        <button type="submit" class="adm-btn adm-btn-red"
                                                onclick="return confirm('Disattivare {{ addslashes($company->name) }}?')">
                                            Disattiva
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($companies->hasPages())
            <div style="margin-top:18px;display:flex;justify-content:center;">
                {{ $companies->links() }}
            </div>
        @endif
        @endif
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form    = document.getElementById('bulkForm');
    if (!form) return;

    var master   = document.getElementById('cb-all');
    var counter  = document.getElementById('bulk-count');
    var scope    = document.getElementById('bulk-scope');
    var actionEl = document.getElementById('bulk-action');
    var planEl   = document.getElementById('bulk-plan');
    var btnSel   = document.getElementById('btn-apply-selected');
    var btnAll   = document.getElementById('btn-apply-all');
    var boxes    = Array.prototype.slice.call(document.querySelectorAll('.bulk-cb'));

    var LABELS = { activate: 'Attivare', deactivate: 'Disattivare', suspend: 'Sospendere', plan: 'Cambiare piano a' };

    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        counter.textContent = n;
        btnSel.disabled = n === 0;
        if (master) {
            master.checked = n > 0 && n === boxes.length;
            master.indeterminate = n > 0 && n < boxes.length;
        }
    }

    // Mostra il selettore piano solo quando l'azione è "Cambia piano".
    function togglePlan() {
        planEl.style.display = actionEl.value === 'plan' ? '' : 'none';
    }
    actionEl.addEventListener('change', togglePlan);

    if (master) {
        master.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = master.checked; });
            refresh();
        });
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });

    function clearIds() {
        form.querySelectorAll('input[name="company_ids[]"]').forEach(function (i) { i.remove(); });
    }

    // Applica a selezionate: inietta gli id scelti come hidden nel form.
    btnSel.addEventListener('click', function (e) {
        var selected = boxes.filter(function (b) { return b.checked; });
        if (selected.length === 0) { e.preventDefault(); return; }
        if (!confirm(LABELS[actionEl.value] + ' ' + selected.length + ' aziende selezionate?')) {
            e.preventDefault(); return;
        }
        scope.value = 'selected';
        clearIds();
        selected.forEach(function (b) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'company_ids[]'; h.value = b.value;
            form.appendChild(h);
        });
    });

    // Applica a tutte le aziende che rispettano i filtri correnti.
    btnAll.addEventListener('click', function (e) {
        if (!confirm(LABELS[actionEl.value] + ' TUTTE le aziende che rispettano i filtri correnti? Operazione potenzialmente estesa.')) {
            e.preventDefault(); return;
        }
        scope.value = 'all_filtered';
        clearIds();
    });

    togglePlan();
    refresh();
});
</script>
@endsection
