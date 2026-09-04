@props(['amount', 'old' => null, 'currency' => 'KY'])
{{-- Prezzo di un prodotto. $amount e $old sono in centesimi di KY (interi).
     $old si stampa barrato solo se c'e' davvero ed e' piu' alto. --}}
<span class="product-price">{{ ky_format($amount) }} <small>{{ $currency }}</small></span>
@if($old !== null && (int) $old > (int) $amount)
    <span class="product-price-old">{{ ky_format($old) }} {{ $currency }}</span>
@endif
