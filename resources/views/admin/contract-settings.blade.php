@extends('layouts.admin')

@section('content')
<div class="page-header">
    <h1>&#x1F4DC; Contratto di Adesione &mdash; Gestione</h1>
    <p class="subtitle">Gestisci il testo, l&apos;obbligo di firma e monitora lo stato degli utenti.</p>
</div>

@if(session('success'))
    <div class="alert alert-success">&#x2705; {{ session('success') }}</div>
@endif

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:28px;">
    <div class="kpi-card">
        <div class="kpi-label">Contratti firmati</div>
        <div class="kpi-value" style="color:#16a34a">{{ number_format($signedCount) }}</div>
        <div class="kpi-sub">su {{ number_format($totalUsers) }} utenti</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Da firmare</div>
        <div class="kpi-value" style="color:{{ ($totalUsers-$signedCount)>0?&apos;#dc2626&apos;:&apos;#16a34a&apos; }}">{{ number_format($totalUsers-$signedCount) }}</div>
        <div class="kpi-sub">utenti in sospeso</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Versione contratto</div>
        <div class="kpi-value" style="color:#6366f1">v{{ $contractVersion }}</div>
        <div class="kpi-sub">attuale</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Firma forzata</div>
        <div class="kpi-value" style="color:{{ $forceSign?&apos;#dc2626&apos;:&apos;#94a3b8&apos; }}">{{ $forceSign?&apos;ATTIVA&apos;:&apos;No&apos; }}</div>
        <div class="kpi-sub">nessun rinvio</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;margin-bottom:28px;">

    <div class="card">
        <div class="card-header"><h2 style="margin:0;font-size:1rem;">&#x2699;&#xFE0F; Impostazioni firma</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.contract-settings.update') }}">
                @csrf @method('PATCH')
                <div class="form-group" style="margin-bottom:18px;">
                    <label for="contract_required_from" style="font-weight:600;display:block;margin-bottom:5px;">Data riferimento &ldquo;nuovi utenti&rdquo;</label>
                    <input type="date" id="contract_required_from" name="contract_required_from"
                           value="{{ old('contract_required_from', $requiredFrom?->format('Y-m-d') ?? '') }}"
                           class="form-control">
                    <small style="color:#64748b;font-size:12px;display:block;margin-top:4px;">
                        Utenti registrati <strong>da questa data in poi</strong> devono firmare prima di accedere (nessun rinvio).
                    </small>
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="font-weight:600;display:block;margin-bottom:8px;">Forza firma per tutti</label>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="hidden" name="contract_force_sign" value="0">
                        <input type="checkbox" name="contract_force_sign" value="1"
                               {{ $forceSign ? 'checked' : '' }}
                               style="width:18px;height:18px;cursor:pointer;">
                        <span style="font-size:14px;color:#374151;">Nessun utente pu&ograve; rimandare la firma</span>
                    </label>
                    @if($forceSign)
                    <div style="margin-top:8px;padding:10px 14px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;font-size:13px;color:#991b1b;">
                        &#x26A0;&#xFE0F; Tutti gli utenti non ancora firmati saranno bloccati al prossimo accesso.
                    </div>
                    @endif
                </div>
                <button type="submit" class="btn btn-primary btn-sm">&#x1F4BE; Salva impostazioni</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 style="margin:0;font-size:1rem;">&#x1F465; Utenti senza contratto</h2></div>
        <div class="card-body" style="padding:0;">
            @php
                $unsigned = \App\Models\User::whereNotNull('company_id')
                    ->whereNull('contract_signed_at')->with('company')
                    ->orderByDesc('created_at')->limit(15)->get();
            @endphp
            @if($unsigned->isEmpty())
                <div style="padding:24px;text-align:center;color:#16a34a;font-size:14px;">&#x2705; Tutti hanno firmato.</div>
            @else
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead><tr style="background:#f8fafc;">
                        <th style="padding:9px 14px;text-align:left;font-size:12px;color:#64748b;font-weight:600;">Utente / Azienda</th>
                        <th style="padding:9px 14px;text-align:left;font-size:12px;color:#64748b;font-weight:600;">Registrato</th>
                        <th style="padding:9px 14px;text-align:left;font-size:12px;color:#64748b;font-weight:600;">Rinviato</th>
                    </tr></thead>
                    <tbody>
                        @foreach($unsigned as $u)
                        <tr style="border-top:1px solid #f1f5f9;">
                            <td style="padding:8px 14px;">
                                <div style="font-weight:600">{{ $u->name }}</div>
                                <div style="color:#64748b;font-size:12px">{{ $u->company?->name }}</div>
                            </td>
                            <td style="padding:8px 14px;color:#64748b">{{ $u->created_at->format('d/m/Y') }}</td>
                            <td style="padding:8px 14px;color:#94a3b8;font-size:12px">
                                {{ $u->contract_postponed_at ? $u->contract_postponed_at->diffForHumans() : '&mdash;' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($totalUsers - $signedCount > 15)
                    <div style="padding:8px 14px;font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9;">
                        Mostrati 15 di {{ $totalUsers - $signedCount }} utenti senza firma.
                    </div>
                @endif
            @endif
        </div>
    </div>

</div>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:1rem;">&#x270D;&#xFE0F; Testo del contratto <span style="font-size:12px;font-weight:400;color:#94a3b8;margin-left:8px;">versione {{ $contractVersion }}</span></h2>
        <button onclick="togglePreview()" class="btn btn-secondary btn-sm" id="previewBtn">&#x1F441; Anteprima</button>
    </div>
    <div class="card-body">

        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 18px;margin-bottom:18px;">
            <div style="font-size:13px;font-weight:700;color:#0369a1;margin-bottom:8px;">&#x1F4CC; Variabili disponibili &mdash; sostituite automaticamente con i dati dell&apos;azienda:</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;" id="placeholderBar">
                @foreach([
                    '[[ragione_sociale]]' => 'Nome azienda',
                    '[[partita_iva]]'     => 'P.IVA',
                    '[[codice_fiscale]]'  => 'Codice fiscale',
                    '[[settore]]'         => 'Settore',
                    '[[citta]]'           => 'Citt&agrave;',
                    '[[telefono]]'        => 'Telefono',
                    '[[email]]'           => 'Email',
                    '[[sito_web]]'        => 'Sito web',
                    '[[nome_rappresentante]]' => 'Nome legale repr.',
                    '[[uuid_azienda]]'    => 'Codice univoco',
                    '[[data_firma]]'      => 'Data firma',
                ] as $ph => $lbl)
                <span onclick="insertPH('{{ $ph }}')"
                      title="{{ $lbl }}"
                      style="background:#e0f2fe;color:#0369a1;padding:4px 10px;border-radius:20px;font-size:12px;font-family:monospace;cursor:pointer;border:1px solid #bae6fd;">
                    {{ str_replace(['[[',']]'], ['{{','}}'], $ph) }}
                </span>
                @endforeach
            </div>
            <div style="font-size:12px;color:#64748b;margin-top:8px;">Clicca per inserire nella posizione del cursore. Supporta HTML: h2, p, strong, em, ul, li, hr, blockquote.</div>
        </div>

        <form method="POST" action="{{ route('admin.contract-text.update') }}" id="contractTextForm">
            @csrf
            <div id="editorArea">
                <textarea id="contract_text" name="contract_text" rows="28"
                    style="width:100%;font-family:monospace;font-size:13px;padding:14px;border:1.5px solid #e2e8f0;border-radius:8px;resize:vertical;line-height:1.6;"
                    placeholder="Incolla qui il testo HTML del contratto...">{{ old('contract_text', $contractText) }}</textarea>
            </div>
            <div id="previewArea" style="display:none;background:#fafafa;border:1.5px solid #e2e8f0;border-radius:8px;padding:28px 32px;max-height:500px;overflow-y:auto;"></div>

            <div style="display:flex;align-items:center;gap:16px;margin-top:16px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">&#x1F4BE; Salva testo contratto</button>
                <button type="button" onclick="resetDefault()" class="btn btn-secondary btn-sm" style="color:#dc2626;">&#x21A9; Ripristina default</button>
                <span style="font-size:12px;color:#94a3b8;">Il salvataggio incrementa la versione del contratto.</span>
            </div>
        </form>
    </div>
</div>

<style>
#previewArea h2 { font-size:.95rem;font-weight:700;margin:20px 0 8px;color:#0f766e; }
#previewArea p  { font-size:14px;margin:0 0 12px;line-height:1.7; }
#previewArea hr { border:none;border-top:1px solid #e2e8f0;margin:20px 0; }
#previewArea ul,#previewArea ol { padding-left:20px;font-size:14px; }
</style>

<script>
function togglePreview() {
    const ed = document.getElementById('editorArea');
    const pr = document.getElementById('previewArea');
    const bt = document.getElementById('previewBtn');
    if (pr.style.display === 'none') {
        pr.innerHTML = document.getElementById('contract_text').value;
        pr.style.display = 'block'; ed.style.display = 'none';
        bt.textContent = '\u270F\uFE0F Modifica';
    } else {
        pr.style.display = 'none'; ed.style.display = 'block';
        bt.textContent = '\u1F441 Anteprima';
    }
}
function insertPH(ph) {
    const ta = document.getElementById('contract_text');
    const s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0,s) + ph + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + ph.length;
    ta.focus();
}
function resetDefault() {
    if (!confirm('Sostituire il testo attuale con quello di default?')) return;
    fetch('{{ route("admin.contract-settings") }}?default_text=1')
        .then(() => location.reload());
}
</script>
@endsection
