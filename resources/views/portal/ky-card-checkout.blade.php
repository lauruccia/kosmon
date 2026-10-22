@extends('layouts.portal')

@section('content')

{{-- ── BREADCRUMB ──────────────────────────────────────────────────────── --}}
<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-muted);margin-bottom:20px;">
    <a href="{{ route('portal.ky-cards.index', ($redirectTo ?? null) ? ['redirect_to' => $redirectTo] : []) }}" style="color:var(--primary);text-decoration:none;font-weight:600;">← Ricarica KY</a>
    <span>/</span>
    <span style="color:var(--ink);">{{ $card->name }}</span>
</div>

@if($redirectTo ?? null)
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#1d4ed8;display:flex;align-items:center;gap:8px;">
    ℹ️ Completa questa ricarica: appena confermata torni automaticamente al pagamento che stavi per fare.
</div>
@endif

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
                $installmentOptions = $installmentOptions ?? [];
                $recurringEnabled   = $recurringEnabled ?? false;
                // 22/10/2026: la scheda carta serve anche solo per rate / rinnovo
                // (non hanno bisogno dello Stripe Price ID della card).
                $hasCard   = $hasStripe || count($installmentOptions) > 0 || $recurringEnabled;
                $firstTab  = $hasCard ? 'stripe' : ($hasPaypal ? 'paypal' : 'bank');
            @endphp

            @if($hasCard)
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
        @if($hasCard)
        @php
            // Come si puo' pagare con carta: una volta / a rate / ogni mese.
            // Un solo form, un solo pulsante: la scelta cambia azione, testi e riepilogo.
            $modes = [];
            if ($hasStripe)                    { $modes['once'] = true; }
            if (count($installmentOptions) > 0) { $modes['installments'] = true; }
            if ($recurringEnabled)             { $modes['recurring'] = true; }
            $defaultMode = array_key_first($modes);
            $fmt         = fn ($v) => number_format($v, 2, ',', '.');
            $planOptions = array_map(fn ($n) => ['n' => $n, 'amount' => $fmt($card->price_eur / $n)], $installmentOptions);
            $defaultN    = count($installmentOptions) ? (in_array(12, $installmentOptions, true) ? 12 : max($installmentOptions)) : null;
            $minAmount   = count($installmentOptions) ? $fmt($card->price_eur / max($installmentOptions)) : null;
        @endphp
        <div id="panel-stripe" class="pay-panel card" style="padding:22px;">
            <form id="pay-form" method="POST" action="{{ $defaultMode === 'once' ? route('portal.ky-cards.stripe-checkout', $card) : route('portal.ky-cards.subscription-checkout', $card) }}">
                @csrf
                @if($redirectTo ?? null)
                <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
                @endif
                <input type="hidden" name="kind" id="pay-kind" value="{{ $defaultMode === 'once' ? '' : $defaultMode }}" @if($defaultMode === 'once') disabled @endif>
                <input type="hidden" name="installments" id="pay-installments" value="{{ $defaultMode === 'installments' ? $defaultN : '' }}" @if($defaultMode !== 'installments') disabled @endif>

                @if(count($modes) > 1)
                <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:10px;">Come vuoi pagare?</div>
                <div class="pay-modes" role="radiogroup" aria-label="Modalità di pagamento con carta">
                    @if(isset($modes['once']))
                    <label class="pay-mode {{ $defaultMode === 'once' ? 'is-active' : '' }}" data-mode="once">
                        <input type="radio" name="pay_mode" value="once" {{ $defaultMode === 'once' ? 'checked' : '' }}>
                        <span class="pay-mode__title">Una volta</span>
                        <span class="pay-mode__price">{{ $fmt($card->price_eur) }} €</span>
                        <span class="pay-mode__hint">Pagamento unico</span>
                    </label>
                    @endif
                    @if(isset($modes['installments']))
                    <label class="pay-mode {{ $defaultMode === 'installments' ? 'is-active' : '' }}" data-mode="installments">
                        <input type="radio" name="pay_mode" value="installments" {{ $defaultMode === 'installments' ? 'checked' : '' }}>
                        <span class="pay-mode__title">A rate</span>
                        <span class="pay-mode__price">da {{ $minAmount }} €<small>/mese</small></span>
                        <span class="pay-mode__hint">KY subito, zero interessi</span>
                    </label>
                    @endif
                    @if(isset($modes['recurring']))
                    <label class="pay-mode {{ $defaultMode === 'recurring' ? 'is-active' : '' }}" data-mode="recurring">
                        <input type="radio" name="pay_mode" value="recurring" {{ $defaultMode === 'recurring' ? 'checked' : '' }}>
                        <span class="pay-mode__title">Ogni mese</span>
                        <span class="pay-mode__price">{{ $fmt($card->price_eur) }} €<small>/mese</small></span>
                        <span class="pay-mode__hint">Disdici quando vuoi</span>
                    </label>
                    @endif
                </div>
                @else
                <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:5px;">Paga con carta</div>
                @endif

                @if(isset($modes['installments']))
                <div id="pay-n-wrap" style="{{ $defaultMode === 'installments' ? '' : 'display:none;' }}margin-top:16px;">
                    <div style="font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;">Numero di rate</div>
                    <div class="pay-ns">
                        @foreach($planOptions as $o)
                        <button type="button" class="pay-n {{ $o['n'] === $defaultN ? 'is-active' : '' }}" data-n="{{ $o['n'] }}" data-amount="{{ $o['amount'] }}">
                            <span class="pay-n__n"><strong>{{ $o['n'] }}</strong> rate</span>
                            <span class="pay-n__amt">{{ $o['amount'] }} € / mese</span>
                        </button>
                        @endforeach
                    </div>
                </div>
                @endif

                <div id="pay-note" style="font-size:13px;color:var(--ink-soft);margin:16px 0 14px;line-height:1.5;"></div>

                <button type="submit" id="pay-cta" class="cta" style="width:100%;justify-content:center;font-size:14px;padding:13px 20px;border-radius:10px;">
                    Paga {{ $fmt($card->price_eur) }} € con carta →
                </button>
            </form>

            <div style="display:flex;gap:7px;margin-top:14px;flex-wrap:wrap;align-items:center;">
                <span style="background:var(--surface-alt,#f1f5f9);border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600;color:var(--ink-soft);">🔒 SSL sicuro</span>
                <span style="background:var(--surface-alt,#f1f5f9);border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600;color:var(--ink-soft);">Visa / MC / Amex</span>
                <span style="font-size:11.5px;color:var(--ink-muted);">Verrai reindirizzato su Stripe: i dati della carta non passano da noi.</span>
            </div>
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
                @if($redirectTo ?? null)
                <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
                @endif
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
                {{-- 09/09/2026: erano affiancati con la freccia in mezzo e in
                     questa colonna stretta il simbolo € finiva a capo da solo
                     sui tagli grossi (7.200,00). Incolonnati non succede piu',
                     e le cifre restano allineate fra loro. --}}
                <div style="display:grid;gap:6px;margin-bottom:{{ $card->ky_bonus > 0 ? '10px' : '0' }};">
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;">
                        <span style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Paghi</span>
                        <span style="font-size:19px;font-weight:800;color:var(--ink);white-space:nowrap;font-variant-numeric:tabular-nums;">{{ number_format($card->price_eur, 2, ',', '.') }}<span style="font-size:12px;font-weight:700;color:var(--ink-soft);"> €</span></span>
                    </div>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;border-top:1px solid var(--line);padding-top:6px;">
                        <span style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">Ricevi</span>
                        <span style="font-size:22px;font-weight:800;color:var(--primary);white-space:nowrap;font-variant-numeric:tabular-nums;">{{ ky_format($card->ky_total) }}<span style="font-size:12px;font-weight:700;"> KY</span></span>
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
                <span id="sum-label" style="font-size:13.5px;font-weight:700;color:var(--ink);">Totale</span>
                <div style="text-align:right;">
                    <div id="sum-today" style="font-size:15px;font-weight:800;color:var(--ink);">{{ number_format($card->price_eur, 2, ',', '.') }} €</div>
                    <div id="sum-ky" style="font-size:12.5px;font-weight:700;color:#1d4ed8;">= {{ ky_format($card->ky_total) }} KY</div>
                </div>
            </div>
            <div id="sum-later" style="display:none;margin-top:10px;padding:8px 10px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;font-size:12px;line-height:1.45;color:#1e3a8a;"></div>
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
.pay-modes { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.pay-mode { position:relative; display:flex !important; flex-direction:column; align-items:flex-start; gap:2px; margin:0; padding:13px 14px; border:2px solid var(--border); border-radius:12px; background:var(--card-bg); cursor:pointer; transition:border-color .15s, box-shadow .15s, background .15s; }
.pay-mode > span { display:block; }
.pay-mode input { position:absolute; opacity:0; pointer-events:none; }
.pay-mode:hover { border-color:#93c5fd; }
.pay-mode.is-active { border-color:#2563eb; background:#eff6ff; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.pay-mode__title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--ink-muted); }
.pay-mode.is-active .pay-mode__title { color:#1d4ed8; }
.pay-mode__price { font-size:19px; font-weight:800; color:var(--ink); white-space:nowrap; font-variant-numeric:tabular-nums; }
.pay-mode__price small { font-size:12px; font-weight:700; color:var(--ink-soft); }
.pay-mode__hint { font-size:11.5px; color:var(--ink-soft); }
.pay-ns { display:flex; gap:8px; flex-wrap:wrap; }
.pay-n { flex:1 1 110px; display:flex; flex-direction:column; align-items:flex-start; gap:1px; padding:10px 12px; border:2px solid var(--border); border-radius:10px; background:var(--card-bg); font-size:13px; color:var(--ink); cursor:pointer; transition:border-color .15s, background .15s; }
.pay-n__n { font-size:13px; }
.pay-n strong { font-size:18px; }
.pay-n__amt { font-size:12px; color:var(--ink-soft); font-variant-numeric:tabular-nums; }
.pay-n:hover { border-color:#93c5fd; }
.pay-n.is-active { border-color:#2563eb; background:#eff6ff; }
.pay-n.is-active .pay-n__amt { color:#1d4ed8; font-weight:700; }
</style>

@if($hasPaypal)
<script src="https://www.paypal.com/sdk/js?client-id={{ config('services.paypal.client_id') }}&currency=EUR"></script>
<script>
var redirectTo = @json($redirectTo ?? null);
paypal.Buttons({
    createOrder: function() {
        var createUrl = '{{ route('portal.ky-cards.paypal-create-order', $card) }}'
            + (redirectTo ? '?redirect_to=' + encodeURIComponent(redirectTo) : '');
        return fetch(createUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
        }).then(r => r.json()).then(data => {
            if (data.error) { alert(data.error); return null; }
            return data.id;
        });
    },
    onApprove: function(data) {
        var captureUrl = '{{ route('portal.ky-cards.paypal-capture', ['purchase' => '__UUID__']) }}'.replace('__UUID__', data.orderID);
        window.location = captureUrl + (redirectTo ? '?redirect_to=' + encodeURIComponent(redirectTo) : '');
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

@if(! empty($hasCard) && isset($modes) && count($modes) > 0)
<script>
(function () {
    var cfg = {
        price: @json($fmt($card->price_eur)),
        ky: @json(ky_format($card->ky_total)),
        onceAction: @json(route('portal.ky-cards.stripe-checkout', $card)),
        subAction: @json(route('portal.ky-cards.subscription-checkout', $card)),
        mode: @json($defaultMode),
        n: @json($defaultN),
        amounts: @json(collect($planOptions)->pluck('amount', 'n'))
    };
    var $ = function (id) { return document.getElementById(id); };
    var form = $('pay-form');

    function render() {
        var once = cfg.mode === 'once', inst = cfg.mode === 'installments';
        form.action = once ? cfg.onceAction : cfg.subAction;
        $('pay-kind').disabled = once;
        $('pay-kind').value = once ? '' : cfg.mode;
        $('pay-installments').disabled = !inst;
        $('pay-installments').value = inst ? cfg.n : '';

        document.querySelectorAll('.pay-mode').forEach(function (el) {
            el.classList.toggle('is-active', el.getAttribute('data-mode') === cfg.mode);
        });
        var wrap = $('pay-n-wrap');
        if (wrap) wrap.style.display = inst ? '' : 'none';
        document.querySelectorAll('.pay-n').forEach(function (el) {
            el.classList.toggle('is-active', parseInt(el.getAttribute('data-n'), 10) === cfg.n);
        });

        var today = cfg.price, note, cta, later = '', label = 'Totale', ky = '= ' + cfg.ky + ' KY';
        if (once) {
            note = 'Pagamento unico. I KY vengono accreditati subito.';
            cta = 'Paga ' + cfg.price + ' € con carta →';
        } else if (inst) {
            var a = cfg.amounts[cfg.n];
            today = a; label = 'Paghi oggi';
            ky = '= ' + cfg.ky + ' KY subito';
            note = 'Ricevi <strong>subito tutti i ' + cfg.ky + ' KY</strong>. Paghi ' + cfg.n + ' rate mensili da <strong>' + a + ' €</strong>, uguali, senza interessi, addebitate in automatico sulla carta.';
            later = 'Poi ' + (cfg.n - 1) + ' &times; ' + a + ' € ogni mese.<br>Totale ' + cfg.price + ' €, nessuna maggiorazione.';
            cta = 'Paga la 1ª rata di ' + a + ' € →';
        } else {
            label = 'Paghi oggi';
            ky = '= ' + cfg.ky + ' KY ogni mese';
            note = 'Ogni mese vengono addebitati <strong>' + cfg.price + ' €</strong> sulla carta e ricevi <strong>' + cfg.ky + ' KY</strong>. Puoi disdire quando vuoi.';
            later = 'Poi ' + cfg.price + ' € ogni mese, fino alla disdetta.';
            cta = 'Attiva il rinnovo mensile · ' + cfg.price + ' € →';
        }
        $('pay-note').innerHTML = note;
        $('pay-cta').innerHTML = cta;
        $('sum-label').textContent = label;
        $('sum-today').textContent = today + ' €';
        $('sum-ky').textContent = ky;
        var l = $('sum-later');
        l.style.display = later ? '' : 'none';
        l.innerHTML = later;
    }

    document.querySelectorAll('.pay-mode input').forEach(function (r) {
        r.addEventListener('change', function () { cfg.mode = r.value; render(); });
    });
    document.querySelectorAll('.pay-n').forEach(function (b) {
        b.addEventListener('click', function () { cfg.n = parseInt(b.getAttribute('data-n'), 10); render(); });
    });
    render();
})();
</script>
@endif
@endsection
