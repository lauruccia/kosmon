@props(['message' => null])
{{-- Stato vuoto del catalogo. Lo slot serve per i bottoni ("Rimuovi filtri",
     "Pubblica il primo prodotto"...). --}}
<div class="shop-empty">
    <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.4" aria-hidden="true" focusable="false">
        <path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0"
              stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    @if($message)<p class="subtle">{{ $message }}</p>@endif
    {{ $slot }}
</div>
