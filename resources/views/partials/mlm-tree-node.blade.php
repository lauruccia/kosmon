{{-- Nodo ricorsivo dell'albero MLM. Parametri: $node, $mode, $mlmRankMeta --}}
@php
    $meta = $mlmRankMeta[$node['rank']] ?? $mlmRankMeta['start'];
@endphp
<li>
    <a class="mlm-node" href="#"
       data-name="{{ $node['name'] }}"
       data-rank-label="{{ $meta['label'] }}"
       data-color="{{ $meta['color'] }}"
       data-points="{{ $node['points'] }}"
       data-agents="{{ $node['agents_count'] }}"
       data-clients="{{ $node['clients_count'] }}"
       @if(($mode ?? 'portal') === 'admin')
       data-tree-url="{{ route('admin.mlm.tree', $node['id']) }}"
       data-show-url="{{ route('admin.mlm.show', $node['id']) }}"
       @endif>
        <svg viewBox="0 0 24 24" fill="{{ $meta['color'] }}">
            <circle cx="12" cy="7" r="4.5"/><path d="M12 13c-5 0-8 2.6-8 6v1.5h16V19c0-3.4-3-6-8-6z"/>
        </svg>
        <span class="mlm-node-name" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
    </a>
    @if(count($node['children']))
        <ul>
            @foreach($node['children'] as $child)
                @include('partials.mlm-tree-node', ['node' => $child, 'mode' => $mode, 'mlmRankMeta' => $mlmRankMeta])
            @endforeach
        </ul>
    @endif
</li>
