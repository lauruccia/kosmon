@extends('layouts.portal')

@section('content')

<style>
.sched-summary { display:flex; flex-wrap:wrap; gap:0; align-items:stretch; }
.sched-summary-col { flex:1; min-width:120px; padding:4px 20px 4px 0; margin-right:20px; border-right:1px solid var(--border); }
.sched-summary-col:last-child { border-right:none; margin-right:0; padding-right:0; }
.sched-body { display:grid; grid-template-columns:1fr 200px 220px; gap:16px; align-items:start; margin-top:16px; }
.sched-body.no-siblings { grid-template-columns:1fr 220px; }
@media(max-width:900px) {
    .sched-body, .sched-body.no-siblings { grid-template-columns:1fr; }
    .sched-summary-col { border-right:none; border-bottom:1px solid var(--border); padding:8px 0; margin-right:0; }
    .sched-summary-col:last-child { border-bottom:none; }
}
</style>

{{-- Breadcrumb --}}
<div style="font-size:12px;color:var(--ink-muted);margin-bottom:14px;">
    <a href="{{ route('portal.scheduled-payments.index') }}" style="color:var(--primary);text-decoration:none;font-weight:600;">Pagamenti programmati</a>
    <span style="margin:0 6px;">&rsaquo;</span>
    <span>{{ $pageTitle }}</span>
</div>

{{-- Banner stato --}}
@if($payment->isExecuted())
    <div class="alert alert-success" style="margin-bottom:14px;">
        Eseguito il {{ $payment->executed_at?->format('d/m/Y \a\l\l\e H:i') }}.
        @if($payment->transfer)
            <a href="{{ route('portal.movements') }}" style="font-weight:700;color:inherit;margin-left:6px;">Vedi movimento &rarr;</a>
        @endif
    </div>
@elseif($payment->isFailed())
    <div class="alert alert-danger" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <span>Esecuzione fallita: {{ $payment->failure_reason ?? 'errore sconosciuto' }}.</span>
        @if($isSender)
        <form method="POST" action="{{ route('portal.scheduled-payments.retry', $payment) }}"
              onsubmit="return confirm('Ritentare il pagamento ora?')">
            @csrf
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">↺ Riprova ora</button>
        </form>
        @endif
    </div>
@elseif($payment->isCancelled())
    <div class="alert alert-warning" style="margin-bottom:14px;">Pagamento annullato.</div>
@elseif($payment->isDue())
    <div class="alert alert-warning" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <span>Scaduto — in attesa di elaborazione automatica.</span>
        @if($isSender)
        <form method="POST" action="{{ route('portal.scheduled-payments.retry', $payment) }}"
              onsubmit="return confirm('Eseguire il pagamento ora?')">
            @csrf
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">▶ Esegui ora</button>
        </form>
        @endif
    </div>
@endif

{{-- Riga sommario (full-width) --}}
<div class="card card-pad">
    <div class="sched-summary">

        <div class="sched-summary-col">
            <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">Da</div>
            <div style="font-weight:700;font-size:14px;">{{ $payment->fromAccount?->company?->name ?? $payment->fromAccount?->display_name ?? '—' }}</div>
        </div>

        <div class="sched-summary-col">
            <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">A</div>
            <div style="font-weight:700;font-size:14px;">{{ $payment->toAccount?->company?->name ?? $payment->toAccount?->display_name ?? '—' }}</div>
        </div>

        <div class="sched-summary-col" style="flex:0 0 auto;">
            <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">Importo</div>
            <div style="font-size:20px;font-weight:800;color:var(--primary);">{{ $payment->formattedAmount() }}</div>
        </div>

        <div class="sched-summary-col" style="flex:0 0 auto;">
            <div style="font-size:11px;color:var(--ink-muted);margin-bottom:4px;">Stato</div>
            <span class="chip {{ $payment->statusChipClass() }}">{{ $payment->statusLabel() }}</span>
        </div>

        <div class="sched-summary-col" style="flex:0 0 auto;">
            <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">Data programmata</div>
            <div style="font-weight:700;font-size:14px;">{{ $payment->scheduled_at->format('d/m/Y') }}</div>
            <div style="font-size:11px;color:var(--ink-muted);">{{ $payment->scheduled_at->format('H:i') }}</div>
        </div>

        @if($payment->isRecurring())
        <div class="sched-summary-col" style="flex:0 0 auto;">
            <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">Ricorrenza</div>
            <div style="font-weight:700;font-size:14px;">Rata {{ $payment->recurrence_index }}/{{ $payment->recurrence_total }}</div>
            <div style="font-size:11px;color:var(--ink-muted);">{{ $payment->recurrenceTypeLabel() }}</div>
        </div>
        @endif

    </div>
