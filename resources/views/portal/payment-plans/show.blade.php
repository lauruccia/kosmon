@extends('layouts.portal')

@section('content')

<section class="page-intro--row page-intro">
    <div class="page-intro-body">
        <span class="eyebrow">Piano rateale #{{ $plan->id }}</span>
        <h2>{{ $plan->description ?: 'Piano rateale' }}</h2>
        <p>
            {{ $isDebtor ? 'Stai pagando' : 'Stai ricevendo' }}
            <strong>{{ number_format($plan->total_amount, 2, ',', '.') }} KY</strong>
            in {{ $plan->installments_count }} rate {{ $plan->frequencyLabel() }}i
            {{ $isDebtor ? 'a' : 'da' }}
            <strong>{{ $isDebtor ? $plan->toAccount?->display_name : $plan->fromAccount?->display_name }}</strong>.
        </p>
    </div>
    <div class="page-actions">
        <a class="cta secondary" href="{{ route('portal.payment-plans.index') }}">← I miei piani</a>
        @if(($plan->status === 'active' && $isDebtor) || ($plan->isPendingApproval() && $isProposer))
            <form method="POST" action="{{ route('portal.payment-plans.cancel', $plan) }}" style="display:inline;">
                @csrf
                <button type="submit" class="cta secondary"
                    style="border-color:#dc2626;color:#dc2626;"
                    onclick="return confirm('Ritirare la proposta? Verra' marcata come annullata.')">
                    {{ $plan->isPendingApproval() ? 'Ritira proposta' : 'Annulla piano' }}
                </button>
            </form>
        @endif
    </div>
</section>

@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- Banner pending approval --}}
@if($plan->isPendingApproval())
<div style="background:#fff;border:2px solid #fcd34d;border-radius:14px;padding:20px 24px;margin-bottom:24px;">
    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        <div style="font-size:36px;">⏳</div>
        <div style="flex:1;min-width:220px;">
            <div style="font-weight:800;font-size:16px;margin-bottom:6px;">In attesa di approvazione</div>
            @if($isProposer)
                <p style="font-size:14px;color:var(--text-muted);margin:0;">
                    Hai inviato questa proposta a <strong>{{ $plan->counterpartyAccount()?->display_name }}</strong>.
                    Le rate partiranno solo dopo la sua accettazione.
                </p>
            @elseif($canApprove)
                <p style="font-size:14px;color:var(--text-muted);margin-bottom:14px;">
                    <strong>{{ $plan->proposerAccount()?->display_name }}</strong>
                    @if($plan->initiator_role === 'debtor')
                        chiede di pagarti <strong>{{ number_format($plan->total_amount, 2, ',', '.') }} KY</strong>
                        in {{ $plan->installments_count }} rate {{ $plan->frequencyLabel() }}i.
                    @else
                        ti propone di pagare <strong>{{ number_format($plan->total_amount, 2, ',', '.') }} KY</strong>
                        in {{ $plan->installments_count }} rate {{ $plan->frequencyLabel() }}i.
                    @endif
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <form method="POST" action="{{ route('portal.payment-plans.approve', $plan) }}">
                        @csrf
                        <button type="submit" class="cta" style="background:#16a34a;">
                            Accetta piano rateale
                        </button>
                    </form>
                    <form method="POST" action="{{ route('portal.payment-plans.reject', $plan) }}">
                        @csrf
                        <button type="submit" class="cta secondary" style="border-color:#dc2626;color:#dc2626;"
                            onclick="return confirm('Rifiutare questa proposta? Il proponente verra' notificato.')">
                            Rifiuta proposta
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>
@elseif($plan->isRejected())
<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:14px;padding:20px 24px;margin-bottom:24px;">
    <div style="display:flex;gap:12px;align-items:center;">
        <div style="font-size:32px;">❌</div>
        <div>
            <div style="font-weight:700;color:#dc2626;">Proposta rifiutata</div>
            <div style="font-size:13px;color:#b91c1c;margin-top:3px;">
                @if($isProposer)
                    La controparte ha rifiutato la tua proposta. Puoi crearne una nuova con condizioni diverse.
                @else
                    Hai rifiutato questa proposta rateale.
                @endif
            </div>
        </div>
    </div>
</div>
@endif

{{-- KPI strip --}}
@php
    $paid      = $plan->installments->where('status', 'paid')->count();
    $pending   = $plan->installments->where('status', 'pending')->count();
    $failed    = $plan->installments->where('status', 'failed')->count();
    $pct       = $plan->installments_count > 0 ? round($paid / $plan->installments_count * 100) : 0;
    $amtPaid   = $plan->installments->where('status', 'paid')->sum('amount');
    $amtLeft   = $plan->total_amount - $amtPaid;
@endphp

<section class="hero-strip" style="margin-bottom:22px;">
    <article class="stat-card" style="border-left:4px solid #16a34a;">
        <div class="eyebrow">Pagato</div>
        <div class="section-title" style="font-size:28px;color:#16a34a;">{{ number_format($amtPaid, 2, ',', '.') }} KY</div>
        <div class="table-muted">{{ $paid }} {{ $paid === 1 ? 'rata' : 'rate' }} su {{ $plan->installments_count }}</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #f59e0b;">
        <div class="eyebrow">Rimanente</div>
        <div class="section-title" style="font-size:28px;color:#f59e0b;">{{ number_format($amtLeft, 2, ',', '.') }} KY</div>
        <div class="table-muted">{{ $pending }} rate ancora da pagare</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #0284c7;">
        <div class="eyebrow">Totale piano</div>
        <div class="section-title" style="font-size:28px;color:#0284c7;">{{ number_format($plan->total_amount, 2, ',', '.') }} KY</div>
        <div class="table-muted">{{ $plan->frequencyLabel() }} · {{ $plan->installments_count }} rate</div>
    </article>
    <article class="stat-card" style="border-left:4px solid {{ $plan->status === 'active' ? '#16a34a' : ($plan->status === 'completed' ? '#6d28d9' : '#dc2626') }};">
        <div class="eyebrow">Stato piano</div>
        <div class="section-title" style="font-size:22px;">
            @if($plan->status === 'active') ✅ Attivo
            @elseif($plan->status === 'completed') 🏁 Completato
            @elseif($plan->status === 'pending_approval') ⏳ In attesa
            @elseif($plan->status === 'rejected') ❌ Rifiutato
            @else 🚫 Annullato
            @endif
        </div>
        @if($failed > 0)<div class="table-muted" style="color:#dc2626;">{{ $failed }} rate fallite</div>@endif
    </article>
