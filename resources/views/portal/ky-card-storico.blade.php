@extends('layouts.portal')

@section('content')

{{-- ── BREADCRUMB ──────────────────────────────────────────────────────── --}}
<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-muted);margin-bottom:20px;">
    <a href="{{ route('portal.ky-cards.index') }}" style="color:var(--primary);text-decoration:none;font-weight:600;">← Ricarica KY</a>
    <span>/</span>
    <span style="color:var(--ink);">Storico acquisti</span>
</div>

{{-- ── KPI STRIP (lifetime, ignorano filtri) ────────────────────────────── --}}
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;">

    <div class="card" style="padding:14px 18px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:5px;">Acquisti completati</div>
        <div style="font-size:26px;font-weight:800;color:var(--ink);line-height:1;">{{ number_format($totals->count ?? 0, 0, ',', '.') }}</div>
        <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">totale storico</div>
    </div>

    <div class="card" style="padding:14px 18px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:5px;">Totale speso</div>
        <div style="font-size:26px;font-weight:800;color:var(--ink);line-height:1;">
            {{ number_format(($totals->eur_cents ?? 0) / 100, 2, ',', '.') }}<span style="font-size:14px;font-weight:600;color:var(--ink-soft);"> €</span>
        </div>
        <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">totale storico</div>
    </div>

    <div class="card" style="padding:14px 18px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:5px;">KY ricevuti</div>
        <div style="font-size:26px;font-weight:800;color:#1d4ed8;line-height:1;">
            {{ ky_format($totals->ky_total ?? 0) }}<span style="font-size:14px;font-weight:600;"> KY</span>
        </div>
        <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">totale storico</div>
    </div>

</div>

{{-- ── FILTRI ───────────────────────────────────────────────────────────── --}}
@php
    $hasFilters = $filters['dal'] || $filters['al'] || $filters['stato'] || $filters['metodo'] || $filters['cardId'];
