@extends('layouts.portal')

@section('content')
<div style="max-width:420px;margin:0 auto;">
    <div class="stack">

        <div style="text-align:center;">
            <div style="font-size:56px;margin-bottom:8px;">&#128246;</div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0;">Richiesta di pagamento</h1>
        </div>

        {{-- Riepilogo richiesta --}}
        <section class="card card-pad" style="text-align:center;">
            <div style="font-size:40px;font-weight:900;color:var(--ink);">
                {{ ky_format($session->amount) }} KY
            </div>
            @if($session->description)
                <div style="font-size:14px;color:var(--ink-muted);margin-top:4px;">{{ $session->description }}</div>
            @endif
            <div style="margin-top:12px;font-size:13px;color:var(--ink-muted);">
                Richiesta da <strong style="color:var(--ink);">{{ $session->merchant->name }}</strong>
            </div>
            <div style="margin-top:8px;font-size:12px;color:var(--ink-muted);">
                Card: {{ $session->card->serial_number ?? substr($session->card->uuid, 0, 8) }}
            </div>

            {{-- Countdown --}}
            <div style="margin-top:16px;display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:var(--ink-muted);">
                &#9203; Scade tra <span id="countdown" style="font-weight:700;color:var(--ink);">3:00</span>
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

        {{-- Form PIN --}}
        <section class="card card-pad">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:16px;text-align:center;">
                &#128274; Inserisci il tuo PIN per confermare
            </div>

            <form method="POST" action="{{ route('nfc.card.authorize.post', $session->nonce) }}">
                @csrf
                <div style="margin-bottom:20px;">
                    <input type="password" name="pin" inputmode="numeric" pattern="\d*"
                           minlength="4" maxlength="8" required autofocus autocomplete="current-password"
                           placeholder="&#9679;&#9679;&#9679;&#9679;"
                           style="width:100%;border:1.5px solid var(--line);border-radius:12px;padding:16px;font-size:28px;text-align:center;letter-spacing:10px;background:var(--surface-soft);color:var(--ink);outline:none;">
                </div>

                <button type="submit" class="cta" style="width:100%;font-size:16px;padding:14px;">
                    Autorizza pagamento
                </button>

                <a href="{{ route('portal.dashboard') }}"
                   style="display:block;text-align:center;margin-top:12px;font-size:13px;color:var(--ink-muted);">
                    Rifiuta
                </a>
            </form>
        </section>

        <div style="font-size:11px;color:var(--ink-muted);text-align:center;line-height:1.5;">
            Dopo 3 tentativi errati la card viene bloccata temporaneamente per 30 minuti.
        </div>

    </div>
</div>

<script>
(function () {
    const expiresAt = new Date(@json($session->expires_at->valueOf()));
    const totalMs   = 3 * 60 * 1000;
    const cntEl     = document.getElementById('countdown');
    const barEl     = document.getElementById('timer-bar');

    function tick() {
        const remaining = Math.max(0, expiresAt - Date.now());
        const secs      = Math.floor(remaining / 1000);
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        if (cntEl) cntEl.textContent = m + ':' + String(s).padStart(2, '0');
        if (barEl)  barEl.style.width = (remaining / totalMs * 100) + '%';

        if (remaining <= 0) {
            clearInterval(timer);
            window.location = '{{ route('portal.dashboard') }}';
        }
    }
    const timer = setInterval(tick, 500);
    tick();
})();
</script>
@endsection
