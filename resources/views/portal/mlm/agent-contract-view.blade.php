<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Il mio contratto agente KNM — KMoney</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; min-height: 100vh; }
        .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .brand  { font-weight: 800; font-size: 1.15rem; color: #0f766e; text-decoration: none; }
        .topbar-user { font-size: 13px; color: #64748b; }
        .page { max-width: 860px; margin: 32px auto; padding: 0 20px 80px; }
        .banner { border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 14px; display: flex; gap: 12px; align-items: flex-start; }
        .banner-signed { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .banner-note   { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .banner-icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
        .card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #0e7490 100%); color: #fff; padding: 24px 32px; }
        .card-header h1 { margin: 0 0 4px; font-size: 1.35rem; font-weight: 800; }
        .card-header p  { margin: 0; font-size: 13px; opacity: .8; }
        .card-body { padding: 28px 32px; }
        .contract-wrapper { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 24px; }
        .contract-toolbar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; }
        .contract-toolbar span { font-size: 13px; color: #64748b; }
        .contract-body { padding: 28px 32px; max-height: 460px; overflow-y: auto; font-size: 14px; line-height: 1.75; }
        .contract-body h2 { font-size: 1.05rem; font-weight: 800; margin: 24px 0 8px; color: #0f766e; }
        .contract-body h3 { font-size: .95rem; font-weight: 700; margin: 20px 0 6px; color: #0f766e; }
        .contract-body h4 { font-size: .9rem; font-weight: 700; margin: 18px 0 6px; color: #334155; }
        .contract-body h5 { font-size: .85rem; font-weight: 700; margin: 16px 0 4px; color: #475569; text-transform: uppercase; }
        .contract-body p  { margin: 0 0 12px; }
        .contract-body hr { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }
        .contract-body ul, .contract-body ol { padding-left: 20px; margin: 0 0 12px; }
        .contract-body li { margin-bottom: 6px; }
        .contract-body table { width: 100%; border-collapse: collapse; margin: 8px 0 16px; font-size: 13px; }
        .contract-body table td, .contract-body table th { border: 1px solid #e2e8f0; padding: 6px 10px; }
        .expand-btn { font-size: 12px; color: #0f766e; border: 1px solid #0f766e; background: none; padding: 4px 10px; border-radius: 6px; cursor: pointer; }
        .back-link { display: inline-block; margin-top: 8px; font-size: 13px; color: #0f766e; text-decoration: none; font-weight: 600; }
        @media (max-width: 600px) {
            .card-header, .card-body { padding: 18px 18px; }
            .contract-body { padding: 18px 18px; }
        }
    </style>
</head>
<body>

<nav class="topbar">
    <a href="{{ route('home') }}" class="brand">KMoney</a>
    <span class="topbar-user">{{ $user->name }}</span>
</nav>

<div class="page">

    <div class="banner banner-signed">
        <span class="banner-icon">✅</span>
        <div>Firmato il <strong>{{ $signature->signed_at->format('d/m/Y \a\l\l\e H:i') }}</strong> — versione contratto {{ $signature->contract_version }}{{ $signature->directives_version ? ', versione direttive '.$signature->directives_version : '' }}.</div>
    </div>

    <div class="card">
        <div class="card-header">
            <h1>📜 Il mio contratto agente KNM</h1>
            <p>Testo esatto letto e firmato al momento dell'accettazione (non cambia se l'admin aggiorna il modello in seguito).</p>
        </div>
        <div class="card-body">

            <div class="contract-wrapper">
                <div class="contract-toolbar">
                    <span>📄 Contratto di nomina ad agente — versione {{ $signature->contract_version }}</span>
                    <button class="expand-btn" onclick="toggleExpand(this, 'contractBody')">⤢ Espandi</button>
                </div>
                <div class="contract-body" id="contractBody">
                    {!! sanitize_html($contractHtml) !!}
                </div>
            </div>

            @if($directivesHtml)
                <div class="contract-wrapper">
                    <div class="contract-toolbar">
                        <span>📋 Direttive e Procedure Kosmos — versione {{ $signature->directives_version }}</span>
                        <button class="expand-btn" onclick="toggleExpand(this, 'directivesBody')">⤢ Espandi</button>
                    </div>
                    <div class="contract-body" id="directivesBody">
                        {!! sanitize_html($directivesHtml) !!}
                    </div>
                </div>
            @else
                <div class="banner banner-note">
                    <span class="banner-icon">ℹ️</span>
                    <div>Le "Direttive e Procedure Kosmos" sono state introdotte il 07/08/2026, dopo la tua firma: non facevano parte del contratto che hai accettato in quel momento.</div>
                </div>
            @endif

            <a href="{{ route('portal.dashboard') }}" class="back-link">← Torna alla dashboard</a>
        </div>
    </div>
</div>

<script>
function toggleExpand(btn, bodyId) {
    const body = document.getElementById(bodyId);
    if (body.style.maxHeight === 'none') {
        body.style.maxHeight = '460px';
        btn.textContent = '⤢ Espandi';
    } else {
        body.style.maxHeight = 'none';
        btn.textContent = '⤡ Comprimi';
    }
}
</script>
</body>
</html>
