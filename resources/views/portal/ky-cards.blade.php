@extends('layouts.portal')

@section('content')
<style>
/* ── CATALOGO RICARICHE (09/09/2026) ────────────────────────────────────
   Riscritta l'impaginazione delle card. Cosa c'era che non andava:
   1. `var(--card-bg)` e `var(--border)` NON ESISTONO nel layout (le
      variabili si chiamano --surface e --line): lo sfondo delle card era
      quindi trasparente e il bordo prendeva il colore del testo;
   2. «Paghi 1.200,00 €» stava su una riga flex insieme alla freccia e a
      «Ricevi 1.500,00 KY»: sui tagli grossi il simbolo € finiva a capo da
      solo. Ora i due importi sono INCOLONNATI, uno sotto l'altro, con
      etichetta a sinistra e cifra a destra: non vanno mai a capo, e le
      cifre restano allineate fra card diverse (tabular-nums);
   3. le card avevano altezze diverse e il pulsante ballava: ora sono in
      colonna flex e «Acquista ora» e' ancorato in fondo (margin-top:auto),
      quindi i pulsanti sono tutti sulla stessa linea;
   4. griglia da catalogo: colonne uguali, allineate a SINISTRA come in un
      ecommerce, che si aggiungono e si tolgono da sole con la larghezza. */
.kyc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(272px, 1fr));
    align-items: stretch;
    gap: 16px;
    margin-bottom: 26px;
}
.kyc-card {
    min-width: 0;
    display: flex;
    flex-direction: column;
    border-radius: 14px;
    overflow: hidden;
    text-decoration: none;
    background: var(--surface);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-xs);
    transition: transform .18s, box-shadow .18s, border-color .18s;
}
.kyc-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow);
    border-color: var(--line-strong);
}
.kyc-card.is-bonus { border-color: #bbf7d0; }

.kyc-card__head {
    position: relative;
    padding: 14px 16px 12px;
    color: #fff;
    background: linear-gradient(135deg, var(--navy) 0%, #1e40af 100%);
}
.kyc-card__badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .04em;
    padding: 3px 9px;
    border-radius: 999px;
}
.kyc-card__eyebrow {
    display: block;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .15em;
    opacity: .6;
}
.kyc-card__name {
    display: block;
    margin-top: 3px;
    padding-right: 58px;   /* non finisce sotto al badge del bonus */
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -.02em;
}
.kyc-card__desc {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-top: 3px;
    font-size: 11.5px;
    line-height: 1.35;
    opacity: .72;
}
.kyc-card__pay { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; }
.kyc-card__pay span {
    background: rgba(255,255,255,.15);
    border-radius: 5px;
    padding: 2px 7px;
    font-size: 10px;
    font-weight: 600;
}

.kyc-card__body {
    display: flex;
    flex-direction: column;
    gap: 9px;
    flex: 1;
    padding: 12px 16px 14px;
}
.kyc-deal {
    display: grid;
    gap: 6px;
    padding: 9px 11px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--surface-soft);
}
.kyc-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 10px;
}
.kyc-row + .kyc-row { border-top: 1px solid var(--line); padding-top: 6px; }
.kyc-row__label {
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ink-muted);
}
.kyc-row__value {
    font-size: 19px;
    font-weight: 800;
    line-height: 1.1;
    color: var(--ink);
    white-space: nowrap;               /* il simbolo non va mai a capo */
    font-variant-numeric: tabular-nums;
}
.kyc-row__unit { font-size: 12px; font-weight: 700; color: var(--ink-soft); }
.kyc-row--get .kyc-row__value { font-size: 24px; color: var(--primary); }
.kyc-row--get .kyc-row__unit  { color: var(--primary); }

