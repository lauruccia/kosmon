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
            @if($payment->isRecurring())
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Ricorrenza</div>
                <div style="font-size:13px;font-weight:600;">
                    Rata {{ $payment->recurrence_index }} di {{ $payment->recurrence_total }}
                    &mdash; {{ $payment->recurrenceTypeLabel() }}
                </div>
            </div>
            @endif
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:14px;">
        @if($canCancel)
            <div class="card card-pad">
                <div style="font-weight:700;margin-bottom:8px;">Annulla questa rata</div>
                <p style="font-size:13px;color:var(--ink-muted);margin-bottom:14px;">
                    Annullando solo questa rata, le altre rimangono attive.
                </p>
                <form method="POST" action="{{ route('portal.scheduled-payments.cancel', $payment) }}"
                      onsubmit="return confirm('Annullare questa rata?')">
                    @csrf
                    <button type="submit" class="btn btn-danger" style="width:100%;">Annulla questa rata</button>
                </form>
            </div>

            @if($payment->isRecurring())
                @php
                    $pendingSiblings = $payment->siblings()
                        ->where('status', 'pending')
                        ->count();
                @endphp
                @if($pendingSiblings > 1)
                <div class="card card-pad" style="border:1.5px solid var(--danger);">
                    <div style="font-weight:700;margin-bottom:8px;color:var(--danger);">Annulla tutte le rate rimanenti</div>
                    <p style="font-size:13px;color:var(--ink-muted);margin-bottom:14px;">
                        Annulla tutte le <strong>{{ $pendingSiblings }}</strong> rate ancora in attesa di questo gruppo ricorrente.
                    </p>
                    <form method="POST" action="{{ route('portal.scheduled-payments.cancel-group', $payment->recurrence_group) }}"
                          onsubmit="return confirm('Annullare tutte le {{ $pendingSiblings }} rate rimanenti di questo pagamento ricorrente?')">
                        @csrf
                        <button type="submit" class="btn btn-danger" style="width:100%;">
                            Annulla tutte le rate ({{ $pendingSiblings }})
                        </button>
                    </form>
                </div>
                @endif
            @endif
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

        @if($payment->isRecurring())
        <div class="card card-pad" style="background:var(--surface-soft);">
            <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;">
                Tutte le rate
            </div>
            @foreach($payment->siblings() as $sibling)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">
                    <span style="font-weight:{{ $sibling->id === $payment->id ? '700' : '400' }};">
                        {{ $sibling->recurrence_index }}. {{ $sibling->scheduled_at->format('d/m/Y') }}
                        @if($sibling->id === $payment->id)
                            <span style="font-size:11px;color:var(--primary);font-weight:600;">(questa)</span>
                        @endif
                    </span>
                    <span class="chip {{ $sibling->statusChipClass() }}" style="font-size:10px;">{{ $sibling->statusLabel() }}</span>
                </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