</div>

{{-- Corpo: dettagli | rate | azioni --}}
<div class="sched-body {{ $payment->isRecurring() ? '' : 'no-siblings' }}">

    {{-- Dettagli --}}
    <div class="card card-pad">
        <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">Dettagli</div>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Descrizione / Causale</div>
                <div style="font-weight:600;margin-top:2px;">{{ $payment->description }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Creato il</div>
                <div style="font-size:13px;margin-top:2px;">{{ $payment->created_at->format('d/m/Y \a\l\l\e H:i') }}</div>
            </div>
            @if($payment->isExecuted() && $payment->executed_at)
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Eseguito il</div>
                <div style="font-size:13px;margin-top:2px;">{{ $payment->executed_at->format('d/m/Y \a\l\l\e H:i') }}</div>
            </div>
            @endif
        </div>
    </div>

    {{-- Tutte le rate (solo se ricorrente) --}}
    @if($payment->isRecurring())
    <div class="card card-pad" style="background:var(--surface-soft);padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
            Rate ({{ $payment->recurrence_total }})
        </div>
        @foreach($payment->siblings() as $sibling)
            <a href="{{ route('portal.scheduled-payments.show', $sibling) }}"
               style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;gap:8px;">
                <span style="font-size:13px;font-weight:{{ $sibling->id === $payment->id ? '700' : '400' }};color:{{ $sibling->id === $payment->id ? 'var(--primary)' : 'inherit' }};">
                    {{ $sibling->recurrence_index }}. {{ $sibling->scheduled_at->format('d/m/Y') }}
                </span>
                <span class="chip {{ $sibling->statusChipClass() }}" style="font-size:10px;white-space:nowrap;">{{ $sibling->statusLabel() }}</span>
            </a>
        @endforeach
    </div>
    @endif

    {{-- Azioni --}}
    <div style="display:flex;flex-direction:column;gap:12px;">
        @if($canCancel)
            <div class="card card-pad">
                <div style="font-weight:700;margin-bottom:6px;font-size:14px;">Annulla questa rata</div>
                <p style="font-size:12px;color:var(--ink-muted);margin-bottom:12px;line-height:1.4;">
                    Le altre rate del gruppo rimangono attive.
                </p>
                <form method="POST" action="{{ route('portal.scheduled-payments.cancel', $payment) }}"
                      onsubmit="return confirm('Annullare questa rata?')">
                    @csrf
                    <button type="submit" class="btn btn-danger" style="width:100%;">Annulla questa rata</button>
                </form>
            </div>

            @if($payment->isRecurring())
                @php
                    $pendingSiblings = $payment->siblings()->where('status', 'pending')->count();
                @endphp
                @if($pendingSiblings > 1)
                <div class="card card-pad" style="border:1.5px solid var(--danger);">
                    <div style="font-weight:700;margin-bottom:6px;font-size:14px;color:var(--danger);">Annulla tutte le rimanenti</div>
                    <p style="font-size:12px;color:var(--ink-muted);margin-bottom:12px;line-height:1.4;">
                        Annulla le <strong>{{ $pendingSiblings }}</strong> rate ancora in attesa.
                    </p>
                    <form method="POST" action="{{ route('portal.scheduled-payments.cancel-group', $payment->recurrence_group) }}"
                          onsubmit="return confirm('Annullare tutte le {{ $pendingSiblings }} rate rimanenti?')">
                        @csrf
                        <button type="submit" class="btn btn-danger" style="width:100%;">
                            Annulla tutte ({{ $pendingSiblings }})
                        </button>
                    </form>
                </div>
                @endif
            @endif
        @else
            <div class="card card-pad" style="background:var(--surface-soft);">
                <div style="font-size:13px;color:var(--ink-muted);">
                    @if($payment->isPending())
                        Solo il mittente può annullare.
                    @else
                        Nessuna azione disponibile.
                    @endif
                </div>
            </div>
        @endif
    </div>

</div>
@endsection
