@extends('layouts.portal')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div>
        <h1 style="margin:0;">&#x1F4DC; Contratto di Adesione</h1>
        <p class="subtitle" style="margin:2px 0 0;">Gestisci testo, obbligo di firma e stato utenti.</p>
    </div>
    <a href="{{ route('admin.contract-signatures') }}" class="btn btn-secondary btn-sm">&#x1F4CB; Log firme ({{ $signedCount }})</a>
</div>

@if(session('success'))
    <div class="alert alert-success" style="margin-top:10px;margin-bottom:0;">&#x2705; {{ session('success') }}</div>
@endif

{{-- KPI bar --}}
<div style="display:flex;gap:12px;flex-wrap:wrap;margin:14px 0 16px;">
    @php $unsigned_count = $totalUsers - $signedCount; @endphp
    <div class="kpi-card" style="flex:1;min-width:130px;padding:10px 14px;">
        <div class="kpi-label">Firmati</div>
        <div class="kpi-value" style="color:#16a34a;font-size:1.5rem;">{{ number_format($signedCount) }}</div>
        <div class="kpi-sub">su {{ number_format($totalUsers) }}</div>
    </div>
    <div class="kpi-card" style="flex:1;min-width:130px;padding:10px 14px;">
        <div class="kpi-label">Da firmare</div>
        <div class="kpi-value" style="color:{{ $unsigned_count>0?'#dc2626':'#16a34a' }};font-size:1.5rem;">{{ number_format($unsigned_count) }}</div>
        <div class="kpi-sub">in sospeso</div>
    </div>
    <div class="kpi-card" style="flex:1;min-width:130px;padding:10px 14px;">
        <div class="kpi-label">Versione</div>
        <div class="kpi-value" style="color:#6366f1;font-size:1.5rem;">v{{ $contractVersion }}</div>
        <div class="kpi-sub">contratto attuale</div>
    </div>
    <div class="kpi-card" style="flex:1;min-width:130px;padding:10px 14px;">
        <div class="kpi-label">Firma forzata</div>
        <div class="kpi-value" style="color:{{ $forceSign?'#dc2626':'#94a3b8' }};font-size:1.5rem;">{{ $forceSign?'ATTIVA':'No' }}</div>
        <div class="kpi-sub">obbligo immediato</div>
    </div>
</div>

