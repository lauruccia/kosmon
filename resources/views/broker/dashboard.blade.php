@extends('layouts.portal')

@section('content')
@if($clients->isEmpty())
    <div class="card light-card card-pad" style="text-align:center;padding:48px;">
        <div style="font-size:32px;margin-bottom:12px;">👥</div>
        <strong>Nessun cliente assegnato.</strong>
        <p class="table-muted" style="margin-top:6px;">Chiedi all'amministratore di assegnarti le aziende da gestire.</p>
    </div>
@else
<div style="display:grid;gap:14px;">
    @foreach($clients as $row)
        @php($company = $row['company']) @php($account = $row['account']) @php($last = $row['recentTransfer'])
        <div class="card light-card" style="padding:16px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">

            {{-- Info azienda --}}
            <div style="flex:1;min-width:180px;">
                <div style="font-weight:700;font-size:15px;">{{ $company->name }}</div>
                <div class="table-muted" style="font-size:12px;margin-top:2px;">
                    {{ $company->sector ?? 'Settore n.d.' }}
                    &middot;
                    <span class="chip {{ $company->status === 'active' ? 'success' : 'pink' }}" style="font-size:10px;">
                        {{ $company->status === 'active' ? 'Attiva' : 'Sospesa' }}
                    </span>
                </div>
            </div>

            {{-- Saldo --}}
            <div style="min-width:130px;text-align:center;">
                @if($account)
                    <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Saldo attuale</div>
                    <div style="font-size:20px;font-weight:800;color:{{ $account->available_balance >= 0 ? 'var(--ink)' : '#dc2626' }};">
                        {{ number_format($account->available_balance, 2, ',', '.') }} <span style="font-size:12px;font-weight:600;">KY</span>
                    </div>
                    <div style="font-size:10px;color:var(--text-muted);">Disponibile: {{ number_format($account->saldoDisponibile(), 2, ',', '.') }} KY</div>
                @else
                    <span class="table-muted" style="font-size:12px;">Nessun conto</span>
                @endif
            </div>

            {{-- Ultimo movimento --}}
            <div style="min-width:160px;font-size:12px;color:var(--text-muted);">
                @if($last)
                    <div style="font-weight:600;color:var(--text);">Ultimo mov.</div>
                    <div>{{ $last->booked_at->format('d/m/Y') }} · {{ number_format($last->amount, 2, ',', '.') }} KY</div>
                @else
                    <div>Nessun movimento</div>
                @endif
            </div>

            {{-- Azioni --}}
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <a href="{{ route('broker.clients.show', $company) }}" class="cta secondary" style="font-size:12px;min-height:32px;">Scheda</a>
                @if($account)
                    <a href="{{ route('broker.pay.form', $company) }}" class="cta" style="font-size:12px;min-height:32px;">Paga per loro</a>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
@endsection
