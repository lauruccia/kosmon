{{--
    Pannello admin "Segnalazioni aziende" (feature richiesta da Laura il
    29/07/2026, vedi Admin\CompanyReportController): elenco READ-ONLY di
    TUTTE le segnalazioni di tutti gli agenti — l'admin è sempre e solo in
    copia/visibilità, nessuna azione di approvazione qui (le decisioni
    spettano all'agente assegnato, vedi portal/mlm/company-reports.blade.php).
--}}
@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
        <h2 style="margin:0 0 4px;font-size:18px;">Segnalazioni aziende</h2>
        <p style="margin:0;color:var(--ink-muted);font-size:13px;">
            Tutte le segnalazioni di tutti gli agenti. Sola visibilità: la decisione di firmare o
            rifiutare spetta all'agente assegnato al cliente.
        </p>
    </div>
    @if($pendingCount > 0)
    <span class="chip" style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;">{{ $pendingCount }} in attesa</span>
    @endif
</div>

<div class="card card-pad" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
    <a href="{{ route('admin.company-reports.index') }}" class="chip {{ !$statusFilter ? 'success' : '' }}" style="font-size:12px;">Tutte</a>
    <a href="{{ route('admin.company-reports.index', ['status' => 'pending']) }}" class="chip {{ $statusFilter === 'pending' ? 'success' : '' }}" style="font-size:12px;">In attesa</a>
    <a href="{{ route('admin.company-reports.index', ['status' => 'contract_signed']) }}" class="chip {{ $statusFilter === 'contract_signed' ? 'success' : '' }}" style="font-size:12px;">Contratto firmato</a>
    <a href="{{ route('admin.company-reports.index', ['status' => 'rejected']) }}" class="chip {{ $statusFilter === 'rejected' ? 'success' : '' }}" style="font-size:12px;">Non riuscite</a>
</div>

<section class="card" style="padding:0;overflow:hidden;">
    @if($reports->isEmpty())
    <div style="padding:36px;text-align:center;color:var(--ink-muted);font-size:14px;">
        Nessuna segnalazione trovata.
    </div>
    @else
    <table class="admin-table">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Agente</th>
                <th>Azienda</th>
                <th>Città</th>
                <th style="text-align:center;">Stato</th>
                <th>Nota agente</th>
                <th>Inviata il</th>
                <th>Chiusa il</th>
            </tr>
        </thead>
        <tbody>
            @foreach($reports as $report)
            <tr>
                <td>
                    <strong style="display:block;">{{ $report->reporter->name ?? 'N/D' }}</strong>
                    <span style="color:var(--ink-muted);font-size:12px;">{{ $report->reporter->email ?? '—' }}</span>
                </td>
                <td>
                    @if($report->agent)
                        <strong style="display:block;">{{ $report->agent->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $report->agent->email }}</span>
                    @else
                        <span style="color:var(--ink-muted);">Nessun agente</span>
                    @endif
                </td>
                <td style="font-weight:600;">{{ $report->company_name }}</td>
                <td style="color:var(--ink-soft);">{{ $report->company_city ?? '—' }}</td>
                <td style="text-align:center;">
                    @if($report->isPending())
                        <span class="chip" style="font-size:10px;background:#fef3c7;color:#92400e;">In attesa</span>
                    @elseif($report->isContractSigned())
                        <span class="chip success" style="font-size:10px;">Contratto firmato</span>
                    @else
                        <span class="chip" style="font-size:10px;background:#fef2f2;color:#991b1b;">Non riuscita</span>
                    @endif
                </td>
                <td style="font-size:12px;color:var(--ink-soft);max-width:180px;">{{ $report->agent_notes ?? '—' }}</td>
                <td style="font-size:12px;color:var(--ink-muted);">{{ $report->created_at->format('d/m/Y') }}</td>
                <td style="font-size:12px;color:var(--ink-muted);">{{ $report->actioned_at?->format('d/m/Y') ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</section>
<div style="margin-top:14px;">{{ $reports->links() }}</div>

@endsection
