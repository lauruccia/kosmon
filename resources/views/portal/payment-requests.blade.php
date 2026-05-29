@extends('layouts.portal')

@section('content')

{{-- Alert sessione --}}
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- KPI strip --}}
<section class="hero-strip" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:22px;">
    <article class="stat-card" style="border-left:4px solid #f59e0b;">
        <div class="eyebrow" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Da confermare</div>
        <div class="section-title" style="font-size:28px;color:#f59e0b;">{{ $pendingReceived->count() }}</div>
        <div class="table-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Incasso in attesa</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #e11d48;">
        <div class="eyebrow" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Da approvare</div>
        <div class="section-title" style="font-size:28px;color:#e11d48;">{{ $formalReceived->filter->isActionable()->count() }}</div>
        <div class="table-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Formali in attesa</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #0284c7;">
        <div class="eyebrow" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Inviate in attesa</div>
        <div class="section-title" style="font-size:28px;color:#0284c7;">{{ $pendingSent->count() + $formalSent->filter->isPending()->count() }}</div>
        <div class="table-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Attendono risposta</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #16a34a;">
        <div class="eyebrow" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Completate</div>
        <div class="section-title" style="font-size:28px;color:#16a34a;">{{ $confirmedReceived->count() + $confirmedSent->count() + $formalReceived->filter->isApproved()->count() }}</div>
        <div class="table-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Pagamenti eseguiti</div>
    </article>
    <article class="stat-card" style="display:flex;flex-direction:column;justify-content:center;gap:10px;border-left:4px solid var(--border);">
        <a class="cta" href="{{ route('portal.receive.form') }}" style="white-space:nowrap;text-align:center;">+ Richiesta incasso</a>
        <a class="cta secondary" href="{{ route('portal.text-requests.create') }}" style="white-space:nowrap;text-align:center;">+ Richiesta formale</a>
    </article>
</section>

{{-- Tab bar --}}
<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:24px;">
    <button onclick="switchTab('incasso')" id="tab-btn-incasso"
        style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;color:var(--ink-muted);">
        📥 Richieste incasso
        @if($pendingReceived->isNotEmpty())
            <span style="background:#f59e0b;color:#fff;border-radius:999px;padding:1px 7px;font-size:11px;margin-left:6px;">{{ $pendingReceived->count() }}</span>
        @endif
    </button>
    <button onclick="switchTab('formali')" id="tab-btn-formali"
        style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;color:var(--ink-muted);">
        📄 Richieste formali
        @php $formalPending = $formalReceived->filter->isActionable()->count(); @endphp
        @if($formalPending > 0)
            <span style="background:#e11d48;color:#fff;border-radius:999px;padding:1px 7px;font-size:11px;margin-left:6px;">{{ $formalPending }}</span>
        @endif
    </button>
</div>

{{-- ════════════════════════════════════════════════════════════
     TAB: INCASSO
     ════════════════════════════════════════════════════════════ --}}
