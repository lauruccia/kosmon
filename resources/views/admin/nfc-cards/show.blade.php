@extends('layouts.portal')

@section('content')
<div class="stack">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <a href="{{ route('admin.nfc-cards.index') }}" style="font-size:13px;color:var(--ink-muted);">&#8592; Torna alle card</a>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:8px 0 2px;">
                &#128246; {{ $card->serial_number ?? substr($card->uuid, 0, 8) }}
            </h1>
            <div style="font-size:13px;color:var(--ink-muted);">
                Cliente: <strong>{{ $card->company->name }}</strong>
            </div>
        </div>
        <div style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 16px;font-size:12px;">
            <div style="color:var(--ink-muted);margin-bottom:2px;">Formato seriale</div>
            <code style="font-size:13px;font-weight:700;color:var(--primary);">KMY-YYYY-XXXXXX-C</code>
        </div>
    </div>

        @if(session('success'))
            <div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;font-size:13px;color:#166534;">
                &#10003; {{ session('success') }}
            </div>
        @endif

        {{-- Stato card --}}
        @php
            $statusLabels = [
                'pending'   => ['label'=>'In attesa di scrittura chip','color'=>'#854d0e','bg'=>'#fef9c3'],
                'issued'    => ['label'=>'Chip scritto — da consegnare','color'=>'#1e40af','bg'=>'#dbeafe'],
                'delivered' => ['label'=>'Consegnata — in attesa attivazione cliente','color'=>'#6d28d9','bg'=>'#ede9fe'],
                'active'    => ['label'=>'Attiva','color'=>'#166534','bg'=>'#dcfce7'],
                'blocked'   => ['label'=>'Bloccata dal cliente','color'=>'#991b1b','bg'=>'#fee2e2'],
                'revoked'   => ['label'=>'Revocata','color'=>'#6b7280','bg'=>'#f3f4f6'],
            ];
            $sl = $statusLabels[$card->status] ?? ['label'=>$card->status,'color'=>'#374151','bg'=>'#f3f4f6'];
        @endphp
        <div style="display:flex;align-items:center;gap:10px;padding:14px 16px;border-radius:12px;background:{{ $sl['bg'] }};border:1px solid rgba(0,0,0,.06);">
            <span style="font-weight:700;color:{{ $sl['color'] }};font-size:14px;">{{ $sl['label'] }}</span>
        </div>

        {{-- Sezione: scrivi chip NFC --}}
        @if($card->status === 'pending')
        <section class="card card-pad">
            <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px;">&#128246; Scrivi il chip NFC</div>
            <div style="font-size:13px;color:var(--ink-muted);margin-bottom:16px;">
                Avvicina il chip NFC vuoto a questo dispositivo e premi il pulsante. Il sistema scriverà l'URL firmato sul chip.
            </div>

            <div style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:11px;font-family:monospace;word-break:break-all;color:var(--ink-muted);margin-bottom:16px;">
                {{ $card->nfc_payload }}
            </div>

            <div id="nfc-write-bar" style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;color:var(--ink-muted);text-align:center;margin-bottom:12px;">
                Premi il pulsante per avviare la scrittura NFC
            </div>

            <button id="nfc-write-btn" class="cta" style="width:100%;" onclick="writeNfc()">
                &#128246; Scrivi chip NFC
            </button>

            <form method="POST" action="{{ route('admin.nfc-cards.mark-issued', $card) }}" style="margin-top:10px;">
                @csrf
                <button type="submit" class="cta secondary" style="width:100%;font-size:13px;"
                        onclick="return confirm('Confermi che il chip è stato scritto correttamente?')">
                    &#10003; Segna come emessa (chip già scritto)
                </button>
            </form>
        </section>
        @endif

        {{-- Sezione: segna consegnata --}}
        @if($card->status === 'issued')
        <section class="card card-pad">
            <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:8px;">&#128230; Consegna al cliente</div>
            <p style="font-size:13px;color:var(--ink-muted);margin-bottom:16px;">
                Il chip è stato scritto. Consegna fisicamente la card a <strong>{{ $card->company->name }}</strong>, poi segna la consegna.
                Il cliente riceverà una notifica per attivare la card impostando il PIN.
            </p>
            <form method="POST" action="{{ route('admin.nfc-cards.mark-delivered', $card) }}">
                @csrf
                <button type="submit" class="cta" style="width:100%;">
                    &#10003; Segna come consegnata
                </button>
            </form>
        </section>
        @endif

        {{-- Tracking spedizione --}}
        @if(in_array($card->status, ['issued', 'pending', 'delivered']) && !in_array($card->status, ['revoked', 'active']))
        <section class="card card-pad">
            <div style="font-size:13px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;">📦 Spedizione</div>

            @if($card->shipped_at)
            <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#166534;">
                <div style="font-weight:700;margin-bottom:4px;">✅ Spedita il {{ $card->shipped_at->format('d/m/Y H:i') }}</div>
                @if($card->tracking_code)
                <div>Tracking: <strong style="font-family:monospace;">{{ $card->tracking_code }}</strong>
                    @if($card->shipping_carrier) · {{ $card->shipping_carrier }} @endif
                </div>
                @endif
            </div>
            @endif

            <form method="POST" action="{{ route('admin.nfc-cards.mark-shipped', $card) }}">
                @csrf
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                    <div class="field" style="margin:0;">
                        <label style="font-size:12px;font-weight:700;margin-bottom:4px;display:block;">Codice tracking <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="tracking_code" placeholder="Es. BRT123456789IT"
                            value="{{ $card->tracking_code }}" required style="font-size:13px;">
                    </div>
                    <div class="field" style="margin:0;">
                        <label style="font-size:12px;font-weight:700;margin-bottom:4px;display:block;">Corriere</label>
                        <select name="shipping_carrier" style="font-size:13px;">
                            <option value="">— Seleziona —</option>
                            @foreach(['BRT','GLS','SDA','Poste Italiane','DHL','Nexive','Bartolini','TNT','FedEx'] as $carrier)
                            <option value="{{ $carrier }}" @selected($card->shipping_carrier === $carrier)>{{ $carrier }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button type="submit" class="cta secondary" style="width:100%;font-size:13px;">
                    📦 {{ $card->shipped_at ? 'Aggiorna tracking' : 'Segna come spedita' }}
                </button>
            </form>
        </section>
        @endif

        {{-- Info card --}}
        <section class="card card-pad">
            <div style="font-size:13px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;">Dettagli card</div>
            <dl style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;font-size:13px;">
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">UUID</dt><dd style="font-family:monospace;font-size:11px;word-break:break-all;">{{ $card->uuid }}</dd></div>
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">Seriale</dt><dd>{{ $card->serial_number ?? '—' }}</dd></div>
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">Emessa da</dt><dd>{{ $card->issuer->name ?? '—' }}</dd></div>
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">Creata il</dt><dd>{{ $card->created_at->format('d/m/Y H:i') }}</dd></div>
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">Spedita il</dt><dd>{{ $card->shipped_at?->format('d/m/Y') ?? '—' }}</dd></div>
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">Consegnata</dt><dd>{{ $card->delivered_at?->format('d/m/Y') ?? '—' }}</dd></div>
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">Attivata</dt><dd>{{ $card->activated_at?->format('d/m/Y') ?? '—' }}</dd></div>
                <div><dt style="color:var(--ink-muted);margin-bottom:2px;">Ultimo uso</dt><dd>{{ $card->last_used_at?->diffForHumans() ?? 'Mai' }}</dd></div>
                @if($card->tracking_code)
                <div style="grid-column:1/-1;">
                    <dt style="color:var(--ink-muted);margin-bottom:2px;">Tracking</dt>
                    <dd><strong style="font-family:monospace;">{{ $card->tracking_code }}</strong>
                        @if($card->shipping_carrier) · {{ $card->shipping_carrier }} @endif
                    </dd>
                </div>
                @endif
                @if($card->notes)
                <div style="grid-column:1/-1;"><dt style="color:var(--ink-muted);margin-bottom:2px;">Note</dt><dd>{{ $card->notes }}</dd></div>
                @endif
            </dl>
        </section>

        {{-- Log recenti --}}
        @if($card->logs->count())
        <section class="card card-pad">
            <div style="font-size:13px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;">Log recenti</div>
            <div class="stack" style="gap:8px;">
                @foreach($card->logs as $log)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--surface-soft);border-radius:8px;font-size:12px;">
                    <span style="font-weight:600;color:var(--ink);">{{ $log->eventLabel() }}</span>
                    <span style="color:var(--ink-muted);">{{ $log->created_at->format('d/m H:i') }}</span>
                </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Revoca --}}
        @unless(in_array($card->status, ['revoked']))
        <section class="card card-pad" style="border:1px solid #fee2e2;">
            <div style="font-size:14px;font-weight:700;color:#991b1b;margin-bottom:8px;">&#9888; Zona pericolosa</div>
            <p style="font-size:13px;color:var(--ink-muted);margin-bottom:14px;">La revoca è permanente e irreversibile. La card non potrà più essere usata.</p>
            <form method="POST" action="{{ route('admin.nfc-cards.revoke', $card) }}"
                  onsubmit="return confirm('Sei sicuro di voler revocare definitivamente questa card?')">
                @csrf
                <div style="margin-bottom:10px;">
                    <input type="text" name="reason" placeholder="Motivo revoca (opzionale)"
                           style="width:100%;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff5f5;color:var(--ink);">
                </div>
                <button type="submit" style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;width:100%;">
                    Revoca card definitivamente
                </button>
            </form>
        </section>
        @endunless

</div>

<script>
async function writeNfc() {
    const bar = document.getElementById('nfc-write-bar');
    const btn = document.getElementById('nfc-write-btn');
    const payload = @json($card->nfc_payload);

    if (!('NDEFReader' in window)) {
        bar.textContent = 'NFC non disponibile su questo browser. Usa Chrome su Android.';
        bar.style.color = 'var(--danger)';
        return;
    }

    btn.disabled = true;
    bar.textContent = 'Avvicina il chip NFC vuoto al dispositivo...';
    bar.style.color = 'var(--ink)';

    try {
        const ndef = new NDEFReader();
        await ndef.write({ records: [{ recordType: 'url', data: payload }] });

        bar.textContent = '✓ Chip scritto con successo! Ora segna la card come emessa.';
        bar.style.background = '#dcfce7';
        bar.style.color = '#166534';
        bar.style.border = '1px solid #bbf7d0';
        btn.style.display = 'none';
    } catch (err) {
        bar.textContent = 'Errore: ' + (err.message || err.name) + '. Riprova.';
        bar.style.color = 'var(--danger)';
        btn.disabled = false;
    }
}
</script>
@endsection
