@extends('layouts.portal')

@section('content')
<style>
/* ── KPI grid ── */
.req-kpi-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 18px;
}
.req-kpi-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}
.req-kpi-actions .cta { flex: 1; text-align: center; white-space: nowrap; }

/* ── Tab bar ── */
.req-tabs {
    display: flex;
    border-bottom: 2px solid var(--line);
    margin-bottom: 20px;
    gap: 0;
}
.req-tab-btn {
    flex: 1;
    padding: 11px 8px;
    font-size: 13.5px;
    font-weight: 600;
    border: none;
    background: none;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all .15s;
    color: var(--ink-muted);
    text-align: center;
}
.req-tab-btn.active { border-color: var(--primary); color: var(--primary); }

/* ── Pending card ── */
.req-pending-item {
    background: var(--bg);
    border-radius: 14px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.req-pending-item--incasso { border: 1.5px solid #fde68a; }
.req-pending-item--formale { border: 1.5px solid #fecdd3; }

.req-pending-meta { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.req-pending-amount { font-size: 26px; font-weight: 800; color: #0f52c4; }
.req-pending-actions { display: flex; gap: 10px; }
.req-pending-actions .cta { flex: 1; text-align: center; padding: 13px 10px; font-size: 15px; }

/* ── History grid ── */
.req-history-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: start;
}

/* ── History as cards on mobile ── */
.req-history-list { display: grid; gap: 8px; }
.req-history-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 10px;
}
.req-history-item-left { flex: 1; min-width: 0; }
.req-history-item-name { font-weight: 600; font-size: 13.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.req-history-item-sub  { font-size: 11.5px; color: var(--ink-muted); margin-top: 2px; }
.req-history-item-right { text-align: right; flex-shrink: 0; }
.req-history-item-amount { font-weight: 700; font-size: 14px; white-space: nowrap; }
.req-history-item-date { font-size: 11px; color: var(--ink-muted); margin-top: 2px; }

@media (max-width: 640px) {
    .req-history-grid { grid-template-columns: 1fr; }
    .req-kpi-actions { flex-direction: column; }
}
</style>

{{-- Alert --}}
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- ══ AZIONI RICHIESTE (sopra i KPI, solo se presenti) ══ --}}
@if($pendingReceived->isNotEmpty())
<section class="card light-card" style="margin-bottom:18px;border-left:4px solid #f59e0b;">
    <div class="section-head" style="margin-bottom:14px;">
        <div>
            <span class="eyebrow" style="color:#f59e0b;">Azione richiesta</span>
            <h3 class="section-title">In attesa della tua risposta</h3>
        </div>
        <span style="font-size:22px;">📥</span>
    </div>
    <div style="display:grid;gap:12px;">
        @foreach($pendingReceived as $transfer)
        @php
            $requester     = $transfer->toAccount;
            $requesterName = $requester?->display_name ?? '—';
            $ago           = $transfer->created_at?->diffForHumans() ?? '';
        @endphp
        <div class="req-pending-item req-pending-item--incasso">
            <div class="req-pending-meta">
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:15px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $requesterName }}</div>
                    <div class="table-muted" style="font-size:12px;">{{ $requester?->account_number }}</div>
                    <div class="table-muted" style="font-size:11.5px;">{{ $ago }}</div>
                    @if($transfer->description)
                        <div style="font-size:12.5px;color:var(--ink-soft);font-style:italic;margin-top:4px;">"{{ $transfer->description }}"</div>
                    @endif
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div class="req-pending-amount">{{ ky_format($transfer->amount) }} KY</div>
                    <div class="table-muted" style="font-size:11px;margin-top:2px;">{{ $transfer->reference ?? substr($transfer->id, 0, 8) }}</div>
                </div>
            </div>
            <div class="req-pending-actions">
                <form method="POST" action="{{ route('portal.receive.requests.confirm', $transfer) }}" style="flex:1;display:contents;">
                    @csrf
                    <button type="submit" class="cta"
                        onclick="return confirm('Confermi il pagamento di {{ ky_format($transfer->amount) }} KY a {{ $requesterName }}?')">
                        ✓ Conferma
                    </button>
                </form>
                <form method="POST" action="{{ route('portal.receive.requests.reject', $transfer) }}" style="flex:1;display:contents;">
                    @csrf
                    <button type="submit" class="cta secondary">✕ Rifiuta</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
</section>
@endif

