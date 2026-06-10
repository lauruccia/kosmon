@extends('layouts.portal')

@section('content')
<style>
.ref-grid { display:grid; gap:20px; max-width:700px; }

.ref-hero {
    padding:28px; background:var(--grad-hero);
    border-radius:var(--radius-lg); border:1px solid rgba(255,255,255,.07);
    color:#fff;
}
.ref-hero h1 { font-size:22px; font-weight:800; margin:0 0 6px; }
.ref-hero p  { font-size:14px; opacity:.8; margin:0; }

.ref-card {
    background:#fff; border:1px solid var(--line); border-radius:14px; padding:24px; display:flex; flex-direction:column; gap:16px;
}
.ref-link-box {
    display:flex; gap:8px; align-items:center;
    background:#f8fafc; border:1.5px solid var(--line); border-radius:10px; padding:12px 16px;
}
.ref-link-text {
    flex:1; font-size:13px; font-family:monospace; color:var(--ink-muted);
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0;
}
.ref-copy-btn {
    padding:8px 16px; background:var(--ink); color:#fff;
    border:none; border-radius:8px; font-size:13px; font-weight:700;
    cursor:pointer; flex-shrink:0; transition:opacity .15s;
}
.ref-copy-btn:hover { opacity:.8; }

.ref-code-box {
    display:inline-flex; align-items:center; gap:10px;
    background:#f0f9ff; border:1.5px solid #bae6fd; border-radius:10px;
    padding:10px 18px;
}
.ref-code { font-size:22px; font-weight:800; font-family:monospace; color:#0369a1; letter-spacing:.15em; }

.ref-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
@media(max-width:500px){ .ref-stats { grid-template-columns:1fr; } }
.ref-stat {
    background:#f8fafc; border:1px solid var(--line); border-radius:10px;
    padding:14px 16px; text-align:center;
}
.ref-stat-val { font-size:24px; font-weight:800; color:var(--ink); }
.ref-stat-lbl { font-size:11px; color:var(--ink-muted); text-transform:uppercase; letter-spacing:.07em; margin-top:2px; }

.ref-list { display:flex; flex-direction:column; gap:8px; }
.ref-row {
    display:flex; align-items:center; gap:12px;
    padding:12px 16px; background:#fff;
    border:1px solid var(--line); border-radius:10px;
}
.ref-avatar {
    width:36px; height:36px; border-radius:50%;
    background:var(--ink); color:#fff;
    display:grid; place-items:center; font-size:14px; font-weight:700; flex-shrink:0;
}
.ref-name { font-size:14px; font-weight:600; color:var(--ink); }
.ref-meta { font-size:12px; color:var(--ink-muted); }
.ref-badge {
    margin-left:auto; padding:4px 10px;
    border-radius:20px; font-size:11px; font-weight:700;
}
.ref-badge--pending  { background:#fef3c7; color:#92400e; }
.ref-badge--approved { background:#dcfce7; color:#166534; }

.ref-share-row { display:flex; gap:10px; flex-wrap:wrap; }
</style>

<div class="ref-grid">

    <div class="ref-hero">
        <h1>🎁 Invita un amico nel circuito</h1>
        <p>Ogni persona che inviti e viene approvata porta valore a tutto il circuito.</p>
    </div>

    {{-- Codice e link --}}
    <div class="ref-card">
        <div>
            <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:8px;">Il tuo codice personale</div>
            <div class="ref-code-box">
                <span class="ref-code">{{ $referralCode }}</span>
            </div>
        </div>

        <div>
            <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:8px;">Link di invito</div>
            <div class="ref-link-box">
                <span class="ref-link-text" id="ref-url">{{ $referralUrl }}</span>
                <button class="ref-copy-btn" onclick="copyRef()">Copia</button>
            </div>
        </div>

        <div class="ref-share-row">
            <a href="https://wa.me/?text={{ urlencode('Entra nel circuito KMoney con il mio link: ' . $referralUrl) }}"
               target="_blank" rel="noopener" class="cta secondary" style="flex:1;text-align:center;">
                📲 WhatsApp
            </a>
            <a href="mailto:?subject=Entra in KMoney&body={{ urlencode('Ciao! Ti invito nel circuito KMoney: ' . $referralUrl) }}"
               class="cta secondary" style="flex:1;text-align:center;">
                ✉️ Email
            </a>
        </div>

        <p style="font-size:12px;color:var(--ink-muted);margin:0;">
            Chi si registra con il tuo link viene associato al tuo profilo. Potrai vedere il loro stato di onboarding qui sotto.
        </p>
    </div>

    {{-- Stats --}}
    <div class="ref-stats">
        <div class="ref-stat">
            <div class="ref-stat-val">{{ $referrals->count() }}</div>
            <div class="ref-stat-lbl">Invitati totali</div>
        </div>
        <div class="ref-stat">
            <div class="ref-stat-val">
                {{ $referrals->filter(fn($u) => $u->company?->kyc_status === 'approved' || $u->account_holder_type === 'private')->count() }}
            </div>
            <div class="ref-stat-lbl">Approvati</div>
        </div>
        <div class="ref-stat">
            <div class="ref-stat-val">{{ $referrals->filter(fn($u) => $u->company?->kyc_status === 'pending')->count() }}</div>
            <div class="ref-stat-lbl">In attesa</div>
        </div>
    </div>

    {{-- Lista invitati --}}
    @if($referrals->isNotEmpty())
    <div class="ref-card">
        <div style="font-size:15px;font-weight:700;color:var(--ink);">Chi hai invitato</div>
        <div class="ref-list">
            @foreach($referrals as $invited)
                @php
                    $isApproved = $invited->company
                        ? $invited->company->kyc_status === 'approved'
                        : $invited->email_verified_at !== null;
                @endphp
                <div class="ref-row">
                    <div class="ref-avatar">{{ mb_substr($invited->name, 0, 1) }}</div>
                    <div>
                        <div class="ref-name">{{ $invited->name }}</div>
                        <div class="ref-meta">{{ $invited->company?->name ?? 'Privato' }} · {{ $invited->created_at->format('d/m/Y') }}</div>
                    </div>
                    <span class="ref-badge {{ $isApproved ? 'ref-badge--approved' : 'ref-badge--pending' }}">
                        {{ $isApproved ? '✓ Attivo' : 'In attesa' }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>
    @else
    <div style="padding:20px;text-align:center;color:var(--ink-muted);font-size:14px;background:#f8fafc;border:1px solid var(--line);border-radius:12px;">
        Ancora nessun invitato. Condividi il tuo link per iniziare!
    </div>
    @endif

</div>

<script>
function copyRef() {
    const url = document.getElementById('ref-url').textContent.trim();
    navigator.clipboard.writeText(url).then(() => {
        const btn = document.querySelector('.ref-copy-btn');
        const orig = btn.textContent;
        btn.textContent = '✓ Copiato';
        setTimeout(() => { btn.textContent = orig; }, 2000);
    });
}
</script>
@endsection
