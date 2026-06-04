@extends('layouts.portal')

@section('content')
<div style="margin-bottom:16px;">
    <a href="{{ route('portal.shop') }}" style="color:#64748b;text-decoration:none;font-size:14px;">← Torna allo shop</a>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

    {{-- Colonna principale --}}
    <div class="stack">
        <section class="card light-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                <div>
                    <span class="eyebrow">{{ $listing->category_label }}</span>
                    <h2 style="font-size:26px;font-weight:700;color:#10263d;margin:6px 0 0;">{{ $listing->title }}</h2>
                    <div class="subtle" style="margin-top:6px;">
                        Pubblicato da <strong>{{ $listing->company->name }}</strong>
                        · {{ $listing->created_at->locale('it')->isoFormat('D MMM YYYY') }}
                        · {{ $listing->views_count }} visualizzazioni
                    </div>
                </div>
                @if($listing->featured)
                    <span class="pill warn">★ In evidenza</span>
                @endif
            </div>

            {{-- Galleria immagini --}}
            @php $urls = $listing->image_urls; @endphp
            @if(count($urls) > 0)
            <div style="margin-top:20px;">
                {{-- Immagine principale --}}
                <div style="position:relative;border-radius:12px;overflow:hidden;background:#f1f5f9;cursor:zoom-in;" onclick="openLightbox(0)">
                    <img id="gallery-main"
                         src="{{ $urls[0] }}"
                         alt="{{ $listing->title }}"
                         style="width:100%;max-height:420px;object-fit:cover;display:block;">
                    @if(count($urls) > 1)
                    <div style="position:absolute;bottom:10px;right:14px;background:rgba(0,0,0,.5);color:#fff;font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;">
                        1 / {{ count($urls) }}
                    </div>
                    @endif
                </div>
                {{-- Thumbnail strip --}}
                @if(count($urls) > 1)
                <div style="display:flex;gap:8px;margin-top:10px;overflow-x:auto;padding-bottom:4px;">
                    @foreach($urls as $i => $url)
                    <img src="{{ $url }}"
                         alt="Foto {{ $i + 1 }}"
                         onclick="selectThumb({{ $i }})"
                         id="thumb-{{ $i }}"
                         style="width:72px;height:72px;object-fit:cover;border-radius:8px;cursor:pointer;border:2.5px solid {{ $i === 0 ? '#0c4a86' : '#e2e8f0' }};flex-shrink:0;transition:border-color .15s;">
                    @endforeach
                </div>
                @endif
            </div>
            @endif

            <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0;">

            <div style="font-size:15px;line-height:1.8;color:#334155;white-space:pre-line;">{{ $listing->description }}</div>

            @if($listing->delivery_note)
            <div style="margin-top:20px;background:#eff6ff;border-left:3px solid #0c4a86;border-radius:8px;padding:12px 16px;font-size:14px;color:#1e3a5f;">
                🚚 <strong>Consegna/erogazione:</strong> {{ $listing->delivery_note }}
            </div>
            @endif

            @if($listing->expires_at)
            <div style="margin-top:12px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:8px;padding:12px 16px;font-size:14px;color:#78350f;">
                ⏱ <strong>Offerta valida fino al:</strong> {{ $listing->expires_at->locale('it')->isoFormat('D MMMM YYYY') }}
            </div>
            @endif
        </section>

        @if($related->isNotEmpty())
        <section class="card light-card">
            <div class="section-head">
                <div><span class="eyebrow">Stessa categoria</span><h3 class="section-title">Prodotti correlati</h3></div>
            </div>
            <div style="display:flex;flex-direction:column;gap:12px;">
                @foreach($related as $rel)
                <article style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f1f5f9;">
                    <div>
                        <div style="font-weight:600;font-size:14px;color:#10263d;">{{ $rel->title }}</div>
                        <div class="subtle">{{ $rel->company->name }}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <strong style="color:#0c4a86;">{{ ky_format($rel->price_ky) }} KY</strong>
                        <a href="{{ route('portal.shop.show', $rel) }}" class="cta secondary" style="padding:6px 14px;font-size:13px;">Vedi</a>
                    </div>
                </article>
                @endforeach
            </div>
        </section>
        @endif
    </div>

    {{-- Sidebar acquisto --}}
    <div class="stack">
        <section class="card account-hero card-pad">
            <div class="k-tag">Acquisto nel circuito KMoney</div>
            <div style="font-size:36px;font-weight:300;color:#0c4a86;letter-spacing:.06em;margin:16px 0 4px;">
                {{ ky_format($listing->price_ky) }}
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <span style="font-size:14px;color:#64748b;">KY (KMoney)</span>
                <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:14px;{{ $listing->ky_badge_color }}">
                    {{ $listing->ky_badge_label }}
                </span>
            </div>
            @if($listing->ky_percentage < 100)
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#0369a1;">
                <strong>Pagamento misto:</strong>
                {{ $listing->ky_amount }} KY pagati nel circuito
                + {{ 100 - $listing->ky_percentage }}% ({{ number_format($listing->euro_amount, 2, ',', '.') }} KY equiv.) saldati in EUR direttamente col venditore.
            </div>
            @endif

            <div class="metric">
                <div class="metric-label">Venditore</div>
                <div class="metric-value" style="font-size:16px;">{{ $listing->company->name }}</div>
            </div>
            @if($listing->contact_info)
            <div class="metric">
                <div class="metric-label">Contatto</div>
                <div class="metric-value" style="font-size:14px;">{{ $listing->contact_info }}</div>
            </div>
            @endif
            <div class="metric">
                <div class="metric-label">Il tuo saldo</div>
                <div class="metric-value">{{ ky_format($currentAccount->saldoDisponibile()) }} KY</div>
            </div>

            <div class="quick-actions" style="margin-top:20px;">
                @if($currentAccount->saldoDisponibile() >= $listing->price_ky)
                    <a class="cta"
                       href="{{ route('portal.pay.form') }}?to_company_id={{ $listing->company_id }}&amount={{ $listing->price_ky }}&description={{ urlencode('Acquisto: ' . $listing->title) }}"
                       style="width:100%;text-align:center;">
                        Paga {{ ky_format($listing->price_ky) }} KY
                    </a>
                @else
                    <button disabled class="cta" style="width:100%;text-align:center;opacity:.5;cursor:not-allowed;">
                        Saldo insufficiente
                    </button>
                @endif
            </div>

            @if($currentAccount->saldoDisponibile() < $listing->price_ky)
            <p style="font-size:12px;color:#94a3b8;margin-top:10px;text-align:center;">
                Ti mancano {{ ky_format($listing->price_ky - $currentAccount->saldoDisponibile()) }} KY
            </p>
            @endif
        </section>

        @if(auth()->user()->company_id === $listing->company_id || auth()->user()->is_super_admin)
        <section class="card light-card">
            <h3 class="card-title">Gestione prodotto</h3>
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px;">
                <a href="{{ route('portal.shop.edit', $listing) }}" class="cta secondary" style="text-align:center;">Modifica</a>
                <form method="POST" action="{{ route('portal.shop.destroy', $listing) }}" onsubmit="return confirm('Rimuovere questo prodotto dallo shop?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="width:100%;padding:10px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:10px;font-weight:600;cursor:pointer;">Rimuovi</button>
                </form>
            </div>
        </section>
        @endif
    </div>
