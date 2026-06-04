@extends('layouts.portal')

@section('content')
<div style="max-width:420px;margin:0 auto;">
    <div class="stack">

        <div style="text-align:center;">
            <div style="font-size:56px;margin-bottom:8px;">&#128246;</div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0;">Richiesta di pagamento</h1>
        </div>

        {{-- Riepilogo --}}
        <section class="card card-pad" style="text-align:center;">
            <div style="font-size:44px;font-weight:900;color:var(--ink);">
                {{ ky_format($session->amount) }} <span style="font-size:24px;font-weight:600;">KY</span>
            </div>
            @if($session->description)
                <div style="font-size:14px;color:var(--ink-muted);margin-top:6px;">{{ $session->description }}</div>
            @endif
            <div style="margin-top:14px;font-size:14px;color:var(--ink-muted);">
                Da <strong style="color:var(--ink);">{{ $session->merchant?->name ?? $session->merchantAccount?->display_name ?? 'Commerciante' }}</strong>
            </div>
            <div style="margin-top:6px;font-size:12px;color:var(--ink-muted);">
                Card: {{ $session->card->serial_number ?? substr($session->card->uuid, 0, 8) }}
            </div>

            {{-- Countdown --}}
            <div style="margin-top:16px;display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:var(--ink-muted);">
                &#9203; Scade tra <span id="countdown" style="font-weight:700;color:var(--ink);"></span>
            </div>
            <div style="margin-top:6px;height:3px;background:var(--surface-soft);border-radius:2px;overflow:hidden;">
                <div id="timer-bar" style="height:100%;background:var(--primary);transition:width .5s linear;width:100%;"></div>
            </div>
        </section>

        @if(session('portal_error'))
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;font-size:13px;color:#991b1b;">
                &#9888; {{ session('portal_error') }}
            </div>
        @endif

        {{-- Azioni --}}
        <form method="POST" action="{{ route('nfc.card.authorize.post', $session->nonce) }}">
            @csrf

            <div class="stack" style="gap:12px;">
                <button type="submit" id="btn-conferma" class="cta" style="width:100%;font-size:18px;padding:18px;border-radius:14px;">
                    &#10003;&nbsp; Conferma pagamento
                </button>

                <a href="{{ route('portal.dashboard') }}"
                   style="display:block;text-align:center;padding:14px;font-size:14px;color:var(--ink-muted);border:1.5px solid var(--line);border-radius:14px;text-decoration:none;">
                    Rifiuta
                </a>
            </div>
        </form>

        <p style="font-size:11px;color:var(--ink-muted);text-align:center;line-height:1.5;margin:0;">
            Confermando autorizzi l'addebito di {{ ky_format($session->amount) }} KY sul tuo conto.
        </p>

    </div>
</div>

<script>
(function () {
    const expiresAt = new Date(@json($session->expires_at->valueOf()));
    const totalMs   = 10 * 60 * 1000;
    const cntEl     = document.getElementById('countdown');
    const barEl     = document.getElementById('timer-bar');

    function tick() {
        const remaining = Math.max(0, expiresAt - Date.now());
        const secs = Math.floor(remaining / 1000);
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        if (cntEl) cntEl.textContent = m + ':' + String(s).padStart(2, '0');
        if (barEl)  barEl.style.width = (remaining / totalMs * 100) + '%';

        if (remaining <= 0) {
            clearInterval(timer);
            document.getElementById('btn-conferma').disabled = true;
            if (cntEl) cntEl.textContent = 'Scaduta';
            window.location = '{{ route('portal.dashboard') }}';
        }
    }
    const timer = setInterval(tick, 500);
    tick();
})();
</script>
@endsection