@php $formalPendingListTop = $formalReceived->filter->isActionable(); @endphp
@if($formalPendingListTop->isNotEmpty())
<section class="card light-card" style="margin-bottom:18px;border-left:4px solid #e11d48;">
    <div class="section-head" style="margin-bottom:14px;">
        <div>
            <span class="eyebrow" style="color:#e11d48;">Azione richiesta</span>
            <h3 class="section-title">Richieste formali da approvare</h3>
        </div>
        <span style="font-size:22px;">📄</span>
    </div>
    <div style="display:grid;gap:12px;">
        @foreach($formalPendingListTop as $req)
        @php $senderName = $req->fromAccount?->company?->name ?? $req->fromAccount?->display_name ?? '—'; @endphp
        <div class="req-pending-item req-pending-item--formale">
            <div class="req-pending-meta">
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:15px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $senderName }}</div>
                    <div style="font-size:13px;color:var(--ink-soft);margin-top:2px;">{{ $req->causale }}</div>
                    @if($req->due_date)
                        <div style="font-size:12px;margin-top:3px;color:{{ $req->due_date->isPast() ? '#dc2626' : 'var(--ink-muted)' }};font-weight:{{ $req->due_date->isPast() ? '700' : '400' }};">
                            Scadenza: {{ $req->due_date->format('d/m/Y') }}
                        </div>
                    @endif
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div class="req-pending-amount">{{ $req->formattedAmount() }}</div>
                </div>
            </div>
            <div class="req-pending-actions">
                <a href="{{ route('portal.text-requests.show', $req) }}" class="cta" style="flex:1;text-align:center;padding:13px 10px;font-size:15px;">
                    Approva / Rifiuta
                </a>
            </div>
        </div>
        @endforeach
    </div>
</section>
@endif

{{-- KPI 2×2 --}}
<div class="req-kpi-grid">
    <article class="stat-card" style="border-left:4px solid #f59e0b;">
        <div class="eyebrow">Da confermare</div>
        <div class="section-title" style="font-size:28px;color:#f59e0b;">{{ $pendingReceived->count() }}</div>
        <div class="table-muted" style="font-size:12px;">Incasso in attesa</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #e11d48;">
        <div class="eyebrow">Da approvare</div>
        <div class="section-title" style="font-size:28px;color:#e11d48;">{{ $formalReceived->filter->isActionable()->count() }}</div>
        <div class="table-muted" style="font-size:12px;">Formali in attesa</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #0284c7;">
        <div class="eyebrow">Inviate in attesa</div>
        <div class="section-title" style="font-size:28px;color:#0284c7;">{{ $pendingSent->count() + $formalSent->filter->isPending()->count() }}</div>
        <div class="table-muted" style="font-size:12px;">Attendono risposta</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #16a34a;">
        <div class="eyebrow">Completate</div>
        <div class="section-title" style="font-size:28px;color:#16a34a;">{{ $confirmedReceived->count() + $confirmedSent->count() + $formalReceived->filter->isApproved()->count() }}</div>
        <div class="table-muted" style="font-size:12px;">Pagamenti eseguiti</div>
    </article>
</div>

{{-- CTA buttons --}}
<div class="req-kpi-actions">
    <a class="cta" href="{{ route('portal.receive.form') }}">+ Richiesta incasso</a>
    <a class="cta secondary" href="{{ route('portal.text-requests.create') }}">+ Richiesta formale</a>
</div>

{{-- Tab bar --}}
<div class="req-tabs">
    <button class="req-tab-btn" onclick="switchTab('incasso')" id="tab-btn-incasso">
        📥 Richieste incasso
        @if($pendingReceived->isNotEmpty())
            <span style="background:#f59e0b;color:#fff;border-radius:999px;padding:1px 7px;font-size:11px;margin-left:5px;">{{ $pendingReceived->count() }}</span>
        @endif
    </button>
    <button class="req-tab-btn" onclick="switchTab('formali')" id="tab-btn-formali">
        📄 Richieste formali
        @php $formalPending = $formalReceived->filter->isActionable()->count(); @endphp
        @if($formalPending > 0)
            <span style="background:#e11d48;color:#fff;border-radius:999px;padding:1px 7px;font-size:11px;margin-left:5px;">{{ $formalPending }}</span>
        @endif
    </button>
</div>