</div>

{{-- Lightbox --}}
@php $urls = $listing->image_urls; @endphp
@if(count($urls) > 0)
<div id="lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center;" onclick="closeLightbox(event)">
    <button onclick="lightboxPrev(event)" style="position:absolute;left:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:48px;height:48px;border-radius:50%;cursor:pointer;">‹</button>
    <img id="lightbox-img" src="" alt="" style="max-width:90vw;max-height:88vh;object-fit:contain;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.6);">
    <button onclick="lightboxNext(event)" style="position:absolute;right:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:48px;height:48px;border-radius:50%;cursor:pointer;">›</button>
    <button onclick="closeLightbox()" style="position:absolute;top:16px;right:20px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:20px;width:40px;height:40px;border-radius:50%;cursor:pointer;">✕</button>
    <div id="lightbox-counter" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,.7);font-size:13px;"></div>
</div>

<script>
(function () {
    const urls  = @json($urls);
    let current = 0;

    window.openLightbox = function (idx) {
        current = idx;
        updateLightbox();
        document.getElementById('lightbox').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };
    window.closeLightbox = function (e) {
        if (e && e.target !== document.getElementById('lightbox')) return;
        document.getElementById('lightbox').style.display = 'none';
        document.body.style.overflow = '';
    };
    window.lightboxPrev = function (e) { e.stopPropagation(); current = (current - 1 + urls.length) % urls.length; updateLightbox(); };
    window.lightboxNext = function (e) { e.stopPropagation(); current = (current + 1) % urls.length; updateLightbox(); };

    function updateLightbox() {
        document.getElementById('lightbox-img').src = urls[current];
        document.getElementById('lightbox-counter').textContent = urls.length > 1 ? `${current + 1} / ${urls.length}` : '';
    }

    window.selectThumb = function (idx) {
        current = idx;
        document.getElementById('gallery-main').src = urls[idx];
        const counter = document.querySelector('#gallery-main + div');
        if (counter) counter.textContent = `${idx + 1} / ${urls.length}`;
        document.querySelectorAll('[id^="thumb-"]').forEach((el, i) => {
            el.style.borderColor = i === idx ? '#0c4a86' : '#e2e8f0';
        });
    };

    document.addEventListener('keydown', function (e) {
        const lb = document.getElementById('lightbox');
        if (lb.style.display === 'none') return;
        if (e.key === 'ArrowLeft')  lightboxPrev(e);
        if (e.key === 'ArrowRight') lightboxNext(e);
        if (e.key === 'Escape')     { lb.style.display='none'; document.body.style.overflow=''; }
    });
})();
</script>
@endif
@endsection
