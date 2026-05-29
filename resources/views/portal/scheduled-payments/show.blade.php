@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <span class="eyebrow">
        <a href="{{ route('portal.scheduled-payments.index') }}" style="color:var(--primary);text-decoration:none;">Pagamenti programmati</a>
        &rsaquo; Dettaglio
    </span>
    <h2>{{ $pageTitle }}</h2>
</section>

@if($payment->isExecuted())
    <div class="alert alert-success" style="margin-bottom:20px;">
        Eseguito il {{ $payment->executed_at?->format('d/m/Y \a\l\l\e H:i') }}.
        @if($payment->transfer)
            <a href="{{ route('portal.movements') }}" style="font-weight:700;color:inherit;margin-left:6px;">Vedi movimento &rarr;</a>
        @endif
    </div>
@elseif($payment->isFailed())
    <div class="alert alert-danger" style="margin-bottom:20px;">
        Esecuzione fallita: {{ $payment->failure_reason ?? 'errore sconosciuto' }}.
    </div>
@elseif($payment->isCancelled())
    <div class="alert alert-warning" style="margin-bottom:20px;">Pagamento annullato.</div>
@elseif($payment->isDue())
    <div class="alert alert-warning" style="margin-bottom:20px;">
        In elaborazione — il pagamento verrà eseguito a breve.
    </div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:700px;">

    <div class="card card-pad">
        <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;">Dettagli</div>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Da</div>
                <div style="font-weight:700;">{{ $payment->fromAccount?->company?->name ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">A</div>
                <div style="font-weight:700;">{{ $payment->toAccount?->company?->name ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Importo</div>
                <div style="font-size:22px;font-weight:800;color:var(--primary);">{{ $payment->formattedAmount() }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Descrizione</div>
                <div style="font-weight:600;">{{ $payment->description }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Programmato per</div>
                <div style="font-weight:700;font-size:15px;">{{ $payment->scheduled_at->format('d/m/Y \a\l\l\e H:i') }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Stato</div>
                <span class="chip {{ $payment->statusChipClass() }}">{{ $payment->statusLabel() }}</span>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Creato il</div>
                <div style="font-size:13px;">{{ $payment->created_at->format('d/m/Y H:i') }}</div>
            </div>
        </div>
    </div>

    <div>
        @if($canCancel)
            <div class="card card-pad">
                <div style="font-weight:700;margin-bottom:8px;">Annulla pagamento</div>
                <p style="font-size:13px;color:var(--ink-muted);margin-bottom:14px;">
                    Annullando, il pagamento non verrà eseguito alla scadenza.
                </p>
                <form method="POST" action="{{ route('portal.scheduled-payments.cancel', $payment) }}"
                      onsubmit="return confirm('Annullare questo pagamento programmato?')">
                    @csrf
                    <button type="submit" class="btn btn-danger" style="width:100%;">Annulla pagamento</button>
                </form>
            </div>
        @else
            <div class="card card-pad" style="background:var(--surface-soft);">
                <div style="font-size:13px;color:var(--ink-muted);">
                    @if($payment->isPending())
                        Solo il mittente può annullare un pagamento programmato.
                    @else
                        Nessuna azione disponibile.
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
