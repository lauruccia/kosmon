@extends('layouts.portal')

@section('content')
<style>
    .plan-hero {
        padding:24px 28px; border-radius:var(--radius-lg); margin-bottom:22px;
        background:var(--grad-hero); color:#fff; position:relative; overflow:hidden;
    }
    .plan-hero::before {
        content:""; position:absolute; top:-60px; right:-60px; width:220px; height:220px; border-radius:50%;
        background:radial-gradient(circle, rgba(79,70,229,.3), transparent 70%); pointer-events:none;
    }
    .plan-hero-current { position:relative; z-index:1; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; opacity:.7; }
    .plan-hero-name { position:relative; z-index:1; font-size:26px; font-weight:800; margin:4px 0 8px; }
    .plan-hero-desc { position:relative; z-index:1; font-size:13.5px; opacity:.8; max-width:640px; line-height:1.55; }

    .plans-pricing-grid {
        display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:18px;
    }
    .price-card {
        border-radius:16px; border:1.5px solid var(--line); background:var(--surface);
        box-shadow:var(--shadow-xs); overflow:hidden; display:flex; flex-direction:column;
        transition:transform .18s, box-shadow .18s, border-color .18s;
    }
    .price-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
    .price-card.is-current { border-color:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.15); }
    .price-card-head { padding:20px 20px 16px; border-bottom:1px solid var(--line); }
    .price-card-badge {
        display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px;
        font-size:10.5px; font-weight:800; letter-spacing:.04em; color:#fff; margin-bottom:10px;
    }
    .price-card-name { font-size:17px; font-weight:800; color:var(--ink); margin:0 0 4px; }
    .price-card-price { font-size:28px; font-weight:800; color:var(--ink); letter-spacing:-.02em; }
    .price-card-price small { font-size:13px; font-weight:600; color:var(--ink-muted); }
    .price-card-desc { padding:16px 20px; font-size:13px; color:var(--ink-soft); line-height:1.6; flex:1; }
    .price-card-features { list-style:none; margin:0; padding:0 20px 16px; display:flex; flex-direction:column; gap:8px; }
    .price-card-features li { font-size:12.5px; color:var(--ink-soft); display:flex; align-items:flex-start; gap:7px; }
    .price-card-footer { padding:14px 20px 20px; }
    .price-card-footer .cta { width:100%; justify-content:center; }
    .current-pill {
        display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:999px;
        background:#dcfce7; color:#166534; font-size:12px; font-weight:700;
    }

    .plan-history { margin-top:26px; }
    .plan-history-item {
        display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--line);
        font-size:13px;
    }
    .plan-history-item:last-child { border-bottom:none; }
    .ph-status { font-size:11px; font-weight:700; padding:2px 9px; border-radius:999px; }
    .ph-status--completed { background:#dcfce7; color:#166534; }
    .ph-status--pending, .ph-status--pending_bank_transfer { background:#fef3c7; color:#92400e; }
    .ph-status--failed, .ph-status--cancelled { background:#fee2e2; color:#991b1b; }
</style>

<div class="plan-hero">
    <div class="plan-hero-current">Piano attuale</div>
    <div class="plan-hero-name">{{ $company->plan?->name ?? 'Nessun piano attivo' }}</div>
    <div class="plan-hero-desc">
        {{ $company->plan?->description ?? 'La tua azienda non ha ancora un piano di abbonamento assegnato. Scegline uno qui sotto oppure contatta l\'amministrazione.' }}
    </div>
</div>

<div class="plans-pricing-grid">
    @foreach($plans as $plan)
        @php $isCurrent = $company->plan_id === $plan->id; @endphp
        <div class="price-card @if($isCurrent) is-current @endif">
            <div class="price-card-head">
                <span class="price-card-badge" style="background:{{ $plan->effective_badge_color }};">{{ strtoupper($plan->slug) }}</span>
                <div class="price-card-name">{{ $plan->name }}</div>
                <div class="price-card-price">
                    {{ $plan->price_cents > 0 ? number_format($plan->price_cents / 100, 0) : 'Gratis' }}
                    @if($plan->price_cents > 0)<small>€/anno</small>@endif
                </div>
            </div>
            <div class="price-card-desc">{{ $plan->description }}</div>
            <ul class="price-card-features">
                <li>{{ $plan->can_sell_products ? '✅' : '➖' }} Vendita prodotti nello shop</li>
                <li>✅ Presenza nella directory del circuito</li>
                <li>{{ $plan->card_style === 'rich' ? '✅' : ($plan->card_style === 'compact' ? '➖' : '➖') }} Card directory con logo e banner</li>
                <li>{{ $plan->allow_ky_payment ? '✅' : '➖' }} Pagabile anche in KY</li>
            </ul>
            <div class="price-card-footer">
                @if($isCurrent)
                    <span class="current-pill" style="width:100%;justify-content:center;">✓ Piano attuale</span>
                @elseif($plan->price_cents > ($company->plan?->price_cents ?? 0))
                    <a href="{{ route('portal.plan.checkout', $plan) }}" class="cta">
                        Passa a {{ $plan->name }} — {{ number_format($company->upgradePriceDifference($plan) / 100, 2, ',', '.') }} €
                    </a>
                @else
                    <span class="cta secondary" style="width:100%;justify-content:center;opacity:.6;cursor:not-allowed;" title="I downgrade sono gestiti dall'amministrazione">
                        Contatta l'assistenza
                    </span>
                @endif
            </div>
        </div>
    @endforeach
</div>

@if($recentPayments->isNotEmpty())
<div class="card light-card plan-history">
    <h3 class="card-title" style="margin-bottom:10px;">Storico pagamenti piano</h3>
    @foreach($recentPayments as $payment)
    <div class="plan-history-item">
        <div>
            <strong>{{ $payment->fromPlan->name ?? '—' }} → {{ $payment->toPlan->name ?? '—' }}</strong>
            <div class="subtle" style="font-size:11.5px;">{{ $payment->created_at->locale('it')->isoFormat('D MMM YYYY, HH:mm') }} · {{ strtoupper($payment->payment_method) }}</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span>{{ number_format($payment->amount_cents / 100, 2, ',', '.') }} €</span>
            <span class="ph-status ph-status--{{ $payment->status }}">{{ $payment->status }}</span>
        </div>
    </div>
    @endforeach
</div>
@endif
@endsection
