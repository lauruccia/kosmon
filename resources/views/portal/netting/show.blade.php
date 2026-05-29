@extends('layouts.portal')

@section('content')

<section class="page-intro--row page-intro">
    <div class="page-intro-body">
        <span class="eyebrow">Compensazione #{{ $proposal->id }}</span>
        <h2>{{ $proposal->description ?: 'Compensazione crediti incrociati' }}</h2>
        <p>
            <strong>{{ $proposal->proposerAccount?->display_name }}</strong>
            ha proposto di compensare i crediti incrociati con
            <strong>{{ $proposal->counterpartyAccount?->display_name }}</strong>.
        </p>
    </div>
    <div class="page-actions">
        <a class="cta secondary" href="{{ route('portal.netting.index') }}">← Le mie compensazioni</a>
    </div>
</section>

@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- Stato + Azioni (solo counterparty su proposta pending) --}}
@if($isCounterparty && $proposal->isPending() && !$proposal->isExpired())
<section class="card light-card" style="margin-bottom:20px;border:2px solid #f59e0b;background:#fffbeb;">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;">
        <div>
            <div style="font-size:15px;font-weight:700;color:#92400e;">⏳ Azione richiesta</div>
            <div style="font-size:13px;color:#92400e;margin-top:4px;">
                {{ $proposal->proposerAccount?->display_name }} ha proposto questa compensazione.
                Hai tempo fino al <strong>{{ $proposal->expires_at?->format('d/m/Y') }}</strong> per rispondere.
            </div>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <form method="POST" action="{{ route('portal.netting.reject', $proposal) }}" style="display:inline;">
                @csrf
                <button type="submit" class="cta secondary"
                    style="border-color:#dc2626;color:#dc2626;"
                    onclick="return confirm('Rifiutare la proposta? I crediti in sospeso rimarranno invariati.')">
                    ❌ Rifiuta
                </button>
            </form>
            <form method="POST" action="{{ route('portal.netting.accept', $proposal) }}" style="display:inline;">
                @csrf
                <button type="submit" class="cta"
                    style="background:#16a34a;"
                    onclick="return confirm('Accettare la compensazione? I crediti selezionati saranno annullati e verrà generato il saldo netto.')">
                    ✅ Accetta compensazione
                </button>
            </form>
        </div>
    </div>
</section>
@endif

{{-- KPI strip --}}
<section class="hero-strip" style="margin-bottom:22px;">
    <article class="stat-card" style="border-left:4px solid #0284c7;">
        <div class="eyebrow">Crediti {{ $proposal->proposerAccount?->display_name }}</div>
        <div class="section-title" style="font-size:28px;color:#0284c7;">{{ number_format($proposal->proposer_total, 2, ',', '.') }} KY</div>
        <div class="table-muted">{{ count($proposal->proposer_transfer_ids ?? []) }} trasferimenti</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #16a34a;">
        <div class="eyebrow">Crediti {{ $proposal->counterpartyAccount?->display_name }}</div>
        <div class="section-title" style="font-size:28px;color:#16a34a;">{{ number_format($proposal->counterparty_total, 2, ',', '.') }} KY</div>
        <div class="table-muted">{{ count($proposal->counterparty_transfer_ids ?? []) }} trasferimenti</div>
    </article>
    <article class="stat-card" style="border-left:4px solid {{ $proposal->net_amount === 0 ? '#6d28d9' : '#f59e0b' }};">
        <div class="eyebrow">Saldo netto</div>
        <div class="section-title" style="font-size:28px;color:{{ $proposal->net_amount === 0 ? '#6d28d9' : '#f59e0b' }};">
            @if($proposal->net_amount === 0) ⚖️ Pareggio
            @else {{ number_format($proposal->net_amount, 2, ',', '.') }} KY
            @endif
        </div>
        @if($proposal->net_amount > 0)
            <div class="table-muted">Paga: {{ $proposal->netPayerAccount?->display_name }}</div>
        @else
            <div class="table-muted">Nessun pagamento netto</div>
        @endif
    </article>
    <article class="stat-card" style="border-left:4px solid
        @if($proposal->status === 'accepted') #16a34a
        @elseif($proposal->status === 'rejected') #dc2626
        @elseif($proposal->status === 'expired') #6b7280
        @else #f59e0b @endif;">
        <div class="eyebrow">Stato</div>
        <div class="section-title" style="font-size:22px;">
            @if($proposal->status === 'pending')   ⏳ In attesa
            @elseif($proposal->status === 'accepted') ✅ Accettata
            @elseif($proposal->status === 'rejected') ❌ Rifiutata
            @else 🕐 Scaduta
            @endif
        </div>
        @if($proposal->actioned_at)
            <div class="table-muted" style="font-size:11px;">{{ $proposal->actioned_at->format('d/m/Y H:i') }}</div>
        @endif
    </article>
</section>

