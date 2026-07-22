{{-- Nodo ricorsivo dell'albero MLM. Parametri: $node, $mode, $mlmRankMeta, $depth (0 = radice visualizzata) --}}
@php
    $depth = $depth ?? 0;
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
    // Punti cumulativi del sotto-ramo (nodo + downline, vedi
    // MlmTreeService::subtree). Sui figli DIRETTI della radice visualizzata
    // (depth 1 = le "colonne") compare anche il badge sotto il riquadro:
    // e' la distribuzione punti per ramo/colonna richiesta da Laura (22/07).
    // Dal 22/07 pomeriggio bis include anche l'omaggio netto del ramo
    // (branch_granted_points), perche' conta per il requisito "colonne da
    // 300 punti" — mostrato scomposto (badge/popup) cosi' si capisce quanto
    // e' lavoro reale e quanto regalo.
    $branchPoints = $node['branch_points'] ?? $node['points'];
    $branchGranted = $node['branch_granted_points'] ?? 0;
@endphp
<li>
    <a class="mlm-node" href="#" style="--node-color: {{ $meta['color'] }}; --node-tint1: {{ $meta['tint1'] }}; --node-tint2: {{ $meta['tint2'] }};"
       data-name="{{ $node['name'] }}"
       data-initials="{{ $initials }}"
       data-rank-label="{{ $meta['label'] }}"
       data-color="{{ $meta['color'] }}"
       data-points="{{ mlm_points_format($node['points']) }}"
       data-branch-points="{{ mlm_points_format($branchPoints) }}"
       @if($branchGranted !== 0)
       data-branch-granted="{{ sprintf('%+d', $branchGranted) }}"
       @endif
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
    @if($depth === 1)
        <span class="mlm-branch-badge" title="Punti totali di questa colonna (reali + omaggio): {{ $node['name'] }} + tutta la sua downline. Le qualifiche piu' alte richiedono colonne da 300 punti.">Ramo: {{ mlm_points_format($branchPoints) }} pt @if($branchGranted !== 0)<span class="mlm-branch-badge-granted">({{ sprintf('%+d', $branchGranted) }} omaggio)</span>@endif</span>
    @endif
    @if(count($node['children']))
        <ul>
            @foreach($node['children'] as $child)
                @include('partials.mlm-tree-node', ['node' => $child, 'mode' => $mode, 'mlmRankMeta' => $mlmRankMeta, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