.kyc-card__bonus {
    padding: 6px 10px;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    background: var(--success-soft);
    font-size: 11.5px;
    line-height: 1.35;
    color: #166534;
}
.kyc-card__cta {
    margin-top: auto;                  /* pulsanti allineati fra le card */
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 9px;
    background: linear-gradient(180deg, #2563eb, #1d4ed8);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
}
.kyc-card:hover .kyc-card__cta { background: var(--primary-strong); }

.kyc-note {
    margin: -12px 0 26px;
    font-size: 12px;
    color: var(--ink-muted);
}

/* Storico */
.kyc-hist-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 11px 16px;
}
.kyc-hist-row + .kyc-hist-row { border-top: 1px solid var(--line); }
.kyc-hist-icon {
    width: 32px; height: 32px; flex-shrink: 0;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
}

@media (max-width: 560px) {
    .kyc-grid { grid-template-columns: 1fr; }
}
</style>

@if(session('success'))
    <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger" style="margin-bottom:16px;">{{ session('error') }}</div>
@endif

{{-- Arrivati qui per saldo insufficiente su un pagamento (shop, richiesta di
     pagamento…): a ricarica completata veniamo riportati li' in automatico. --}}
@if($redirectTo ?? null)
<div class="alert" style="background:var(--primary-light);border:1px solid rgba(15,82,196,.18);color:var(--primary-strong);margin-bottom:16px;">
    Scegli una ricarica: appena completata torni automaticamente al pagamento che stavi per fare.
</div>
@endif

<section class="page-intro page-intro--row">
    <div class="page-intro-body">
        <span class="eyebrow">Ricarica</span>
        <h2>Aggiungi KY al tuo conto</h2>
        <p>Scegli un taglio: paghi in euro e ricevi i KY sul conto, cashback compreso.
           Con carta l'accredito e' immediato; con bonifico appena il pagamento risulta ricevuto.</p>
    </div>
    <div style="text-align:right;flex-shrink:0;">
        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);">Saldo attuale</div>
        <div style="font-size:22px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums;white-space:nowrap;">
            {{ ky_format($currentAccount->available_balance) }} <span style="font-size:12px;font-weight:700;color:var(--ink-soft);">KY</span>
        </div>
    </div>
</section>

{{-- ── CATALOGO CARD ────────────────────────────────────────────────────── --}}
@if($cards->isEmpty())
    <div class="card light-card" style="padding:48px;text-align:center;color:var(--ink-muted);">
        <div style="font-size:40px;margin-bottom:12px;">💳</div>
        <div style="font-size:17px;font-weight:700;color:var(--ink);margin-bottom:4px;">Nessuna ricarica disponibile</div>
        <div style="font-size:13px;">Torna presto: nuovi tagli in arrivo.</div>
    </div>
@else
    <div class="kyc-grid">
        @foreach($cards as $card)
        <a class="kyc-card {{ $card->ky_bonus > 0 ? 'is-bonus' : '' }}"
           href="{{ route('portal.ky-cards.checkout', $card) }}{{ ($redirectTo ?? null) ? '?redirect_to=' . urlencode($redirectTo) : '' }}"
           aria-label="Acquista {{ $card->name }}: paghi {{ number_format($card->price_eur, 2, ',', '.') }} euro e ricevi {{ ky_format($card->ky_total) }} KY">

            <div class="kyc-card__head">
                @if($card->ky_bonus > 0)
                    <span class="kyc-card__badge">{{ $card->bonus_label }}</span>
                @endif
                <span class="kyc-card__eyebrow">KYCard</span>
                <span class="kyc-card__name">{{ $card->name }}</span>
                @if($card->description)
                    <span class="kyc-card__desc">{{ $card->description }}</span>
                @endif
                <span class="kyc-card__pay">
                    @if($card->stripe_price_id && config('services.stripe.key'))
                        <span>💳 Carta</span>
                    @endif
                    @if(config('services.paypal.client_id'))
                        <span>🅿 PayPal</span>
                    @endif
                    <span>🏦 Bonifico</span>
                </span>
            </div>

            <div class="kyc-card__body">
                <div class="kyc-deal">
                    <div class="kyc-row">
                        <span class="kyc-row__label">Paghi</span>
                        <span class="kyc-row__value">{{ number_format($card->price_eur, 2, ',', '.') }}<span class="kyc-row__unit"> €</span></span>
                    </div>
                    <div class="kyc-row kyc-row--get">
                        <span class="kyc-row__label">Ricevi</span>
                        <span class="kyc-row__value">{{ ky_format($card->ky_total) }}<span class="kyc-row__unit"> KY</span></span>
                    </div>
                </div>

                @if($card->ky_bonus > 0)
                <div class="kyc-card__bonus">
                    🎁 <strong>{{ ky_format($card->ky_base_amount) }} KY</strong> + <strong>{{ ky_format($card->ky_bonus) }} KY</strong> di cashback
                </div>
                @endif

                <span class="kyc-card__cta">Acquista ora <span aria-hidden="true">›</span></span>
            </div>
        </a>
        @endforeach
    </div>

    <p class="kyc-note">I KY arrivano sul conto a pagamento riuscito. Nessun costo aggiuntivo oltre al prezzo indicato.</p>
@endif

{{-- ── ULTIMI ACQUISTI ─────────────────────────────────────────────────── --}}
@if($recentPurchases->isNotEmpty())
<div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);">I tuoi ultimi acquisti</div>
        <a href="{{ route('portal.ky-cards.storico') }}" style="font-size:12.5px;font-weight:600;color:var(--primary);text-decoration:none;">Vedi tutto lo storico →</a>
    </div>
    @php
        // Stessa regola dello storico: il nome di chi ha ricaricato compare
        // solo se sul conto ha ricaricato piu' di una persona.
        $mostraChiHaRicaricato = $recentPurchases->pluck('user_id')->unique()->count() > 1
            || $recentPurchases->contains(fn ($p) => $p->user_id !== $currentUser->id);
    @endphp
    <div class="card" style="padding:0;overflow:hidden;">
        @foreach($recentPurchases as $p)
        <div class="kyc-hist-row">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <div class="kyc-hist-icon" style="background:{{ $p->isCompleted() ? 'var(--primary-light)' : ($p->isAwaitingBankTransfer() ? 'var(--warning-soft)' : 'var(--danger-soft)') }};">
                    {{ $p->isCompleted() ? '✅' : ($p->isAwaitingBankTransfer() ? '⏳' : '❌') }}
                </div>
                <div style="min-width:0;">
                    <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $p->kyCard->name ?? '—' }}</div>
                    <div style="font-size:11.5px;color:var(--ink-muted);">
                        {{ $p->created_at->format('d/m/Y H:i') }} &middot;
                        @if($p->payment_method === 'stripe') 💳 Carta
                        @elseif($p->payment_method === 'paypal') 🅿 PayPal
                        @else 🏦 Bonifico
                        @endif
                        @if($mostraChiHaRicaricato)
                            &middot; {{ $p->user->name ?? 'Utente rimosso' }}
                        @endif
                    </div>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                @if($p->isCompleted())
                    <div style="font-size:14px;font-weight:800;color:var(--primary);font-variant-numeric:tabular-nums;white-space:nowrap;">+{{ ky_format($p->ky_amount) }} KY</div>
                @elseif($p->isAwaitingBankTransfer())
                    <div style="font-size:12.5px;font-weight:700;color:var(--warning);">In attesa bonifico</div>
                @elseif($p->isFailed())
                    <div style="font-size:12.5px;font-weight:700;color:var(--danger);">Fallito</div>
                @else
                    <div style="font-size:12.5px;color:var(--ink-muted);">In elaborazione…</div>
                @endif
                <div style="font-size:11.5px;color:var(--ink-muted);white-space:nowrap;">{{ number_format($p->price_eur, 2, ',', '.') }} €</div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

@endsection
