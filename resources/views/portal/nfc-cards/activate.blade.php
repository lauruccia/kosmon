@extends('layouts.portal')

@section('content')
    <div class="portal-grid" style="max-width:480px;">
        <div class="stack">

            {{-- Breadcrumb --}}
            <div style="font-size:13px;color:var(--ink-muted);">
                <a href="{{ route('portal.nfc-cards.index') }}" style="color:var(--primary);text-decoration:none;">Card NFC</a>
                <span style="margin:0 6px;">&#8250;</span>
                <a href="{{ route('portal.nfc-cards.show', $card->uuid) }}" style="color:var(--primary);text-decoration:none;">
                    {{ $card->serial_number ?? substr($card->uuid, 0, 8) }}
                </a>
                <span style="margin:0 6px;">&#8250;</span>
                <span>Attivazione</span>
            </div>

            <section class="card card-pad">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="font-size:48px;margin-bottom:12px;">&#128274;</div>
                    <div style="font-size:20px;font-weight:800;color:var(--ink);margin-bottom:6px;">Attiva la tua Card NFC</div>
                    <div style="font-size:13px;color:var(--ink-muted);max-width:320px;margin:0 auto;">
                        Crea un PIN da 4–8 cifre per proteggere i pagamenti con questa card.
                    </div>
                </div>

                {{-- Info card --}}
                <div style="padding:12px 14px;background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;margin-bottom:24px;text-align:center;">
                    <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">Card</div>
                    <div style="font-size:15px;font-weight:700;color:var(--ink);">{{ $card->serial_number ?? substr($card->uuid, 0, 8) }}</div>
                </div>

                <form method="POST" action="{{ route('portal.nfc-cards.activate.post', $card->uuid) }}" id="activate-form">
                    @csrf

                    {{-- PIN --}}
                    <div style="margin-bottom:18px;">
                        <label for="pin" style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                            Scegli PIN (4–8 cifre)
                        </label>
                        <input type="password" name="pin" id="pin"
                               inputmode="numeric" pattern="\d{4,8}" minlength="4" maxlength="8"
                               autocomplete="new-password" required
                               style="width:100%;border:1.5px solid {{ $errors->has('pin') ? '#dc2626' : 'var(--line)' }};border-radius:12px;padding:14px 16px;font-size:24px;letter-spacing:.3em;text-align:center;color:var(--ink);background:var(--surface-soft);outline:none;">
                        @error('pin')
                            <p style="color:#dc2626;font-size:12px;margin-top:6px;">&#9888; {{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Conferma PIN --}}
                    <div style="margin-bottom:28px;">
                        <label for="pin_confirmation" style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                            Conferma PIN
                        </label>
                        <input type="password" name="pin_confirmation" id="pin_confirmation"
                               inputmode="numeric" pattern="\d{4,8}" minlength="4" maxlength="8"
                               autocomplete="new-password" required
                               style="width:100%;border:1.5px solid var(--line);border-radius:12px;padding:14px 16px;font-size:24px;letter-spacing:.3em;text-align:center;color:var(--ink);background:var(--surface-soft);outline:none;">
                    </div>

                    <button type="submit" class="cta" style="width:100%;font-size:15px;padding:13px;">
                        Attiva card
                    </button>
                </form>

                <div style="margin-top:16px;padding:12px 14px;background:var(--surface-soft);border-radius:10px;border:1px solid var(--line);">
                    <div style="font-size:11px;font-weight:700;color:var(--ink-muted);margin-bottom:4px;">&#128274; Sicurezza</div>
                    <div style="font-size:12px;color:var(--ink-muted);line-height:1.5;">
                        Dopo 3 tentativi errati la card si blocca automaticamente per 30 minuti.
                        Non condividere il PIN con nessuno.
                    </div>
                </div>
            </section>

        </div>
    </div>

    <script>
    (function () {
        const pinInput   = document.getElementById('pin');
        const confInput  = document.getElementById('pin_confirmation');

        [pinInput, confInput].forEach(input => {
            input.addEventListener('focus', () => input.style.borderColor = 'var(--primary)');
            input.addEventListener('blur',  () => input.style.borderColor = 'var(--line)');
            // Accetta solo cifre
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '');
            });
        });
    })();
    </script>
@endsection