</section>

{{-- Barra progresso --}}
<section class="card light-card" style="margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <span style="font-size:13px;font-weight:700;">Progresso pagamento</span>
        <span style="font-size:13px;color:var(--ink-muted);">{{ $pct }}%</span>
    </div>
    <div style="height:12px;background:var(--line);border-radius:6px;overflow:hidden;">
        <div style="height:100%;width:{{ $pct }}%;background:linear-gradient(90deg,#0f52c4,#16a34a);border-radius:6px;transition:width .5s;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--ink-muted);">
        <span>{{ number_format($amtPaid, 2, ',', '.') }} KY pagati</span>
        <span>{{ number_format($amtLeft, 2, ',', '.') }} KY rimanenti</span>
    </div>
</section>

{{-- Dettagli piano --}}
<div class="summary-grid" style="margin-bottom:20px;">
    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Parti coinvolte</span>
                <h3 class="section-title">Chi paga · Chi riceve</h3>
            </div>
        </div>
        <div style="display:grid;gap:14px;">
            <div style="display:flex;gap:12px;align-items:center;">
                <div style="width:36px;height:36px;border-radius:10px;background:#fef2f2;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">📤</div>
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Pagante</div>
                    <div style="font-weight:600;">{{ $plan->fromAccount?->display_name ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);">{{ $plan->fromAccount?->account_number }}</div>
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:center;">
                <div style="width:36px;height:36px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">📥</div>
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Creditore</div>
                    <div style="font-weight:600;">{{ $plan->toAccount?->display_name ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);">{{ $plan->toAccount?->account_number }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="card light-card">
        <div class="section-head">
            <div>
                <span class="eyebrow">Parametri</span>
                <h3 class="section-title">Dettagli piano</h3>
            </div>
        </div>
        <div style="display:grid;gap:10px;font-size:14px;">
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Totale</span><strong>{{ number_format($plan->total_amount, 2, ',', '.') }} KY</strong></div>
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">N° rate</span><strong>{{ $plan->installments_count }}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Frequenza</span><strong>{{ ucfirst($plan->frequencyLabel()) }}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Prima rata</span><strong>{{ $plan->first_due_date->format('d/m/Y') }}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Creato da</span><strong>{{ $plan->initiator?->name ?? '—' }}</strong></div>
            <div style="display:flex;justify-content:space-between;"><span class="table-muted">Data creazione</span><strong>{{ $plan->created_at->format('d/m/Y') }}</strong></div>
        </div>
    </section>
</div>

{{-- Tabella rate --}}
<section class="card light-card" style="margin-bottom:20px;">
    <div class="section-head">
        <div>
            <span class="eyebrow">Scadenziario</span>
            <h3 class="section-title">Tutte le rate</h3>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Scadenza</th>
                    <th>Importo</th>
                    <th>Stato</th>
                    <th>Processata il</th>
                    <th>Movimento</th>
                </tr>
            </thead>
            <tbody>
                @foreach($plan->installments as $installment)
                @php
                    $isOverdue = $installment->status === 'pending' && $installment->due_date->isPast();
                @endphp
                <tr style="{{ $isOverdue ? 'background:#fff7ed;' : '' }}">
                    <td style="font-weight:700;color:var(--ink-muted);">{{ $installment->installment_number }}</td>
                    <td style="font-weight:600;color:{{ $isOverdue ? '#dc2626' : 'var(--ink)' }};">
                        {{ $installment->due_date->format('d/m/Y') }}
                        @if($isOverdue) <span style="font-size:11px;background:#fef2f2;color:#dc2626;padding:2px 6px;border-radius:4px;margin-left:4px;">SCADUTA</span> @endif
                    </td>
                    <td style="font-weight:700;">{{ number_format($installment->amount, 2, ',', '.') }} KY</td>
                    <td>
                        @if($installment->status === 'paid')
                            <span class="chip success">Pagata</span>
                        @elseif($installment->status === 'failed')
                            <span class="chip pink" title="{{ $installment->failure_reason }}">Fallita</span>
                        @elseif($installment->status === 'cancelled')
                            <span class="chip">Annullata</span>
                        @else
                            <span class="chip" style="{{ $isOverdue ? 'background:#fef2f2;color:#dc2626;border-color:#fecaca;' : '' }}">In attesa</span>
                        @endif
                    </td>
                    <td class="table-muted" style="font-size:12px;">
                        {{ $installment->processed_at?->format('d/m/Y H:i') ?? '—' }}
                    </td>
                    <td>
                        @if($installment->transfer)
                            <code style="font-size:11px;background:var(--bg);padding:2px 6px;border-radius:4px;">
                                {{ $installment->transfer->reference }}
                            </code>
                        @elseif($installment->failure_reason)
                            <span style="font-size:11px;color:#dc2626;">{{ $installment->failure_reason }}</span>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

@endsection
