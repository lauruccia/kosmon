@extends('layouts.portal')




@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- Proposte in attesa di approvazione --}}
@if($pendingApproval->isNotEmpty())
<section style="margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
        <h3 style="margin:0;font-size:18px;">Proposte in attesa</h3>
        <span style="background:#f59e0b;color:#fff;font-size:11px;font-weight:700;padding:2px 10px;border-radius:99px;">{{ $pendingApproval->count() }} in attesa</span>
    </div>
    <div style="display:grid;gap:12px;">
        @foreach($pendingApproval as $plan)
        @php
            $isDebtorRole   = $plan->initiator_role === 'debtor';
            $proposerName   = $isDebtorRole
                ? ($plan->fromAccount?->display_name ?? 'Acquirente')
                : ($plan->toAccount?->display_name ?? 'Venditore');
            $proposerIcon   = $isDebtorRole ? '🛒' : '🏪';
            $roleLabel      = $isDebtorRole ? 'chiede di pagare a rate' : 'offre la rateizzazione';
        @endphp
        <div style="background:#fff;border:2px solid #fcd34d;border-radius:14px;padding:18px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
            <div style="font-size:32px;">{{ $proposerIcon }}</div>
            <div style="flex:1;min-width:200px;">
                <div style="font-weight:700;font-size:15px;margin-bottom:3px;">
                    {{ $proposerName }} <span style="color:var(--text-muted);font-weight:400;font-size:13px;">{{ $roleLabel }}</span>
                </div>
                <div style="font-size:13px;color:var(--text-muted);">
                    <strong style="color:var(--text);">{{ ky_format($plan->total_amount) }} KY</strong>
                    in {{ $plan->installments_count }} rate {{ $plan->frequencyLabel() }}i
                    &mdash; prima rata {{ \Carbon\Carbon::parse($plan->first_due_date)->format('d/m/Y') }}
                </div>
                @if($plan->description)
                    <div style="font-size:12px;color:var(--text-muted);margin-top:3px;font-style:italic;">{{ $plan->description }}</div>
                @endif
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <form method="POST" action="{{ route('portal.payment-plans.approve', $plan) }}">
                    @csrf
                    <button type="submit" class="cta" style="background:#16a34a;padding:8px 18px;font-size:13px;">
                        Accetta
                    </button>
                </form>
                <form method="POST" action="{{ route('portal.payment-plans.reject', $plan) }}">
                    @csrf
                    <button type="submit" class="cta secondary" style="border-color:#dc2626;color:#dc2626;padding:8px 18px;font-size:13px;"
                        onclick="return confirm('Rifiutare questa proposta? Il proponente verrà notificato.')">
                        Rifiuta
                    </button>
                </form>
                <a href="{{ route('portal.payment-plans.show', $plan) }}" class="cta secondary" style="padding:8px 14px;font-size:13px;">
                    Dettagli
                </a>
            </div>
        </div>
        @endforeach
    </div>
</section>
@endif