{{-- Impostazioni + Utenti senza firma --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;margin-bottom:16px;">

    <div class="card">
        <div class="card-header"><h2 style="margin:0;font-size:.95rem;">&#x2699;&#xFE0F; Impostazioni firma</h2></div>
        <div class="card-body" style="padding:14px 16px;">
            <form method="POST" action="{{ route('admin.contract-settings.update') }}">
                @csrf @method('PATCH')
                <div style="margin-bottom:12px;">
                    <label for="contract_required_from" style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;">Data riferimento &ldquo;nuovi utenti&rdquo;</label>
                    <input type="date" id="contract_required_from" name="contract_required_from"
                           value="{{ old('contract_required_from', $requiredFrom?->format('Y-m-d') ?? '') }}"
                           class="form-control" style="font-size:13px;">
                    <small style="color:#64748b;font-size:11px;display:block;margin-top:3px;">Utenti da questa data in poi devono firmare prima di accedere.</small>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Forza firma per tutti</label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="hidden" name="contract_force_sign" value="0">
                        <input type="checkbox" name="contract_force_sign" value="1" {{ $forceSign ? 'checked' : '' }} style="width:16px;height:16px;cursor:pointer;">
                        <span style="font-size:13px;color:#374151;">Nessun utente pu&ograve; rimandare</span>
                    </label>
                    @if($forceSign)
                    <div style="margin-top:6px;padding:8px 12px;background:#fef2f2;border-radius:6px;border:1px solid #fecaca;font-size:12px;color:#991b1b;">
                        &#x26A0;&#xFE0F; Tutti gli utenti non firmati saranno bloccati al prossimo accesso.
                    </div>
                    @endif
                </div>
                <button type="submit" class="btn btn-primary btn-sm">&#x1F4BE; Salva impostazioni</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 style="margin:0;font-size:.95rem;">&#x1F465; Utenti senza contratto</h2></div>
        <div class="card-body" style="padding:0;">
            @php
                $unsigned = \App\Models\User::whereNotNull('company_id')
                    ->whereNull('contract_signed_at')->with('company')
                    ->orderByDesc('created_at')->limit(15)->get();
            @endphp
            @if($unsigned->isEmpty())
                <div style="padding:16px;text-align:center;color:#16a34a;font-size:13px;">&#x2705; Tutti hanno firmato.</div>
            @else
                <table style="width:100%;font-size:12px;border-collapse:collapse;">
                    <thead><tr style="background:#f8fafc;">
                        <th style="padding:7px 12px;text-align:left;color:#64748b;font-weight:600;">Utente / Azienda</th>
                        <th style="padding:7px 12px;text-align:left;color:#64748b;font-weight:600;">Registrato</th>
                        <th style="padding:7px 12px;text-align:left;color:#64748b;font-weight:600;">Rinviato</th>
                    </tr></thead>
                    <tbody>
                        @foreach($unsigned as $u)
                        <tr style="border-top:1px solid #f1f5f9;">
                            <td style="padding:6px 12px;">
                                <div style="font-weight:600;font-size:12px;">{{ $u->name }}</div>
                                <div style="color:#64748b;font-size:11px;">{{ $u->company?->name }}</div>
                            </td>
                            <td style="padding:6px 12px;color:#64748b;">{{ $u->created_at->format('d/m/Y') }}</td>
                            <td style="padding:6px 12px;color:#94a3b8;font-size:11px;">
                                {{ $u->contract_postponed_at ? $u->contract_postponed_at->diffForHumans() : '—' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($unsigned_count > 15)
                    <div style="padding:6px 12px;font-size:11px;color:#94a3b8;border-top:1px solid #f1f5f9;">
                        Mostrati 15 di {{ $unsigned_count }} utenti senza firma.
                    </div>
                @endif
            @endif
        </div>
    </div>

</div>

{{-- Editor testo contratto --}}
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;">
        <h2 style="margin:0;font-size:.95rem;">&#x270D;&#xFE0F; Testo contratto <span style="font-size:11px;font-weight:400;color:#94a3b8;margin-left:6px;">v{{ $contractVersion }}</span></h2>
        <button onclick="togglePreview()" class="btn btn-secondary btn-sm" id="previewBtn">&#x1F441; Anteprima</button>
    </div>
    <div class="card-body" style="padding:12px 16px;">

        {{-- Placeholder bar --}}
        <div style="display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:8px 12px;margin-bottom:10px;">
            <span style="font-size:11px;font-weight:700;color:#0369a1;white-space:nowrap;padding-top:2px;">&#x1F4CC; Variabili:</span>
            <div style="display:flex;flex-wrap:wrap;gap:5px;" id="placeholderBar">
                @foreach([
                    '[[ragione_sociale]]'     => 'Ragione sociale',
                    '[[partita_iva]]'         => 'P.IVA',
                    '[[codice_fiscale]]'      => 'Cod. fiscale',
                    '[[settore]]'             => 'Settore',
                    '[[citta]]'               => 'Città',
                    '[[telefono]]'            => 'Telefono',
                    '[[email]]'               => 'Email',
                    '[[sito_web]]'            => 'Sito web',
                    '[[nome_rappresentante]]' => 'Legale rappr.',
                    '[[uuid_azienda]]'        => 'Codice univoco',
                    '[[data_firma]]'          => 'Data firma',
                ] as $ph => $lbl)
                @php $phDisplay = str_replace(['[[',']]'], ['{{','}}'], $ph); @endphp
                <span onclick="insertPH('{{ $ph }}')" title="{{ $lbl }}"
                      style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:20px;font-size:11px;font-family:monospace;cursor:pointer;border:1px solid #bae6fd;white-space:nowrap;">
                    {{ $phDisplay }}
                </span>
                @endforeach
            </div>
        </div>

        <form method="POST" action="{{ route('admin.contract-text.update') }}">
            @csrf
            <div id="editorArea">
                <textarea id="contract_text" name="contract_text" rows="22"
                    style="width:100%;font-family:monospace;font-size:13px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:6px;resize:vertical;line-height:1.6;box-sizing:border-box;"
                    placeholder="Incolla qui il testo HTML del contratto...">{{ old('contract_text', $contractText) }}</textarea>
            </div>
            <div id="previewArea" style="display:none;background:#fafafa;border:1.5px solid #e2e8f0;border-radius:6px;padding:20px 24px;max-height:460px;overflow-y:auto;"></div>

            <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">&#x1F4BE; Salva testo</button>
                <button type="button" onclick="resetDefault()" class="btn btn-secondary btn-sm" style="color:#dc2626;">&#x21A9; Ripristina default</button>
                <span style="font-size:11px;color:#94a3b8;">Il salvataggio incrementa la versione del contratto.</span>
            </div>
        </form>
    </div>
</div>

<style>
#previewArea h2 { font-size:.9rem;font-weight:700;margin:16px 0 6px;color:#0f766e; }
#previewArea p  { font-size:13px;margin:0 0 10px;line-height:1.7; }
#previewArea hr { border:none;border-top:1px solid #e2e8f0;margin:16px 0; }
#previewArea ul,#previewArea ol { padding-left:18px;font-size:13px; }
</style>

<script>
function togglePreview() {
    const ed = document.getElementById('editorArea');
    const pr = document.getElementById('previewArea');
    const bt = document.getElementById('previewBtn');
    if (pr.style.display === 'none') {
        pr.innerHTML = document.getElementById('contract_text').value;
        pr.style.display = 'block'; ed.style.display = 'none';
        bt.textContent = '✏️ Modifica';
    } else {
        pr.style.display = 'none'; ed.style.display = 'block';
        bt.textContent = '👁 Anteprima';
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
    fetch('{{ route("admin.contract-settings") }}?default_text=1').then(() => location.reload());
}
</script>
@endsection
