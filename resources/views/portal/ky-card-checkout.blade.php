@extends('layouts.portal')

@section('content')

{{-- ── BREADCRUMB ──────────────────────────────────────────────────────── --}}
<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-muted);margin-bottom:20px;">
    <a href="{{ route('portal.ky-cards.index') }}" style="color:var(--primary);text-decoration:none;font-weight:600;">← Ricarica KY</a>
    <span>/</span>
    <span style="color:var(--ink);">{{ $card->name }}</span>
</div>

{{-- ── LAYOUT CHECKOUT ─────────────────────────────────────────────────── --}}
<div id="checkout-grid" style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

    {{-- ════════════════════════════════════════════
         COLONNA SX — metodo di pagamento
    ═════════════════════════════════════════════ --}}
    <div>
        <div style="font-size:17px;font-weight:800;color:var(--ink);margin-bottom:16px;">Scegli il metodo di pagamento</div>

        {{-- ── Tab metodi ──────────────────────────────────────────────── --}}
        <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
            @php
                $hasStripe = $card->stripe_price_id && config('services.stripe.key');
                $hasPaypal = (bool) config('services.paypal.client_id');
                $firstTab  = $hasStripe ? 'stripe' : ($hasPaypal ? 'paypal' : 'bank');
            @endphp

            @if($hasStripe)
            <button type="button" id="tab-stripe" onclick="switchTab('stripe')"
                    style="display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;outline:none;
                           border:2px solid #2563eb;background:#eff6ff;
                           font-size:13px;font-weight:700;cursor:pointer;color:#1d4ed8;transition:all .15s;">
                💳 Carta di credito
            </button>
            @endif

            @if($hasPaypal)
            <button type="button" id="tab-paypal" onclick="switchTab('paypal')"
                    style="display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;outline:none;
                           border:2px solid var(--border);background:var(--card-bg);
                           font-size:13px;font-weight:700;cursor:pointer;color:var(--ink);transition:all .15s;">
                🅿 PayPal
            </button>
            @endif

            <button type="button" id="tab-bank" onclick="switchTab('bank')"
                    style="display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;outline:none;
                           border:2px solid var(--border);background:var(--card-bg);
                           font-size:13px;font-weight:700;cursor:pointer;color:var(--ink);transition:all .15s;">
                🏦 Bonifico
            </button>
        </div>

        {{-- ── Pannello Stripe ─────────────────────────────────────────── --}}
        @if($hasStripe)
        <div id="panel-stripe" class="pay-panel card" style="padding:22px;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Paga con carta</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
                Verrai reindirizzato su Stripe. I dati della tua carta non vengono mai condivisi con noi.
            </div>
            <div style="display:flex;gap:7px;margin-bottom:18px;flex-wrap:wrap;">
                <span style="background:var(--surface-alt,#f1f5f9);border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600;color:var(--ink-soft);">🔒 SSL sicuro</span>
                <span style="background:var(--surface-alt,#f1f5f9);border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600;color:var(--ink-soft);">Visa / MC / Amex</span>
                <span style="background:var(--surface-alt,#f1f5f9);border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600;color:var(--ink-soft);">KY accreditati subito</span>
            </div>
            <form method="POST" action="{{ route('portal.ky-cards.stripe-checkout', $card) }}">
                @csrf
                <button type="submit" class="cta" style="width:100%;justify-content:center;font-size:14px;padding:13px 20px;border-radius:10px;">
                    Paga {{ number_format($card->price_eur, 2, ',', '.') }} € con carta →
                </button>
            </form>
        </div>
        @endif

        {{-- ── Pannello PayPal ─────────────────────────────────────────── --}}
        @if($hasPaypal)
        <div id="panel-paypal" class="pay-panel card" style="padding:22px;display:none;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Paga con PayPal</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
                Completa il pagamento con il tuo account PayPal. I KY vengono accreditati non appena confermato.
            </div>
            <div id="paypal-button-container"></div>
        </div>
        @endif

        {{-- ── Pannello Bonifico ───────────────────────────────────────── --}}
        <div id="panel-bank" class="pay-panel card" style="padding:22px;display:none;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Bonifico bancario</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">
                Ricevi le coordinate bancarie via email. I KY vengono accreditati dopo verifica (1–2 giorni lavorativi).
            </div>
            <div style="background:var(--surface-alt,#f8fafc);border-radius:8px;padding:11px 14px;margin-bottom:16px;font-size:12.5px;color:var(--ink-soft);">
                ⏱ Accredito in <strong style="color:var(--ink);">1–2 giorni lavorativi</strong>
            </div>
            <form method="POST" action="{{ route('portal.ky-cards.bank-transfer', $card) }}">
                @csrf
                <button type="submit" class="cta secondary" style="width:100%;justify-content:center;font-size:14px;padding:13px 20px;border-radius:10px;">
                    Ricevi coordinate per il bonifico →
                </button>
            </form>
        </div>
    </div>

    {{-- ════════════════════════════════════════════
         COLONNA DX — riepilogo ordine
    ═════════════════════════════════════════════ --}}
    <div>
        {{-- Card prodotto compatta --}}
        <div style="border-radius:14px;overflow:hidden;border:2px solid {{ $card->ky_bonus > 0 ? '#bbf7d0' : 'var(--border)' }};margin-bottom:14px;box-shadow:var(--shadow);position:relative;">
            @if($card->ky_bonus > 0)
            <div style="position:absolute;top:10px;right:10px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;font-size:10px;font-weight:800;padding:3px 9px;border-radius:20px;">
                {{ $card->bonus_label }}
            </div>
            @endif

            <div style="background:linear-gradient(135deg,#0b2244,#1e40af);padding:16px 18px;color:#fff;">
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;opacity:.6;margin-bottom:4px;">KYCard</div>
                <div style="font-size:16px;font-weight:800;">{{ $card->name }}</div>
                @if($card->description)
                    <div style="font-size:11.5px;opacity:.72;margin-top:2px;">{{ $card->description }}</div>
                @endif
            </div>

            <div style="padding:14px 18px;background:var(--card-bg);">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:{{ $card->ky_bonus > 0 ? '10px' : '0' }};">
                    <div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:2px;">Paghi</div>
                        <div style="font-size:22px;font-weight:800;color:var(--ink);">{{ number_format($card->price_eur, 2, ',', '.') }} <span style="font-size:13px;">€</span></div>
                    </div>
                    <span style="font-size:18px;color:var(--ink-muted);">→</span>
                    <div style="text-align:right;">
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:2px;">Ricevi</div>
                        <div style="font-size:22px;font-weight:800;color:#1d4ed8;">{{ ky_format($card->ky_total) }} <span style="font-size:13px;">KY</span></div>
                    </div>
                </div>
                @if($card->ky_bonus > 0)
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:6px 11px;font-size:12px;color:#166534;">
                    🎁 Include <strong>+{{ ky_format($card->ky_bonus) }} KY</strong> cashback
                </div>
                @endif
            </div>
        </div>

        {{-- Riepilogo ordine --}}
        <div class="card" style="padding:16px 18px;margin-bottom:10px;">
            <div style="font-size:11px;font-weight:700;color:var(--ink);margin-bottom:12px;text-transform:uppercase;letter-spacing:.07em;">Riepilogo ordine</div>

            <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--ink-soft);margin-bottom:7px;">
                <span>{{ $card->name }}</span>
                <span>{{ number_format($card->price_eur, 2, ',', '.') }} €</span>
            </div>
            @if($card->ky_bonus > 0)
            <div style="display:flex;justify-content:space-between;font-size:12.5px;color:#16a34a;margin-bottom:7px;">
                <span>Cashback incluso</span>
                <span>+{{ ky_format($card->ky_bonus) }} KY</span>
            </div>
            @endif

            <div style="border-top:1px solid var(--border);margin:10px 0;"></div>

            <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:13.5px;font-weight:700;color:var(--ink);">Totale</span>
                <div style="text-align:right;">
                    <div style="font-size:15px;font-weight:800;color:var(--ink);">{{ number_format($card->price_eur, 2, ',', '.') }} €</div>
                    <div style="font-size:12.5px;font-weight:700;color:#1d4ed8;">= {{ ky_format($card->ky_total) }} KY</div>
                </div>
            </div>
        </div>

        <div style="font-size:11.5px;color:var(--ink-muted);line-height:1.55;padding:0 2px;">
            🔒 Pagamento sicuro. I KY vengono accreditati sul conto non appena il pagamento è confermato.
        </div>
    </div>
