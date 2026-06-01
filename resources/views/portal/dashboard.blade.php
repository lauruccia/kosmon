@extends('layouts.portal')

@section('content')

{{-- Banner contratto da firmare (utenti esistenti che possono posticipare) --}}
@if(auth()->user() && !auth()->user()->contract_signed_at && session('success') !== 'Contratto firmato con successo. Benvenuto nel circuito KMoney!')
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">📋</span>
        <div>
            <strong style="font-size:14px;color:#92400e;">Contratto di Adesione da firmare</strong>
            <div style="font-size:13px;color:#a16207;margin-top:2px;">Completa la firma digitale per garantire il pieno utilizzo del circuito.</div>
        </div>
    </div>
    <a href="{{ route('portal.contract.sign') }}" style="background:#f59e0b;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;">
        ✍️ Firma ora
    </a>
</div>
@endif

<style>
/* ── Bank Hero ────────────────────────────────────────── */
.bank-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #0f52c4 100%);
    border-radius: var(--radius, 12px);
    padding: 24px 28px 20px;
    margin-bottom: 16px;
    color: #fff;
    display: block;
    width: 100%;
    box-sizing: border-box;
}
.bank-hero__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 22px;
    flex-wrap: wrap;
    gap: 12px;
}
.bank-hero__type {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    opacity: .55;
}
.bank-hero__name {
    display: block;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -.02em;
}
.bank-hero__actions { display: flex; gap: 8px; flex-wrap: wrap; }
.bank-hero__actions .cta {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    color: #fff;
    font-size: 13px;
    min-height: 38px;
    padding: 0 16px;
    border-radius: 8px;
    -webkit-tap-highlight-color: transparent;
}
.bank-hero__actions .cta:hover { background: rgba(255,255,255,.25); }
@media (max-width: 480px) {
    .bank-hero { padding: 16px 16px 14px; }
    .bank-hero__header { margin-bottom: 14px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .bank-hero__actions { width: 100%; }
    .bank-hero__actions .cta { flex: 1; min-width: 0; text-align: center; font-size: 12px; padding: 0 10px; }
    .bank-kpi-grid { grid-template-columns: 1fr 1fr; }
}

/* ── Bank KPI grid ──────────────────────────────────── */
.bank-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1px;
    background: rgba(255,255,255,.1);
    border-radius: 10px;
    overflow: hidden;
}
@media (max-width: 900px) { .bank-kpi-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 520px)  { .bank-kpi-grid { grid-template-columns: 1fr; } }
.bkpi {
    background: rgba(255,255,255,.06);
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.bkpi.bkpi--primary { background: rgba(255,255,255,.13); }
.bkpi__icon { opacity: .55; margin-bottom: 6px; }
.bkpi__label {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    opacity: .6;
}
.bkpi__value {
    font-size: 24px;
    font-weight: 900;
    letter-spacing: -.03em;
    line-height: 1.1;
    color: #fff;
}
.bkpi__value--muted { opacity: .4; font-size: 20px; }
.bkpi__currency { font-size: 12px; font-weight: 600; opacity: .7; margin-left: 2px; }
.bkpi__note { font-size: 11.5px; opacity: .55; margin-top: 2px; }
.bkpi__note strong { opacity: 1; color: #fff; }
.bkpi__note a { color: #fff; }

/* ── Account Limits ─────────────────────────────────── */
.account-limits {
    background: var(--surface, #fff);
    border: 1px solid var(--line, #e5e7eb);
    border-radius: var(--radius, 12px);
    padding: 16px 20px;
    margin-bottom: 18px;
    display: block;
    box-sizing: border-box;
}
.account-limits__title {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--ink-muted, #6b7280);
    margin-bottom: 14px;
}
.limits-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
}
@media (max-width: 900px) { .limits-grid { grid-template-columns: 1fr 1fr; } }
.limit-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 14px 8px 0;
    border-right: 1px solid var(--line, #e5e7eb);
}
.limit-item:last-child { border-right: none; padding-right: 0; }
.limit-item:first-child { padding-left: 0; }
@media (max-width: 900px) {
    .limit-item { border-right: none; border-bottom: 1px solid var(--line, #e5e7eb); padding: 10px 0; }
    .limit-item:nth-last-child(-n+2) { border-bottom: none; }
}
.limit-item__icon {
    width: 28px; height: 28px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 13px; margin-top: 1px;
}
.limit-item__icon--slate { background: #f1f5f9; color: #475569; }
.limit-item__icon--amber { background: #fef3c7; color: #d97706; }
.limit-item__icon--blue  { background: #dbeafe; color: #2563eb; }
.limit-item__icon--green { background: #dcfce7; color: #16a34a; }
.limit-item__body { display: flex; flex-direction: column; gap: 2px; }
.limit-item__label { font-size: 10px; color: var(--ink-muted, #6b7280); font-weight: 600; margin-bottom: 2px; }
.limit-item__value { font-size: 14px; font-weight: 800; color: var(--ink, #111827); letter-spacing: -.01em; }
.limit-item__unit { font-size: 10px; font-weight: 600; color: var(--teal-strong, #0f52c4); margin-left: 1px; }
.limit-item__unlimited { font-size: 11.5px; font-weight: 600; color: var(--ink-muted, #9ca3af); font-style: italic; }

/* ── Dashboard 2-col grid ───────────────────────────── */
.dashboard-bank-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 14px;
    align-items: start;
}
@media (max-width: 980px) { .dashboard-bank-grid { grid-template-columns: 1fr; } }
.dashboard-bank-col { display: grid; gap: 14px; }
.bank-trend-list {
    display: grid;
    gap: 12px;
}
.bank-trend-row {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) 92px;
    gap: 10px;
    align-items: center;
    font-size: 12px;
}
.bank-trend-month {
    font-weight: 800;
    color: var(--ink);
}
.bank-trend-bars {
    display: grid;
    gap: 4px;
}
.bank-trend-track {
    height: 8px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface-hover);
}
.bank-trend-track span {
    display: block;
    height: 100%;
    border-radius: inherit;
}
.bank-trend-track.in span { background: #16a34a; }
.bank-trend-track.out span { background: #dc2626; }
.bank-trend-value {
    text-align: right;
    font-weight: 800;
    white-space: nowrap;
}
@media (max-width: 560px) {
    .bank-trend-row {
        grid-template-columns: 42px minmax(0, 1fr);
    }
    .bank-trend-value {
        grid-column: 2;
        text-align: left;
    }
}
</style>

{{-- ══════════════════════════════════════════════════
     HERO BANCARIO
══════════════════════════════════════════════════ --}}
<div class="bank-hero">

    <div class="bank-hero__header">
        <div>
            <span class="bank-hero__type">{{ $currentAccount->owner_type === 'private' ? 'Conto Personale' : 'Conto Aziendale' }}</span>
            <span class="bank-hero__name">{{ $currentAccount->owner_label }}</span>
        </div>
        <div class="bank-hero__actions">
            <a class="cta" href="{{ route('portal.pay.form') }}">Paga</a>
            <a class="cta" href="{{ route('portal.receive.form') }}">Incassa</a>
            @if(!$currentAccount->isSubAccount())
            <form method="POST" action="{{ route('portal.payments.toggle-pause') }}" style="display:inline;">
                @csrf
                @php $isPaused = $currentAccount->company?->isPaymentsPaused(); @endphp
                <button type="submit" class="cta"
                    style="{{ $isPaused ? 'background:rgba(220,38,38,.2);border-color:rgba(220,38,38,.5);' : 'background:rgba(255,255,255,.15);' }}"
                    title="{{ $isPaused ? 'Riattiva pagamenti automatici' : 'Sospendi pagamenti automatici' }}">
                    {{ $isPaused ? '▶ Riattiva auto' : '⏸ Pausa auto' }}
                </button>
            </form>
            @endif
        </div>
        @if(!$currentAccount->isSubAccount() && $currentAccount->company?->isPaymentsPaused())
        <div style="background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.3);border-radius:8px;padding:10px 14px;margin-top:10px;font-size:13px;color:#dc2626;display:flex;align-items:center;gap:8px;">
            <strong>⏸ Pagamenti automatici sospesi</strong> — Rate e pagamenti programmati non vengono elaborati.
        </div>
        @endif
    </div>

    <div class="bank-kpi-grid">

        {{-- SALDO --}}
        <div class="bkpi">
            <div class="bkpi__icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            </div>
            <div class="bkpi__label">Saldo effettivo</div>
            <div class="bkpi__value">
                {{ $currentBalance >= 0 ? '+' : '' }}{{ number_format($currentBalance, 2, ',', '.') }}<span class="bkpi__currency">KY</span>
            </div>
            <div class="bkpi__note">{{ $currentBalance >= 0 ? 'Saldo positivo' : 'Saldo negativo' }}</div>
        </div>

        {{-- FIDO --}}
        <div class="bkpi">
            <div class="bkpi__icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
            </div>
            <div class="bkpi__label">Fido</div>
            @if($massimale > 0)
                @php
                    $fidoUsato = $currentBalance < 0 ? abs($currentBalance) : 0;
                    $fidoResiduo = max(0, $massimale - $fidoUsato);
                @endphp
                <div class="bkpi__value">{{ number_format($massimale, 2, ',', '.') }}<span class="bkpi__currency">KY</span></div>
                <div class="bkpi__note">Residuo: <strong>{{ number_format($fidoResiduo, 2, ',', '.') }} KY</strong></div>
            @else
                <div class="bkpi__value bkpi__value--muted">—</div>
                <div class="bkpi__note"><a href="{{ route('portal.fido') }}">Nessun fido attivo</a></div>
            @endif
        </div>

        {{-- KYCARD --}}
        <div class="bkpi">
            <div class="bkpi__icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="bkpi__label">KyCard acquistate</div>
            <div class="bkpi__value">{{ $kyCardCount }}<span class="bkpi__currency">card</span></div>
            <div class="bkpi__note">
                @if($kyCardCount > 0)
                    Totale: <strong>{{ number_format($kyCardTotalKy, 0, ',', '.') }} KY</strong>
                @else
                    <a href="{{ route('portal.ky-cards.index') }}">Ricarica KY →</a>
                @endif
            </div>
        </div>

        {{-- TOTALE SPENDIBILE --}}
        <div class="bkpi bkpi--primary">
            <div class="bkpi__icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div class="bkpi__label">Totale spendibile</div>
            <div class="bkpi__value">{{ number_format($availableBalance, 2, ',', '.') }}<span class="bkpi__currency">KY</span></div>
            <div class="bkpi__note">@if($massimale > 0) Saldo + fido residuo @else Saldo disponibile @endif</div>
        </div>

    </div>
</div>

{{-- ══════════════════════════════════════════════════
     LIMITI DEL CONTO
══════════════════════════════════════════════════ --}}
<section class="account-limits">
    <div class="account-limits__title">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Limiti del conto
    </div>
    <div class="limits-grid">

        <div class="limit-item">
            <div class="limit-item__icon limit-item__icon--slate">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="limit-item__body">
                <div class="limit-item__label">Saldo massimo</div>
                <div class="limit-item__value">
                    @if($limitMaxBalance !== null)
                        {{ number_format($limitMaxBalance, 2, ',', '.') }}<span class="limit-item__unit">KY</span>
                    @else
                        <span class="limit-item__unlimited">Nessun limite</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="limit-item">
            <div class="limit-item__icon limit-item__icon--amber">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
            </div>
            <div class="limit-item__body">
                <div class="limit-item__label">Limite per transazione</div>
                <div class="limit-item__value">
                    @if($limitSingleTx !== null)
                        {{ number_format($limitSingleTx, 2, ',', '.') }}<span class="limit-item__unit">KY</span>
                    @else
                        <span class="limit-item__unlimited">Nessun limite</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="limit-item">
            <div class="limit-item__icon limit-item__icon--blue">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="limit-item__body">
                <div class="limit-item__label">Limite giornaliero</div>
                <div class="limit-item__value">
                    @if($limitDaily !== null)
                        {{ number_format($limitDaily, 2, ',', '.') }}<span class="limit-item__unit">KY</span>
                    @else
                        <span class="limit-item__unlimited">Nessun limite</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="limit-item">
            <div class="limit-item__icon limit-item__icon--green">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="limit-item__body">
                <div class="limit-item__label">Limite mensile</div>
                <div class="limit-item__value">
                    @if($limitMonthly !== null)
                        {{ number_format($limitMonthly, 2, ',', '.') }}<span class="limit-item__unit">KY</span>
                    @else
                        <span class="limit-item__unlimited">Nessun limite</span>
                    @endif
                </div>
            </div>
        </div>

    </div>
</section>

{{-- Richieste in attesa --}}
@if($pendingIncomingRequests->isNotEmpty())
<div class="card" style="padding:0;overflow:hidden;border:2px solid #f59e0b;border-radius:var(--radius);margin-bottom:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;background:#fef3c7;border-bottom:1px solid #fde68a;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:20px;">🔔</span>
            <div>
                <div style="font-weight:700;font-size:14px;color:#92400e;">{{ $pendingIncomingRequests->count() }} {{ $pendingIncomingRequests->count() === 1 ? 'richiesta in attesa' : 'richieste in attesa' }}</div>
                <div style="font-size:12px;color:#a16207;">Conferma o rifiuta prima di procedere</div>
            </div>
        </div>
        <a href="{{ route('portal.requests') }}" style="font-size:12px;font-weight:700;color:#92400e;text-decoration:underline;white-space:nowrap;">Vedi tutte →</a>
    </div>
    @foreach($pendingIncomingRequests->take(3) as $req)
    @php
        $requester = $req->toAccount;
    @endphp
    <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;border-bottom:1px solid var(--line);">
        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:13px;">
                {{ $requester?->display_name ?? 'Azienda' }} ti chiede <strong style="color:#0f52c4;">{{ number_format($req->amount, 2, ',', '.') }} KY</strong>
            </div>
            @if($req->description)
            <div style="font-size:12px;color:var(--ink-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px;">{{ $req->description }}</div>
            @endif
            <div style="font-size:11px;color:var(--ink-muted);">{{ $req->created_at->diffForHumans() }}</div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;">
            <form method="POST" action="{{ route('portal.receive.requests.confirm', $req) }}" style="display:inline;">
                @csrf
                <button type="submit" class="cta" style="min-height:30px;font-size:12px;padding:0 12px;" onclick="return confirm('Confermi il pagamento di {{ number_format($req->amount, 2, ',', '.') }} KY?')">Conferma</button>
            </form>
            <form method="POST" action="{{ route('portal.receive.requests.reject', $req) }}" style="display:inline;">
                @csrf
                <button type="submit" class="cta secondary" style="min-height:30px;font-size:12px;padding:0 12px;" onclick="return confirm('Rifiuti questa richiesta?')">Rifiuta</button>
            </form>
        </div>
    </div>
    @endforeach
    @if($pendingIncomingRequests->count() > 3)
    <div style="padding:10px 16px;font-size:12px;color:var(--ink-muted);text-align:center;">
        e altre {{ $pendingIncomingRequests->count() - 3 }} — <a href="{{ route('portal.requests') }}" style="color:var(--primary);">vedi tutte</a>
    </div>
    @endif
</div>
@endif

{{-- ══════════════════════════════════════════════════
     GRIGLIA PRINCIPALE
══════════════════════════════════════════════════ --}}
<div class="dashboard-bank-grid">

    {{-- Colonna sinistra --}}
    <div class="dashboard-bank-col">

        {{-- KPI 30 giorni --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <section class="card light-card card-pad" style="border-left:4px solid #16a34a;border-radius:var(--radius,8px);">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:6px;">Entrate — 30 gg</div>
                <div style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:#16a34a;">
                    +{{ number_format($income30, 2, ',', '.') }} <span style="font-size:12px;font-weight:600;">KY</span>
                </div>
                @if($incomeTrend !== null)
                <div style="font-size:12px;margin-top:5px;color:{{ $incomeTrend >= 0 ? '#16a34a' : '#dc2626' }};">
                    {{ $incomeTrend >= 0 ? '▲' : '▼' }} {{ abs($incomeTrend) }}% vs prec.
                </div>
                @else
                <div style="font-size:12px;margin-top:5px;color:var(--ink-muted);">Primo periodo</div>
                @endif
            </section>
            <section class="card light-card card-pad" style="border-left:4px solid #dc2626;border-radius:var(--radius,8px);">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin-bottom:6px;">Uscite — 30 gg</div>
                <div style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:#dc2626;">
                    -{{ number_format($expense30, 2, ',', '.') }} <span style="font-size:12px;font-weight:600;">KY</span>
                </div>
                @if($expenseTrend !== null)
                <div style="font-size:12px;margin-top:5px;color:{{ $expenseTrend <= 0 ? '#16a34a' : '#dc2626' }};">
                    {{ $expenseTrend >= 0 ? '▲' : '▼' }} {{ abs($expenseTrend) }}% vs prec.
                </div>
                @else
                <div style="font-size:12px;margin-top:5px;color:var(--ink-muted);">Primo periodo</div>
                @endif
            </section>
        </div>

        {{-- Grafico --}}
        <section class="card card-pad" style="padding:18px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                <div>
                    <div class="k-tag" style="margin-bottom:4px;">Pulse</div>
                <div class="card-title">Flussi ultimi 6 mesi</div>
                </div>
                <div style="display:flex;gap:14px;font-size:11.5px;color:var(--ink-soft);">
                    <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:3px;background:#16a34a;display:inline-block;"></span>Entrate</span>
                    <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:3px;background:#dc2626;display:inline-block;"></span>Uscite</span>
                </div>
            </div>
            @php
                $maxTrendValue = max(
                    1,
                    (int) $monthlyTrend->max('income'),
                    (int) $monthlyTrend->max('expense')
                );
            @endphp
            <div class="bank-trend-list">
                @foreach($monthlyTrend as $month)
                    @php
                        $incomeWidth = max(2, round(((int) $month['income'] / $maxTrendValue) * 100));
                        $expenseWidth = max(2, round(((int) $month['expense'] / $maxTrendValue) * 100));
                        $trendNet = (int) $month['income'] - (int) $month['expense'];
                    @endphp
                    <div class="bank-trend-row">
                        <span class="bank-trend-month">{{ $month['label'] }}</span>
                        <span class="bank-trend-bars">
                            <span class="bank-trend-track in"><span style="width:{{ $incomeWidth }}%;"></span></span>
                            <span class="bank-trend-track out"><span style="width:{{ $expenseWidth }}%;"></span></span>
                        </span>
                        <span class="bank-trend-value" style="color:{{ $trendNet >= 0 ? '#16a34a' : '#dc2626' }};">{{ $trendNet >= 0 ? '+' : '' }}{{ number_format($trendNet, 2, ',', '.') }} KY</span>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Grafico storico saldo --}}
        <section class="card card-pad" style="padding:18px;" id="balance-history-section">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                <div>
                    <div class="k-tag" style="margin-bottom:4px;">Storico</div>
                    <div class="card-title">Andamento saldo</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <button onclick="loadBalanceChart(7)"  id="btn7"  class="period-btn active" style="font-size:11.5px;padding:4px 12px;border-radius:20px;border:1.5px solid var(--teal-strong);background:var(--teal-strong);color:#fff;cursor:pointer;">7g</button>
                    <button onclick="loadBalanceChart(30)" id="btn30" class="period-btn" style="font-size:11.5px;padding:4px 12px;border-radius:20px;border:1.5px solid var(--line);background:transparent;color:var(--ink-soft);cursor:pointer;">30g</button>
                    <button onclick="loadBalanceChart(90)" id="btn90" class="period-btn" style="font-size:11.5px;padding:4px 12px;border-radius:20px;border:1.5px solid var(--line);background:transparent;color:var(--ink-soft);cursor:pointer;">90g</button>
                </div>
            </div>
            <canvas id="balanceHistoryChart" style="width:100%;max-height:200px;"></canvas>
            <div id="balanceChartLoading" style="text-align:center;padding:30px;color:var(--ink-muted);font-size:13px;">Caricamento...</div>
        </section>

                {{-- Fido (solo se attivo) --}}
        @if($massimale > 0)
        @php
            $fidoUsato   = $currentBalance < 0 ? abs($currentBalance) : 0;
            $fidoResiduo = max(0, $massimale - $fidoUsato);
            $fidoPct     = $massimale > 0 ? round($fidoUsato / $massimale * 100) : 0;
        @endphp
        <section class="card light-card" style="border-left:4px solid #7c3aed;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;">
                <div>
                    <div class="eyebrow" style="color:#7c3aed;">Fido attivo</div>
                    <div style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:#7c3aed;margin-top:4px;">
                        {{ number_format($massimale, 2, ',', '.') }} <span style="font-size:13px;font-weight:600;">KY</span>
                    </div>
                </div>
                <a href="{{ route('portal.fido') }}" class="cta secondary" style="font-size:12px;min-height:28px;padding:0 10px;margin-top:2px;">Dettagli</a>
            </div>
            <div class="progress" style="margin-top:4px;background:#ede9fe;">
                <span style="width:{{ $fidoPct }}%;background:#7c3aed;"></span>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:6px;">
                <span class="subtle" style="font-size:11.5px;">Usato: <strong style="color:{{ $fidoUsato > 0 ? '#dc2626' : 'inherit' }};">{{ number_format($fidoUsato, 2, ',', '.') }} KY</strong></span>
                <span class="subtle" style="font-size:11.5px;">Residuo: <strong style="color:#7c3aed;">{{ number_format($fidoResiduo, 2, ',', '.') }} KY</strong></span>
            </div>
        </section>
        @endif

    </div>

    {{-- Colonna destra --}}
    <div class="dashboard-bank-col">

        {{-- Movimenti recenti --}}
        <section class="card" style="padding:0;overflow:hidden;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--line);">
                <div class="card-title">Movimenti recenti</div>
                <a class="cta secondary" href="{{ route('portal.movements') }}" style="min-height:28px;font-size:11.5px;padding:0 10px;">Vedi tutti →</a>
            </div>
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Controparte</th>
                        <th>Tipo</th>
                        <th style="text-align:right;">Importo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentTransfers as $transfer)
                        @php
                            $isOutgoing = $transfer->from_account_id === $currentAccount->id;
                            $counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount;
                        @endphp
                        <tr>
                            <td style="padding:10px 12px;">
                                <div class="date-block" style="font-size:16px;">{{ optional($transfer->booked_at)->format('d') }}<span>{{ optional($transfer->booked_at)->format('M') }}</span></div>
                            </td>
                            <td style="padding:10px 12px;">
                                <strong style="font-size:13px;">{{ $counterparty?->display_name }}</strong>
                                <div class="subtle" style="font-size:11.5px;">{{ $transfer->description ?: 'Movimento circuito' }}</div>
                            </td>
                            <td class="flow {{ $isOutgoing ? 'out' : 'in' }}" style="padding:10px 12px;font-size:12.5px;">
                                {{ $isOutgoing ? 'Uscita' : 'Entrata' }}
                            </td>
                            <td style="text-align:right;padding:10px 12px;">
                                <strong style="font-size:14px;">{{ $isOutgoing ? '-' : '+' }}{{ number_format($transfer->amount, 2, ',', '.') }}</strong>
                                <div style="color:var(--teal-strong);font-size:10.5px;font-weight:700;">{{ $transfer->currency_code }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="subtle" style="text-align:center;padding:22px;">Nessun movimento disponibile.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{-- Sottoconti --}}
        <section class="card light-card">
            <div class="section-head" style="margin-bottom:12px;">
                <div>
                    <div class="eyebrow">Struttura</div>
                    <div class="card-title" style="margin-top:2px;">Sottoconti</div>
                </div>
                <span class="pill">{{ $subaccounts->count() }}</span>
            </div>
            @if ($subaccounts->isEmpty())
                <div class="empty-state" style="padding:14px;">
                    <strong>Nessun sottoconto attivo.</strong>
                    <p style="margin:4px 0 0;font-size:13px;">Crea conti per delegati o reparti.</p>
                </div>
            @else
                <div class="timeline-list" style="gap:8px;">
                    @foreach ($subaccounts->take(3) as $subaccount)
                        <article class="timeline-item" style="padding:10px 12px;gap:6px;">
                            <div class="entity-head">
                                <div>
                                    <strong style="font-size:13.5px;">{{ $subaccount->display_name }}</strong>
                                    <div class="subtle" style="font-size:11.5px;">{{ $subaccount->managedUsers->pluck('name')->implode(', ') ?: 'Nessun responsabile' }}</div>
                                </div>
                                <span class="chip {{ $subaccount->status === 'active' ? 'success' : 'pink' }}">{{ $subaccount->status === 'active' ? 'Attivo' : 'Sospeso' }}</span>
                            </div>
                            <div style="display:flex;gap:10px;">
                                <small style="color:var(--ink-soft);"><strong>{{ number_format($subaccount->available_balance, 2, ',', '.') }} KY</strong> disponibili</small>
                                <small style="color:var(--ink-muted);">Limite {{ number_format($subaccount->spending_limit ?? 0, 2, ',', '.') }} KY</small>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div style="margin-top:10px;text-align:right;">
                    <a class="cta secondary" href="{{ route('portal.accounts.structure') }}" style="font-size:12px;min-height:30px;padding:0 10px;">Tutti i sottoconti →</a>
                </div>
            @endif
        </section>

    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
var balanceChartInstance = null;
var currentPeriod = 7;

function loadBalanceChart(days) {
    currentPeriod = days;
    // Update button styles
    [7,30,90].forEach(function(d) {
        var btn = document.getElementById('btn' + d);
        if (btn) {
            if (d === days) {
                btn.style.background = 'var(--teal-strong)';
                btn.style.borderColor = 'var(--teal-strong)';
                btn.style.color = '#fff';
            } else {
                btn.style.background = 'transparent';
                btn.style.borderColor = 'var(--line)';
                btn.style.color = 'var(--ink-soft)';
            }
        }
    });

    var canvas = document.getElementById('balanceHistoryChart');
    var loading = document.getElementById('balanceChartLoading');
    canvas.style.display = 'none';
    loading.style.display = 'block';

    fetch('{{ route("portal.balance-history") }}?days=' + days)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loading.style.display = 'none';
            canvas.style.display = 'block';

            var labels  = data.map(function(d) {
                var date = new Date(d.date);
                return days <= 7
                    ? date.toLocaleDateString('it-IT', {weekday:'short', day:'numeric'})
                    : date.toLocaleDateString('it-IT', {day:'numeric', month:'short'});
            });
            var values = data.map(function(d) { return d.balance; });

            var isPositive = values[values.length - 1] >= 0;
            var lineColor  = isPositive ? '#0d9488' : '#dc2626';
            var fillColor  = isPositive ? 'rgba(13,148,136,.1)' : 'rgba(220,38,38,.08)';

            if (balanceChartInstance) { balanceChartInstance.destroy(); }

            balanceChartInstance = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Saldo KY',
                        data: values,
                        borderColor: lineColor,
                        backgroundColor: fillColor,
                        borderWidth: 2,
                        pointRadius: days <= 7 ? 4 : 2,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.35,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    return ' ' + ctx.parsed.y.toLocaleString('it-IT', {minimumFractionDigits:2}) + ' KY';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: {
                            ticks: {
                                font: { size: 11 },
                                callback: function(v) { return v.toLocaleString('it-IT') + ' KY'; }
                            }
                        }
                    }
                }
            });
            canvas.style.maxHeight = '200px';
        })
        .catch(function() {
            loading.textContent = 'Errore caricamento dati.';
        });
}

document.addEventListener('DOMContentLoaded', function() { loadBalanceChart(7); });
</script>
@endpush

@endsection
