@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <span class="eyebrow">
        <a href="{{ route('portal.text-requests.index') }}" style="color:var(--primary);text-decoration:none;">Richieste</a>
        &rsaquo; Dettaglio
    </span>
    <h2>{{ $pageTitle }}</h2>
</section>

{{-- Banner stato --}}
@if($req->isApproved())
    <div class="alert alert-success" style="margin-bottom:20px;">
        Pagamento eseguito il {{ $req->actioned_at?->format('d/m/Y \a\l\l\e H:i') }}.
        @if($req->transfer)
            <a href="{{ route('portal.movements') }}" style="font-weight:700;color:inherit;margin-left:6px;">Vedi nel registro movimenti &rarr;</a>
        @endif
    </div>
@elseif($req->isRejected())
    <div class="alert alert-danger" style="margin-bottom:20px;">
        Richiesta rifiutata il {{ $req->actioned_at?->format('d/m/Y') }}.
    </div>
@elseif($req->isCancelled())
    <div class="alert alert-warning" style="margin-bottom:20px;">
        Richiesta annullata dal mittente.
    </div>
@elseif($req->isExpired())
    <div class="alert alert-warning" style="margin-bottom:20px;">
        Questa richiesta è scaduta.
    </div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:760px;">

    {{-- Dettagli --}}
    <div class="card card-pad">
        <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;">Dettagli richiesta</div>

        <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Da</div>
                <div style="font-weight:700;">{{ $req->fromAccount?->company?->name ?? $req->fromAccount?->display_name ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">A</div>
                <div style="font-weight:700;">{{ $req->toAccount?->company?->name ?? $req->toAccount?->display_name ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Importo</div>
                <div style="font-size:22px;font-weight:800;color:var(--primary);">{{ $req->formattedAmount() }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Causale</div>
                <div style="font-weight:600;">{{ $req->causale }}</div>
            </div>
            @if($req->note)
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);">Note</div>
                    <div style="font-size:13px;">{{ $req->note }}</div>
                </div>
            @endif
            @if($req->due_date)
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);">Scadenza</div>
                    <div style="font-weight:600;{{ $req->due_date->isPast() && $req->isPending() ? 'color:var(--danger);' : '' }}">
                        {{ $req->due_date->format('d/m/Y') }}
                    </div>
                </div>
            @endif
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Stato</div>
                <span class="chip {{ $req->statusChipClass() }}">{{ $req->statusLabel() }}</span>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Creata il</div>
                <div style="font-size:13px;">{{ $req->created_at->format('d/m/Y H:i') }}</div>
            </div>
        </div>
    </div>

    {{-- Azioni --}}
    <div style="display:flex;flex-direction:column;gap:12px;">

        @if($canAction)
            {{-- Approva --}}
            <div class="card card-pad" style="border:2px solid var(--success-color,#22c55e);">
                <div style="font-weight:700;margin-bottom:8px;">Approva e paga</div>
                <p style="font-size:13px;color:var(--ink-muted);margin-bottom:14px;">
                    Confermando verranno trasferiti <strong>{{ $req->formattedAmount() }}</strong> dal tuo conto a
                    <strong>{{ $req->fromAccount?->company?->name ?? '—' }}</strong>.
                </p>
                <form method="POST" action="{{ route('portal.text-requests.approve', $req) }}"
                      onsubmit="return confirm('Confermi il pagamento di {{ $req->formattedAmount() }}?')">
                    @csrf
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        ✓ Approva e paga {{ $req->formattedAmount() }}
                    </button>
                </form>
            </div>

            {{-- Rifiuta --}}
            <div class="card card-pad">
                <div style="font-weight:700;margin-bottom:8px;">Rifiuta richiesta</div>
                <form method="POST" action="{{ route('portal.text-requests.reject', $req) }}">
                    @csrf
                    <textarea name="rejection_note" rows="2" maxlength="500"
                              placeholder="Motivo (opzionale)..."
                              class="form-control" style="margin-bottom:10px;font-size:13px;"></textarea>
                    <button type="submit" class="btn btn-danger" style="width:100%;">
                        Rifiuta richiesta
                    </button>
                </form>
            </div>
        @endif

        @if($canCancel)
            <div class="card card-pad">
                <div style="font-weight:700;margin-bottom:8px;">Annulla richiesta</div>
                <p style="font-size:13px;color:var(--ink-muted);margin-bottom:12px;">
                    Annulla questa richiesta. Il destinatario non potrà più pagarla.
                </p>
                <form method="POST" action="{{ route('portal.text-requests.cancel', $req) }}"
                      onsubmit="return confirm('Annullare questa richiesta?')">
                    @csrf
                    <button type="submit" class="btn btn-secondary" style="width:100%;">Annulla richiesta</button>
                </form>
            </div>
        @endif

        @if(!$canAction && !$canCancel)
            <div class="card card-pad" style="background:var(--surface-soft);">
                <div style="font-size:13px;color:var(--ink-muted);">
                    Nessuna azione disponibile per questa richiesta.
                </div>
            </div>
        @endif

    </div>
</div>
@endsection
