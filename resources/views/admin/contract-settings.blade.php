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
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;gap:8px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:.95rem;">&#x270D;&#xFE0F; Testo contratto <span style="font-size:11px;font-weight:400;color:#94a3b8;margin-left:6px;">v{{ $contractVersion }}</span></h2>
        <div style="display:flex;gap:4px;background:#f1f5f9;border-radius:8px;padding:3px;">
            <button type="button" onclick="setMode('main','visual')" id="tabVisual" class="mode-tab mode-tab-active">&#x1F441; Visuale</button>
            <button type="button" onclick="setMode('main','html')" id="tabHtml" class="mode-tab">&lt;/&gt; HTML</button>
        </div>
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
                <span onclick="insertPH('main','{{ $ph }}')" title="{{ $lbl }}"
                      style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:20px;font-size:11px;font-family:monospace;cursor:pointer;border:1px solid #bae6fd;white-space:nowrap;">
                    {{ $phDisplay }}
                </span>
                @endforeach
            </div>
        </div>

        <form method="POST" action="{{ route('admin.contract-text.update') }}" onsubmit="syncToTextarea('main');">
            @csrf

            {{-- Toolbar formattazione (solo modalità visuale) --}}
            <div id="visualToolbar" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px;border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px;background:#fafafa;">
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('main','bold')" class="fmt-btn" title="Grassetto" style="font-weight:700;">B</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('main','italic')" class="fmt-btn" title="Corsivo" style="font-style:italic;">I</button>
                <span style="width:1px;background:#e2e8f0;margin:0 2px;"></span>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmtBlock('main','h2')" class="fmt-btn" title="Titolo articolo">Titolo</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmtBlock('main','p')" class="fmt-btn" title="Paragrafo">Paragrafo</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('main','insertUnorderedList')" class="fmt-btn" title="Elenco puntato">&bull; Elenco</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="insertHr('main')" class="fmt-btn" title="Linea separatrice">&horbar; Separatore</button>
                <span style="width:1px;background:#e2e8f0;margin:0 2px;"></span>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('main','removeFormat')" class="fmt-btn" title="Rimuovi formattazione">&#x232B; Pulisci</button>
            </div>

            {{-- Editor visuale (WYSIWYG) --}}
            <div id="visualEditor" class="rich-editor" contenteditable="true" oninput="syncToTextarea('main')"
                 style="min-height:380px;max-height:560px;overflow-y:auto;background:#fff;border:1.5px solid #e2e8f0;border-radius:6px;padding:20px 24px;font-size:14px;line-height:1.75;outline:none;">{!! sanitize_html(old('contract_text', $contractText)) !!}</div>

            {{-- Editor HTML grezzo --}}
            <textarea id="contract_text" name="contract_text" rows="22"
                style="display:none;width:100%;font-family:monospace;font-size:13px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:6px;resize:vertical;line-height:1.6;box-sizing:border-box;"
                placeholder="Incolla qui il testo HTML del contratto...">{{ old('contract_text', $contractText) }}</textarea>

            <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">&#x1F4BE; Salva testo</button>
                <button type="button" onclick="resetDefault()" class="btn btn-secondary btn-sm" style="color:#dc2626;">&#x21A9; Ripristina default</button>
                <span style="font-size:11px;color:#94a3b8;">Il salvataggio incrementa la versione del contratto.</span>
            </div>
        </form>
    </div>
</div>

