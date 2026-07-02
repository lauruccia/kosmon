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
    <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">Storico prelievi</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">
                I guadagni del multilevel (commissioni e bonus) vengono liquidati in euro con bonifico bancario dopo l'approvazione dell'amministrazione.
            </p>
        </div>
        <div style="text-align:right;">
            <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:2px;">Maturato disponibile</span>
            <strong style="font-size:24px;">&euro; {{ number_format($availableCents / 100, 2, ',', '.') }}</strong>
        </div>
    </div>

    <div style="margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        @if(! $paymentDetail)
            <a href="{{ route('portal.mlm.payment-details.edit') }}" class="btn btn-primary">Inserisci i dati bancari per prelevare</a>
            <span style="font-size:12px;color:var(--ink-muted);">Serve un IBAN verificato per ricevere il bonifico.</span>
        @elseif($hasOpenPayout)
            <button type="button" class="btn" disabled style="opacity:.55;cursor:not-allowed;">Effettua un prelievo</button>
            <span style="font-size:12px;color:var(--ink-muted);">Hai una richiesta in corso: attendi che venga elaborata.</span>
        @elseif($availableCents <= 0)
            <button type="button" class="btn" disabled style="opacity:.55;cursor:not-allowed;">Effettua un prelievo</button>
            <span style="font-size:12px;color:var(--ink-muted);">Non hai ancora importi maturati da prelevare.</span>
        @else
            <form method="POST" action="{{ route('portal.mlm.prelievi.store') }}"
                  onsubmit="return confirm('Richiedere il prelievo di &euro; {{ number_format($availableCents / 100, 2, ',', '.') }}? Verrà pagato con bonifico dopo l\'approvazione.');">
                @csrf
                <button type="submit" class="btn btn-primary">Effettua un prelievo (&euro; {{ number_format($availableCents / 100, 2, ',', '.') }})</button>
            </form>
        @endif
    </div>
</div>

<section class="card light-card">
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Richiesto il</th>
                <th style="text-align:right;">Commissioni</th>
                <th style="text-align:right;">Bonus</th>
                <th style="text-align:right;">Totale</th>
                <th>Stato</th>
                <th>Pagamento</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payouts as $payout)
                @php
                    [$label, $bg, $fg] = match($payout->status) {
                        'paid'     => ['Pagato', 'rgba(22,163,74,.12)', '#166534'],
                        'approved' => ['Approvato', 'rgba(12,74,134,.1)', '#0c4a86'],
                        'rejected' => ['Rifiutato', 'rgba(220,38,38,.1)', '#b91c1c'],
                        default    => ['In attesa', 'rgba(217,119,6,.12)', '#b45309'],
                    };
                @endphp
                <tr>
                    <td>{{ $payout->period_from->format('d/m/Y') }} — {{ $payout->period_to->format('d/m/Y') }}</td>
                    <td>{{ $payout->requested_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td style="text-align:right;">&euro; {{ number_format($payout->commissions_total_eur_cents / 100, 2, ',', '.') }}</td>
                    <td style="text-align:right;">&euro; {{ number_format($payout->bonus_total_eur_cents / 100, 2, ',', '.') }}</td>
                    <td style="text-align:right;"><strong>&euro; {{ number_format($payout->total_eur_cents / 100, 2, ',', '.') }}</strong></td>
                    <td><span class="pill" style="background:{{ $bg }};color:{{ $fg }};">{{ $label }}</span></td>
                    <td>
                        @if($payout->status === 'paid')
                            {{ $payout->paid_at?->format('d/m/Y') }}
                            @if($payout->payment_reference)
                                <span style="display:block;color:var(--ink-muted);font-size:12px;">Rif. {{ $payout->payment_reference }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun prelievo registrato.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

<div style="margin-top:14px;">{{ $payouts->links() }}</div>
@endsection
