{{-- Nodo ricorsivo dell'albero MLM. Parametri: $node, $mode, $mlmRankMeta --}}
@php
    $meta = $mlmRankMeta[$node['rank']] ?? $mlmRankMeta['start'];
    $words = preg_split('/\s+/', trim($node['name']));
    $initials = count($words) >= 2
        ? mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1))
        : mb_strtoupper(mb_substr($node['name'], 0, 2));
    // Punti omaggio: visibili sempre all'admin, nel portale SOLO sul proprio
    // nodo (un agente non vede i regali fatti ad altri). L'attributo non
    // viene proprio renderizzato negli altri casi, così non è nemmeno
    // ispezionabile nell'HTML.
    $grantedVisible = ($node['granted_points'] ?? 0) !== 0
        && ((($mode ?? 'portal') === 'admin') || auth()->id() === $node['id']);
@endphp
<li>
    <a class="mlm-node" href="#" style="--node-color: {{ $meta['color'] }}; --node-tint1: {{ $meta['tint1'] }}; --node-tint2: {{ $meta['tint2'] }};"
       data-name="{{ $node['name'] }}"
       data-initials="{{ $initials }}"
       data-rank-label="{{ $meta['label'] }}"
       data-color="{{ $meta['color'] }}"
       data-points="{{ mlm_points_format($node['points']) }}"
       data-basiq="{{ !empty($node['basiq']) ? '1' : '' }}"
       @if($grantedVisible)
       data-granted="{{ sprintf('%+d', $node['granted_points']) }}"
       @endif
       data-agents="{{ $node['agents_count'] }}"
       data-clients="{{ $node['clients_count'] }}"
       @if(($mode ?? 'portal') === 'admin')
       data-tree-url="{{ route('admin.mlm.tree', $node['id']) }}"
       data-show-url="{{ route('admin.mlm.show', $node['id']) }}"
       @endif>
        <span class="mlm-node-avatar">{{ $initials }}</span>
        <span class="mlm-node-text">
            <span class="mlm-node-name" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
            <span class="mlm-node-points">{{ $meta['label'] }} · {{ mlm_points_format($node['points']) }} pt</span>
        </span>
    </a>
    @if(count($node['children']))
        <ul>
            @foreach($node['children'] as $child)
                @include('partials.mlm-tree-node', ['node' => $child, 'mode' => $mode, 'mlmRankMeta' => $mlmRankMeta])
            @endforeach
        </ul>
    @endif
</li>
