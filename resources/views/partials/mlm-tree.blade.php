{{-- Albero MLM condiviso (portale "Struttura" e admin "Albero agenti").
     Parametri: $tree (array annidato da MlmTreeService::subtree), $mode ('portal'|'admin') --}}
@php
    $mlmRankMeta = [
        'start'      => ['label' => 'Start',      'color' => '#0284c7', 'tint1' => '#e0f2fe', 'tint2' => '#f0f9ff'],
        'basic'      => ['label' => 'Basic',      'color' => '#16a34a', 'tint1' => '#dcfce7', 'tint2' => '#f0fdf4'],
        'key'        => ['label' => 'Key',        'color' => '#b45309', 'tint1' => '#fef3c7', 'tint2' => '#fffbeb'],
        'senior'     => ['label' => 'Senior',     'color' => '#ea580c', 'tint1' => '#ffedd5', 'tint2' => '#fff7ed'],
        'top'        => ['label' => 'Top',        'color' => '#dc2626', 'tint1' => '#fee2e2', 'tint2' => '#fef2f2'],
        'supervisor' => ['label' => 'SuperVisor', 'color' => '#7c3aed', 'tint1' => '#ede9fe', 'tint2' => '#f5f3ff'],
        'manager'    => ['label' => 'Manager',    'color' => '#374151', 'tint1' => '#e5e7eb', 'tint2' => '#f3f4f6'],
    ];
@endphp

