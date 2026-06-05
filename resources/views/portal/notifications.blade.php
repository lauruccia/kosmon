@extends('layouts.portal')

@section('content')
<style>
    .notif-list { display: grid; gap: 8px; }
    .notif-item {
        display: grid;
        grid-template-columns: 44px 1fr auto;
        gap: 14px; align-items: start;
        padding: 14px 16px;
        border-radius: var(--radius);
        background: var(--surface);
        border: 1px solid var(--line);
        box-shadow: var(--shadow-xs);
        transition: background .18s, border-color .18s;
        text-decoration: none; color: inherit;
    }
    .notif-item.unread {
        border-left: 3px solid var(--primary);
        background: var(--primary-light);
    }
    [data-theme="dark"] .notif-item.unread { background: rgba(15,82,196,.08); }
    .notif-item:hover { border-color: var(--line-strong); background: var(--surface-soft); }
    .notif-item.unread:hover { background: var(--primary-light); }
    .notif-icon {
        width: 44px; height: 44px; border-radius: 14px;
        background: var(--surface-soft); border: 1px solid var(--line);
        display: grid; place-items: center;
        font-size: 20px; flex-shrink: 0;
    }
    .notif-item.unread .notif-icon { background: var(--primary-light); border-color: rgba(15,82,196,.2); }
    .notif-title { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 3px; }
    .notif-body  { font-size: 13px; color: var(--ink-soft); line-height: 1.5; }
    .notif-time  { font-size: 11.5px; color: var(--ink-muted); white-space: nowrap; margin-top: 2px; }
    .notif-dot   {
        width: 8px; height: 8px; border-radius: 50%;
        background: var(--primary); flex-shrink: 0; margin-top: 6px;
    }
    .notif-empty {
        padding: 40px 20px; text-align: center;
        border-radius: var(--radius); border: 1.5px dashed var(--line-strong);
        background: var(--surface-soft); color: var(--ink-soft);
    }
    .notif-header {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 14px;
    }
</style>

{{-- Banner attivazione notifiche push --}}
@if(config('webpush.vapid.public_key'))
<div id="push-banner" style="display:none;background:var(--primary-soft,#eef3fc);border:1px solid #bfcfee;border-radius:12px;padding:14px 18px;margin-bottom:20px;align-items:center;gap:14px;flex-wrap:wrap;">
    <div style="flex:1;min-width:200px;">
        <strong style="font-size:14px;color:var(--primary);">Attiva le notifiche push</strong>
        <div style="font-size:13px;color:var(--ink-muted);margin-top:2px;">Ricevi pagamenti e avvisi in tempo reale anche a browser chiuso.</div>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0;">
        <button onclick="kmRequestPush(); document.getElementById('push-banner').style.display='none';"
                style="padding:8px 16px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
            Attiva
        </button>
        <button onclick="document.getElementById('push-banner').style.display='none';"
                style="padding:8px 12px;background:none;color:var(--ink-muted);border:1px solid var(--line);border-radius:8px;font-size:13px;cursor:pointer;">
            Non ora
        </button>
    </div>
</div>
<script>
(function() {
    var banner = document.getElementById('push-banner');
    if (!banner) return;
    if (!('Notification' in window) || !('PushManager' in window)) { banner.style.display = 'none'; return; }
    // Verifica se esiste davvero una subscription attiva (non fidarsi solo del localStorage)
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.ready.then(function(reg) {
            reg.pushManager.getSubscription().then(function(sub) {
                if (sub && Notification.permission === 'granted') {
                    // Subscription attiva: aggiorna localStorage e nascondi banner
                    localStorage.setItem('km-push-enabled', '1');
                    banner.style.display = 'none';
                } else {
                    // Nessuna subscription reale: mostra il banner
                    localStorage.removeItem('km-push-enabled');
                    if (Notification.permission !== 'denied') {
                        banner.style.display = 'flex';
                    }
                }
            });
        });
    } else {
        if (Notification.permission === 'granted') { banner.style.display = 'none'; return; }
        banner.style.display = 'flex';
    }
})();
</script>
@endif

<div class="notif-header">
    <div style="font-size:13.5px;color:var(--ink-soft);">
        @php $unread = $notifications->filter(fn($n) => is_null($n->read_at))->count(); @endphp
        @if ($unread > 0)
            <strong style="color:var(--ink);">{{ $unread }}</strong> non {{ $unread === 1 ? 'letta' : 'lette' }}
        @else
            Nessuna notifica non letta
        @endif
    </div>
    @if ($unread > 0)
        <form method="POST" action="{{ route('portal.notifications.read-all') }}">
            @csrf
            <button type="submit" class="cta secondary" style="font-size:12.5px;min-height:32px;padding:0 14px;">
                Segna tutte come lette
            </button>
        </form>
    @endif
</div>

@if ($notifications->isEmpty())
    <div class="notif-empty">
        <div style="font-size:36px;margin-bottom:10px;">🔔</div>
        <strong>Nessuna notifica</strong>
        <p style="margin:6px 0 0;font-size:13px;">Le attività del circuito appariranno qui non appena arrivano.</p>
    </div>
@else
    <div class="notif-list">
        @foreach ($notifications as $notif)
            @php
                $data    = $notif->data;
                $isUnread = is_null($notif->read_at);
                $link    = $data['link'] ?? '#';
            @endphp
            <form method="POST" action="{{ route('portal.notifications.read', $notif->id) }}" style="display:contents;">
                @csrf
                <button type="submit" class="notif-item {{ $isUnread ? 'unread' : '' }}" style="text-align:left;width:100%;cursor:pointer;border-width:1px {{ $isUnread ? 'border-l-[3px]' : '' }};">
                    <div class="notif-icon">{{ $data['icon'] ?? '🔔' }}</div>
                    <div>
                        <div class="notif-title">{{ $data['title'] ?? 'Notifica' }}</div>
                        <div class="notif-body">{{ $data['body'] ?? '' }}</div>
                        <div class="notif-time">{{ $notif->created_at->locale('it')->diffForHumans() }}</div>
                    </div>
                    <div>
                        @if ($isUnread)
                            <div class="notif-dot"></div>
                        @endif
                    </div>
                </button>
            </form>
        @endforeach
    </div>

    {{-- Paginazione --}}
    @if ($notifications->hasPages())
        <div style="margin-top:16px;display:flex;justify-content:center;">
            {{ $notifications->links() }}
        </div>
    @endif
@endif

@endsection
