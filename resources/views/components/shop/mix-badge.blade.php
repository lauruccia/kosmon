@props(['label', 'style' => ''])
{{-- Etichetta del mix KY/EUR, es. "75% KY + 25% EUR".
     Il colore arriva gia' come CSS inline dall'accessor del modello
     (ky_badge_color / effective_ky_badge_color): NON e' una classe Tailwind,
     e' proprio una dichiarazione style — vedi il commento in Listing.php. --}}
<span class="mix-badge" style="{{ $style }}">{{ $label }}</span>