<div id="tab-incasso">

    {{-- Pending ricevute: azione richiesta (a larghezza piena, priorità visiva) --}}
    @if($pendingReceived->isNotEmpty())
    <section class="card light-card" style="margin-bottom:20px;border-left:4px solid #f59e0b;">
        <div class="section-head">
            <div>
                <span class="eyebrow" style="color:#f59e0b;">Azione richiesta</span>
                <h3 class="section-title">Richieste ricevute — in attesa della tua risposta</h3>
            </div>
            <span style="font-size:22px;">📥</span>
        </div>
        <div style="display:grid;gap:14px;">
            @foreach($pendingReceived as $transfer)
            @php
                $requester = $transfer->toAccount;
                $requesterName = $requester?->display_name ?? '—';
                $ago = $transfer->created_at?->diffForHumans() ?? '';
            @endphp
            <div style="background:var(--bg);border:1.5px solid #fde68a;border-radius:12px;padding:16px 18px;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <div style="font-weight:700;font-size:15px;margin-bottom:3px;">{{ $requesterName }}</div>
                    <div class="table-muted" style="font-size:12px;margin-bottom:6px;">{{ $requester?->account_number }} · richiesta {{ $ago }}</div>
                    @if($transfer->description)
                        <div style="font-size:13px;color:var(--ink-soft);font-style:italic;">"{{ $transfer->description }}"</div>
                    @endif
                </div>
                <div style="text-align:right;min-width:120px;">
                    <div style="font-size:24px;font-weight:800;color:#0f52c4;">{{ number_format($transfer->amount, 2, ',', '.') }} KY</div>
                    <div class="table-muted" style="font-size:11px;">{{ $transfer->reference ?? substr($transfer->id, 0, 8) }}</div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <form method="POST" action="{{ route('portal.receive.requests.confirm', $transfer) }}">
                        @csrf
                        <button type="submit" class="cta" style="padding:10px 18px;font-size:14px;"
                            onclick="return confirm('Confermi il pagamento di {{ number_format($transfer->amount, 2, ',', '.') }} KY a {{ $requesterName }}?')">
                            ✓ Conferma
                        </button>
                    </form>
                    <form method="POST" action="{{ route('portal.receive.requests.reject', $transfer) }}">
                        @csrf
                        <button type="submit" class="cta secondary" style="padding:10px 18px;font-size:14px;">
                            ✕ Rifiuta
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- 2 colonne: storico ricevute | storico inviate --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        {{-- Colonna sinistra: ricevute chiuse / empty --}}
        <div>
            @if($confirmedReceived->isNotEmpty() || $rejectedReceived->isNotEmpty())
            <section class="card light-card">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Storico</span>
                        <h3 class="section-title" style="font-size:14px;">Ricevute — chiuse</h3>
                    </div>
                    <span style="font-size:18px;">📋</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Richiedente</th>
                                <th>Importo</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($confirmedReceived->merge($rejectedReceived)->sortByDesc('created_at') as $transfer)
                            <tr>
                                <td class="table-muted" style="white-space:nowrap;font-size:12px;">
                                    {{ ($transfer->booked_at ?? $transfer->updated_at)?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td>
                                    <div style="font-weight:600;font-size:13px;">{{ $transfer->toAccount?->display_name ?? '—' }}</div>
                                    <div class="table-muted" style="font-size:11px;">{{ $transfer->toAccount?->account_number }}</div>
                                </td>
                                <td style="font-weight:700;font-size:14px;color:#0f52c4;white-space:nowrap;">
                                    {{ number_format($transfer->amount, 2, ',', '.') }} KY
                                </td>
                                <td>
                                    @if($transfer->status === 'booked')
                                        <span class="chip success" style="font-size:11px;">Pagata</span>
                                    @elseif($transfer->status === 'rejected')
                                        <span class="chip pink" style="font-size:11px;">Rifiutata</span>
                                    @else
                                        <span class="chip" style="font-size:11px;">{{ ucfirst($transfer->status) }}</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
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

        {{-- Colonna destra: inviate pending + storico --}}
        <div>
            @if($pendingSent->isNotEmpty())
            <section class="card light-card" style="margin-bottom:16px;border-left:4px solid #0284c7;">
                <div class="section-head">
                    <div>
                        <span class="eyebrow" style="color:#0284c7;">In attesa</span>
                        <h3 class="section-title" style="font-size:14px;">Inviate — in attesa</h3>
                    </div>
                    <span style="font-size:18px;">📤</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Inviata</th>
                                <th>A chi</th>
                                <th>Importo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingSent as $transfer)
                            <tr>
                                <td class="table-muted" style="white-space:nowrap;font-size:12px;">
                                    {{ $transfer->created_at?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td>
                                    <div style="font-weight:600;font-size:13px;">{{ $transfer->fromAccount?->display_name ?? '—' }}</div>
                                    <div class="table-muted" style="font-size:11px;">{{ $transfer->fromAccount?->account_number }}</div>
                                </td>
                                <td style="font-weight:700;font-size:14px;color:#0284c7;white-space:nowrap;">
                                    +{{ number_format($transfer->amount, 2, ',', '.') }} KY
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @endif

            @if($confirmedSent->isNotEmpty() || $rejectedSent->isNotEmpty())
            <section class="card light-card">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Storico</span>
                        <h3 class="section-title" style="font-size:14px;">Inviate — chiuse</h3>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Pagante</th>
                                <th>Importo</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($confirmedSent->merge($rejectedSent)->sortByDesc('created_at') as $transfer)
                            <tr>
                                <td class="table-muted" style="white-space:nowrap;font-size:12px;">
                                    {{ ($transfer->booked_at ?? $transfer->updated_at)?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td>
                                    <div style="font-weight:600;font-size:13px;">{{ $transfer->fromAccount?->display_name ?? '—' }}</div>
                                    <div class="table-muted" style="font-size:11px;">{{ $transfer->fromAccount?->account_number }}</div>
                                </td>
                                <td style="font-weight:700;font-size:14px;color:#16a34a;white-space:nowrap;">
                                    +{{ number_format($transfer->amount, 2, ',', '.') }} KY
                                </td>
                                <td>
                                    @if($transfer->status === 'booked')
                                        <span class="chip success" style="font-size:11px;">Incassata</span>
                                    @elseif($transfer->status === 'rejected')
                                        <span class="chip pink" style="font-size:11px;">Rifiutata</span>
                                    @else
                                        <span class="chip" style="font-size:11px;">{{ ucfirst($transfer->status) }}</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @endif

            @if($pendingSent->isEmpty() && $confirmedSent->isEmpty() && $rejectedSent->isEmpty())
            <section class="card light-card" style="text-align:center;padding:36px;color:var(--ink-muted);">
                <div style="font-size:32px;margin-bottom:10px;">📨</div>
                <div style="font-weight:600;margin-bottom:4px;">Nessuna richiesta inviata</div>
                <div style="font-size:13px;">
                    Usa <a href="{{ route('portal.receive.form') }}" style="color:var(--accent);">Nuova richiesta</a>
                    per chiedere un pagamento a un'altra azienda del circuito.
                </div>
            </section>
            @endif
        </div>

    </div>{{-- /grid 2 colonne incasso --}}
</div>{{-- /tab-incasso --}}


{{-- ════════════════════════════════════════════════════════════
     TAB: FORMALI
     ════════════════════════════════════════════════════════════ --}}
<div id="tab-formali" style="display:none;">

    {{-- Pending ricevute formali: azione richiesta (larghezza piena) --}}
    @php $formalPendingList = $formalReceived->filter->isActionable(); @endphp
    @if($formalPendingList->isNotEmpty())
    <section class="card light-card" style="margin-bottom:20px;border-left:4px solid #e11d48;">
        <div class="section-head">
            <div>
                <span class="eyebrow" style="color:#e11d48;">Azione richiesta</span>
                <h3 class="section-title">Richieste formali ricevute — da approvare</h3>
            </div>
            <span style="font-size:22px;">📄</span>
        </div>
        <div style="display:grid;gap:14px;">
            @foreach($formalPendingList as $req)
            @php $senderName = $req->fromAccount?->company?->name ?? $req->fromAccount?->display_name ?? '—'; @endphp
            <div style="background:var(--bg);border:1.5px solid #fecdd3;border-radius:12px;padding:16px 18px;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <div style="font-weight:700;font-size:15px;margin-bottom:3px;">{{ $senderName }}</div>
                    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:4px;">{{ $req->causale }}</div>
                    @if($req->due_date)
                        <div style="font-size:12px;color:{{ $req->due_date->isPast() ? '#dc2626' : 'var(--ink-muted)' }};font-weight:{{ $req->due_date->isPast() ? '700' : '400' }};">
                            Scadenza: {{ $req->due_date->format('d/m/Y') }}
                        </div>
                    @endif
                </div>
                <div style="text-align:right;min-width:120px;">
                    <div style="font-size:24px;font-weight:800;color:#0f52c4;">{{ $req->formattedAmount() }}</div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <a href="{{ route('portal.text-requests.show', $req) }}" class="cta" style="padding:10px 18px;font-size:14px;">
                        Approva / Rifiuta
                    </a>
                </div>
            </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- 2 colonne: storico ricevute formali | storico inviate formali --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        {{-- Colonna sinistra: ricevute formali (non pending) --}}
        <div>
            @php $formalReceivedHistory = $formalReceived->reject->isActionable(); @endphp
            @if($formalReceivedHistory->isNotEmpty())
            <section class="card light-card">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Storico</span>
                        <h3 class="section-title" style="font-size:14px;">Ricevute — chiuse</h3>
                    </div>
                    <span style="font-size:18px;">📋</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Da</th>
                                <th>Importo</th>
                                <th>Stato</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($formalReceivedHistory->sortByDesc('created_at') as $req)
                            <tr>
                                <td class="table-muted" style="white-space:nowrap;font-size:12px;">{{ $req->created_at?->format('d/m/Y') ?? '—' }}</td>
                                <td>
                                    <div style="font-weight:600;font-size:13px;">{{ $req->fromAccount?->company?->name ?? $req->fromAccount?->display_name ?? '—' }}</div>
                                    <div class="table-muted" style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $req->causale }}</div>
                                </td>
                                <td style="font-weight:700;font-size:13px;white-space:nowrap;">{{ $req->formattedAmount() }}</td>
                                <td>
                                    <span class="chip {{ $req->statusChipClass() }}" style="font-size:11px;">{{ $req->statusLabel() }}</span>
                                </td>
                                <td>
                                    <a href="{{ route('portal.text-requests.show', $req) }}" style="font-size:12px;color:var(--primary);font-weight:600;text-decoration:none;">Vedi</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @elseif($formalReceived->isEmpty())
            <section class="card light-card" style="text-align:center;padding:36px;color:var(--ink-muted);">
                <div style="font-size:32px;margin-bottom:10px;">📭</div>
                <div style="font-weight:600;margin-bottom:4px;">Nessuna richiesta formale ricevuta</div>
                <div style="font-size:13px;">Le richieste formali ricevute da altri conti appariranno qui.</div>
            </section>
            @endif
        </div>

        {{-- Colonna destra: inviate formali --}}
        <div>
            @if($formalSent->isNotEmpty())
            <section class="card light-card">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Inviate</span>
                        <h3 class="section-title" style="font-size:14px;">Inviate</h3>
                    </div>
                    <span style="font-size:18px;">📤</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>A</th>
                                <th>Importo</th>
                                <th>Stato</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($formalSent->sortByDesc('created_at') as $req)
                            <tr>
                                <td class="table-muted" style="white-space:nowrap;font-size:12px;">{{ $req->created_at?->format('d/m/Y') ?? '—' }}</td>
                                <td>
                                    <div style="font-weight:600;font-size:13px;">{{ $req->toAccount?->company?->name ?? $req->toAccount?->display_name ?? '—' }}</div>
                                    <div class="table-muted" style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $req->causale }}</div>
                                </td>
                                <td style="font-weight:700;font-size:13px;white-space:nowrap;">{{ $req->formattedAmount() }}</td>
                                <td>
                                    <span class="chip {{ $req->statusChipClass() }}" style="font-size:11px;">{{ $req->statusLabel() }}</span>
                                </td>
                                <td>
                                    <a href="{{ route('portal.text-requests.show', $req) }}" style="font-size:12px;color:var(--primary);font-weight:600;text-decoration:none;">Vedi</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @else
            <section class="card light-card" style="text-align:center;padding:36px;color:var(--ink-muted);">
                <div style="font-size:32px;margin-bottom:10px;">📨</div>
                <div style="font-weight:600;margin-bottom:4px;">Nessuna richiesta formale inviata</div>
                <div style="font-size:13px;">
                    Usa <a href="{{ route('portal.text-requests.create') }}" style="color:var(--accent);">+ Nuova richiesta formale</a>
                    per inviare una richiesta documentata.
                </div>
            </section>
            @endif
        </div>

    </div>{{-- /grid 2 colonne formali --}}
</div>{{-- /tab-formali --}}


<script>
const ACTIVE_STYLE = { borderColor: 'var(--primary)', color: 'var(--primary)' };
const INACTIVE_STYLE = { borderColor: 'transparent', color: 'var(--ink-muted)' };

function switchTab(tab) {
    ['incasso', 'formali'].forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        const pane = document.getElementById('tab-' + t);
        if (t === tab) {
            pane.style.display = '';
            Object.assign(btn.style, ACTIVE_STYLE);
        } else {
            pane.style.display = 'none';
            Object.assign(btn.style, INACTIVE_STYLE);
        }
    });
    // aggiorna URL senza reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.replaceState({}, '', url);
}

// Inizializza con il tab corretto
switchTab('{{ $activeTab }}');
</script>

@endsection