{{-- KPI strip --}}
<section class="hero-strip" style="margin-bottom:22px;grid-template-columns:repeat(5,minmax(0,1fr));">
    <article class="stat-card" style="border-left:4px solid #f59e0b;">
        <div class="eyebrow">Da pagare (attivi)</div>
        <div class="section-title" style="font-size:34px;color:#f59e0b;">
            {{ $asDebtor->where('status','active')->count() }}
        </div>
        <div class="table-muted">Piani in cui sei il pagante</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #0284c7;">
        <div class="eyebrow">Da ricevere (attivi)</div>
        <div class="section-title" style="font-size:34px;color:#0284c7;">
            {{ $asCreditor->where('status','active')->count() }}
        </div>
        <div class="table-muted">Piani in cui incassi</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #16a34a;">
        <div class="eyebrow">Rate pagate (totale)</div>
        <div class="section-title" style="font-size:34px;color:#16a34a;">
            {{ $asDebtor->flatMap->installments->where('status','paid')->count() + $asCreditor->flatMap->installments->where('status','paid')->count() }}
        </div>
        <div class="table-muted">Tra pagante e ricevente</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #6d28d9;">
        <div class="eyebrow">Rate in scadenza</div>
        @php
            $upcomingInstallments = $asDebtor->flatMap->installments->where('status','pending')->filter(fn($i) => $i->due_date->lte(now()->addDays(7)));
            $overdueCount = $asDebtor->flatMap->installments->where('status','pending')->filter(fn($i) => $i->due_date->isPast())->count();
        @endphp
        <div class="section-title" style="font-size:34px;color:{{ $overdueCount > 0 ? '#dc2626' : '#6d28d9' }};">
            {{ $upcomingInstallments->count() }}
        </div>
        <div class="table-muted">
            Prossimi 7 giorni · Pagante
            @if($overdueCount > 0)
                &nbsp;<span style="color:#dc2626;font-weight:700;">· {{ $overdueCount }} scadute!</span>
            @endif
        </div>
    </article>
    {{-- Azioni rapide come 5ª colonna --}}
    <article class="stat-card" style="display:flex;flex-direction:column;justify-content:center;gap:10px;border-left:4px solid var(--primary);">
        <div class="eyebrow">Azioni</div>
        <a class="cta" href="{{ route('portal.payment-plans.create', ['role' => 'creditor']) }}" title="Proponi a un cliente di pagare a rate" style="text-align:center;font-size:13px;">
            🏪 Propongo rate
        </a>
        <a class="cta secondary" href="{{ route('portal.payment-plans.create', ['role' => 'debtor']) }}" title="Chiedi a un venditore di farti pagare a rate" style="text-align:center;font-size:13px;">
            🛒 Chiedo di pagare
        </a>
    </article>
</section>

