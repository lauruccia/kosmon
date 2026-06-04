@extends('layouts.portal')

@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

<section class="card light-card" style="margin-bottom:22px;padding:20px 24px;">
    <form method="GET" action="{{ route('portal.shop') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:200px;">
            <label style="display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em;">Cerca</label>
            <input type="text" name="q" value="{{ $searchQuery }}" placeholder="Prodotto, azienda..." style="width:100%;padding:10px 14px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:14px;color:#10263d;">
        </div>
        <div style="min-width:200px;">
            <label style="display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em;">Categoria</label>
            <select name="category" style="width:100%;padding:10px 14px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:14px;color:#10263d;background:#fff;">
                <option value="">Tutte le categorie</option>
                @foreach($categories as $slug => $label)
                    <option value="{{ $slug }}" @selected($selectedCategory === $slug)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" style="padding:10px 22px;background:#0c4a86;color:#fff;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;">Filtra</button>
        @if($searchQuery || $selectedCategory)
            <a href="{{ route('portal.shop') }}" style="padding:10px 16px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:14px;color:#475569;text-decoration:none;">✕ Reset</a>
        @endif
        <div style="margin-left:auto;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            @if(auth()->user()->canAccessMarketplace())
            <a class="cta" href="{{ route('portal.shop.create') }}" style="padding:10px 18px;font-size:14px;white-space:nowrap;">+ Pubblica prodotto</a>
            @endif
            <a class="cta secondary" href="{{ route('portal.announcements') }}" style="padding:10px 18px;font-size:14px;white-space:nowrap;">Vai agli annunci</a>
        </div>
    </form>
</section>

@if($featuredListings->isNotEmpty() && !$searchQuery && !$selectedCategory)
<section class="card light-card" style="margin-bottom:22px;">
    <div class="section-head">
        <div><span class="eyebrow">In evidenza</span><h3 class="section-title">Prodotti in primo piano</h3></div>
    </div>
    <div class="tile-grid">
        @foreach($featuredListings as $listing)
        <article class="section-panel" style="border-left:3px solid #0c4a86;overflow:hidden;padding:0;">
            @if($listing->first_image_url)
            <div style="height:130px;overflow:hidden;border-radius:10px 10px 0 0;">
                <img src="{{ $listing->first_image_url }}" alt="{{ $listing->title }}" style="width:100%;height:100%;object-fit:cover;display:block;">
            </div>
            @endif
            <div style="padding:16px 18px;">
            <div class="entity-head">
                <div>
                    <span class="eyebrow">{{ $listing->category_label }}</span>
                    <h3 style="margin-top:4px;">{{ $listing->title }}</h3>
                    <div class="subtle">{{ $listing->company->name }}</div>
                </div>
                <span class="pill success">★ Evidenza</span>
            </div>
            <div style="font-size:22px;font-weight:300;color:#0c4a86;letter-spacing:.06em;margin:10px 0;">
                {{ ky_format($listing->price_ky) }} KY
            </div>
            <div class="page-actions" style="margin-top:0;">
                <a class="cta" href="{{ route('portal.shop.show', $listing) }}">Vedi dettaglio</a>
            </div>
            </div>{{-- /padding --}}
        </article>
        @endforeach
    </div>
</section>
@endif

<div class="catalog-grid">
    @forelse($listings as $listing)
    <article class="catalog-card">
        {{-- Thumbnail immagine --}}
        @if($listing->first_image_url)
        <div style="margin:-1px -1px 14px;border-radius:12px 12px 0 0;overflow:hidden;height:160px;">
            <img src="{{ $listing->first_image_url }}"
                 alt="{{ $listing->title }}"
                 style="width:100%;height:100%;object-fit:cover;display:block;">
        </div>
        @endif
        <div class="catalog-head">
            <div>
                <span class="eyebrow">{{ $listing->category_label }}</span>
                <h3 style="margin-top:6px;">{{ $listing->title }}</h3>
            </div>
            @if($listing->featured)<span class="pill warn">★</span>@endif
        </div>
        <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
            <div class="catalog-price">{{ ky_format($listing->price_ky) }} KY</div>
            @if($listing->ky_percentage < 100)
                <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;{{ $listing->ky_badge_color }}">
                    {{ $listing->ky_badge_label }}
                </span>
            @endif
        </div>
        <p class="subtle" style="margin:0;line-height:1.7;">{{ Str::limit($listing->description, 100) }}</p>
        <div class="entity-meta">
            <span class="chip">{{ $listing->company->name }}</span>
            @if($listing->delivery_note)<span class="chip">{{ $listing->delivery_note }}</span>@endif
        </div>
        <div class="page-actions" style="margin-top:0;">
            <a class="cta" href="{{ route('portal.shop.show', $listing) }}">Vedi e acquista</a>
            @if(auth()->user()->company_id === $listing->company_id || auth()->user()->is_super_admin)
                <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary">Modifica</a>
            @endif
        </div>
    </article>
    @empty
    <div style="grid-column:1/-1;text-align:center;padding:48px 24px;">
        <p class="subtle">Nessun prodotto trovato nel catalogo.</p>
        @if($searchQuery || $selectedCategory)
            <a href="{{ route('portal.shop') }}" class="cta secondary" style="margin-top:12px;display:inline-block;">Rimuovi filtri</a>
        @endif
    </div>
    @endforelse
</div>

@if($listings->hasPages())
<div style="margin-top:24px;display:flex;justify-content:center;">
    {{ $listings->appends(request()->query())->links() }}
</div>
@endif

@endsection