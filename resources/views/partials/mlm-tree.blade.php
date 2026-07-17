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

.mlm-tree-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
.mlm-tree-zoom-controls { display:flex; align-items:center; gap:4px; background:var(--surface-soft,#f8fafc); border:1px solid var(--line,#e2e8f0); border-radius:10px; padding:4px; }
.mlm-zoom-btn { width:28px; height:28px; border-radius:7px; border:1px solid var(--line,#e2e8f0); background:#fff; color:var(--ink); font-size:16px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; line-height:1; user-select:none; touch-action:manipulation; }
.mlm-zoom-btn:hover { background:var(--surface-soft,#f1f5f9); }
.mlm-zoom-reset { width:auto; padding:0 10px; font-size:11.5px; font-weight:700; }
.mlm-zoom-level { min-width:42px; text-align:center; font-size:12px; font-weight:700; color:var(--ink-muted); user-select:none; }
.mlm-tree-hint { margin:0; font-size:11.5px; color:var(--ink-muted); }
@media (max-width:640px) { .mlm-tree-hint { display:none; } }

.mlm-tree-legend { flex:0 0 178px; border:1px solid var(--line,#e2e8f0); border-radius:12px; padding:14px; background:var(--surface-soft,#f8fafc); cursor:pointer; user-select:none; }
.mlm-tree-legend:hover { border-color:#cbd5e1; }
.mlm-tree-legend-title { display:flex; align-items:center; justify-content:space-between; gap:6px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-muted); margin:0 0 10px; }
.mlm-tree-legend-chevron { font-size:10px; line-height:1; color:var(--ink-muted); transition:transform .2s ease; }
.mlm-tree-legend.is-collapsed { flex:0 0 auto; align-self:stretch; padding:12px 9px; }
.mlm-tree-legend.is-collapsed .mlm-tree-legend-title { margin:0; justify-content:center; }
.mlm-tree-legend.is-collapsed .mlm-tree-legend-label { display:none; }
.mlm-tree-legend.is-collapsed .mlm-tree-legend-row { display:none; }
.mlm-tree-legend.is-collapsed .mlm-tree-legend-chevron { transform:rotate(180deg); }
@media (max-width:640px) { .mlm-tree-legend.is-collapsed { align-self:auto; } }
.mlm-tree-legend-row { display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:600; color:var(--ink); padding:5px 0; }
.mlm-tree-legend-row i { width:13px; height:13px; border-radius:4px; display:inline-block; flex:0 0 auto; }
@media (max-width:640px) { .mlm-tree-legend { flex-basis:100%; order:2; } }

.mlm-tree-viewport {
    flex:1 1 420px; min-width:0; overflow:hidden; position:relative;
    border-radius:12px; border:1px solid var(--line,#e2e8f0); background:var(--surface-soft,#f8fafc);
    height:min(62vh,560px); touch-action:none; cursor:grab;
}
.mlm-tree-viewport.is-panning { cursor:grabbing; }
@media (max-width:640px) { .mlm-tree-viewport { height:52vh; min-height:340px; } }

.mlm-tree-zoomable { display:inline-block; transform-origin:0 0; will-change:transform; padding:26px 30px; }

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
<div class="mlm-tree-toolbar">
    <div class="mlm-tree-zoom-controls">
        <button type="button" class="mlm-zoom-btn" data-zoom-out aria-label="Riduci zoom">&minus;</button>
        <span class="mlm-zoom-level" data-zoom-level>100%</span>
        <button type="button" class="mlm-zoom-btn" data-zoom-in aria-label="Aumenta zoom">+</button>
        <button type="button" class="mlm-zoom-btn mlm-zoom-reset" data-zoom-reset>Adatta</button>
    </div>
    <p class="mlm-tree-hint">Trascina per spostarti &middot; rotellina o pizzica per zoomare</p>
</div>
<div class="mlm-tree-flex">
    <div class="mlm-tree-viewport" data-tree-viewport>
        <div class="mlm-tree-zoomable" data-tree-zoomable>
            <div class="mlm-tree">
                <ul>
                    @include('partials.mlm-tree-node', ['node' => $tree, 'mode' => $mode, 'mlmRankMeta' => $mlmRankMeta])
                </ul>
            </div>
        </div>
    </div>

    <div class="mlm-tree-legend" data-tree-legend role="button" tabindex="0" aria-expanded="true" title="Clicca per nascondere o mostrare la legenda">
        <p class="mlm-tree-legend-title"><span class="mlm-tree-legend-label">Legenda qualifiche</span> <span class="mlm-tree-legend-chevron">&#9656;</span></p>
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
            <div class="mlm-modal-avatar" id="mlmModalAvatar">&mdash;</div>
            <div>
                <div class="mlm-modal-name" id="mlmModalName">&mdash;</div>
                <div class="mlm-modal-rank" id="mlmModalRank">&mdash;</div>
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
    // ---- Legenda: click per nasconderla/mostrarla (piu' spazio all'albero) ------
    var legend = document.querySelector('[data-tree-legend]');
    if (legend) {
        var toggleLegend = function () {
            var collapsed = legend.classList.toggle('is-collapsed');
            legend.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        };
        legend.addEventListener('click', toggleLegend);
        legend.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleLegend();
            }
        });
    }

    var modal = document.getElementById('mlmNodeModal');
    if (!modal) return;

    var suppressClick = false;

    document.querySelectorAll('.mlm-node').forEach(function (node) {
        node.addEventListener('click', function (event) {
            event.preventDefault();
            if (suppressClick) return;
            document.getElementById('mlmModalName').textContent = node.dataset.name || '';
            document.getElementById('mlmModalRank').textContent = (node.dataset.rankLabel || '') + ' \u2605 ' + (node.dataset.points || '0') + ' pt';
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

    // ---- Zoom / pan dell'albero -------------------------------------------------
    var viewport = document.querySelector('[data-tree-viewport]');
    var zoomable = document.querySelector('[data-tree-zoomable]');
    if (!viewport || !zoomable) return;

    var MIN_SCALE = 0.3, MAX_SCALE = 2.5;
    var scale = 1, panX = 0, panY = 0;
    var levelEl = document.querySelector('[data-zoom-level]');

    function clampScale(s) { return Math.min(MAX_SCALE, Math.max(MIN_SCALE, s)); }

    function apply() {
        zoomable.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + scale + ')';
        if (levelEl) levelEl.textContent = Math.round(scale * 100) + '%';
    }

    function zoomTo(clientX, clientY, newScale) {
        newScale = clampScale(newScale);
        var rect = viewport.getBoundingClientRect();
        var offsetX = clientX - rect.left;
        var offsetY = clientY - rect.top;
        panX = offsetX - (offsetX - panX) * (newScale / scale);
        panY = offsetY - (offsetY - panY) * (newScale / scale);
        scale = newScale;
        apply();
    }

    function zoomBy(clientX, clientY, factor) { zoomTo(clientX, clientY, scale * factor); }

    function fitToViewport() {
        zoomable.style.transform = 'translate(0px,0px) scale(1)';
        var contentWidth = zoomable.scrollWidth || 1;
        var viewWidth = viewport.clientWidth || contentWidth;
        var fit = Math.min(1, (viewWidth - 20) / contentWidth);
        scale = clampScale(fit || 1);
        panX = Math.max(10, (viewWidth - contentWidth * scale) / 2);
        panY = 14;
        apply();
    }

    document.querySelectorAll('[data-zoom-in]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rect = viewport.getBoundingClientRect();
            zoomBy(rect.left + rect.width / 2, rect.top + rect.height / 2, 1.25);
        });
    });
    document.querySelectorAll('[data-zoom-out]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rect = viewport.getBoundingClientRect();
            zoomBy(rect.left + rect.width / 2, rect.top + rect.height / 2, 1 / 1.25);
        });
    });
    document.querySelectorAll('[data-zoom-reset]').forEach(function (btn) {
        btn.addEventListener('click', fitToViewport);
    });

    viewport.addEventListener('wheel', function (event) {
        event.preventDefault();
        var factor = event.deltaY < 0 ? 1.12 : 1 / 1.12;
        zoomBy(event.clientX, event.clientY, factor);
    }, { passive: false });

    // Trascinamento con il mouse.
    var dragging = false, lastX = 0, lastY = 0, movedDuringDrag = false;
    viewport.addEventListener('mousedown', function (event) {
        dragging = true; movedDuringDrag = false;
        lastX = event.clientX; lastY = event.clientY;
        viewport.classList.add('is-panning');
    });
    window.addEventListener('mousemove', function (event) {
        if (!dragging) return;
        var dx = event.clientX - lastX, dy = event.clientY - lastY;
        if (Math.abs(dx) > 2 || Math.abs(dy) > 2) movedDuringDrag = true;
        panX += dx; panY += dy;
        lastX = event.clientX; lastY = event.clientY;
        apply();
    });
    window.addEventListener('mouseup', function () {
        if (dragging && movedDuringDrag) {
            suppressClick = true;
            setTimeout(function () { suppressClick = false; }, 0);
        }
        dragging = false;
        viewport.classList.remove('is-panning');
    });

    // Pizzica per zoomare / trascina con un dito su touch.
    var touchState = null;

    function distance(a, b) {
        return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    }

    viewport.addEventListener('touchstart', function (event) {
        if (event.touches.length === 1) {
            touchState = { mode: 'pan', x: event.touches[0].clientX, y: event.touches[0].clientY };
        } else if (event.touches.length === 2) {
            touchState = {
                mode: 'pinch',
                dist0: distance(event.touches[0], event.touches[1]),
                midX: (event.touches[0].clientX + event.touches[1].clientX) / 2,
                midY: (event.touches[0].clientY + event.touches[1].clientY) / 2,
                startScale: scale,
            };
        }
    }, { passive: true });

    viewport.addEventListener('touchmove', function (event) {
        if (!touchState) return;
        event.preventDefault();
        if (touchState.mode === 'pan' && event.touches.length === 1) {
            var dx = event.touches[0].clientX - touchState.x;
            var dy = event.touches[0].clientY - touchState.y;
            panX += dx; panY += dy;
            touchState.x = event.touches[0].clientX;
            touchState.y = event.touches[0].clientY;
            apply();
        } else if (touchState.mode === 'pinch' && event.touches.length === 2) {
            var dist = distance(event.touches[0], event.touches[1]);
            var ratio = touchState.dist0 > 0 ? (dist / touchState.dist0) : 1;
            zoomTo(touchState.midX, touchState.midY, touchState.startScale * ratio);
        }
    }, { passive: false });

    viewport.addEventListener('touchend', function (event) {
        if (event.touches.length === 0) {
            touchState = null;
        } else if (event.touches.length === 1) {
            touchState = { mode: 'pan', x: event.touches[0].clientX, y: event.touches[0].clientY };
        }
    });

    fitToViewport();
})();
</script>
