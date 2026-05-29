@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <a href="{{ route('broker.dashboard') }}" style="font-size:12px;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:4px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            Dashboard operatore
        </a>
    </div>
    <h2 style="margin-top:6px;">{{ $company->name }}</h2>
    <p>Scheda cliente — saldo, movimenti e azioni rapide.</p>
</section>

{{-- KPI Strip --}}
<div class="kpi-strip" style="margin-bottom:20px;">
    <div class="kpi-card">
        <div class="kpi-label">Saldo attuale</div>
        <div class="kpi-value {{ $account->available_balance >= 0 ? 'positive' : '' }}" style="{{ $account->available_balance < 0 ? 'color:#dc2626;' : '' }}">
            {{ number_format($account->available_balance, 2, ',', '.') }}
            <small style="font-size:13px;font-weight:600;">KY</small>
        </div>
        <div class="kpi-note">Bilancio totale</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Disponibile</div>
        <div class="kpi-value teal">{{ number_format($account->saldoDisponibile(), 2, ',', '.') }} <small style="font-size:13px;font-weight:600;">KY</small></div>
        <div class="kpi-note">Pronto all'uso</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Massimale</div>
        <div class="kpi-value">{{ number_format($account->creditLimits->sum('limit_amount'), 2, ',', '.') }} <small style="font-size:13px;font-weight:600;">KY</small></div>
        <div class="kpi-note">Fido assegnato</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Stato conto</div>
        <div class="kpi-value" style="font-size:16px;margin-top:4px;">
            <span class="chip {{ $account->status === 'active' ? 'success' : 'pink' }}" style="font-size:13px;">
                {{ $account->status === 'active' ? 'Attivo' : 'Sospeso' }}
            </span>
        </div>
        <div class="kpi-note">{{ $company->sector ?? 'Settore n.d.' }}</div>
    </div>
</div>

{{-- Azioni rapide --}}
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
    @if($account->status === 'active')
        <a href="{{ route('broker.pay.form', $company) }}" class="cta" style="gap:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Paga per loro
        </a>
    @endif
    <a href="{{ route('portal.statement') }}?account={{ $account->id }}" class="cta secondary" style="gap:6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Estratto conto
    </a>
</div>

{{-- Info azienda --}}
<div class="portal-grid" style="--grid-cols:2;">

    <section class="card light-card card-pad">
        <div class="eyebrow" style="margin-bottom:10px;">Dati azienda</div>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <tr>
                <td style="padding:5px 0;color:var(--text-muted);width:40%;">Ragione sociale</td>
                <td style="padding:5px 0;font-weight:600;">{{ $company->name }}</td>
            </tr>
            @if($company->vat_number)
            <tr>
                <td style="padding:5px 0;color:var(--text-muted);">P. IVA</td>
                <td style="padding:5px 0;">{{ $company->vat_number }}</td>
            </tr>
            @endif
            @if($company->email)
            <tr>
                <td style="padding:5px 0;color:var(--text-muted);">Email</td>
                <td style="padding:5px 0;"><a href="mailto:{{ $company->email }}" style="color:var(--primary);">{{ $company->email }}</a></td>
            </tr>
            @endif
            @if($company->phone)
            <tr>
                <td style="padding:5px 0;color:var(--text-muted);">Telefono</td>
                <td style="padding:5px 0;">{{ $company->phone }}</td>
            </tr>
            @endif
            <tr>
                <td style="padding:5px 0;color:var(--text-muted);">Stato KYC</td>
                <td style="padding:5px 0;">
                    <span class="chip {{ $company->kyc_status === 'approved' ? 'success' : ($company->kyc_status === 'rejected' ? 'pink' : '') }}">
                        {{ $company->kyc_status_label }}
                    </span>
                </td>
            </tr>
            <tr>
                <td style="padding:5px 0;color:var(--text-muted);">N° conto</td>
                <td style="padding:5px 0;font-family:monospace;font-size:12px;">{{ $account->account_number }}</td>
            </tr>
        </table>
    </section>

    {{-- Utenti del conto --}}
    <section class="card light-card card-pad">
        <div class="eyebrow" style="margin-bottom:10px;">Utenti associati</div>
        @php($companyUsers = $company->users ?? collect())
        @if($companyUsers->isEmpty())
            <p class="table-muted" style="font-size:13px;">Nessun utente associato.</p>
        @else
            <div class="timeline-list" style="gap:6px;">
                @foreach($companyUsers->take(5) as $cu)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);font-size:13px;">
                    <div>
                        <strong>{{ $cu->name }}</strong>
                        <div class="table-muted" style="font-size:11px;">{{ $cu->email }}</div>
                    </div>
                    <span class="chip {{ $cu->is_active ? 'success' : 'pink' }}" style="font-size:10px;">
                        {{ $cu->is_active ? 'Attivo' : 'Inattivo' }}
                    </span>
                </div>
                @endforeach
            </div>
        @endif
    </section>

</div>

{{-- Movimenti recenti --}}
<section class="card" style="padding:0;overflow:hidden;margin-top:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line);">
        <div class="card-title">Ultimi movimenti</div>
        <span class="table-muted" style="font-size:12px;">Ultimi {{ $transfers->count() }} movimenti contabilizzati</span>
    </div>

    @if($transfers->isEmpty())
        <div style="text-align:center;padding:36px;color:var(--text-muted);">
            <div style="font-size:28px;margin-bottom:8px;">📭</div>
            <strong>Nessun movimento disponibile.</strong>
        </div>
    @else
    <table class="transactions-table">
        <thead>
            <tr>
                <th>Data</th>
                <th>Controparte</th>
                <th>Descrizione</th>
                <th>Tipo</th>
                <th style="text-align:right;">Importo</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transfers as $transfer)
                @php($isOutgoing = $transfer->from_account_id === $account->id)
                @php($counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount)
                <tr>
                    <td style="padding:10px 12px;white-space:nowrap;">
                        <div class="date-block" style="font-size:16px;">
                            {{ optional($transfer->booked_at)->format('d') }}
                            <span>{{ optional($transfer->booked_at)->format('M') }}</span>
                        </div>
                    </td>
                    <td style="padding:10px 12px;">
                        <strong style="font-size:13px;">{{ $counterparty?->display_name ?? '—' }}</strong>
                    </td>
                    <td style="padding:10px 12px;max-width:220px;">
                        <span class="subtle" style="font-size:12px;">{{ $transfer->description ?: 'Movimento circuito' }}</span>
                    </td>
                    <td class="flow {{ $isOutgoing ? 'out' : 'in' }}" style="padding:10px 12px;font-size:12px;white-space:nowrap;">
                        {{ $isOutgoing ? 'Uscita' : 'Entrata' }}
                    </td>
                    <td style="text-align:right;padding:10px 12px;white-space:nowrap;">
                        <strong style="font-size:14px;color:{{ $isOutgoing ? '#dc2626' : 'var(--teal-strong)' }};">
                            {{ $isOutgoing ? '-' : '+' }}{{ number_format($transfer->amount, 2, ',', '.') }}
                        </strong>
                        <div style="color:var(--text-muted);font-size:10px;font-weight:700;">KY</div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</section>

@endsection