<style>
.mlm-tree-wrap { border:1px solid var(--line,#e2e8f0); border-radius:16px; background:#fff; padding:16px; }
.mlm-tree-flex { display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap; }

.mlm-tree-legend { flex:0 0 178px; border:1px solid var(--line,#e2e8f0); border-radius:12px; padding:14px; background:var(--surface-soft,#f8fafc); }
.mlm-tree-legend-title { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-muted); margin:0 0 10px; }
.mlm-tree-legend-row { display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:600; color:var(--ink); padding:5px 0; }
.mlm-tree-legend-row i { width:13px; height:13px; border-radius:4px; display:inline-block; flex:0 0 auto; }

.mlm-tree-scroll { flex:1 1 420px; min-width:0; overflow-x:auto; padding:4px 4px 30px; }
.mlm-tree ul { display:flex; justify-content:center; padding:30px 0 0; margin:0; position:relative; }
.mlm-tree > ul { padding-top:0; }
.mlm-tree li { list-style:none; position:relative; padding:30px 14px 0; display:flex; flex-direction:column; align-items:center; }
.mlm-tree > ul > li { padding-top:0; }
.mlm-tree li::before, .mlm-tree li::after {
    content:''; position:absolute; top:0; right:50%;
    border-top:2px solid var(--tree-line, #94a3b8); width:50%; height:30px;
}
.mlm-tree li::after { right:auto; left:50%; border-left:2px solid var(--tree-line, #94a3b8); }
.mlm-tree li:only-child::before, .mlm-tree li:only-child::after { border-top:none; }
.mlm-tree li:first-child::before, .mlm-tree li:last-child::after { border:none; }
.mlm-tree li:last-child::before { border-right:2px solid var(--tree-line, #94a3b8); border-radius:0 8px 0 0; }
.mlm-tree li:first-child::after { border-radius:8px 0 0 0; }
.mlm-tree > ul > li::before, .mlm-tree > ul > li::after { display:none; }
.mlm-tree ul ul::before {
    content:''; position:absolute; top:0; left:50%;
    border-left:2px solid var(--tree-line, #94a3b8); height:30px; width:0;
}

.mlm-node {
    display:flex; flex-direction:row; align-items:center; gap:10px; cursor:pointer; text-decoration:none;
    min-width:172px; padding:9px 14px; border-radius:10px;
    background:linear-gradient(135deg, var(--node-tint1, #f1f5f9), var(--node-tint2, #f8fafc));
    border:1px solid var(--node-color, #94a3b8); box-shadow:0 1px 3px rgba(15,23,42,.08);
    transition:transform .15s ease, box-shadow .15s ease;
}
.mlm-node:hover { transform:translateY(-2px); box-shadow:0 8px 18px -6px rgba(15,23,42,.25); }

.mlm-node-avatar {
    width:34px; height:34px; min-width:34px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:800; background:var(--node-color, #64748b); color:#fff;
    box-shadow:0 1px 2px rgba(0,0,0,.2);
}

.mlm-node-text { display:flex; flex-direction:column; align-items:flex-start; min-width:0; }

.mlm-node-name {
    font-size:12.5px; font-weight:700; color:var(--ink); max-width:118px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}

.mlm-node-points { font-size:10.5px; font-weight:600; color:var(--node-color, #64748b); }

#mlmNodeModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1000; align-items:center; justify-content:center; padding:16px; }
#mlmNodeModal .mlm-modal-card { background:#fff; border-radius:16px; max-width:420px; width:100%; padding:0; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.25); }
#mlmNodeModal .mlm-modal-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--line); }
#mlmNodeModal .mlm-modal-head strong { font-size:15px; }
#mlmNodeModal .mlm-modal-close { border:none; background:none; font-size:20px; line-height:1; cursor:pointer; color:var(--ink-muted); }
#mlmNodeModal .mlm-modal-hero { display:flex; align-items:center; gap:14px; margin:16px; padding:14px 16px; border-radius:12px; background:var(--surface-soft,#f8fafc); border:1px solid rgba(15,23,42,.06); }
#mlmNodeModal .mlm-modal-avatar {
    width:52px; height:52px; min-width:52px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    font-size:17px; font-weight:800; color:#fff; background:#0284c7; box-shadow:0 4px 10px -2px rgba(15,23,42,.35);
}
#mlmNodeModal .mlm-modal-hero .mlm-modal-name { font-size:17px; font-weight:800; color:var(--ink); }
#mlmNodeModal .mlm-modal-hero .mlm-modal-rank { font-size:13px; color:var(--ink-muted); margin-top:2px; }
#mlmNodeModal table { width:calc(100% - 32px); margin:0 16px 16px; border-collapse:collapse; font-size:13px; }
#mlmNodeModal th, #mlmNodeModal td { border:1px solid var(--line); padding:8px 10px; text-align:center; }
#mlmNodeModal th { background:var(--surface-soft,#f8fafc); font-weight:700; }
#mlmNodeModal .mlm-modal-actions { display:flex; gap:8px; margin:0 16px 18px; }
#mlmNodeModal .mlm-modal-actions a { flex:1; text-align:center; padding:9px 10px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; }
#mlmNodeModal .mlm-modal-actions .mlm-btn-tree { background:var(--primary,#0c4a86); color:#fff; }
#mlmNodeModal .mlm-modal-actions .mlm-btn-show { background:var(--surface-soft,#f1f5f9); color:var(--ink); border:1px solid var(--line); }
</style>

<div class="mlm-tree-wrap">
<div class="mlm-tree-flex">
    <div class="mlm-tree-scroll">
        <div class="mlm-tree">
            <ul>
                @include('partials.mlm-tree-node', ['node' => $tree, 'mode' => $mode, 'mlmRankMeta' => $mlmRankMeta])
            </ul>
        </div>
    </div>

    <div class="mlm-tree-legend">
        <p class="mlm-tree-legend-title">Legenda qualifiche</p>
        @foreach($mlmRankMeta as $meta)
            <div class="mlm-tree-legend-row"><i style="background:{{ $meta['color'] }};"></i>{{ $meta['label'] }}</div>
        @endforeach
    </div>
</div>
</div>

<div id="mlmNodeModal">
    <div class="mlm-modal-card">
        <div class="mlm-modal-head">
            <strong>Dettagli utente</strong>
            <button type="button" class="mlm-modal-close" data-close aria-label="Chiudi">&times;</button>
        </div>
        <div class="mlm-modal-hero">
            <div class="mlm-modal-avatar" id="mlmModalAvatar">—</div>
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
            document.getElementById('mlmModalRank').textContent = (node.dataset.rankLabel || '') + ' ★ ' + (node.dataset.points || '0') + ' pt';
            document.getElementById('mlmModalAgents').textContent = node.dataset.agents || '0';
            document.getElementById('mlmModalClients').textContent = node.dataset.clients || '0';
            var avatar = document.getElementById('mlmModalAvatar');
            avatar.textContent = node.dataset.initials || '';
            avatar.style.background = node.dataset.color || '#0284c7';

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
