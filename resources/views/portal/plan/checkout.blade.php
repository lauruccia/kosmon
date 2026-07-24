@extends('layouts.portal')

@section('content')

<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-muted);margin-bottom:20px;">
    <a href="{{ route('portal.plan.index') }}" style="color:var(--primary);text-decoration:none;font-weight:600;">← Il mio piano</a>
    <span>/</span>
    <span style="color:var(--ink);">{{ $targetPlan->name }}</span>
</div>

<div id="checkout-grid" style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

    <div>
        <div style="font-size:17px;font-weight:800;color:var(--ink);margin-bottom:16px;">Scegli il metodo di pagamento</div>

        <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
            @php
                $hasStripe = (bool) config('services.stripe.key');
                $hasPaypal = (bool) config('services.paypal.client_id');
                $firstTab  = $hasStripe ? 'stripe' : ($hasPaypal ? 'paypal' : ($canPayKy ? 'ky' : 'bank'));
            @endphp

            @if($hasStripe)
            <button type="button" id="tab-stripe" onclick="switchTab('stripe')"
                    style="display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;outline:none;
                           border:2px solid var(--border);background:var(--card-bg);
                           font-size:13px;font-weight:700;cursor:pointer;color:var(--ink);transition:all .15s;">
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
            @if($canPayKy)
            <button type="button" id="tab-ky" onclick="switchTab('ky')"
                    style="display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;outline:none;
                           border:2px solid var(--border);background:var(--card-bg);
                           font-size:13px;font-weight:700;cursor:pointer;color:var(--ink);transition:all .15s;">
                🪙 Conto KMoney
            </button>
            @endif
        </div>

        @if($hasStripe)
        <div id="panel-stripe" class="pay-panel card" style="padding:22px;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Paga con carta</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
                Verrai reindirizzato su Stripe. Il nuovo piano si attiva non appena il pagamento è confermato.
            </div>
            <form method="POST" action="{{ route('portal.plan.stripe-checkout', $targetPlan) }}">
                @csrf
                <button type="submit" class="cta" style="width:100%;justify-content:center;font-size:14px;padding:13px 20px;border-radius:10px;">
                    Paga {{ number_format($amountCents / 100, 2, ',', '.') }} € con carta →
                </button>
            </form>
        </div>
        @endif

        @if($hasPaypal)
        <div id="panel-paypal" class="pay-panel card" style="padding:22px;display:none;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Paga con PayPal</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
                Completa il pagamento con il tuo account PayPal. Il nuovo piano si attiva subito dopo conferma.
            </div>
            <div id="paypal-button-container"></div>
        </div>
        @endif

        <div id="panel-bank" class="pay-panel card" style="padding:22px;display:none;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Bonifico bancario</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">
                Ricevi le coordinate bancarie con causale univoca. Il piano si attiva dopo verifica del bonifico da parte dell'amministrazione.
            </div>
            <form method="POST" action="{{ route('portal.plan.bank-transfer', $targetPlan) }}">
                @csrf
                <button type="submit" class="cta secondary" style="width:100%;justify-content:center;font-size:14px;padding:13px 20px;border-radius:10px;">
                    Ricevi coordinate per il bonifico →
                </button>
            </form>
        </div>

        @if($canPayKy)
        <div id="panel-ky" class="pay-panel card" style="padding:22px;display:none;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Paga dal conto KMoney</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
                L'importo viene addebitato subito dal saldo disponibile del tuo conto ({{ ky_format($account->saldoDisponibile()) }} KY). Il piano si attiva immediatamente.
            </div>
            <form method="POST" action="{{ route('portal.plan.pay-ky', $targetPlan) }}"
                  onsubmit="return confirm('Confermi l\'addebito di {{ ky_format($amountCents) }} KY dal conto della tua azienda per passare al piano {{ $targetPlan->name }}?')">
                @csrf
                <button type="submit" class="cta secondary" style="width:100%;justify-content:center;font-size:14px;padding:13px 20px;border-radius:10px;">
                    Addebita {{ ky_format($amountCents) }} KY →
                </button>
            </form>
        </div>
        @endif
    </div>

    <div>
        <div style="border-radius:14px;overflow:hidden;border:2px solid var(--border);margin-bottom:14px;box-shadow:var(--shadow);">
            <div style="background:linear-gradient(135deg,#0b2244,#1e40af);padding:16px 18px;color:#fff;">
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;opacity:.6;margin-bottom:4px;">Upgrade piano</div>
                <div style="font-size:16px;font-weight:800;">{{ $company->plan?->name ?? 'Nessun piano' }} → {{ $targetPlan->name }}</div>
                @if($targetPlan->description)
                    <div style="font-size:11.5px;opacity:.72;margin-top:2px;">{{ $targetPlan->description }}</div>
                @endif
            </div>
            <div style="padding:14px 18px;background:var(--card-bg);">
                <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--ink-soft);margin-bottom:7px;">
                    <span>Canone {{ $targetPlan->name }}</span>
                    <span>{{ number_format($targetPlan->price_cents / 100, 2, ',', '.') }} €</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--ink-soft);margin-bottom:7px;">
                    <span>Gia' pagato ({{ $company->plan?->name ?? '—' }})</span>
                    <span>− {{ number_format(($company->plan?->price_cents ?? 0) / 100, 2, ',', '.') }} €</span>
                </div>
                <div style="border-top:1px solid var(--border);margin:10px 0;"></div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13.5px;font-weight:700;color:var(--ink);">Differenza da pagare</span>
                    <div style="font-size:18px;font-weight:800;color:var(--ink);">{{ number_format($amountCents / 100, 2, ',', '.') }} €</div>
                </div>
            </div>
        </div>
        <div style="font-size:11.5px;color:var(--ink-muted);line-height:1.55;padding:0 2px;">
            🔒 Pagamento sicuro. Il nuovo piano si applica automaticamente non appena il pagamento è confermato.
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
(function () {
    var planPaymentUuid = null;
    paypal.Buttons({
        createOrder: function() {
            return fetch('{{ route('portal.plan.paypal-create-order', $targetPlan) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
            }).then(r => r.json()).then(data => {
                if (data.error) { alert(data.error); return null; }
                planPaymentUuid = data.payment_uuid;
                return data.id;
            });
        },
        onApprove: function() {
            window.location = '{{ route('portal.plan.paypal-capture', ['payment' => '__UUID__']) }}'.replace('__UUID__', planPaymentUuid);
        },
        onError: function() { alert('Errore PayPal. Riprova o scegli un altro metodo.'); }
    }).render('#paypal-button-container');
})();
</script>
@endif

<script>
var firstTab = '{{ $firstTab }}';

function switchTab(method) {
    var methods = ['stripe', 'paypal', 'bank', 'ky'];
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
