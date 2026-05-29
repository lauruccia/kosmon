@extends('layouts.portal')

@section('content')
{{-- Grid a 2 colonne: form a sinistra, istruzioni a destra --}}
    <div style="display:grid;grid-template-columns:minmax(300px,480px) 1fr;gap:20px;align-items:start;width:100%;">

        {{-- Colonna sinistra: form --}}
        <div class="stack">
            <section class="card card-pad">
                <div class="k-tag" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;">
                    <span style="font-size:13px;">&#128246;</span> Nuova richiesta
                </div>

                <form method="POST" action="{{ route('portal.incasso-nfc.store') }}" id="nfc-form">
                    @csrf

                    {{-- Importo --}}
                    <div style="margin-bottom:20px;">
                        <label for="amount" style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                            Importo da incassare
                        </label>

                        {{-- Preset rapidi --}}
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;" id="presets">
                            @foreach([5, 10, 25, 50, 100] as $p)
                                <button type="button" class="preset-btn" data-value="{{ $p }}"
                                    style="padding:6px 14px;font-size:13px;font-weight:600;border-radius:8px;border:1px solid var(--line);background:var(--surface-soft);color:var(--ink-soft);cursor:pointer;transition:all .15s;white-space:nowrap;">
                                    {{ $p }} KY
                                </button>
                            @endforeach
                        </div>

                        {{-- Input importo --}}
                        <div id="amount-wrapper" style="display:flex;align-items:center;background:var(--surface-soft);border:1.5px solid var(--line);border-radius:12px;padding:4px 16px 4px 4px;transition:border-color .2s;">
                            <input type="number" name="amount" id="amount"
                                   min="1" max="9999999"
                                   value="{{ old('amount') }}"
                                   placeholder="0"
                                   autofocus
                                   style="flex:1;border:none;background:transparent;padding:14px 12px;font-size:32px;font-weight:800;color:var(--ink);outline:none;min-width:0;-moz-appearance:textfield;"
                                   required>
                            <span style="font-size:18px;font-weight:700;color:var(--ink-muted);flex-shrink:0;">KY</span>
                        </div>

                        @error('amount')
                            <p style="color:var(--danger);font-size:12px;margin-top:6px;display:flex;align-items:center;gap:4px;">
                                &#9888; {{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Causale --}}
                    <div style="margin-bottom:24px;">
                        <label for="description" style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px;">
                            Causale <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:11px;">(opzionale)</span>
                        </label>
                        <input type="text" name="description" id="description" maxlength="200"
                               value="{{ old('description') }}"
                               placeholder="es. Pranzo del 27/05"
                               style="width:100%;border:1.5px solid var(--line);border-radius:12px;padding:11px 14px;font-size:14px;color:var(--ink);background:var(--surface-soft);outline:none;transition:border-color .2s;">
                    </div>

                    {{-- Submit --}}
                    <button type="submit" class="cta" style="width:100%;font-size:15px;padding:13px;">
                        &#128246; Genera richiesta NFC
                    </button>

                </form>
            </section>
        </div>

        {{-- Colonna destra: istruzioni --}}
        <div class="stack">
            <section class="card card-pad">
                <div style="font-size:13px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:16px;">
                    Come funziona
                </div>

                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-soft,#eff6ff);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;font-weight:800;color:var(--primary);">1</div>
                        <div>
                            <div style="font-weight:700;font-size:14px;color:var(--ink);margin-bottom:2px;">Inserisci l'importo</div>
                            <div style="font-size:13px;color:var(--ink-muted);">Scegli un preset o digita manualmente i KY da incassare.</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-soft,#eff6ff);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;font-weight:800;color:var(--primary);">2</div>
                        <div>
                            <div style="font-weight:700;font-size:14px;color:var(--ink);margin-bottom:2px;">Genera la richiesta</div>
                            <div style="font-size:13px;color:var(--ink-muted);">Viene creata una richiesta di pagamento con QR code e NFC.</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-soft,#eff6ff);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;font-weight:800;color:var(--primary);">3</div>
                        <div>
                            <div style="font-weight:700;font-size:14px;color:var(--ink);margin-bottom:2px;">Il cliente paga</div>
                            <div style="font-size:13px;color:var(--ink-muted);">Avvicina il dispositivo o fai scansionare il QR. Il pagamento è confermato in tempo reale.</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px;padding:12px 14px;background:var(--surface-soft);border-radius:10px;border:1px solid var(--line);">
                    <div style="font-size:12px;font-weight:700;color:var(--ink-muted);margin-bottom:4px;">&#9888; Nota</div>
                    <div style="font-size:12.5px;color:var(--ink-muted);line-height:1.5;">La richiesta NFC scade automaticamente dopo <strong>5 minuti</strong>. NFC è disponibile solo su dispositivi compatibili con HTTPS.</div>
                </div>
            </section>
        </div>

    </div>

    <script>
    (function () {
        // Preset buttons
        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('amount').value = this.dataset.value;
                document.querySelectorAll('.preset-btn').forEach(b => {
                    b.style.background = 'var(--surface-soft)';
                    b.style.color = 'var(--ink-soft)';
                    b.style.borderColor = 'var(--line)';
                });
                this.style.background = 'var(--primary)';
                this.style.color = '#fff';
                this.style.borderColor = 'var(--primary)';
            });
        });

        // Focus glow on amount input
        const amountInput   = document.getElementById('amount');
        const amountWrapper = document.getElementById('amount-wrapper');
        amountInput.addEventListener('focus', () => amountWrapper.style.borderColor = 'var(--primary)');
        amountInput.addEventListener('blur',  () => amountWrapper.style.borderColor = 'var(--line)');

        // Focus glow on description input
        const descInput = document.getElementById('description');
        if (descInput) {
            descInput.addEventListener('focus', () => descInput.style.borderColor = 'var(--primary)');
            descInput.addEventListener('blur',  () => descInput.style.borderColor = 'var(--line)');
        }
    })();
    </script>
@endsection
