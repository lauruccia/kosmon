{{--
    Aggancia public/assets/css/shop.css alla pagina, una volta sola.

    Va messo in cima a ogni vista dello shop. Prima di questo (02/09/2026) ogni
    vista si portava dentro il proprio blocco <style>, con la card prodotto
    copiata quattro volte: qualunque ritocco andava fatto da quattro a dodici
    volte a mano.

    ?v=filemtime: il service worker cachea tutto cio' che sta sotto /assets/ in
    cache-first (public/sw.js, STATIC_PATTERNS). Senza la marca temporale il
    browser servirebbe la versione vecchia per sempre, anche dopo il deploy. Al
    deploy cPanel i file vengono ricopiati e la data cambia da sola: nessuna
    versione da ricordarsi di alzare a mano.

    NIENTE build: e' un file statico servito com'e'. Il deploy cPanel non lancia
    ne' composer install ne' npm run build.
--}}
@once
    @push('head')
        @php
            $shopCssPath = public_path('assets/css/shop.css');
            $shopCssVer  = is_file($shopCssPath) ? filemtime($shopCssPath) : '1';
        @endphp
        <link rel="stylesheet" href="{{ asset('assets/css/shop.css') }}?v={{ $shopCssVer }}">
    @endpush
@endonce
