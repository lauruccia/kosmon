@extends('layouts.portal')

@section('content')
    <section class="page-intro--row page-intro delegate-hero {{ $currentAccount->status !== 'active' ? 'is-suspended' : '' }}">
<div class="page-intro-body">
        <span class="eyebrow">Vista delegato</span>
        <h2>{{ $currentAccount->display_name }}</h2>
        <p>{{ $currentAccount->status === 'active' ? 'Stai operando su un sottoconto delegato. Qui trovi solo budget, limiti residui, stato operativo e movimenti essenziali.' : 'Il sottoconto e sospeso. Puoi consultare lo stato, ma finche il proprietario non riattiva il profilo non puoi eseguire operazioni.' }}</p>
</div>
        <div class="page-actions">
            @if ($currentAccount->status === 'active')
                <a class="cta" href="{{ route('portal.pay.form') }}">Paga</a>
                <a class="cta secondary" href="{{ route('portal.receive.form') }}">Incassa</a>
            @endif
            <a class="cta secondary" href="{{ route('portal.movements') }}">Movimenti</a>
        </div>
    </section>

    <div class="summary-topline">
        <span><strong>Owner:</strong> {{ $rootAccount->display_name }}</span>
        <span><strong>Delegato:</strong> {{ $currentUser->name }}</span>
        <span><strong>Stato:</strong> {{ $currentAccount->status === 'active' ? 'Operativo' : 'Sospeso' }}</span>
    </div>

    <div class="portal-grid delegate-grid">
        <div class="stack">
            <section class="card account-hero card-pad">
                <div class="k-tag">Delegate wallet</div>
                <h1 style="position:relative;z-index:1;margin:16px 0 18px;">Controllo operativo</h1>
                <div class="metric"><div class="metric-label">Saldo sottoconto</div><div class="metric-value">{{ ky_format($currentBalance) }} KY</div></div>
                <div class="metric"><div class="metric-label">Saldo disponibile</div><div class="metric-value">{{ ky_format($availableBalance) }} KY</div></div>
                <div class="metric"><div class="metric-label">Massimale</div><div class="metric-value">{{ ky_format($massimale) }} KY</div></div>
                <div class="metric"><div class="metric-label">Consumato oggi</div><div class="metric-value">{{ ky_format($dailySpent) }} KY</div></div>
            </section>

            <section class="card light-card">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Guardrail</span>
                        <h3 class="section-title">Limiti residui</h3>
                    </div>
                    <span class="pill {{ $currentAccount->status === 'active' ? 'success' : 'warn' }}">{{ $currentAccount->status === 'active' ? 'Attivo' : 'Sospeso' }}</span>
                </div>
                <div class="timeline-list">
                    <article class="timeline-item">
                        <strong>Budget disponibile</strong>
                        <div class="subtle">Saldo immediatamente spendibile sul sottoconto delegato.</div>
                        <div class="metric-value" style="font-size:22px; color:var(--teal-strong);">{{ ky_format($availableBalance) }} KY</div>
                    </article>
                    <article class="timeline-item">
                        <strong>Plafond giornaliero residuo</strong>
                        <div class="subtle">{{ $remainingDailyLimit === null ? 'Nessun tetto giornaliero configurato dal proprietario.' : 'Quanto ti resta oggi prima del blocco automatico.' }}</div>
                        <div class="metric-value" style="font-size:22px; color:var(--teal-strong);">{{ $remainingDailyLimit === null ? 'Illimitato' : ky_format($remainingDailyLimit) . ' KY' }}</div>
                    </article>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card light-card">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Attivita recente</span>
                        <h3 class="section-title">Ultimi movimenti</h3>
                    </div>
                </div>
                <table class="transactions-table">
                    <thead><tr><th>Data</th><th>Controparte</th><th>Tipo</th><th>Importo</th></tr></thead>
                    <tbody>
                        @forelse ($recentTransfers as $transfer)
                            @php($isOutgoing = $transfer->from_account_id === $currentAccount->id)
                            @php($counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount)
                            <tr><td><div class="date-block">{{ optional($transfer->booked_at)->format('d') }}<span>{{ optional($transfer->booked_at)->format('M') }}</span></div></td><td>{{ $counterparty?->display_name }}<div class="subtle">{{ $transfer->description ?: 'Movimento circuito' }}</div></td><td class="flow {{ $isOutgoing ? 'out' : 'in' }}">{{ $isOutgoing ? 'Spesa delegata' : 'Budget ricevuto' }}<small>{{ $transfer->kind }}</small></td><td>{{ $isOutgoing ? '-' : '+' }}{{ ky_format($transfer->amount) }} KY</td></tr>
                        @empty
                            <tr><td colspan="4" class="subtle">Nessun movimento disponibile.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    </div>
@endsection