{{-- Dettagli + Parti --}}
<div class="summary-grid" style="margin-bottom:20px;">

    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Parti coinvolte</span>
                <h3 class="section-title">Proponente · Controparte</h3>
            </div>
        </div>
        <div style="display:grid;gap:14px;">
            <div style="display:flex;gap:12px;align-items:center;">
                <div style="width:36px;height:36px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">📤</div>
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Proponente</div>
                    <div style="font-weight:600;">{{ $proposal->proposerAccount?->display_name ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);">{{ $proposal->proposerAccount?->account_number }}</div>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:center;">
                <div style="width:36px;height:36px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">📥</div>
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Controparte</div>
                    <div style="font-weight:600;">{{ $proposal->counterpartyAccount?->display_name ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);">{{ $proposal->counterpartyAccount?->account_number }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Parametri</span>
                <h3 class="section-title">Dettagli proposta</h3>
            </div>
        </div>
        <div style="display:grid;gap:10px;font-size:14px;">
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Proposta da</span><strong>{{ $proposal->proposedBy?->name ?? '—' }}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Data proposta</span><strong>{{ $proposal->created_at->format('d/m/Y H:i') }}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Scadenza</span><strong>{{ $proposal->expires_at?->format('d/m/Y') ?? '—' }}</strong></div>
            @if($proposal->actioned_at)
                <div style="display:flex;justify-content:space-between;"><span class="table-muted">Gestita da</span><strong>{{ $proposal->actionedBy?->name ?? '—' }}</strong></div>
                <div style="display:flex;justify-content:space-between;"><span class="table-muted">Gestita il</span><strong>{{ $proposal->actioned_at->format('d/m/Y H:i') }}</strong></div>
            @endif
            @if($proposal->description)
                <div style="display:flex;justify-content:space-between;"><span class="table-muted">Descrizione</span><strong>{{ $proposal->description }}</strong></div>
            @endif
            @if($proposal->netTransfer)
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="table-muted">Trasferimento netto</span>
                    <code style="font-size:11px;background:var(--bg);padding:2px 6px;border-radius:4px;">{{ $proposal->netTransfer->reference }}</code>
                </div>
            @endif
        </div>
    </section>

</div>

{{-- Trasferimenti inclusi --}}
<div class="summary-grid" style="margin-bottom:20px;">

    {{-- Crediti del proposer --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Crediti del proponente</span>
                <h3 class="section-title">{{ $proposal->proposerAccount?->display_name }}</h3>
            </div>
            <span style="font-size:22px;">📤</span>
        </div>
        @if($proposerTransfers->isEmpty())
            <div style="color:var(--ink-muted);font-size:13px;padding:12px;">Nessun trasferimento selezionato.</div>
        @else
            <div style="display:grid;gap:8px;">
                @foreach($proposerTransfers as $t)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:var(--bg);border-radius:8px;border:1px solid var(--line);">
                    <div>
                        <div style="font-size:13px;font-weight:600;">{{ $t->description ?: '—' }}</div>
                        <div style="font-size:11px;color:var(--ink-muted);">Rif. {{ $t->reference }}</div>
                    </div>
                    <div style="font-weight:700;color:#0284c7;white-space:nowrap;">{{ number_format($t->amount, 2, ',', '.') }} KY</div>
                </div>
                @endforeach
                <div style="text-align:right;font-size:13px;font-weight:700;padding:6px 12px;color:#0284c7;">
                    Totale: {{ number_format($proposal->proposer_total, 2, ',', '.') }} KY
                </div>
            </div>
        @endif
    </section>

    {{-- Crediti della controparte --}}
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Crediti della controparte</span>
                <h3 class="section-title">{{ $proposal->counterpartyAccount?->display_name }}</h3>
            </div>
            <span style="font-size:22px;">📥</span>
        </div>
        @if($counterpartyTransfers->isEmpty())
            <div style="color:var(--ink-muted);font-size:13px;padding:12px;">Nessun trasferimento selezionato.</div>
        @else
            <div style="display:grid;gap:8px;">
                @foreach($counterpartyTransfers as $t)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:var(--bg);border-radius:8px;border:1px solid var(--line);">
                    <div>
                        <div style="font-size:13px;font-weight:600;">{{ $t->description ?: '—' }}</div>
                        <div style="font-size:11px;color:var(--ink-muted);">Rif. {{ $t->reference }}</div>
                    </div>
                    <div style="font-weight:700;color:#16a34a;white-space:nowrap;">{{ number_format($t->amount, 2, ',', '.') }} KY</div>
                </div>
                @endforeach
                <div style="text-align:right;font-size:13px;font-weight:700;padding:6px 12px;color:#16a34a;">
                    Totale: {{ number_format($proposal->counterparty_total, 2, ',', '.') }} KY
                </div>
            </div>
        @endif
    </section>

</div>

@endsection