@endphp
<form method="GET" action="{{ route('portal.ky-cards.storico') }}" id="filter-form">
<div class="card" style="padding:14px 18px;margin-bottom:18px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">

        {{-- Dal --}}
        <div style="display:flex;flex-direction:column;gap:4px;min-width:130px;">
            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin:0;">Dal</label>
            <input type="date" name="dal" value="{{ $filters['dal'] ?? '' }}"
                   style="padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--card-bg);color:var(--ink);font-size:13px;"
                   onchange="document.getElementById('filter-form').submit()">
        </div>

        {{-- Al --}}
        <div style="display:flex;flex-direction:column;gap:4px;min-width:130px;">
            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin:0;">Al</label>
            <input type="date" name="al" value="{{ $filters['al'] ?? '' }}"
                   style="padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--card-bg);color:var(--ink);font-size:13px;"
                   onchange="document.getElementById('filter-form').submit()">
        </div>

        {{-- Stato --}}
        <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin:0;">Stato</label>
            <select name="stato"
                    style="padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--card-bg);color:var(--ink);font-size:13px;"
                    onchange="document.getElementById('filter-form').submit()">
                <option value="">Tutti</option>
                <option value="completed"             {{ $filters['stato'] === 'completed'             ? 'selected' : '' }}>✅ Completato</option>
                <option value="pending"               {{ $filters['stato'] === 'pending'               ? 'selected' : '' }}>⏳ In elaborazione</option>
                <option value="pending_bank_transfer" {{ $filters['stato'] === 'pending_bank_transfer' ? 'selected' : '' }}>🏦 Attesa bonifico</option>
                <option value="failed"                {{ $filters['stato'] === 'failed'                ? 'selected' : '' }}>❌ Fallito</option>
            </select>
        </div>

        {{-- Metodo --}}
        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin:0;">Metodo</label>
            <select name="metodo"
                    style="padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--card-bg);color:var(--ink);font-size:13px;"
                    onchange="document.getElementById('filter-form').submit()">
                <option value="">Tutti</option>
                <option value="stripe"       {{ $filters['metodo'] === 'stripe'       ? 'selected' : '' }}>💳 Carta</option>
                <option value="paypal"       {{ $filters['metodo'] === 'paypal'       ? 'selected' : '' }}>🅿 PayPal</option>
                <option value="bank_transfer"{{ $filters['metodo'] === 'bank_transfer'? 'selected' : '' }}>🏦 Bonifico</option>
            </select>
        </div>

        {{-- Card --}}
        @if($availableCards->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);margin:0;">KYCard</label>
            <select name="card_id"
                    style="padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--card-bg);color:var(--ink);font-size:13px;"
                    onchange="document.getElementById('filter-form').submit()">
                <option value="">Tutte</option>
                @foreach($availableCards as $c)
                <option value="{{ $c->id }}" {{ $filters['cardId'] === $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        @endif

        {{-- Reset --}}
        @if($hasFilters)
        <div style="display:flex;flex-direction:column;justify-content:flex-end;">
            <a href="{{ route('portal.ky-cards.storico') }}"
               style="padding:7px 14px;border-radius:7px;border:1px solid var(--border);background:var(--card-bg);
                      font-size:13px;font-weight:600;color:var(--ink-soft);text-decoration:none;white-space:nowrap;">
                ✕ Reset
            </a>
        </div>
        @endif

    </div>

    {{-- Badge filtri attivi --}}
    @if($hasFilters)
    <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
        <span style="font-size:11px;color:var(--ink-muted);">Filtri attivi:</span>
        @if($filters['dal'])  <span style="background:var(--primary-light,#e6effc);color:var(--primary);font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">Dal {{ \Carbon\Carbon::parse($filters['dal'])->format('d/m/Y') }}</span> @endif
        @if($filters['al'])   <span style="background:var(--primary-light,#e6effc);color:var(--primary);font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">Al {{ \Carbon\Carbon::parse($filters['al'])->format('d/m/Y') }}</span> @endif
        @if($filters['stato'])
            @php $statoLabel = ['completed'=>'Completato','pending'=>'In elaborazione','pending_bank_transfer'=>'Attesa bonifico','failed'=>'Fallito'][$filters['stato']] ?? $filters['stato']; @endphp
            <span style="background:var(--primary-light,#e6effc);color:var(--primary);font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">{{ $statoLabel }}</span>
        @endif
        @if($filters['metodo'])
            @php $metodoLabel = ['stripe'=>'Carta','paypal'=>'PayPal','bank_transfer'=>'Bonifico'][$filters['metodo']] ?? $filters['metodo']; @endphp
            <span style="background:var(--primary-light,#e6effc);color:var(--primary);font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">{{ $metodoLabel }}</span>
        @endif
        @if($filters['cardId'])
            @php $cardName = $availableCards->firstWhere('id', $filters['cardId'])?->name ?? '—'; @endphp
            <span style="background:var(--primary-light,#e6effc);color:var(--primary);font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">{{ $cardName }}</span>
        @endif
        <span style="font-size:11px;color:var(--ink-muted);">— {{ $purchases->total() }} risultat{{ $purchases->total() === 1 ? 'o' : 'i' }}</span>
    </div>
    @endif
</div>
</form>

{{-- ── TABELLA ACQUISTI ─────────────────────────────────────────────────── --}}
@if($purchases->isEmpty())
    <div class="card" style="padding:40px;text-align:center;color:var(--ink-muted);">
        <div style="font-size:32px;margin-bottom:10px;">🔍</div>
        <div style="font-size:15px;font-weight:600;margin-bottom:4px;">Nessun risultato</div>
        <div style="font-size:13px;">
            @if($hasFilters)
                Nessun acquisto corrisponde ai filtri selezionati.
                <a href="{{ route('portal.ky-cards.storico') }}" style="color:var(--primary);font-weight:600;">Rimuovi i filtri</a>
            @else
                I tuoi acquisti KYCard appariranno qui.
                <a href="{{ route('portal.ky-cards.index') }}" class="cta" style="display:inline-flex;margin-top:16px;">Acquista ora →</a>
            @endif
        </div>
    </div>
@else
    <div style="overflow-x:auto;">
    <div class="card" style="padding:0;overflow:hidden;margin-bottom:18px;min-width:560px;">

        {{-- Header tabella --}}
        <div style="display:grid;grid-template-columns:1fr 120px 130px 110px 100px;gap:12px;
                    padding:9px 18px;background:var(--surface-alt,#f8fafc);
                    border-bottom:1px solid var(--border);
                    font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-muted);">
            <div>Card</div>
            <div>Metodo</div>
            <div>Data</div>
            <div style="text-align:right;">Pagato</div>
            <div style="text-align:right;">KY ricevuti</div>
        </div>

        @foreach($purchases as $p)
        <div style="display:grid;grid-template-columns:1fr 120px 130px 110px 100px;gap:12px;
                    align-items:center;padding:11px 18px;
                    {{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}"
             onmouseover="this.style.background='var(--surface-alt,#f8fafc)'"
             onmouseout="this.style.background=''">

            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:28px;height:28px;border-radius:7px;flex-shrink:0;
                            background:{{ $p->isCompleted() ? '#eff6ff' : ($p->isPendingBankTransfer() ? '#fffbeb' : '#fef2f2') }};
                            display:flex;align-items:center;justify-content:center;font-size:13px;">
                    {{ $p->isCompleted() ? '✅' : ($p->isPendingBankTransfer() ? '⏳' : '❌') }}
                </div>
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $p->kyCard->name ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);">
                        @if($p->isCompleted()) Completato
                        @elseif($p->isPendingBankTransfer()) Attesa bonifico
                        @elseif($p->isFailed()) Fallito
                        @else In elaborazione
                        @endif
                    </div>
                </div>
            </div>

            <div style="font-size:12.5px;color:var(--ink-soft);">
                @if($p->payment_method === 'stripe') 💳 Carta
                @elseif($p->payment_method === 'paypal') 🅿 PayPal
                @else 🏦 Bonifico
                @endif
            </div>

            <div style="font-size:12px;color:var(--ink-muted);">
                {{ $p->created_at->format('d/m/Y') }}<br>
                <span style="font-size:11px;">{{ $p->created_at->format('H:i') }}</span>
            </div>

            <div style="text-align:right;font-size:13px;font-weight:600;color:var(--ink);">
                {{ number_format($p->price_eur, 2, ',', '.') }} €
            </div>

            <div style="text-align:right;">
                @if($p->isCompleted())
                    <div style="font-size:14px;font-weight:800;color:#1d4ed8;">+{{ ky_format($p->ky_amount) }}</div>
                    <div style="font-size:10px;font-weight:700;color:#1d4ed8;">KY</div>
                @else
                    <div style="font-size:13px;color:var(--ink-muted);">—</div>
                @endif
            </div>

        </div>
        @endforeach
    </div>
    </div>

    {{-- Paginazione --}}
    @if($purchases->hasPages())
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:12.5px;color:var(--ink-muted);">
            Risultati {{ $purchases->firstItem() }}–{{ $purchases->lastItem() }} di {{ $purchases->total() }}
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
            @if($purchases->onFirstPage())
                <span style="padding:6px 12px;border-radius:7px;border:1px solid var(--border);font-size:13px;color:var(--ink-muted);cursor:default;">‹ Prec</span>
            @else
                <a href="{{ $purchases->previousPageUrl() }}" style="padding:6px 12px;border-radius:7px;border:1px solid var(--border);font-size:13px;color:var(--ink);text-decoration:none;background:var(--card-bg);">‹ Prec</a>
            @endif
            <span style="font-size:13px;color:var(--ink-muted);">Pag. {{ $purchases->currentPage() }} / {{ $purchases->lastPage() }}</span>
            @if($purchases->hasMorePages())
                <a href="{{ $purchases->nextPageUrl() }}" style="padding:6px 12px;border-radius:7px;border:1px solid var(--border);font-size:13px;color:var(--ink);text-decoration:none;background:var(--card-bg);">Succ ›</a>
            @else
                <span style="padding:6px 12px;border-radius:7px;border:1px solid var(--border);font-size:13px;color:var(--ink-muted);cursor:default;">Succ ›</span>
            @endif
        </div>
    </div>
    @endif

@endif

@endsection
