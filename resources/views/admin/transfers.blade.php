@extends('layouts.portal')

@section('page-actions')
@php
    $csvParams = http_build_query(array_filter([
        'period'    => $movementFilters['period'],
        'from_date' => $movementFilters['from_date'],
        'to_date'   => $movementFilters['to_date'],
    ]));
@endphp
<a class="cta" href="{{ route('admin.report.export-csv') }}?{{ $csvParams }}">⬇ CSV</a>
<a class="cta secondary" href="{{ route('admin.report') }}">Rapporti</a>
<a class="cta secondary" href="{{ route('admin.accounts.index') }}">Conti</a>
<a class="cta secondary" href="{{ route('admin.users.index') }}">Utenti</a>
@endsection




@section('content')
    <section class="grid-cards">
        <article class="stat-card"><div class="eyebrow">Movimenti filtrati</div><div class="section-title">{{ $movementTotals['count'] }}</div></article>
        <article class="stat-card"><div class="eyebrow">Contabilizzati</div><div class="section-title">{{ $movementTotals['bookedCount'] }}</div></article>
        <article class="stat-card"><div class="eyebrow">Volume</div><div class="section-title">{{ ky_format($movementTotals['volume']) }} KY</div></article>
        <article class="stat-card"><div class="eyebrow">Storni</div><div class="section-title">{{ $movementTotals['refunds'] }}</div></article>
    </section>

    <section class="card light-card">
        <div class="section-head"><div><span class="eyebrow">Registro movimenti</span><h3 class="section-title">Movimenti registrati</h3></div><span class="pill">{{ $movementFilters['label'] }}</span></div>

        <form method="get" action="{{ route('admin.transfers.index') }}" style="margin-bottom:10px;">
            <div style="display:grid;grid-template-columns:200px 1fr 1fr auto;gap:8px;align-items:end;">
                <div class="field">
                    <label>Periodo</label>
                    <select name="period">
                        @foreach ($movementPeriodOptions as $value => $label)
                            <option value="{{ $value }}" @selected($movementFilters['period'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Da data</label><input type="date" name="from_date" value="{{ $movementFilters['from_date'] }}"></div>
                <div class="field"><label>A data</label><input type="date" name="to_date" value="{{ $movementFilters['to_date'] }}"></div>
                <div style="padding-bottom:1px;"><button type="submit" class="cta secondary">Filtra</button></div>
            </div>
        </form>

        <div class="timeline-list">
            @forelse ($transfers as $transfer)
                @php
                    $isRefundable = $supportsTransferRefunds
                        && $transfer->status === 'booked'
                        && $transfer->reversalChildren->isEmpty()
                        && $transfer->booked_at !== null
                        && $transfer->booked_at->greaterThanOrEqualTo(now()->subDays($refundWindowDays));
                    $stato = match ($transfer->status) {
                        'booked' => 'Contabilizzato',
                        'pending' => 'In elaborazione',
                        'rejected' => 'Respinto',
                        default => ucfirst(str_replace('_', ' ', $transfer->status ?? 'N/D')),
                    };
                    $causale = match ($transfer->kind) {
                        'portal_payment' => 'Pagamento da portale',
                        'portal_collection' => 'Incasso da portale',
                        'trade_payment' => 'Pagamento commerciale',
                        'admin_refund' => 'Storno amministrativo',
                        default => $transfer->kind ? ucfirst(str_replace('_', ' ', $transfer->kind)) : 'Movimento',
                    };
                    $azioneAdmin = match ($transfer->admin_action) {
                        'refund' => 'Storno',
                        default => $transfer->admin_action ? ucfirst(str_replace('_', ' ', $transfer->admin_action)) : null,
                    };
                @endphp
                <article class="timeline-item">
                    <div class="entity-head">
                        <div>
                            <strong>{{ $transfer->reference }}</strong>
                            <div class="table-muted">{{ $transfer->booked_at?->format('d/m/Y H:i') ?? 'non contabilizzato' }} · {{ $causale }}</div>
                            <div class="table-muted">Operatore: {{ $transfer->initiator?->name ?? 'sistema' }}</div>
                        </div>
                        <div class="entity-meta">
                            <span class="chip {{ $transfer->status === 'booked' ? 'success' : 'pink' }}">{{ $stato }}</span>
                            @if ($supportsTransferRefunds && $azioneAdmin)
                                <span class="chip pink">{{ $azioneAdmin }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="field-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;">
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Da</div><strong>{{ $transfer->fromAccount?->display_name ?? 'N/D' }}</strong><div class="table-muted">{{ $transfer->fromAccount?->company?->name ?? $transfer->fromAccount?->ownerLabel }}</div></div>
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">A</div><strong>{{ $transfer->toAccount?->display_name ?? 'N/D' }}</strong><div class="table-muted">{{ $transfer->toAccount?->company?->name ?? $transfer->toAccount?->ownerLabel }}</div></div>
                        <div class="card-pad" style="padding:14px;border-radius:18px;background:#f6f9fb;border:1px solid #e4edf2;"><div class="eyebrow">Importo</div><strong>{{ ky_format($transfer->amount) }} {{ $transfer->currency_code }}</strong><div class="table-muted">{{ $transfer->description ?: 'nessuna causale' }}</div></div>
                    </div>

                    @if (! $supportsTransferRefunds)
                        <div class="notice">Storni amministrativi non disponibili finché il database non viene aggiornato con i campi dedicati.</div>
                    @elseif ($transfer->reversedTransfer)
                        <div class="notice">Storno di {{ $transfer->reversedTransfer->reference }} registrato il {{ $transfer->refunded_at?->format('d/m/Y H:i') ?? 'N/D' }}.</div>
                    @elseif ($transfer->reversalChildren->isNotEmpty())
                        <div class="notice">Questo movimento è già stato corretto da {{ $transfer->reversalChildren->first()->reference }}.</div>
                    @else
                        <div class="notice {{ $isRefundable ? 'success' : 'error' }}">{{ $isRefundable ? 'Storno amministrativo disponibile entro la finestra operativa.' : 'Storno non disponibile: finestra scaduta o movimento non correggibile.' }}</div>
                    @endif

                    @if ($isRefundable)
                        <form method="post" action="{{ route('admin.transfers.refund', $transfer) }}" class="field-grid">
                            @csrf
                            <div class="field"><label>Motivazione storno</label><input name="reason" type="text" placeholder="Storno operativo, errore utente, correzione contabile"></div>
                            <div class="form-actions"><button type="submit" class="cta">Esegui storno</button></div>
                        </form>
                    @endif
                </article>
            @empty
                <div class="empty-state">Nessun movimento trovato per il periodo selezionato.</div>
            @endforelse
        </div>
    </section>
@endsection
