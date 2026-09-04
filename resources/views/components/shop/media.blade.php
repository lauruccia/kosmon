@props([
    'listing',
    'href'    => null,   // se c'e', la foto e' cliccabile
    'alt'     => null,
    'eager'   => false,  // true solo per la foto grande della scheda prodotto
    'overlay' => null,   // testo sul velo scuro ("Esaurito", "Sospeso"...)
    'class'   => 'product-media',
    'sizes'   => '(max-width: 640px) 50vw, (max-width: 1100px) 33vw, 20vw',
    'placeholderSize' => 34,
])
@php
    /*
     * La foto del prodotto, con le due misure che ImageResizer genera gia'
     * (card 600px e medium 1400px) offerte al browser come srcset.
     *
     * urlRidotto() ricade sull'ORIGINALE quando la miniatura non c'e' — foto
     * caricata prima del meccanismo, foto gia' piu' piccola, oppure GD che non
     * ce l'ha fatta. In quel caso le due URL coincidono, e un srcset con due
     * descrittori diversi sulla stessa immagine direbbe al browser una bugia:
     * percio' srcset si stampa SOLO se le due misure sono davvero diverse.
     */
    $primoPath  = $listing->images[0] ?? null;
    $urlCard    = $listing->card_image_url;
    $urlMedium  = $listing->urlRidotto($primoPath, \App\Services\ImageResizer::MEDIUM);
    $srcset     = ($urlCard && $urlMedium && $urlMedium !== $urlCard)
        ? $urlCard . ' 600w, ' . $urlMedium . ' 1400w'
        : null;
    $tag        = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif class="{{ $class }}">
    @if($urlCard)
        {{-- width/height dichiarati: senza, il browser non sa quanto spazio
             riservare e la griglia salta mentre le foto arrivano. --}}
        <img src="{{ $urlCard }}"
             @if($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
             alt="{{ $alt ?? $listing->title }}"
             width="600" height="375"
             loading="{{ $eager ? 'eager' : 'lazy' }}"
             decoding="{{ $eager ? 'sync' : 'async' }}">
    @else
        <x-shop.placeholder :size="$placeholderSize" />
    @endif

    {{ $slot }}

    @if($overlay)
        <span class="product-media-overlay">{{ $overlay }}</span>
    @endif
</{{ $tag }}>