{{-- Editor testo contratto Agente KNM --}}
<div class="card" style="margin-top:16px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;gap:8px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:.95rem;">🤝 Contratto di nomina Agente KNM <span style="font-size:11px;font-weight:400;color:#94a3b8;margin-left:6px;">v{{ $agentContractVersion }}</span></h2>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="display:flex;gap:10px;font-size:11px;color:#64748b;">
                <span>{{ $agentSignedCount }} firmati</span>
                @if($agentPendingCount > 0)
                    <span style="color:#b45309;font-weight:700;">{{ $agentPendingCount }} in attesa di firma</span>
                @endif
            </div>
            <div style="display:flex;gap:4px;background:#f1f5f9;border-radius:8px;padding:3px;">
                <button type="button" onclick="setMode('agent','visual')" id="tabVisualAgent" class="mode-tab mode-tab-active">&#x1F441; Visuale</button>
                <button type="button" onclick="setMode('agent','html')" id="tabHtmlAgent" class="mode-tab">&lt;/&gt; HTML</button>
            </div>
        </div>
    </div>
    <div class="card-body" style="padding:12px 16px;">
        <div style="display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:8px 12px;margin-bottom:10px;">
            <span style="font-size:11px;font-weight:700;color:#0369a1;white-space:nowrap;padding-top:2px;">📌 Variabili:</span>
            <div style="display:flex;flex-wrap:wrap;gap:5px;">
                @foreach([
                    '[[nome_agente]]'                  => 'Nome',
                    '[[email_agente]]'                 => 'Email',
                    '[[telefono_agente]]'              => 'Telefono',
                    '[[codice_fiscale_agente]]'        => 'Codice fiscale',
                    '[[data_nascita_agente]]'          => 'Data di nascita',
                    '[[luogo_nascita_agente]]'         => 'Luogo di nascita',
                    '[[indirizzo_residenza_agente]]'   => 'Indirizzo residenza',
                    '[[cap_residenza_agente]]'         => 'CAP residenza',
                    '[[comune_residenza_agente]]'      => 'Comune residenza',
                    '[[provincia_residenza_agente]]'   => 'Provincia residenza',
                    '[[nome_sponsor]]'                 => 'Nome sponsor',
                    '[[codice_agente_sponsor]]'        => 'Codice agente sponsor',
                    '[[data_firma]]'                   => 'Data firma',
                ] as $ph => $lbl)
                @php $phDisplayAgent = str_replace(['[[',']]'], ['{{','}}'], $ph); @endphp
                <span onclick="insertPH('agent','{{ $ph }}')" title="{{ $lbl }}"
                      style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:20px;font-size:11px;font-family:monospace;cursor:pointer;border:1px solid #bae6fd;white-space:nowrap;">
                    {{ $phDisplayAgent }}
                </span>
                @endforeach
            </div>
        </div>

        <form method="POST" action="{{ route('admin.agent-contract-text.update') }}" onsubmit="syncToTextarea('agent');">
            @csrf

            {{-- Toolbar formattazione (solo modalità visuale) --}}
            <div id="visualToolbarAgent" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px;border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px;background:#fafafa;">
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('agent','bold')" class="fmt-btn" title="Grassetto" style="font-weight:700;">B</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('agent','italic')" class="fmt-btn" title="Corsivo" style="font-style:italic;">I</button>
                <span style="width:1px;background:#e2e8f0;margin:0 2px;"></span>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmtBlock('agent','h2')" class="fmt-btn" title="Titolo articolo">Titolo</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmtBlock('agent','p')" class="fmt-btn" title="Paragrafo">Paragrafo</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('agent','insertUnorderedList')" class="fmt-btn" title="Elenco puntato">&bull; Elenco</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="insertHr('agent')" class="fmt-btn" title="Linea separatrice">&horbar; Separatore</button>
                <span style="width:1px;background:#e2e8f0;margin:0 2px;"></span>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('agent','removeFormat')" class="fmt-btn" title="Rimuovi formattazione">&#x232B; Pulisci</button>
            </div>

            {{-- Editor visuale (WYSIWYG) --}}
            <div id="visualEditorAgent" class="rich-editor" contenteditable="true" oninput="syncToTextarea('agent')"
                 style="min-height:380px;max-height:560px;overflow-y:auto;background:#fff;border:1.5px solid #e2e8f0;border-radius:6px;padding:20px 24px;font-size:14px;line-height:1.75;outline:none;">{!! sanitize_html(old('agent_contract_text', $agentContractText)) !!}</div>

            {{-- Editor HTML grezzo --}}
            <textarea id="agent_contract_text" name="agent_contract_text" rows="18"
                style="display:none;width:100%;font-family:monospace;font-size:13px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:6px;resize:vertical;line-height:1.6;box-sizing:border-box;"
                placeholder="Incolla qui il testo HTML del contratto di nomina ad agente...">{{ old('agent_contract_text', $agentContractText) }}</textarea>

            <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">💾 Salva testo contratto agente</button>
                <button type="button" onclick="resetAgentDefault()" class="btn btn-secondary btn-sm" style="color:#dc2626;">↩ Ripristina default</button>
                <span style="font-size:11px;color:#94a3b8;">Il salvataggio incrementa la versione del contratto agente.</span>
            </div>
        </form>
    </div>
</div>

{{-- Editor testo Direttive e Procedure Kosmos (Agente) --}}
{{-- 2026-08-07: secondo documento che l'agente accetta con la STESSA firma
     OTP del contratto sopra (vedi MlmAgentContractController e la pagina
     portal.mlm.agent-contract-sign) — non un flusso di firma separato. --}}
