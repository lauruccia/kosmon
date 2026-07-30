{{--
    Pagina agente "Segnalazioni aziende" (feature richiesta da Laura il
    29/07/2026, vedi MlmPortalController::companyReports() e
    CompanyReportService).

    AGGIORNAMENTO 30/07/2026 (decisione esplicita di Laura): pagina di
    SOLA VISIBILITÀ — la conferma "contratto firmato" (che eroga il bonus
    KY al cliente) o "non riuscita" spetta ora solo all'admin (vedi
    Admin\CompanyReportController::sign()/reject() e
    admin/mlm/company-reports.blade.php). Prima era l'agente a decidere e
    l'admin restava in copia: i ruoli sono stati invertiti.
--}}
@extends('layouts.portal')

@section('content')

<div class="card card-pad" style="margin-bottom:14px;">
    <h2 style="margin:0 0 4px;font-size:18px;">Segnalazioni aziende</h2>
    <p style="margin:0;color:var(--ink-muted);font-size:13px;">
        Aziende segnalate dai tuoi clienti dove vorrebbero spendere i loro KY. La conferma del
        contratto firmato (e l'erogazione del bonus KY al cliente) spetta all'admin: qui trovi
        solo lo stato delle segnalazioni.
    </p>
</div>

<section class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;">
        <div class="card-title">In attesa</div>
        @if($pending->count())
        <span class="chip" style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;">{{ $pending->count() }} da valutare</span>
        @endif
    </div>

    @if($pending->isEmpty())
    <div style="padding:36px;text-align:center;color:var(--ink-muted);font-size:14px;">
        Nessuna segnalazione in attesa. 🎉
    </div>
    @else
    @foreach($pending as $report)
    <div style="padding:18px 20px;border-bottom:1px solid var(--line);display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;">
        <div>
            <div style="font-size:15px;font-weight:700;color:var(--ink);">{{ $report->company_name }}</div>
            <div style="font-size:12px;color:var(--ink-muted);margin-bottom:6px;">
                {{ $report->company_city ?? 'Città non indicata' }} — segnalata il {{ $report->created_at->format('d/m/Y H:i') }}
            </div>
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:8px;">
                Segnalata da <strong>{{ $report->reporter->name ?? 'N/D' }}</strong>
                ({{ $report->reporter->email ?? '—' }})
            </div>
            @if($report->company_notes)
            <div style="background:var(--bg-soft,#f9fafb);border:1px solid var(--line);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--ink-soft);font-style:italic;">
                "{{ $report->company_notes }}"
            </div>
            @endif
        </div>

        <div style="min-width:200px;text-align:right;">
            <span class="chip" style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;">In attesa di conferma admin</span>
        </div>
    </div>
    @endforeach
    @endif
</section>

@if($closed->isNotEmpty())
<section class="card" style="padding:0;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--line);">
        <div class="card-title">Storico</div>
    </div>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Azienda</th>
                <th>Segnalata da</th>
                <th style="text-align:center;">Esito</th>
                <th style="text-align:right;">Bonus</th>
                <th>Nota</th>
                <th>Chiusa il</th>
            </tr>
        </thead>
        <tbody>
            @foreach($closed as $report)
            <tr>
                <td style="font-weight:600;">{{ $report->company_name }}</td>
                <td>{{ $report->reporter->name ?? 'N/D' }}</td>
                <td style="text-align:center;">
                    @if($report->isContractSigned())
                        <span class="chip success" style="font-size:10px;">Contratto firmato</span>
                    @else
                        <span class="chip" style="font-size:10px;background:#fef2f2;color:#991b1b;">Non riuscita</span>
                    @endif
                </td>
                <td style="text-align:right;font-weight:700;">
                    {{ $report->bonusTransfer ? ky_format($report->bonusTransfer->amount) . ' KY' : '—' }}
                </td>
                <td style="font-size:12px;color:var(--ink-soft);max-width:200px;">{{ $report->agent_notes ?? '—' }}</td>
                <td style="font-size:12px;color:var(--ink-muted);">{{ $report->actioned_at?->format('d/m/Y') ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>
<div style="margin-top:14px;">{{ $closed->links() }}</div>
@endif

@endsection
