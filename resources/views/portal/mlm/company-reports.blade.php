{{--
    Pagina agente "Segnalazioni aziende" (feature richiesta da Laura il
    29/07/2026, vedi MlmPortalController::companyReports()/companyReportSign()/
    companyReportReject() e CompanyReportService): elenco delle segnalazioni
    ricevute dai propri clienti, con azione "Contratto firmato" (eroga
    subito il bonus KY al cliente) o "Non riuscita" (richiede una nota).
--}}
@extends('layouts.portal')

@section('content')
@if(session('status'))
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(22,163,74,.09);border:1px solid rgba(22,163,74,.3);color:#166534;font-size:13px;font-weight:600;">
        {{ session('status') }}
    </div>
@endif
@if($errors->any())
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);color:#b91c1c;font-size:13px;font-weight:600;">
        {{ $errors->first() }}
    </div>
@endif

<div class="card card-pad" style="margin-bottom:14px;">
    <h2 style="margin:0 0 4px;font-size:18px;">Segnalazioni aziende</h2>
    <p style="margin:0;color:var(--ink-muted);font-size:13px;">
        Aziende segnalate dai tuoi clienti dove vorrebbero spendere i loro KY. Se firmi un contratto
        con l'azienda, il cliente riceve subito il bonus KY previsto.
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

        <div style="display:flex;flex-direction:column;gap:10px;min-width:260px;">
            <form method="POST" action="{{ route('portal.mlm.company-reports.sign', $report) }}">
                @csrf
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px;">
                    <div style="font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Contratto firmato</div>
                    <input type="text" name="agent_notes" maxlength="1000" placeholder="Nota (facoltativa)"
                        style="width:100%;padding:7px 10px;border:1px solid #86efac;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box;margin-bottom:8px;">
                    <button type="submit" style="width:100%;padding:9px;background:#16a34a;color:#fff;border:none;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer;">
                        ✅ Contratto firmato
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('portal.mlm.company-reports.reject', $report) }}">
                @csrf
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;">
                    <div style="font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Non riuscita</div>
                    <input type="text" name="agent_notes" maxlength="1000" placeholder="Motivazione (obbligatoria)" required
                        style="width:100%;padding:7px 10px;border:1px solid #fca5a5;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box;margin-bottom:8px;">
                    <button type="submit"
                        onclick="return confirm('Confermi che la segnalazione di {{ $report->company_name }} non è andata a buon fine?')"
                        style="width:100%;padding:9px;background:#dc2626;color:#fff;border:none;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer;">
                        ❌ Non riuscita
                    </button>
                </div>
            </form>
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
