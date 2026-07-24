@extends('layouts.portal')

@section('page-actions')
<a class="cta" href="{{ route('admin.listings.create') }}">+ Nuovo prodotto per azienda</a>
<a class="cta secondary" href="{{ route('admin.listings.orders') }}">Ordini</a>
<a class="cta secondary" href="{{ route('portal.shop') }}">Vista portale</a>
@endsection

@section('content')

<section class="grid-cards" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:16px;">
    <article class="stat-card">
        <div class="eyebrow">Prodotti totali</div>
        <div class="section-title">{{ $stats['total'] }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Attivi</div>
        <div class="section-title" style="color:var(--success);">{{ $stats['active'] }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Bozze</div>
        <div class="section-title" style="color:var(--warning);">{{ $stats['draft'] }}</div>
    </article>
    <article class="stat-card">
        <div class="eyebrow">Sospesi</div>
        <div class="section-title" style="color:var(--danger);">{{ $stats['suspended'] }}</div>
    </article>
</section>

<section class="card light-card">
    <div class="section-head">
        <div><span class="eyebrow">Catalogo shop</span><h3 class="section-title">Moderazione prodotti</h3></div>
        <span class="pill">{{ $listings->total() }} risultati</span>
    </div>

    <form method="get" action="{{ route('admin.listings.index') }}" style="margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:1fr 200px auto;gap:8px;align-items:end;">
            <div class="field">
                <label>Cerca titolo o azienda</label>
                <input type="text" name="q" value="{{ $search }}" placeholder="Cerca prodotto o azienda…">
            </div>
            <div class="field">
                <label>Stato</label>
                <select name="status">
                    <option value="">Tutti gli stati</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected($statusFilter === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex;gap:8px;padding-bottom:1px;">
                <button type="submit" class="cta secondary">Filtra</button>
                @if($search || $statusFilter)
                    <a href="{{ route('admin.listings.index') }}" class="cta secondary">Reset</a>
                @endif
            </div>
        </div>
    </form>

    @if($listings->isEmpty())
        <div class="empty-state">Nessun prodotto trovato{{ $search || $statusFilter ? ' per i filtri selezionati' : '' }}.</div>
    @else
    <div style="overflow-x:auto;">
        <table class="admin-table" style="min-width:940px;">
            <thead>
                <tr>
                    <th>Prodotto</th>
                    <th>Azienda</th>
                    <th>Categoria</th>
                    <th style="text-align:right;">Prezzo</th>
                    <th>Stato</th>
                    <th style="text-align:center;">In evidenza</th>
                    <th>Pubblicato da</th>
                    <th>Data</th>
                    <th style="text-align:center;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                @foreach($listings as $listing)
                <tr>
                    <td>
                        <a href="{{ route('portal.shop.show', $listing) }}" target="_blank" style="font-weight:700;color:var(--primary);text-decoration:none;">
                            {{ $listing->title }}
                        </a>
                        <div style="font-size:11px;color:var(--ink-muted);">{{ $listing->views_count }} visite</div>
                    </td>
                    <td style="color:var(--ink-soft);">{{ $listing->company->name ?? '—' }}</td>
                    <td style="font-size:12px;color:var(--ink-soft);">{{ $listing->category_label }}</td>
                    {{-- price_ky è in centesimi: number_format() lo mostrava grezzo (senza /100),
                         500 centesimi (5,00 KY) appariva "500 KY" — stesso bug del 24/07, qui mai corretto. --}}
                    <td style="text-align:right;font-weight:700;">{{ ky_format($listing->price_ky) }} KY</td>
                    <td>
                        <form method="POST" action="{{ route('admin.listings.status', $listing) }}" style="display:inline;">
                            @csrf
                            <select name="status" onchange="this.form.submit()" class="listing-status-select status-{{ $listing->status }}">
                                @foreach($statuses as $s)
                                    <option value="{{ $s }}" @selected($listing->status === $s)>{{ ucfirst($s) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td style="text-align:center;">
                        @if($listing->featured)
                            <span style="color:#e0a000;font-weight:700;" title="In evidenza">★</span>
                        @else
                            <span style="color:var(--line-strong);">☆</span>
                        @endif
                    </td>
                    <td style="font-size:12px;color:var(--ink-soft);">{{ $listing->createdByUser->name ?? '—' }}</td>
                    <td style="font-size:12px;color:var(--ink-muted);white-space:nowrap;">
                        {{ $listing->created_at->format('d/m/Y') }}
                        @if($listing->expires_at)
                            <br><span style="{{ $listing->is_expired ? 'color:var(--danger);' : '' }}">scade {{ $listing->expires_at->format('d/m/Y') }}</span>
                        @endif
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary" style="font-size:11px;padding:3px 10px;">Modifica</a>
                        <form method="POST" action="{{ route('portal.shop.destroy', $listing) }}" style="display:inline;" onsubmit="return confirm('Eliminare questo prodotto?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="cta secondary" style="font-size:11px;padding:3px 10px;color:var(--danger);border-color:rgba(159,18,57,.3);margin-left:4px;">Elimina</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if($listings->hasPages())
        <div style="margin-top:14px;">{{ $listings->withQueryString()->links() }}</div>
    @endif
</section>

<style>
    /* Reset dell'aspetto nativo del <select> (Chrome/Edge disegnano un outline
       spesso e una barra colorata sotto al focus): con appearance:none
       ripristiniamo solo la nostra freccia custom e un focus ring coerente
       col design system, invece del contorno ciano/blu di sistema. */
    .listing-status-select {
        appearance: none; -webkit-appearance: none; -moz-appearance: none;
        border-radius: 999px; padding: 5px 28px 5px 12px; font-size: 11px; font-weight: 700;
        font-family: inherit; line-height: 1.4;
        border: 1.5px solid var(--line); background-color: var(--surface-soft); color: var(--ink-soft);
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path d='M1 1l4 4 4-4' stroke='%234a637d' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>");
        background-repeat: no-repeat; background-position: right 10px center; background-size: 10px 6px;
        outline: none; box-shadow: none;
        cursor: pointer;
        transition: border-color .15s, box-shadow .15s;
    }
    .listing-status-select:hover { border-color: var(--line-strong); }
    .listing-status-select:focus, .listing-status-select:focus-visible {
        border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light);
    }
    .listing-status-select.status-active { background-color: var(--success-soft); color: var(--success); border-color: rgba(6,95,70,.18); }
    .listing-status-select.status-suspended { background-color: var(--danger-soft); color: var(--danger); border-color: rgba(159,18,57,.18); }
    .listing-status-select.status-draft { background-color: var(--warning-soft); color: var(--warning); border-color: rgba(120,53,15,.15); }
    .listing-status-select.status-expired { background-color: var(--surface-soft); color: var(--ink-soft); border-color: var(--line); }
</style>

@endsection
