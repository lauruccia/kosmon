@props(['size' => 34])
{{-- Disegno mostrato al posto della foto quando il prodotto non ne ha.
     Era copiato identico in cinque punti. --}}
<div class="product-media-placeholder">
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
        <path d="M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M8 13a4 4 0 008 0"
              stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
</div>
