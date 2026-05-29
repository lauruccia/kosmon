@extends('layouts.portal')

@section('content')
<div style="max-width:440px;margin:0 auto;">
    <div class="stack">

        <div>
            <a href="{{ route('portal.nfc-cards.index') }}" style="font-size:13px;color:var(--ink-muted);">&#8592; Le mie card</a>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:8px 0 4px;">Attiva la tua Card NFC</h1>
            <div style="font-size:13px;color:var(--ink-muted);">Card: <strong>{{ $card->serial_number ?? substr($card->uuid, 0, 8) }}</strong></div>
        </div>

        <section class="card card-pad" style="text-align:center;padding:24px;">
            <div style="font-size:56px;margin-bottom:8px;">&#128246;</div>
            <div style="font-size:14px;color:var(--ink-muted);">Scegli un PIN per proteggere i pagamenti con questa card.</div>
        </section>

        <section class="card card-pad">
            <form method="POST" action="{{ route('portal.nfc-cards.activate.post', $card->uuid) }}" class="stack">
                @csrf

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        PIN (4-8 cifre) *
                    </label>
                    <input type="password" name="pin" inputmode="numeric" pattern="\d*"
                           minlength="4" maxlength="8" required autocomplete="new-password"
                           placeholder="&#9679;&#9679;&#9679;&#9679;"
                           style="width:100%;border:1.5px solid var(--line);border-radius:12px;padding:14px;font-size:24px;text-align:center;letter-spacing:8px;background:var(--surface-soft);color:var(--ink);outline:none;">
                    @error('pin')<p style="color:var(--danger);font-size:12px;margin-top:4px;">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                        Conferma PIN *
                    </label>
                    <input type="password" name="pin_confirmation" inputmode="numeric" pattern="\d*"
                           minlength="4" maxlength="8" required autocomplete="new-password"
                           placeholder="&#9679;&#9679;&#9679;&#9679;"
                           style="width:100%;border:1.5px solid var(--line);border-radius:12px;padding:14px;font-size:24px;text-align:center;letter-spacing:8px;background:var(--surface-soft);color:var(--ink);outline:none;">
                </div>

                <div style="background:var(--surface-soft);border-radius:10px;padding:12px 14px;font-size:12px;color:var(--ink-muted);line-height:1.5;">
                    &#128274; Il PIN protegge ogni pagamento effettuato con questa card. Viene richiesto ogni volta che un merchant avvicina la card al suo dispositivo.
                </div>

                <button type="submit" class="cta" style="width:100%;">
                    Attiva card con questo PIN
                </button>
            </form>
        </section>

    </div>
</div>
@endsection
