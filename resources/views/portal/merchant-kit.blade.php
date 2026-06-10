@extends('layouts.portal')

@section('content')
<style>
.kit-grid { display:grid; gap:20px; max-width:860px; }

.kit-hero {
    padding:28px 28px 24px;
    background:var(--grad-hero);
    border-radius:var(--radius-lg);
    border:1px solid rgba(255,255,255,.07);
    color:#fff;
    display:flex; align-items:center; gap:20px; flex-wrap:wrap;
}
.kit-hero-icon {
    width:56px; height:56px; border-radius:16px;
    background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.22);
    display:grid; place-items:center; font-size:28px; flex-shrink:0;
}
.kit-hero-title { font-size:22px; font-weight:800; margin:0 0 4px; }
.kit-hero-sub { font-size:14px; opacity:.8; margin:0; }

/* Tool cards */
.kit-tools { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; }
@media(max-width:680px){ .kit-tools { grid-template-columns:1fr; } }

.kit-tool {
    background:#fff; border:1px solid var(--line); border-radius:14px;
    padding:22px 22px 18px; display:flex; flex-direction:column; gap:14px;
}
.kit-tool-header { display:flex; align-items:center; gap:12px; }
.kit-tool-icon {
    width:40px; height:40px; border-radius:12px;
    display:grid; place-items:center; font-size:20px; flex-shrink:0;
}
.kit-tool-name { font-size:15px; font-weight:700; color:var(--ink); margin:0 0 2px; }
.kit-tool-desc { font-size:12px; color:var(--ink-muted); margin:0; }

/* QR box */
.kit-qr-box {
    background:#f8fafc; border:1px solid var(--line); border-radius:12px;
    padding:18px; display:flex; flex-direction:column; align-items:center; gap:10px;
}
.kit-qr-number { font-size:12px; font-family:monospace; color:var(--ink-muted); letter-spacing:.05em; }

/* Link box */
.kit-link-box {
    display:flex; gap:8px; align-items:center;
    background:#f8fafc; border:1px solid var(--line); border-radius:10px;
    padding:10px 14px;
}
.kit-link-url {
    flex:1; font-size:12px; color:var(--ink-muted); font-family:monospace;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    min-width:0;
}
.kit-copy-btn {
    padding:6px 12px; background:var(--ink); color:#fff;
    border:none; border-radius:8px; font-size:12px; font-weight:700;
    cursor:pointer; flex-shrink:0; transition:opacity .15s;
}
.kit-copy-btn:hover { opacity:.8; }

/* Steps */
.kit-steps { counter-reset:step; display:flex; flex-direction:column; gap:10px; }
.kit-step {
    display:flex; gap:12px; align-items:flex-start;
    font-size:13px; color:var(--ink);
}
.kit-step::before {
    counter-increment:step;
    content:counter(step);
    width:24px; height:24px; border-radius:50%;
    background:var(--ink); color:#fff;
    font-size:11px; font-weight:800;
    display:grid; place-items:center; flex-shrink:0; margin-top:1px;
}

/* CTA row */
.kit-cta-row { display:flex; gap:10px; flex-wrap:wrap; }
</style>