</div>

<style>
@media (max-width: 720px) {
    #checkout-grid { grid-template-columns: 1fr !important; }
}
</style>

@if($hasPaypal)
<script src="https://www.paypal.com/sdk/js?client-id={{ config('services.paypal.client_id') }}&currency=EUR"></script>
<script>
paypal.Buttons({
    createOrder: function() {
        return fetch('{{ route('portal.ky-cards.paypal-create-order', $card) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
        }).then(r => r.json()).then(data => {
            if (data.error) { alert(data.error); return null; }
            return data.id;
        });
    },
    onApprove: function(data) {
        window.location = '{{ route('portal.ky-cards.paypal-capture', ['purchase' => '__UUID__']) }}'.replace('__UUID__', data.orderID);
    },
    onError: function() { alert('Errore PayPal. Riprova o scegli un altro metodo.'); }
}).render('#paypal-button-container');
</script>
@endif

<script>
var firstTab = '{{ $firstTab }}';

function switchTab(method) {
    var methods = ['stripe', 'paypal', 'bank'];
    methods.forEach(function(m) {
        var btn = document.getElementById('tab-' + m);
        if (btn) {
            btn.style.borderColor = 'var(--border)';
            btn.style.background  = 'var(--card-bg)';
            btn.style.color       = 'var(--ink)';
        }
        var panel = document.getElementById('panel-' + m);
        if (panel) panel.style.display = 'none';
    });
    var activeBtn = document.getElementById('tab-' + method);
    if (activeBtn) {
        activeBtn.style.borderColor = '#2563eb';
        activeBtn.style.background  = '#eff6ff';
        activeBtn.style.color       = '#1d4ed8';
    }
    var activePanel = document.getElementById('panel-' + method);
    if (activePanel) activePanel.style.display = 'block';
}

document.addEventListener('DOMContentLoaded', function() {
    switchTab(firstTab);
});
</script>
@endsection
