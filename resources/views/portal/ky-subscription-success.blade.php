@extends('layouts.portal')

@section('content')
@php
    $isInstallments = $sub->kind === \App\Models\KyCardSubscription::KIND_INSTALLMENTS;
    $activated      = in_array($sub->status, ['active', 'completed', 'cancelling', 'past_due'], true) || $sub->installments_paid > 0;
@endphp
<div style="max-width:520px;margin:60px auto;text-align:center;">

    <div style="width:72px;height:72px;border-radius:50%;background:{{ $activated ? '#dcfce7' : '#eff6ff' }};display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:36px;">
        {{ $activated ? '✓' : '⏳' }}
    </div>

    @if($activated)
        <h1 style="font-size:26px;font-weight:800;color:var(--ink);margin-bottom:8px;">
            {{ $isInstallments ? 'Pagamento a rate attivato!' : 'Rinnovo mensile attivato!' }}
        </h1>
        <p style="color:var(--ink-soft);font-size:15px;margin-bottom:28px;">
            @if($isInstallments)
                Hai pagato la prima rata. I {{ ky_format($sub->ky_total) }} KY arrivano sul conto in pochi istanti.
            @else
                Hai pagato il primo mese. I {{ ky_format($sub->ky_total) }} KY arrivano sul conto in pochi istanti.
            @endif
        </p>
    @else
        <h1 style="font-size:26px;font-weight:800;color:var(--ink);margin-bottom:8px;">Pagamento in verifica</h1>
        <p style="color:var(--ink-soft);font-size:15px;margin-bottom:28px;">
            Stiamo aspettando la conferma da Stripe. Di solito servono pochi secondi:
            la pagina si aggiorna da sola.
        </p>
        <meta http-equiv="refresh" content="6">
    @endif

    <div class="card" style="padding:24px;margin-bottom:24px;text-align:left;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="font-size:13px;color:var(--ink-muted);">Card</div>
            <div style="font-size:14px;font-weight:700;">{{ $sub->kyCard->name ?? '—' }}</div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="font-size:13px;color:var(--ink-muted);">{{ $isInstallments ? 'Rata' : 'Importo mensile' }}</div>
            <div style="font-size:14px;font-weight:700;">{{ number_format($sub->amount_cents / 100, 2, ',', '.') }} &euro; al mese</div>
        </div>
        @if($isInstallments)
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="font-size:13px;color:var(--ink-muted);">Rate</div>
            <div style="font-size:14px;font-weight:700;">{{ (int) $sub->installments_paid }} di {{ (int) $sub->installments_total }} pagate</div>
        </div>
        @endif
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--border);">
            <div style="font-size:14px;font-weight:700;color:var(--ink);">KY</div>
            <div style="font-size:22px;font-weight:800;color:#1d4ed8;">{{ ky_format($sub->ky_total) }} KY</div>
        </div>
    </div>

    <p style="font-size:12.5px;color:var(--ink-muted);margin-bottom:20px;">
        @if($isInstallments)
            Le rate successive vengono addebitate in automatico ogni mese sulla stessa carta.
        @else
            Il rinnovo viene addebitato in automatico ogni mese sulla stessa carta.
        @endif
    </p>

    <div style="display:flex;gap:12px;justify-content:center;">
        <a href="{{ route('portal.dashboard') }}" class="cta" style="min-width:160px;justify-content:center;">Vai al conto</a>
        <a href="{{ route('portal.ky-cards.index') }}" class="cta secondary">Ricarica KY</a>
    </div>
</div>
@endsection
