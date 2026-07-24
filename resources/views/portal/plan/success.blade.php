@extends('layouts.portal')

@section('content')
<div style="max-width:520px;margin:0 auto;">
    <div class="card" style="padding:32px;text-align:center;">

        @if($payment->isCompleted())
            <div style="width:64px;height:64px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 18px;">✓</div>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--ink);">Upgrade completato!</h2>
            <p style="color:var(--ink-soft);font-size:14px;margin:0 0 24px;">
                Il tuo piano è ora <strong>{{ $payment->toPlan->name ?? '—' }}</strong>. Le modifiche sono già attive nella directory del circuito.
            </p>
        @elseif($payment->isPendingBankTransfer())
            <div style="width:64px;height:64px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 18px;">⏳</div>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--ink);">In attesa del bonifico</h2>
            <p style="color:var(--ink-soft);font-size:14px;margin:0 0 24px;">
                Ti abbiamo mostrato le coordinate bancarie. Il piano si attiva dopo verifica.
            </p>
        @elseif($payment->isFailed())
            <div style="width:64px;height:64px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 18px;">✕</div>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--ink);">Pagamento non riuscito</h2>
            <p style="color:var(--ink-soft);font-size:14px;margin:0 0 24px;">
                Il pagamento non è andato a buon fine. Puoi riprovare dalla pagina "Il mio piano".
            </p>
        @else
            <div style="width:64px;height:64px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 18px;">⏳</div>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--ink);">Pagamento in elaborazione</h2>
            <p style="color:var(--ink-soft);font-size:14px;margin:0 0 24px;">
                Stiamo verificando il pagamento. Ricarica la pagina tra qualche secondo se non vedi ancora una conferma.
            </p>
        @endif

        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;margin-bottom:20px;text-align:left;">
            <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--ink-soft);margin-bottom:6px;">
                <span>Piano</span>
                <span style="font-weight:700;color:var(--ink);">{{ $payment->fromPlan->name ?? '—' }} → {{ $payment->toPlan->name ?? '—' }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--ink-soft);">
                <span>Importo</span>
                <span style="font-weight:700;color:var(--ink);">{{ number_format($payment->amount_cents / 100, 2, ',', '.') }} €</span>
            </div>
        </div>

        <a href="{{ route('portal.plan.index') }}" class="cta" style="width:100%;justify-content:center;">Vai al mio piano</a>
    </div>
</div>
@endsection