<div class="card" style="margin-top:16px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;gap:8px;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:.95rem;">📋 Direttive e Procedure Kosmos (Agente) <span style="font-size:11px;font-weight:400;color:#94a3b8;margin-left:6px;">v{{ $agentDirectivesVersion }}</span></h2>
        <div style="display:flex;gap:4px;background:#f1f5f9;border-radius:8px;padding:3px;">
            <button type="button" onclick="setMode('directives','visual')" id="tabVisualDirectives" class="mode-tab mode-tab-active">&#x1F441; Visuale</button>
            <button type="button" onclick="setMode('directives','html')" id="tabHtmlDirectives" class="mode-tab">&lt;/&gt; HTML</button>
        </div>
    </div>
    <div class="card-body" style="padding:12px 16px;">
        <div style="font-size:12px;color:#64748b;margin-bottom:10px;">
            Documento generale (nessun dato personale/placeholder): firmato dall'agente insieme al contratto di nomina, con lo stesso codice OTP.
        </div>

        <form method="POST" action="{{ route('admin.agent-directives-text.update') }}" onsubmit="syncToTextarea('directives');">
            @csrf

            {{-- Toolbar formattazione (solo modalità visuale) --}}
            <div id="visualToolbarDirectives" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px;border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px;background:#fafafa;">
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('directives','bold')" class="fmt-btn" title="Grassetto" style="font-weight:700;">B</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('directives','italic')" class="fmt-btn" title="Corsivo" style="font-style:italic;">I</button>
                <span style="width:1px;background:#e2e8f0;margin:0 2px;"></span>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmtBlock('directives','h2')" class="fmt-btn" title="Titolo principale">Titolo</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmtBlock('directives','h3')" class="fmt-btn" title="Sottotitolo">Sottotitolo</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmtBlock('directives','p')" class="fmt-btn" title="Paragrafo">Paragrafo</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('directives','insertUnorderedList')" class="fmt-btn" title="Elenco puntato">&bull; Elenco</button>
                <button type="button" onmousedown="event.preventDefault()" onclick="insertHr('directives')" class="fmt-btn" title="Linea separatrice">&horbar; Separatore</button>
                <span style="width:1px;background:#e2e8f0;margin:0 2px;"></span>
                <button type="button" onmousedown="event.preventDefault()" onclick="fmt('directives','removeFormat')" class="fmt-btn" title="Rimuovi formattazione">&#x232B; Pulisci</button>
            </div>

            {{-- Editor visuale (WYSIWYG) --}}
            <div id="visualEditorDirectives" class="rich-editor" contenteditable="true" oninput="syncToTextarea('directives')"
                 style="min-height:380px;max-height:560px;overflow-y:auto;background:#fff;border:1.5px solid #e2e8f0;border-radius:6px;padding:20px 24px;font-size:14px;line-height:1.75;outline:none;">{!! sanitize_html(old('agent_directives_text', $agentDirectivesText)) !!}</div>

            {{-- Editor HTML grezzo --}}
            <textarea id="agent_directives_text" name="agent_directives_text" rows="18"
                style="display:none;width:100%;font-family:monospace;font-size:13px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:6px;resize:vertical;line-height:1.6;box-sizing:border-box;"
                placeholder="Incolla qui il testo HTML delle Direttive e Procedure Kosmos...">{{ old('agent_directives_text', $agentDirectivesText) }}</textarea>

            <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">💾 Salva testo direttive</button>
                <button type="button" onclick="resetAgentDirectivesDefault()" class="btn btn-secondary btn-sm" style="color:#dc2626;">↩ Ripristina default</button>
                <span style="font-size:11px;color:#94a3b8;">Il salvataggio incrementa la versione delle direttive.</span>
            </div>
        </form>
    </div>
</div>