{{-- ══ TAB INCASSO ══ --}}
<div id="tab-incasso">

    {{-- Storico: 2 col desktop, 1 col mobile --}}
    <div class="req-history-grid">

        {{-- Ricevute chiuse --}}
        <div>
            @if($confirmedReceived->isNotEmpty() || $rejectedReceived->isNotEmpty())
            <section class="card light-card">
                <div class="section-head" style="margin-bottom:12px;">
                    <div>
                        <span class="eyebrow">Storico ricevute</span>
                        <h3 class="section-title" style="font-size:14px;">Chiuse</h3>
                    </div>
                    <span style="font-size:18px;">📋</span>
                </div>
                <div class="req-history-list">
                    @foreach($confirmedReceived->merge($rejectedReceived)->sortByDesc('created_at') as $transfer)
                    <div class="req-history-item">
                        <div class="req-history-item-left">
                            <div class="req-history-item-name">{{ $transfer->toAccount?->display_name ?? '—' }}</div>
                            <div class="req-history-item-sub">{{ $transfer->toAccount?->account_number }}</div>
                        </div>
                        <div class="req-history-item-right">
                            <div class="req-history-item-amount" style="color:#0f52c4;">{{ ky_format($transfer->amount) }} KY</div>
                            <div style="margin-top:4px;">
                                @if($transfer->status === 'booked')
                                    <span class="chip success" style="font-size:11px;">Pagata</span>
                                @elseif($transfer->status === 'rejected')
                                    <span class="chip pink" style="font-size:11px;">Rifiutata</span>
                                @endif
                            </div>
                            <div class="req-history-item-date">{{ ($transfer->booked_at ?? $transfer->updated_at)?->format('d/m/Y') ?? '—' }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </section>
            @elseif($pendingReceived->isEmpty())
            <section class="card light-card" style="text-align:center;padding:36px;color:var(--ink-muted);">
                <div style="font-size:32px;margin-bottom:10px;">📭</div>
                <div style="font-weight:600;margin-bottom:4px;">Nessuna richiesta ricevuta</div>
                <div style="font-size:13px;">Quando un'azienda del circuito ti invierà una richiesta, apparirà qui.</div>
            </section>
            @endif
        </div>

        {{-- Inviate pending + chiuse --}}
        <div>
            @if($pendingSent->isNotEmpty())
            <section class="card light-card" style="margin-bottom:14px;border-left:4px solid #0284c7;">
                <div class="section-head" style="margin-bottom:12px;">
                    <div>
                        <span class="eyebrow" style="color:#0284c7;">In attesa</span>
                        <h3 class="section-title" style="font-size:14px;">Inviate — attesa risposta</h3>
                    </div>
                    <span style="font-size:18px;">📤</span>
                </div>
                <div class="req-history-list">
                    @foreach($pendingSent as $transfer)
                    <div class="req-history-item">
                        <div class="req-history-item-left">
                            <div class="req-history-item-name">{{ $transfer->fromAccount?->display_name ?? '—' }}</div>
                            <div class="req-history-item-sub">{{ $transfer->fromAccount?->account_number }}</div>
                        </div>
                        <div class="req-history-item-right">
                            <div class="req-history-item-amount" style="color:#0284c7;">+{{ ky_format($transfer->amount) }} KY</div>
                            <div class="req-history-item-date">{{ $transfer->created_at?->format('d/m/Y') ?? '—' }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </section>
            @endif

            @if($confirmedSent->isNotEmpty() || $rejectedSent->isNotEmpty())
            <section class="card light-card">
                <div class="section-head" style="margin-bottom:12px;">
                    <div>
                        <span class="eyebrow">Storico inviate</span>
                        <h3 class="section-title" style="font-size:14px;">Chiuse</h3>
                    </div>
                </div>
                <div class="req-history-list">
                    @foreach($confirmedSent->merge($rejectedSent)->sortByDesc('created_at') as $transfer)
                    <div class="req-history-item">
                        <div class="req-history-item-left">
                            <div class="req-history-item-name">{{ $transfer->fromAccount?->display_name ?? '—' }}</div>
                            <div class="req-history-item-sub">{{ $transfer->fromAccount?->account_number }}</div>
                        </div>
                        <div class="req-history-item-right">
                            <div class="req-history-item-amount" style="color:#16a34a;">+{{ ky_format($transfer->amount) }} KY</div>
                            <div style="margin-top:4px;">
                                @if($transfer->status === 'booked')
                                    <span class="chip success" style="font-size:11px;">Incassata</span>
                                @elseif($transfer->status === 'rejected')
                                    <span class="chip pink" style="font-size:11px;">Rifiutata</span>
                                @endif
                            </div>
                            <div class="req-history-item-date">{{ ($transfer->booked_at ?? $transfer->updated_at)?->format('d/m/Y') ?? '—' }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </section>
            @endif

            @if($pendingSent->isEmpty() && $confirmedSent->isEmpty() && $rejectedSent->isEmpty())
            <section class="card light-card" style="text-align:center;padding:36px;color:var(--ink-muted);">
                <div style="font-size:32px;margin-bottom:10px;">📨</div>
                <div style="font-weight:600;margin-bottom:4px;">Nessuna richiesta inviata</div>
                <div style="font-size:13px;">Usa <a href="{{ route('portal.receive.form') }}" style="color:var(--accent);">Nuova richiesta</a> per chiedere un pagamento.</div>
            </section>
            @endif
        </div>

    </div>{{-- /history-grid incasso --}}
</div>{{-- /tab-incasso --}}


{{-- ══ TAB FORMALI ══ --}}
<div id="tab-formali" style="display:none;">

    {{-- Storico formali --}}
    <div class="req-history-grid">

        {{-- Ricevute formali chiuse --}}
        <div>
            @php $formalReceivedHistory = $formalReceived->reject->isActionable(); @endphp
            @if($formalReceivedHistory->isNotEmpty())
            <section class="card light-card">
                <div class="section-head" style="margin-bottom:12px;">
                    <div>
                        <span class="eyebrow">Storico ricevute</span>
                        <h3 class="section-title" style="font-size:14px;">Chiuse</h3>
                    </div>
                    <span style="font-size:18px;">📋</span>
                </div>
                <div class="req-history-list">
                    @foreach($formalReceivedHistory->sortByDesc('created_at') as $req)
                    <div class="req-history-item">
                        <div class="req-history-item-left">
                            <div class="req-history-item-name">{{ $req->fromAccount?->company?->name ?? $req->fromAccount?->display_name ?? '—' }}</div>
                            <div class="req-history-item-sub" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;">{{ $req->causale }}</div>
                        </div>
                        <div class="req-history-item-right">
                            <div class="req-history-item-amount">{{ $req->formattedAmount() }}</div>
                            <div style="margin-top:4px;"><span class="chip {{ $req->statusChipClass() }}" style="font-size:11px;">{{ $req->statusLabel() }}</span></div>
                            <a href="{{ route('portal.text-requests.show', $req) }}" style="font-size:12px;color:var(--primary);font-weight:600;text-decoration:none;display:block;margin-top:4px;">Vedi</a>
                        </div>
                    </div>
                    @endforeach
                </div>
            </section>
            @elseif($formalReceived->isEmpty())
            <section class="card light-card" style="text-align:center;padding:36px;color:var(--ink-muted);">
                <div style="font-size:32px;margin-bottom:10px;">📭</div>
                <div style="font-weight:600;margin-bottom:4px;">Nessuna ricevuta</div>
                <div style="font-size:13px;">Le richieste formali ricevute appariranno qui.</div>
            </section>
            @endif
        </div>

        {{-- Inviate formali --}}
        <div>
            @if($formalSent->isNotEmpty())
            <section class="card light-card">
                <div class="section-head" style="margin-bottom:12px;">
                    <div>
                        <span class="eyebrow">Inviate</span>
                        <h3 class="section-title" style="font-size:14px;">Inviate</h3>
                    </div>
                    <span style="font-size:18px;">📤</span>
                </div>
                <div class="req-history-list">
                    @foreach($formalSent->sortByDesc('created_at') as $req)
                    <div class="req-history-item">
                        <div class="req-history-item-left">
                            <div class="req-history-item-name">{{ $req->toAccount?->company?->name ?? $req->toAccount?->display_name ?? '—' }}</div>
                            <div class="req-history-item-sub" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;">{{ $req->causale }}</div>
                        </div>
                        <div class="req-history-item-right">
                            <div class="req-history-item-amount">{{ $req->formattedAmount() }}</div>
                            <div style="margin-top:4px;"><span class="chip {{ $req->statusChipClass() }}" style="font-size:11px;">{{ $req->statusLabel() }}</span></div>
                            <a href="{{ route('portal.text-requests.show', $req) }}" style="font-size:12px;color:var(--primary);font-weight:600;text-decoration:none;display:block;margin-top:4px;">Vedi</a>
                        </div>
                    </div>
                    @endforeach
                </div>
            </section>
            @else
            <section class="card light-card" style="text-align:center;padding:36px;color:var(--ink-muted);">
                <div style="font-size:32px;margin-bottom:10px;">📨</div>
                <div style="font-weight:600;margin-bottom:4px;">Nessuna inviata</div>
                <div style="font-size:13px;">
                    <a href="{{ route('portal.text-requests.create') }}" style="color:var(--accent);">+ Nuova richiesta formale</a>
                </div>
            </section>
            @endif
        </div>

    </div>{{-- /history-grid formali --}}
</div>{{-- /tab-formali --}}


<script>
function switchTab(tab) {
    ['incasso', 'formali'].forEach(function(t) {
        var btn  = document.getElementById('tab-btn-' + t);
        var pane = document.getElementById('tab-' + t);
        if (t === tab) {
            pane.style.display = '';
            btn.classList.add('active');
        } else {
            pane.style.display = 'none';
            btn.classList.remove('active');
        }
    });
    var url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.replaceState({}, '', url);
}
switchTab('{{ $activeTab }}');
</script>

@endsection
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   