{{-- Piani da pagare + da ricevere affiancati --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

{{-- Piani da pagare --}}
<section class="card light-card" style="margin-bottom:0;">
    <div class="section-head">
        <div>
            <span class="eyebrow">Pagante</span>
            <h3 class="section-title">Piani che devi pagare</h3>
        </div>
        <span style="font-size:22px;">📤</span>
    </div>

    @if($asDebtor->isEmpty())
        <div style="text-align:center;padding:32px;color:var(--ink-muted);">
            <div style="font-size:28px;margin-bottom:8px;">📭</div>
            <div style="font-weight:600;">Nessun piano rateale attivo come pagante.</div>
                        <a href="{{ route('portal.payment-plans.create', ['role' => 'debtor']) }}" style="margin-top:8px;display:inline-block;font-size:13px;color:var(--primary);text-decoration:underline;">
                            Crea un nuovo piano &rarr;
                        </a>
            <div style="font-size:13px;margin-top:4px;">
                <a href="{{ route('portal.payment-plans.create') }}" style="color:var(--accent);">Crea un nuovo piano →</a>
            </div>
        </div>
    @else
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Creditor</th>
                        <th>Totale</th>
                        <th>Progresso</th>
                        <th>Rate</th>
                        <th>Frequenza</th>
                        <th>Stato</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($asDebtor as $plan)
                    @php
                        $paid    = $plan->installments->where('status','paid')->count();
                        $total   = $plan->installments_count;
                        $pct     = $total > 0 ? round($paid / $total * 100) : 0;
                        $nextDue = $plan->installments->where('status','pending')->sortBy('due_date')->first();
                    @endphp
                    <tr>
                        <td>
                            <div style="font-weight:600;">{{ $plan->toAccount?->display_name ?? '—' }}</div>
                            <div class="table-muted" style="font-size:11px;">{{ $plan->description }}</div>
                        </td>
                        <td style="font-weight:700;color:#0f52c4;">{{ ky_format($plan->total_amount) }} KY</td>
                        <td style="min-width:120px;">
                            <div style="height:7px;background:var(--line);border-radius:4px;overflow:hidden;margin-bottom:4px;">
                                <div style="height:100%;width:{{ $pct }}%;background:linear-gradient(90deg,#f59e0b,#fbbf24);border-radius:4px;transition:width .4s;"></div>
                            </div>
                            <div class="table-muted" style="font-size:11px;">{{ $paid }}/{{ $total }} rate &middot; {{ $pct }}%</div>
                        </td>
                        <td class="table-muted">
                            @if($nextDue)
                                <span style="color:{{ $nextDue->due_date->isPast() ? '#dc2626' : 'var(--ink-soft)' }}">
                                    pross. {{ $nextDue->due_date->format('d/m/Y') }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="table-muted">{{ $plan->frequencyLabel() }}</td>
                        <td>
                            @if($plan->status === 'active')
                                <span class="chip success">Attivo</span>
                            @elseif($plan->status === 'completed')
                                <span class="chip">Completato</span>
                            @else
                                <span class="chip pink">Annullato</span>
                            @endif
                        </td>
                        <td><a href="{{ route('portal.payment-plans.show', $plan) }}" class="cta secondary" style="font-size:12px;padding:6px 12px;min-height:30px;">Dettaglio</a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- Piani da ricevere --}}
<section class="card light-card" style="margin-bottom:0;">
    <div class="section-head">
        <div>
            <span class="eyebrow">Creditore</span>
            <h3 class="section-title">Piani da cui incassi</h3>
        </div>
        <span style="font-size:22px;">📥</span>
    </div>

    @if($asCreditor->isEmpty())
        <div style="text-align:center;padding:32px;color:var(--ink-muted);">
            <div style="font-size:28px;margin-bottom:8px;">📭</div>
            <div style="font-weight:600;">Nessun piano rateale ricevuto.</div>
                        <a href="{{ route('portal.payment-plans.create', ['role' => 'creditor']) }}" style="margin-top:8px;display:inline-block;font-size:13px;color:var(--primary);text-decoration:underline;">
                            Proponi rate a un cliente &rarr;
                        </a>
        </div>
    @else
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Pagante</th>
                        <th>Totale</th>
                        <th>Progresso</th>
                        <th>Rate</th>
                        <th>Frequenza</th>
                        <th>Stato</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($asCreditor as $plan)
                    @php
                        $paid    = $plan->installments->where('status','paid')->count();
                        $total   = $plan->installments_count;
                        $pct     = $total > 0 ? round($paid / $total * 100) : 0;
                        $nextDue = $plan->installments->where('status','pending')->sortBy('due_date')->first();
                    @endphp
                    <tr>
                        <td>
                            <div style="font-weight:600;">{{ $plan->fromAccount?->display_name ?? '—' }}</div>
                            <div class="table-muted" style="font-size:11px;">{{ $plan->description }}</div>
                        </td>
                        <td style="font-weight:700;color:#16a34a;">+{{ ky_format($plan->total_amount) }} KY</td>
                        <td style="min-width:130px;">
                            <div style="height:7px;background:var(--line);border-radius:4px;overflow:hidden;margin-bottom:4px;">
                                <div style="height:100%;width:{{ $pct }}%;background:linear-gradient(90deg,#0284c7,#38bdf8);border-radius:4px;transition:width .4s;"></div>
                            </div>
                            <div class="table-muted" style="font-size:11px;">{{ $paid }}/{{ $total }} rate &middot; {{ $pct }}%</div>
                        </td>
                        <td class="table-muted">
                            @if($nextDue)
                                <span style="color:{{ $nextDue->due_date->isPast() ? '#dc2626' : 'var(--ink-soft)' }};font-size:12px;">
                                    {{ $nextDue->due_date->format('d/m/Y') }}
                                </span>
                            @else
                                <span style="color:#16a34a;font-size:12px;">✓ Saldato</span>
                            @endif
                        </td>
                        <td class="table-muted">{{ $plan->frequencyLabel() }}</td>
                        <td>
                            @if($plan->status === 'active') <span class="chip success">Attivo</span>
                            @elseif($plan->status === 'completed') <span class="chip">Completato</span>
                            @else <span class="chip pink">Annullato</span>
                            @endif
                        </td>
                        <td><a href="{{ route('portal.payment-plans.show', $plan) }}" class="cta secondary" style="font-size:12px;padding:6px 12px;min-height:30px;">Dettaglio</a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

</div>{{-- fine grid 2 colonne --}}

@endsection