<style>
.mode-tab { border:none;background:transparent;color:#64748b;font-size:12px;font-weight:600;padding:4px 12px;border-radius:6px;cursor:pointer; }
.mode-tab-active { background:#fff;color:#0f766e;box-shadow:0 1px 2px rgba(0,0,0,.08); }
.fmt-btn { border:1px solid #e2e8f0;background:#fff;color:#374151;font-size:12px;padding:4px 9px;border-radius:5px;cursor:pointer;line-height:1; }
.fmt-btn:hover { background:#f1f5f9;border-color:#cbd5e1; }
.rich-editor h2 { font-size:1.05rem;font-weight:800;margin:22px 0 8px;color:#0f766e; }
.rich-editor h3 { font-size:.95rem;font-weight:700;margin:18px 0 6px;color:#0f766e; }
.rich-editor h4 { font-size:.9rem;font-weight:700;margin:16px 0 6px;color:#334155; }
.rich-editor h5 { font-size:.85rem;font-weight:700;margin:14px 0 4px;color:#475569;text-transform:uppercase; }
.rich-editor p  { margin:0 0 12px; }
.rich-editor hr { border:none;border-top:1px solid #e2e8f0;margin:18px 0; }
.rich-editor ul,.rich-editor ol { padding-left:20px; }
.rich-editor li { margin-bottom:6px; }
.rich-editor table { width:100%;border-collapse:collapse;margin:8px 0 16px;font-size:13px; }
.rich-editor table td,.rich-editor table th { border:1px solid #e2e8f0;padding:6px 10px; }
.rich-editor:focus { border-color:#0f766e; }
</style>

<script>
// I tre editor (contratto generale "main", contratto agente "agent",
// direttive e procedure agente "directives") condividono la stessa logica
// visuale/HTML, distinta solo dagli id degli elementi coinvolti (vedi ids()
// sotto).
let editMode = { main: 'visual', agent: 'visual', directives: 'visual' };

function ids(which) {
    if (which === 'agent') {
        return { visual: 'visualEditorAgent', toolbar: 'visualToolbarAgent', ta: 'agent_contract_text', tabVisual: 'tabVisualAgent', tabHtml: 'tabHtmlAgent' };
    }
    if (which === 'directives') {
        return { visual: 'visualEditorDirectives', toolbar: 'visualToolbarDirectives', ta: 'agent_directives_text', tabVisual: 'tabVisualDirectives', tabHtml: 'tabHtmlDirectives' };
    }
    return { visual: 'visualEditor', toolbar: 'visualToolbar', ta: 'contract_text', tabVisual: 'tabVisual', tabHtml: 'tabHtml' };
}

function setMode(which, mode) {
    const id = ids(which);
    const visual = document.getElementById(id.visual);
    const toolbar = document.getElementById(id.toolbar);
    const ta = document.getElementById(id.ta);
    if (mode === 'html') {
        // visuale -> html
        ta.value = visual.innerHTML;
        visual.style.display = 'none'; toolbar.style.display = 'none';
        ta.style.display = 'block';
    } else {
        // html -> visuale
        visual.innerHTML = ta.value;
        ta.style.display = 'none';
        visual.style.display = 'block'; toolbar.style.display = 'flex';
    }
    editMode[which] = mode;
    document.getElementById(id.tabVisual).classList.toggle('mode-tab-active', mode === 'visual');
    document.getElementById(id.tabHtml).classList.toggle('mode-tab-active', mode === 'html');
}

// Tiene il campo inviato sempre allineato all'editor attivo
function syncToTextarea(which) {
    const id = ids(which);
    if (editMode[which] === 'visual') {
        document.getElementById(id.ta).value = document.getElementById(id.visual).innerHTML;
    }
}

function fmt(which, cmd) {
    document.getElementById(ids(which).visual).focus();
    document.execCommand(cmd, false, null);
    syncToTextarea(which);
}
function fmtBlock(which, tag) {
    document.getElementById(ids(which).visual).focus();
    document.execCommand('formatBlock', false, tag);
    syncToTextarea(which);
}
function insertHr(which) {
    document.getElementById(ids(which).visual).focus();
    document.execCommand('insertHorizontalRule', false, null);
    syncToTextarea(which);
}

function insertPH(which, ph) {
    const id = ids(which);
    if (editMode[which] === 'visual') {
        const ed = document.getElementById(id.visual);
        ed.focus();
        document.execCommand('insertText', false, ph);
        syncToTextarea(which);
    } else {
        const ta = document.getElementById(id.ta);
        const s = ta.selectionStart, e = ta.selectionEnd;
        ta.value = ta.value.slice(0,s) + ph + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + ph.length;
        ta.focus();
    }
}

function resetDefault() {
    if (!confirm('Sostituire il testo attuale con quello di default?')) return;
    fetch('{{ route("admin.contract-settings") }}?default_text=1').then(() => location.reload());
}

function resetAgentDefault() {
    if (!confirm('Sostituire il testo del contratto agente con quello di default?')) return;
    fetch('{{ route("admin.contract-settings") }}?default_agent_text=1').then(() => location.reload());
}

function resetAgentDirectivesDefault() {
    if (!confirm('Sostituire il testo delle Direttive e Procedure Kosmos con quello di default?')) return;
    fetch('{{ route("admin.contract-settings") }}?default_agent_directives_text=1').then(() => location.reload());
}
</script>
@endsection
