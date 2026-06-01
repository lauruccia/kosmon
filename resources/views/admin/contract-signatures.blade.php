@extends('layouts.portal')

@section('title', 'Log Firme Contratto')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="margin:0 0 4px;">&#x270D;&#xFE0F; Log Firme Contratto</h1>
        <p class="subtitle" style="margin:0;">Elenco completo di tutti i contratti firmati con dati di firma.</p>
    </div>
    <a href="{{ route('admin.contract-settings') }}" class="btn btn-secondary btn-sm">&#x2190; Impostazioni contratto</a>
</div>

{{-- Filtro --}}
<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Cerca azienda / utente</label>
                <input type="text" name="q" value="{{ request('q') }}"
                       placeholder="nome, email, P.IVA..."
                       style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;width:220px;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Versione</label>
                <select name="version" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
                    <option value="">Tutte</option>
                    @foreach($versions as $v)
                        <option value="{{ $v }}" {{ request('version') == $v ? 'selected' : '' }}>v{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Dal</label>
                <input type="date" name="from" value="{{ request('from') }}"
                       style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Al</label>
                <input type="date" name="to" value="{{ request('to') }}"
                       style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">&#x1F50D; Filtra</button>
            @if(request()->hasAny(['q','version','from','to']))
                <a href="{{ route('admin.contract-signatures') }}" class="btn btn-secondary btn-sm">&#x2715; Reset</a>
            @endif
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:1rem;">&#x1F4CB; Firme registrate <span style="font-size:13px;font-weight:400;color:#94a3b8;">({{ $signatures->total() }} totali)</span></h2>
        <a href="{{ route('admin.contract-signatures.export') }}" class="btn btn-secondary btn-sm">&#x1F4E5; Esporta CSV</a>
    </div>
    <div class="card-body" style="padding:0;">
        @if($signatures->isEmpty())
            <div style="padding:40px;text-align:center;color:#94a3b8;font-size:14px;">
                Nessuna firma trovata con i filtri selezionati.
            </div>
        @else
        <div style="overflow-x:auto;">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.04em;">Azienda / Utente</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.04em;">Data firma</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.04em;">Ver.</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.04em;">IP</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.04em;">Dispositivo</th>
                    <th style="padding:10px 16px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.04em;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @foreach($signatures as $sig)
                <tr style="border-top:1px solid #f1f5f9;transition:background .1s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <td style="padding:10px 16px;">
                        <div style="font-weight:600;color:#1e293b;">{{ $sig->company?->name ?? $sig->user?->name ?? '—' }}</div>
                        <div style="color:#64748b;font-size:12px;">{{ $sig->user?->email }}</div>
                        @if($sig->company?->vat_number)
                        <div style="color:#94a3b8;font-size:11px;font-family:monospace;">P.IVA {{ $sig->company->vat_number }}</div>
                        @endif
                    </td>
                    <td style="padding:10px 16px;white-space:nowrap;">
                        <div style="font-weight:600;">{{ $sig->signed_at->format('d/m/Y') }}</div>
                        <div style="color:#64748b;font-size:12px;">{{ $sig->signed_at->format('H:i:s') }}</div>
                    </td>
                    <td style="padding:10px 16px;">
                        <span style="background:#ede9fe;color:#6d28d9;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:700;">v{{ $sig->contract_version }}</span>
                    </td>
                    <td style="padding:10px 16px;font-family:monospace;font-size:12px;color:#374151;">
                        {{ $sig->ip_address ?? '—' }}
                    </td>
                    <td style="padding:10px 16px;max-width:200px;">
                        <div style="font-size:11.5px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                             title="{{ $sig->user_agent }}">
                            {{ $sig->user_agent ? \Illuminate\Support\Str::limit($sig->user_agent, 55) : '—' }}
                        </div>
                    </td>
                    <td style="padding:10px 16px;text-align:center;">
                        <a href="{{ route('admin.contract-signatures.show', $sig) }}"
                           class="btn btn-secondary btn-sm"
                           style="font-size:12px;padding:5px 12px;">
                            &#x1F441; Visualizza
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        {{-- Paginazione --}}
        @if($signatures->hasPages())
        <div style="padding:16px 20px;border-top:1px solid #f1f5f9;">
            {{ $signatures->withQueryString()->links() }}
        </div>
        @endif
        @endif
    </div>
</div>
@endsection
