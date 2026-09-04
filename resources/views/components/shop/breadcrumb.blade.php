@props(['items' => []])
{{-- Briciole di pane. $items e' un elenco di ['label' => ..., 'url' => ...];
     l'ultima voce non ha url e viene marcata aria-current. --}}
@if(count($items))
<nav class="shop-crumbs" aria-label="Percorso">
    @foreach($items as $i => $item)
        @if(!empty($item['url']) && $i < count($items) - 1)
            <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
            <span class="sep" aria-hidden="true">›</span>
        @else
            <span aria-current="page">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
@endif
