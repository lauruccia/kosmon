@extends('layouts.portal')

@section('content')
<style>
    .company-profile-hero {
        position: relative; overflow: hidden;
        padding: 28px 28px 24px;
        border-radius: var(--radius-lg);
        background: var(--grad-hero);
        border: 1px solid rgba(255,255,255,.07);
        color: #fff;
        margin-bottom: 0;
    }
    .company-profile-hero::before {
        content:""; position:absolute; top:-60px; right:-60px;
        width:240px; height:240px; border-radius:50%;
        background:radial-gradient(circle, rgba(79,70,229,.3), transparent 70%);
        pointer-events:none;
    }
    .company-profile-hero::after {
        content:""; position:absolute; bottom:-60px; left:-30px;
        width:190px; height:190px; border-radius:50%;
        background:radial-gradient(circle, rgba(15,82,196,.22), transparent 70%);
        pointer-events:none;
    }
    .company-profile-avatar {
        position:relative; z-index:1;
        width:68px; height:68px; border-radius:20px;
        background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.28);
        display:grid; place-items:center;
        font-size:28px; font-weight:800; color:#fff;
        margin-bottom:14px;
        overflow:hidden;
    }
    .company-profile-avatar img {
        width:100%; height:100%; object-fit:cover; border-radius:18px;
    }
    .company-profile-name {
        position:relative; z-index:1;
        margin:0 0 6px; font-size:30px; font-weight:800;
        letter-spacing:-.02em; line-height:1.1;
    }
    .company-profile-meta {
        position:relative; z-index:1;
        display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;
    }
    .profile-chip {
        display:inline-flex; align-items:center;
        padding:3px 10px; border-radius:999px;
        background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.2);
        font-size:11px; font-weight:700; color:rgba(255,255,255,.9);
        text-transform:uppercase; letter-spacing:.06em;
    }
    .profile-chip.verified { background:rgba(16,185,129,.22); border-color:rgba(16,185,129,.4); }
    .company-description {
        position:relative; z-index:1;
        max-width:680px; font-size:14px; line-height:1.6;
        color:rgba(255,255,255,.78); margin-bottom:16px;
    }
    .company-contact-row {
        position:relative; z-index:1;
        display:flex; flex-wrap:wrap; gap:16px;
    }
    .company-contact-item {
        display:flex; align-items:center; gap:7px;
        font-size:13px; color:rgba(255,255,255,.72);
    }
    .company-contact-icon {
        width:26px; height:26px; border-radius:8px;
        background:rgba(255,255,255,.12);
        display:grid; place-items:center;
        font-size:9px; font-weight:800; color:#fff; letter-spacing:.05em;
    }
    .company-contact-item a { color:rgba(255,255,255,.9); text-decoration:underline; }

    .company-stat-strip {
        display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px;
    }
    .company-stat-card {
        padding:16px; border-radius:var(--radius);
        background:var(--surface); border:1px solid var(--line);
        box-shadow:var(--shadow-xs);
        transition:background .28s, border-color .28s;
    }
    .company-stat-label {
        font-size:10px; font-weight:700; text-transform:uppercase;
        letter-spacing:.1em; color:var(--ink-muted);
    }
    .company-stat-value {
        margin-top:4px; font-size:22px; font-weight:800;
        letter-spacing:-.02em; color:var(--ink);
    }
    .company-stat-note { margin-top:2px; font-size:11px; color:var(--ink-muted); }

    .listing-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0,1fr));
        gap:14px;
    }
    .listing-card {
        border-radius:var(--radius); overflow:hidden;
        background:var(--surface); border:1px solid var(--line);
        box-shadow:var(--shadow-xs);
        transition:transform .18s, box-shadow .18s, border-color .18s;
        display:flex; flex-direction:column;
    }
    .listing-card:hover {
        transform:translateY(-2px);
        box-shadow:var(--shadow);
        border-color:var(--line-strong);
    }
    .listing-card-img {
        height:160px; background:var(--surface-soft);
        display:grid; place-items:center; overflow:hidden;
    }
    .listing-card-img img { width:100%; height:100%; object-fit:cover; display:block; }
    .listing-card-img-placeholder {
        font-size:36px; font-weight:800; color:var(--ink-muted); letter-spacing:-.02em;
    }
    .listing-card-body { padding:14px; flex:1; display:flex; flex-direction:column; gap:6px; }
    .listing-card-title {
        font-size:15px; font-weight:700; color:var(--ink);
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    }
    .listing-card-price { font-size:18px; font-weight:800; color:var(--primary); }
    .listing-card-category { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-muted); }
    .listing-card-footer {
        padding:10px 14px; border-top:1px solid var(--line);
        display:flex; justify-content:flex-end;
    }

    .announcement-list { display:grid; gap:10px; }
    .announcement-item {
        padding:14px 16px; border-radius:var(--radius);
        background:var(--surface); border:1px solid var(--line);
        box-shadow:var(--shadow-xs);
        display:grid; gap:6px;
        transition:border-color .18s, background .18s;
    }
    .announcement-item:hover { border-color:var(--line-strong); background:var(--surface-soft); }
    .announcement-item-header { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .ann-type-badge {
        display:inline-flex; align-items:center; min-height:22px; padding:0 9px;
        border-radius:999px; font-size:10px; font-weight:700;
        text-transform:uppercase; letter-spacing:.06em;
    }
    .ann-type-badge.offer   { background:var(--teal-soft); color:var(--teal-strong); border:1px solid rgba(2,132,199,.2); }
    .ann-type-badge.request { background:var(--warning-soft); color:var(--warning); border:1px solid rgba(120,53,15,.2); }
    .announcement-title { font-size:15px; font-weight:700; color:var(--ink); }
    .announcement-body  { font-size:13px; color:var(--ink-soft); line-height:1.5; }
    @media (max-width:980px) {
        .company-stat-strip { grid-template-columns:repeat(2,1fr); }
        .listing-grid { grid-template-columns:repeat(2,1fr); }
    }
    @media (max-width:720px) {
        .company-stat-strip, .listing-grid { grid-template-columns:1fr; }
    }
</style>

<div style="margin-bottom:14px;">
    <a href="{{ route('portal.companies') }}" style="color:var(--ink-soft);font-size:13.5px;">← Directory aziende</a>
</div>

<div class="stack">

    {{-- ── Banner sospensione ── --}}
    @if($company->isSuspended())
    <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:center;">
        <span style="font-size:22px;">🔴</span>
        <div>
            <strong style="color:#991b1b;font-size:14px;">Account sospeso</strong>
            <div style="font-size:13px;color:#b91c1c;margin-top:2px;">
                Questa azienda è stata temporaneamente sospesa dal circuito.
                @if($company->suspension_reason) Motivo: {{ $company->suspension_reason }} @endif
            </div>
        </div>
    </div>
    @endif

    {{-- ── HERO ── --}}
    <section class="company-profile-hero" @if($company->banner_path) style="background-image:url({{ \Illuminate\Support\Facades\Storage::disk('public')->url($company->banner_path) }});background-size:cover;background-position:center;background-color:transparent;" @endif>
        <div class="company-profile-avatar">
            @if($company->logo_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo_path) }}" alt="{{ $company->name }}">
            @else
                {{ strtoupper(substr($company->name, 0, 1)) }}
            @endif
        </div>
        <h1 class="company-profile-name">{{ $company->name }}</h1>
        <div class="company-profile-meta">
            @if ($company->sector)
                <span class="profile-chip">{{ $company->sector }}</span>
            @endif
            @if ($company->city)
                <span class="profile-chip">📍 {{ $company->city }}</span>
            @endif
            @if ($company->kyc_status === 'approved')
                <span class="profile-chip verified">✓ Verificata</span>
            @endif
            @if ($company->approved_at)
                <span class="profile-chip">Socio dal {{ $company->approved_at->locale('it')->isoFormat('MMM YYYY') }}</span>
            @endif
        </div>

        @if ($company->tagline)
            <p style="position:relative;z-index:1;font-size:16px;font-style:italic;color:rgba(255,255,255,.85);margin:0 0 10px;line-height:1.4;">"{{ $company->tagline }}"</p>
        @endif
        @if ($company->description)
            <p class="company-description">{{ $company->description }}</p>
        @endif

        <div class="company-contact-row">
            @if ($company->email)
                <div class="company-contact-item">
                    <span class="company-contact-icon">ML</span>
                    <a href="mailto:{{ $company->email }}">{{ $company->email }}</a>
                </div>
            @endif
            @if ($company->website)
                <div class="company-contact-item">
                    <span class="company-contact-icon">WB</span>
                    <a href="{{ $company->website }}" target="_blank" rel="noopener">{{ parse_url($company->website, PHP_URL_HOST) ?? $company->website }}</a>
                </div>
            @endif
            @if ($company->phone)
                <div class="company-contact-item">
                    <span class="company-contact-icon">TEL</span>
                    <span>{{ $company->phone }}</span>
                </div>
            @endif
            @if ($company->linkedin_url)
                <div class="company-contact-item">
                    <span class="company-contact-icon">LI</span>
                    <a href="{{ $company->linkedin_url }}" target="_blank" rel="noopener">LinkedIn</a>
                </div>
            @endif
            @if ($company->instagram_url)
                <div class="company-contact-item">
                    <span class="company-contact-icon">IG</span>
                    <a href="{{ $company->instagram_url }}" target="_blank" rel="noopener">Instagram</a>
                </div>
            @endif
            @if ($company->facebook_url)
                <div class="company-contact-item">
                    <span class="company-contact-icon">FB</span>
                    <a href="{{ $company->facebook_url }}" target="_blank" rel="noopener">Facebook</a>
                </div>
            @endif
        </div>
    </section>

    {{-- ── STAT STRIP ── --}}
    <div class="company-stat-strip">
        <div class="company-stat-card">
            <div class="company-stat-label">Prodotti attivi</div>
            <div class="company-stat-value">{{ $activeListings->count() }}</div>
            <div class="company-stat-note">Offerte nello shop</div>
        </div>
        <div class="company-stat-card">
            <div class="company-stat-label">Annunci attivi</div>
            <div class="company-stat-value">{{ $activeAnnouncements->count() }}</div>
            <div class="company-stat-note">Offerte e richieste B2B</div>
        </div>
        <div class="company-stat-card">
            <div class="company-stat-label">Volume ricevuto</div>
            <div class="company-stat-value">{{ ky_format($totalVolume) }} <small style="font-size:13px;font-weight:600;">KY</small></div>
            <div class="company-stat-note">Transazioni totali nel circuito</div>
        </div>
    </div>

    {{-- ── LISTING ── --}}
    @if ($activeListings->isNotEmpty())
        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Shop</span>
                    <h2 class="section-title" style="margin-top:4px;">Prodotti e servizi</h2>
                </div>
                <a href="{{ route('portal.shop') }}?company={{ $company->id }}" class="cta secondary" style="font-size:12px;padding:0 12px;min-height:32px;">Vedi nello shop →</a>
            </div>
            <div class="listing-grid">
                @foreach ($activeListings as $listing)
                    <a href="{{ route('portal.shop.show', $listing) }}" class="listing-card"
                        style="text-decoration:none;color:inherit;{{ $listing->featured ? 'border:2px solid #f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.12);' : '' }}">
                        @if($listing->featured)
                        <div style="background:linear-gradient(90deg,#f59e0b,#fbbf24);padding:4px 12px;font-size:11px;font-weight:800;color:#fff;letter-spacing:.05em;text-transform:uppercase;">
                            ★ In evidenza
                        </div>
                        @endif
                        <div class="listing-card-img">
                            @php $imgs = $listing->image_urls; @endphp
                            @if (count($imgs) > 0)
                                <img src="{{ $imgs[0] }}" alt="{{ $listing->title }}">
                            @else
                                <div class="listing-card-img-placeholder">{{ strtoupper(substr($listing->title, 0, 2)) }}</div>
                            @endif
                        </div>
                        <div class="listing-card-body">
                            <div class="listing-card-category">{{ $listing->category_label }}</div>
                            <div class="listing-card-title">{{ $listing->title }}</div>
                            <div class="listing-card-price">{{ ky_format($listing->price_ky) }} KY</div>
                        </div>
                        <div class="listing-card-footer" style="justify-content:space-between;align-items:center;">
                            <span style="font-size:11px;color:var(--ink-muted);">Paga in KY</span>
                            <span style="font-size:12px;color:var(--primary);font-weight:700;">Acquista →</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── CTA acquisto B2B ── --}}
    @if($activeListings->isNotEmpty() && auth()->id() && auth()->user()->id !== ($company->ownerUser?->id ?? null))
    <div style="background:linear-gradient(135deg,#ede9fe,#e0f2fe);border:1.5px solid rgba(99,102,241,.2);border-radius:14px;padding:20px 24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
        <div style="font-size:32px;">🤝</div>
        <div style="flex:1;min-width:200px;">
            <div style="font-weight:800;font-size:15px;margin-bottom:4px;">Vuoi acquistare da {{ $company->name }}?</div>
            <div style="font-size:13px;color:var(--ink-soft);">Usa i KY del tuo portafoglio per acquistare direttamente i prodotti o invia una richiesta di pagamento.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="{{ route('portal.pay.form') }}?to={{ $account->account_number ?? '' }}" class="cta">Invia pagamento</a>
            <a href="{{ route('portal.shop') }}?company={{ $company->id }}" class="cta secondary">Vai allo shop</a>
        </div>
    </div>
    @endif

    {{-- ── ANNUNCI ── --}}
    @if ($activeAnnouncements->isNotEmpty())
        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Bacheca</span>
                    <h2 class="section-title" style="margin-top:4px;">Annunci B2B</h2>
                </div>
                <a href="{{ route('portal.announcements') }}" class="cta secondary" style="font-size:12px;padding:0 12px;min-height:32px;">Tutti gli annunci →</a>
            </div>
            <div class="announcement-list">
                @foreach ($activeAnnouncements as $ann)
                    <a href="{{ route('portal.announcements.show', $ann) }}" class="announcement-item" style="text-decoration:none;">
                        <div class="announcement-item-header">
                            <span class="ann-type-badge {{ $ann->type === 'offer' ? 'offer' : 'request' }}">
                                {{ $ann->type === 'offer' ? 'Offerta' : 'Richiesta' }}
                            </span>
                            @if ($ann->sector)
                                <span class="chip" style="font-size:10.5px;">{{ $ann->sector }}</span>
                            @endif
                            <span class="subtle" style="font-size:12px;margin-left:auto;">{{ $ann->created_at->locale('it')->diffForHumans() }}</span>
                        </div>
                        <div class="announcement-title">{{ $ann->title }}</div>
                        @if ($ann->body)
                            <div class="announcement-body">{{ Str::limit($ann->body, 140) }}</div>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Stato vuoto --}}
    @if ($activeListings->isEmpty() && $activeAnnouncements->isEmpty())
        <div class="empty-state">
            <div style="font-size:32px;margin-bottom:8px;">📭</div>
            <strong>Nessuna offerta disponibile</strong>
            <p style="margin:6px 0 0;font-size:13px;">Questa azienda non ha ancora pubblicato prodotti o annunci nel circuito.</p>
        </div>
    @endif

</div>
@endsection