<div class="kit-grid">

    {{-- Hero --}}
    <div class="kit-hero">
        <div class="kit-hero-icon">🛠️</div>
        <div>
            <h1 class="kit-hero-title">Kit merchant KMoney</h1>
            <p class="kit-hero-sub">Tutti gli strumenti per accettare pagamenti KY nel tuo negozio o studio.</p>
        </div>
    </div>

    @if(!$kyNumber)
        <div style="padding:18px;background:#fef3c7;border:1px solid #fcd34d;border-radius:12px;color:#92400e;font-size:14px;">
            Il tuo conto non ha ancora un numero KY assegnato. Contatta il supporto.
        </div>
    @else

    <div class="kit-tools">

        {{-- QR CODE --}}
        <div class="kit-tool">
            <div class="kit-tool-header">
                <div class="kit-tool-icon" style="background:#eff6ff;">📱</div>
                <div>
                    <p class="kit-tool-name">QR personale</p>
                    <p class="kit-tool-desc">Il cliente scansiona e ti invia l'importo che decide.</p>
                </div>
            </div>
            <div class="kit-qr-box">
                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(160)->errorCorrection('H')->generate($qrPayUrl) !!}
                <span class="kit-qr-number">{{ $kyNumber }}</span>
            </div>
            <div class="kit-cta-row">
                <a href="{{ route('portal.merchant-kit.qr-pdf') }}" class="cta" style="flex:1;text-align:center;">
                    ⬇ Scarica PDF per stampa
                </a>
                <a href="https://wa.me/?text={{ $whatsappText }}" target="_blank" rel="noopener"
                   class="cta secondary" style="flex:1;text-align:center;">
                    WhatsApp
                </a>
            </div>
        </div>

        {{-- LINK DI PAGAMENTO --}}
        <div class="kit-tool">
            <div class="kit-tool-header">
                <div class="kit-tool-icon" style="background:#f0fdf4;">🔗</div>
                <div>
                    <p class="kit-tool-name">Link di pagamento</p>
                    <p class="kit-tool-desc">Condividi il tuo link personale ovunque.</p>
                </div>
            </div>
            <div class="kit-link-box">
                <span class="kit-link-url" id="pay-link-url">{{ $qrPayUrl }}</span>
                <button class="kit-copy-btn" onclick="copyLink()">Copia</button>
            </div>
            <div class="kit-cta-row">
                <a href="{{ route('portal.payment-links.create') }}" class="cta secondary" style="flex:1;text-align:center;">
                    + Link con importo fisso
                </a>
            </div>
            <p style="font-size:12px;color:var(--ink-muted);margin:0;">
                Il link con importo fisso è utile per fatture specifiche. Puoi crearne quanti ne vuoi.
            </p>
        </div>

        {{-- INCASSO QR DINAMICO --}}
        <div class="kit-tool">
            <div class="kit-tool-header">
                <div class="kit-tool-icon" style="background:#fefce8;">💰</div>
                <div>
                    <p class="kit-tool-name">Richiesta QR con importo</p>
                    <p class="kit-tool-desc">Genera un QR per un importo specifico al momento.</p>
                </div>
            </div>
            <div class="kit-steps">
                <div class="kit-step">Inserisci l'importo da incassare</div>
                <div class="kit-step">Mostra il QR al cliente</div>
                <div class="kit-step">Ricevi la notifica di pagamento avvenuto</div>
            </div>
            <div class="kit-cta-row">
                <a href="{{ route('portal.incasso-qr.form') }}" class="cta" style="flex:1;text-align:center;">
                    Genera QR con importo →
                </a>
            </div>
        </div>

        {{-- NFC --}}
        <div class="kit-tool">
            <div class="kit-tool-header">
                <div class="kit-tool-icon" style="background:#fdf4ff;">📶</div>
                <div>
                    <p class="kit-tool-name">Carta NFC fisica</p>
                    <p class="kit-tool-desc">Il cliente avvicina lo smartphone e paga.</p>
                </div>
            </div>
            <div class="kit-steps">
                <div class="kit-step">Richiedi la tua card NFC all'amministratore del circuito</div>
                <div class="kit-step">La card viene associata al tuo conto</div>
                <div class="kit-step">Il cliente avvicina il telefono alla card e inserisce l'importo</div>
            </div>
            <div class="kit-cta-row">
                <a href="{{ route('portal.nfc-cards.index') }}" class="cta secondary" style="flex:1;text-align:center;">
                    Le mie card NFC →
                </a>
            </div>
        </div>

    </div>

    {{-- Sezione istruzioni --}}
    <div class="kit-tool" style="max-width:860px;">
        <div class="kit-tool-header">
            <div class="kit-tool-icon" style="background:#f0f9ff;">ℹ️</div>
            <div>
                <p class="kit-tool-name">Come scegliere lo strumento giusto</p>
                <p class="kit-tool-desc">Dipende dal tuo modo di lavorare.</p>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;font-size:13px;color:var(--ink);">
            <div>
                <strong>Negozio fisico</strong><br>
                Stampa il PDF con il QR e appendilo alla cassa. I clienti scansionano e inseriscono l'importo.
            </div>
            <div>
                <strong>Professionista / fatture</strong><br>
                Crea un link con importo fisso per ogni fattura e invialo via email o WhatsApp.
            </div>
            <div>
                <strong>Ristorante / bar</strong><br>
                Usa la richiesta QR con importo al momento del conto. Mostra lo schermo al cliente.
            </div>
            <div>
                <strong>Cassa veloce</strong><br>
                La card NFC è lo strumento più rapido. Il cliente avvicina il telefono e conferma.
            </div>
        </div>
    </div>

    @endif

</div>

<script>
function copyLink() {
    const url = document.getElementById('pay-link-url').textContent.trim();
    navigator.clipboard.writeText(url).then(() => {
        const btn = document.querySelector('.kit-copy-btn');
        const orig = btn.textContent;
        btn.textContent = '✓ Copiato';
        setTimeout(() => { btn.textContent = orig; }, 2000);
    });
}
</script>
@endsection
