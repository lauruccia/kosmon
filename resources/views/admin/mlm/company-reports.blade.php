{{--
    Pannello admin "Segnalazioni aziende" (feature richiesta da Laura il
    29/07/2026, vedi Admin\CompanyReportController).

    AGGIORNAMENTO 30/07/2026 (decisione esplicita di Laura): l'admin non è
    più solo in copia/visibilità — è l'UNICO che può segnare "contratto
    firmato" (eroga il bonus KY al cliente segnalante) o "non riuscita" per
    le segnalazioni in attesa. L'agente assegnato resta in sola lettura
    (vedi portal/mlm/company-reports.blade.php).
--}}
@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
        <h2 style="margin:0 0 4px;font-size:18px;">Segnalazioni aziende</h2>
        <p style="margin:0;color:var(--ink-muted);font-size:13px;">
            Tutte le segnalazioni di tutti gli agenti. La decisione di segnare "contratto firmato"
            o "non riuscita" spetta a te: l'agente assegnato resta in sola visibilità.
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
                <th>Dettagli segnalazione</th>
                <th>Referente</th>
                <th style="text-align:center;">Stato</th>
                <th>Nota</th>
                <th>Inviata il</th>
                <th>Chiusa il</th>
                <th style="min-width:200px;">Azione</th>
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
                <td>
                    <strong style="display:block;">{{ $report->company_name }}</strong>
                    <span style="color:var(--ink-muted);font-size:12px;">{{ $report->company_city ?? 'Città non indicata' }}</span>
                </td>
                <td style="font-size:12px;color:var(--ink-soft);max-width:220px;">
                    <div><strong>Settore:</strong> {{ $report->company_sector ?? '—' }}</div>
                    <div><strong>Conoscenza:</strong> {{ $report->knowledgeLevelLabel() ?? '—' }}</div>
                    @if($report->company_notes)
                    <div style="margin-top:4px;font-style:italic;">"{{ $report->company_notes }}"</div>
                    @endif
                </td>
                <td style="font-size:12px;color:var(--ink-soft);max-width:170px;">
                    @if($report->contact_name || $report->contact_phone || $report->contact_email)
                        @if($report->contact_name)<div>{{ $report->contact_name }}</div>@endif
                        @if($report->contact_phone)<div>📞 {{ $report->contact_phone }}</div>@endif
                        @if($report->contact_email)<div>✉️ {{ $report->contact_email }}</div>@endif
                    @else
                        <span style="color:var(--ink-muted);">Non indicato</span>
                    @endif
                </td>
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
                <td>
                    @if($report->isPending())
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <form method="POST" action="{{ route('admin.company-reports.sign', $report) }}">
                            @csrf
                            <input type="text" name="agent_notes" maxlength="1000" placeholder="Nota (facoltativa)"
                                style="width:100%;padding:5px 8px;border:1px solid #86efac;border-radius:6px;font-size:12px;margin-bottom:4px;box-sizing:border-box;">
                            <button type="submit" style="width:100%;padding:6px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:11px;cursor:pointer;">
                                ✅ Contratto firmato
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.company-reports.reject', $report) }}">
                            @csrf
                            <input type="text" name="agent_notes" maxlength="1000" placeholder="Motivazione (obbligatoria)" required
                                style="width:100%;padding:5px 8px;border:1px solid #fca5a5;border-radius:6px;font-size:12px;margin-bottom:4px;box-sizing:border-box;">
                            <button type="submit"
                                onclick="return confirm('Confermi che la segnalazione di {{ $report->company_name }} non è andata a buon fine?')"
                                style="width:100%;padding:6px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:11px;cursor:pointer;">
                                ❌ Non riuscita
                            </button>
                        </form>
                    </div>
                    @else
                    <span style="color:var(--ink-muted);font-size:12px;">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</section>
<div style="margin-top:14px;">{{ $reports->links() }}</div>

@endsection
