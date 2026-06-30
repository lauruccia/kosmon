@extends('layouts.portal')

@section('content')
<div style="max-width:520px;margin:60px auto;text-align:center;">

    <div style="width:72px;height:72px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:36px;">
        &#10003;
    </div>

    <h1 style="font-size:26px;font-weight:800;color:var(--ink);margin-bottom:8px;">Ricarica completata!</h1>

    @if($purchase->isCompleted())
        <p style="color:var(--ink-soft);font-size:15px;margin-bottom:28px;">
            I tuoi KY sono stati accreditati sul conto.
        </p>

        <div class="card" style="padding:24px;margin-bottom:24px;text-align:left;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div style="font-size:13px;color:var(--ink-muted);">Card acquistata</div>
                <div style="font-size:14px;font-weight:700;">{{ $purchase->kyCard->name ?? '—' }}</div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div style="font-size:13px;color:var(--ink-muted);">Pagato</div>
                <div style="font-size:14px;font-weight:700;">{{ number_format($purchase->price_eur, 2, ',', '.') }} &euro;</div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--border);">
                <div style="font-size:14px;font-weight:700;color:var(--ink);">KY accreditati</div>
                <div style="font-size:22px;font-weight:800;color:#1d4ed8;">+{{ ky_format($purchase->ky_amount) }} KY</div>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:center;">
            <a href="{{ route('portal.dashboard') }}" class="cta" style="min-width:160px;justify-content:center;">Vai al conto</a>
            <a href="{{ route('portal.ky-cards.index') }}" class="cta secondary">Acquista ancora</a>
        </div>
    @else
        <p style="color:var(--ink-soft);font-size:15px;margin-bottom:28px;">
            Il pagamento &egrave; in fase di verifica. I KY saranno accreditati a breve.
        </p>
        <a href="{{ route('portal.dashboard') }}" class="cta" style="min-width:160px;justify-content:center;">Torna alla dashboard</a>
    @endif

</div>
@endsection
