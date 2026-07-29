{{--
    Pagina cliente "Segnala un'azienda" (feature richiesta da Laura il
    29/07/2026, vedi CompanyReportController/CompanyReportService): form di
    segnalazione in testo libero + storico delle proprie segnalazioni.
--}}
@extends('layouts.portal')

@section('content')

@if(session('success'))
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;font-size:14px;color:#166534;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
    ✅ {{ session('success') }}
</div>
@endif

<div class="card light-card" style="padding:18px 20px;margin-bottom:20px;">
    <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px;">Segnala un'azienda</div>
    <p style="font-size:13px;color:var(--ink-soft);line-height:1.55;margin:0;">
        Conosci un'azienda dove ti piacerebbe spendere i tuoi KY? Segnalacela: la richiesta arriva
        subito al tuo agente di riferimento. Se l'agente riesce a firmare un contratto con
        l'azienda, riceverai automaticamente un bonus KY sul tuo conto.
    </p>
</div>

<div class="card light-card" style="padding:22px;margin-bottom:20px;">
    @if($errors->any())
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:13px;color:#b91c1c;margin-bottom:14px;">
        {{ $errors->first() }}
    </div>
    @endif

    <form method="POST" action="{{ route('portal.company-reports.store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:12px;">
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--ink-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;">
                    Nome azienda *
                </label>
                <input type="text" name="company_name" maxlength="190" required
                    value="{{ old('company_name') }}"
                    placeholder="es. Bar Centrale"
                    style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:var(--bg);color:var(--ink);box-sizing:border-box;">
            </div>
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--ink-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;">
                    Città <span style="font-weight:400;">(facoltativa)</span>
                </label>
                <input type="text" name="company_city" maxlength="120"
                    value="{{ old('company_city') }}"
                    placeholder="es. Cagliari"
                    style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:var(--bg);color:var(--ink);box-sizing:border-box;">
            </div>
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:11px;font-weight:700;color:var(--ink-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;">
                Note <span style="font-weight:400;">(facoltative — indirizzo, contatti, perché la segnali...)</span>
            </label>
            <textarea name="company_notes" rows="3" maxlength="2000"
                placeholder="Indirizzo, contatti, oppure perché pensi sia adatta al circuito..."
                style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--bg);color:var(--ink);box-sizing:border-box;resize:vertical;">{{ old('company_notes') }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="padding:9px 22px;">Invia segnalazione</button>
    </form>
</div>

<section class="card" style="padding:0;overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--line);">
        <div class="card-title">Le mie segnalazioni</div>
    </div>
    @if($reports->isEmpty())
    <div style="padding:36px;text-align:center;color:var(--ink-muted);font-size:14px;">
        Non hai ancora segnalato nessuna azienda.
    </div>
    @else
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Azienda</th>
                <th>Città</th>
                <th>Inviata il</th>
                <th style="text-align:center;">Stato</th>
                <th>Nota agente</th>
            </tr>
        </thead>
        <tbody>
            @foreach($reports as $report)
            <tr>
                <td style="font-weight:600;">{{ $report->company_name }}</td>
                <td style="color:var(--ink-soft);">{{ $report->company_city ?? '—' }}</td>
                <td style="font-size:12px;color:var(--ink-muted);">{{ $report->created_at->format('d/m/Y H:i') }}</td>
                <td style="text-align:center;">
                    @if($report->isPending())
                        <span class="pill" style="background:rgba(217,119,6,.12);color:#b45309;">In attesa</span>
                    @elseif($report->isContractSigned())
                        <span class="pill" style="background:rgba(22,163,74,.12);color:#166534;">Contratto firmato</span>
                    @else
                        <span class="pill" style="background:rgba(220,38,38,.1);color:#b91c1c;">Non riuscita</span>
                    @endif
                </td>
                <td style="font-size:12px;color:var(--ink-soft);max-width:220px;">{{ $report->agent_notes ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</section>
<div style="margin-top:14px;">{{ $reports->links() }}</div>

@endsection
