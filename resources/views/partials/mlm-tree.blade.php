{{-- Albero MLM condiviso (portale "Struttura" e admin "Albero agenti").
     Parametri: $tree (array annidato da MlmTreeService::subtree), $mode ('portal'|'admin') --}}
@php
    $mlmRankMeta = [
        'start'      => ['label' => 'Start',      'color' => '#38bdf8'],
        'basic'      => ['label' => 'Basic',      'color' => '#16a34a'],
        'key'        => ['label' => 'Key',        'color' => '#eab308'],
        'senior'     => ['label' => 'Senior',     'color' => '#f97316'],
        'top'        => ['label' => 'Top',        'color' => '#dc2626'],
        'supervisor' => ['label' => 'SuperVisor', 'color' => '#8b5cf6'],
        'manager'    => ['label' => 'Manager',    'color' => '#0f172a'],
    ];
@endphp

<style>
.mlm-tree-legend { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:14px; }
.mlm-tree-legend span { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--ink-muted); }
.mlm-tree-legend i { width:10px; height:10px; border-radius:50%; display:inline-block; }

.mlm-tree-scroll { overflow-x:auto; padding:6px 4px 26px; }
.mlm-tree ul { display:flex; justify-content:center; padding:26px 0 0; margin:0; position:relative; }
.mlm-tree > ul { padding-top:0; }
.mlm-tree li { list-style:none; position:relative; padding:26px 12px 0; display:flex; flex-direction:column; align-items:center; }
.mlm-tree > ul > li { padding-top:0; }
.mlm-tree li::before, .mlm-tree li::after {
    content:''; position:absolute; top:0; right:50%;
    border-top:1px solid var(--line,#d7dce3); width:50%; height:26px;
}
.mlm-tree li::after { right:auto; left:50%; border-left:1px solid var(--line,#d7dce3); }
.mlm-tree li:only-child::before, .mlm-tree li:only-child::after { border-top:none; }
.mlm-tree li:first-child::before, .mlm-tree li:last-child::after { border-top:none; }
.mlm-tree li:last-child::before { border-right:1px solid var(--line,#d7dce3); border-radius:0 6px 0 0; }
.mlm-tree li:first-child::after { border-radius:6px 0 0 0; }
.mlm-tree > ul > li::before, .mlm-tree > ul > li::after { display:none; }
.mlm-tree ul ul::before {
    content:''; position:absolute; top:0; left:50%;
    border-left:1px solid var(--line,#d7dce3); height:26px; width:0;
}

.mlm-node { display:flex; flex-direction:column; align-items:center; gap:4px; cursor:pointer; text-decoration:none; min-width:74px; }
.mlm-node svg { width:46px; height:46px; filter:drop-shadow(0 1px 2px rgba(0,0,0,.18)); transition:transform .12s; }
.mlm-node:hover svg { transform:scale(1.12); }
.mlm-node-name { font-size:12px; font-weight:600; color:var(--ink); max-width:96px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

#mlmNodeModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1000; align-items:center; justify-content:center; padding:16px; }
#mlmNodeModal .mlm-modal-card { background:#fff; border-radius:14px; max-width:420px; width:100%; padding:0; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.25); }
#mlmNodeModal .mlm-modal-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--line); }
#mlmNodeModal .mlm-modal-head strong { font-size:15px; }
#mlmNodeModal .mlm-modal-close { border:none; background:none; font-size:20px; line-height:1; cursor:pointer; color:var(--ink-muted); }
#mlmNodeModal .mlm-modal-hero { display:flex; align-items:center; gap:14px; margin:16px; padding:14px 16px; border-radius:10px; background:#fdf6dd; }
#mlmNodeModal .mlm-modal-hero svg { width:44px; height:44px; }
#mlmNodeModal .mlm-modal-hero .mlm-modal-name { font-size:17px; font-weight:800; color:var(--ink); }
#mlmNodeModal .mlm-modal-hero .mlm-modal-rank { font-size:13px; color:var(--ink-muted); }
#mlmNodeModal table { width:calc(100% - 32px); margin:0 16px 16px; border-collapse:collapse; font-size:13px; }
#mlmNodeModal th, #mlmNodeModal td { border:1px solid var(--line); padding:8px 10px; text-align:center; }
#mlmNodeModal th { background:var(--surface-soft,#f8fafc); font-weight:700; }
#mlmNodeModal .mlm-modal-actions { display:flex; gap:8px; margin:0 16px 18px; }
#mlmNodeModal .mlm-modal-actions a { flex:1; text-align:center; padding:9px 10px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; }
#mlmNodeModal .mlm-modal-actions .mlm-btn-tree { background:var(--primary,#0c4a86); color:#fff; }
#mlmNodeModal .mlm-modal-actions .mlm-btn-show { background:var(--surface-soft,#f1f5f9); color:var(--ink); border:1px solid var(--line); }
</style>

<div class="mlm-tree-legend">
    @foreach($mlmRankMeta as $meta)
        <span><i style="background:{{ $meta['color'] }};"></i>{{ $meta['label'] }}</span>
    @endforeach
</div>

<div class="mlm-tree-scroll">
    <div class="mlm-tree">
        <ul>
            @include('partials.mlm-tree-node', ['node' => $tree, 'mode' => $mode, 'mlmRankMeta' => $mlmRankMeta])
        </ul>
    </div>
</div>

<div id="mlmNodeModal">
    <div class="mlm-modal-card">
        <div class="mlm-modal-head">
            <strong>Dettagli utente</strong>
            <button type="button" class="mlm-modal-close" data-close aria-label="Chiudi">&times;</button>
        </div>
        <div class="mlm-modal-hero">
            <svg viewBox="0 0 24 24" id="mlmModalIcon" fill="currentColor" style="color:#38bdf8;">
                <circle cx="12" cy="7" r="4.5"/><path d="M12 13c-5 0-8 2.6-8 6v1.5h16V19c0-3.4-3-6-8-6z"/>
            </svg>
            <div>
                <div class="mlm-modal-name" id="mlmModalName">—</div>
                <div class="mlm-modal-rank" id="mlmModalRank">—</div>
            </div>
        </div>
        <table>
            <thead><tr><th></th><th>Agenti</th><th>Clienti</th></tr></thead>
            <tbody><tr><td style="font-weight:700;">Diretti</td><td id="mlmModalAgents">0</td><td id="mlmModalClients">0</td></tr></tbody>
        </table>
        @if(($mode ?? 'portal') === 'admin')
        <div class="mlm-modal-actions">
            <a href="#" id="mlmModalTreeBtn" class="mlm-btn-tree">Vedi il suo albero</a>
            <a href="#" id="mlmModalShowBtn" class="mlm-btn-show">Scheda agente</a>
        </div>
        @endif
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('mlmNodeModal');
    if (!modal) return;

    document.querySelectorAll('.mlm-node').forEach(function (node) {
        node.addEventListener('click', function (event) {
            event.preventDefault();
            document.getElementById('mlmModalName').textContent = node.dataset.name || '';
            document.getElementById('mlmModalRank').textContent = (node.dataset.rankLabel || '') + ' \u2605 ' + (node.dataset.points || '0');
            document.getElementById('mlmModalAgents').textContent = node.dataset.agents || '0';
            document.getElementById('mlmModalClients').textContent = node.dataset.clients || '0';
            document.getElementById('mlmModalIcon').style.color = node.dataset.color || '#38bdf8';

            var treeBtn = document.getElementById('mlmModalTreeBtn');
            var showBtn = document.getElementById('mlmModalShowBtn');
            if (treeBtn) treeBtn.href = node.dataset.treeUrl || '#';
            if (showBtn) showBtn.href = node.dataset.showUrl || '#';

            modal.style.display = 'flex';
        });
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal || event.target.closest('[data-close]')) {
            modal.style.display = 'none';
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') modal.style.display = 'none';
    });
})();
</script>
