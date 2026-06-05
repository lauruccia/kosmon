@extends('layouts.portal')

@section('content')
<div style="max-width:700px;margin:0 auto;padding:0 16px 48px;">

    <div style="margin-bottom:24px;">
        <div class="eyebrow">Impostazioni</div>
        <h1 class="page-title">Preferenze notifiche</h1>
        <p class="subtle">Scegli quali eventi ricevere e su quale canale. Le modifiche si applicano immediatamente.</p>
    </div>

    @if(session('success'))
        <div class="alert success" style="margin-bottom:20px;">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('portal.notification-preferences.update') }}">
        @csrf
        @method('PATCH')

        <section class="card" style="padding:0;overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid var(--line);background:var(--surface-2);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div class="card-title" style="margin:0;">Canali di notifica per evento</div>
                    <div style="display:flex;gap:20px;font-size:12px;color:var(--ink-soft);font-weight:600;">
                        <span style="width:72px;text-align:center;">In-app</span>
                        <span style="width:72px;text-align:center;">Email</span>
                    </div>
                </div>
            </div>

            @foreach($events as $key => $meta)
                @php
                    $savedChannels = $prefs[$key] ?? $meta['default'];
                    $dbChecked   = in_array('database', $savedChannels);
                    $mailChecked = in_array('mail', $savedChannels);
                @endphp
                <div style="display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid var(--line);">
                    <div style="font-size:14px;">{{ $meta['label'] }}</div>
                    <div style="display:flex;gap:20px;">
                        <div style="width:72px;text-align:center;">
                            <input type="hidden" name="event_{{ $key }}_database" value="0">
                            <input type="checkbox" name="event_{{ $key }}_database" value="1"
                                {{ $dbChecked ? 'checked' : '' }}
                                style="width:18px;height:18px;cursor:pointer;accent-color:var(--teal-strong);">
                        </div>
                        <div style="width:72px;text-align:center;">
                            <input type="hidden" name="event_{{ $key }}_mail" value="0">
                            <input type="checkbox" name="event_{{ $key }}_mail" value="1"
                                {{ $mailChecked ? 'checked' : '' }}
                                style="width:18px;height:18px;cursor:pointer;accent-color:var(--teal-strong);">
                        </div>
                    </div>
                </div>
            @endforeach
        </section>

        <div style="margin-top:20px;display:flex;gap:12px;">
            <button type="submit" class="cta">Salva preferenze</button>
            <a href="{{ route('portal.dashboard') }}" class="cta secondary">Annulla</a>
        </div>
    </form>

    @if(config('webpush.vapid.public_key'))
    <section class="card" style="margin-top:28px;padding:18px 20px;">
        <div class="card-title" style="margin-bottom:6px;">Notifiche push browser</div>
        <p style="font-size:13px;color:var(--ink-soft);margin:0 0 14px;">
            Ricevi avvisi di pagamento in tempo reale anche a schermo bloccato.
        </p>
        <div id="push-status-msg" style="font-size:13px;color:var(--ink-soft);margin-bottom:10px;"></div>
        <button id="push-enable-btn" onclick="kmRequestPush()" class="cta" style="display:none;">
            Attiva notifiche push
        </button>
        <button id="push-disable-btn" onclick="kmDisablePush()" class="cta secondary" style="display:none;">
            Disattiva notifiche push
        </button>
        <div id="push-denied-msg" style="display:none;font-size:13px;color:var(--danger,#c0392b);">
            Hai bloccato le notifiche nel browser. Per attivarle vai nelle impostazioni del browser e riabilita i permessi per questo sito.
        </div>
    </section>
    <script>
    (function() {
        var btn     = document.getElementById('push-enable-btn');
        var disBtn  = document.getElementById('push-disable-btn');
        var denied  = document.getElementById('push-denied-msg');
        var msg     = document.getElementById('push-status-msg');
        if (!('Notification' in window) || !('PushManager' in window)) {
            if (msg) msg.textContent = 'Il tuo browser non supporta le notifiche push.';
            return;
        }
        if (Notification.permission === 'denied') {
            if (denied) denied.style.display = 'block';
            return;
        }
        if (Notification.permission === 'granted' && localStorage.getItem('km-push-enabled') === '1') {
            if (disBtn) disBtn.style.display = 'inline-flex';
            if (msg) msg.textContent = 'Le notifiche push sono attive.';
        } else {
            if (btn) btn.style.display = 'inline-flex';
        }
    })();
    </script>
    @endif
</div>
@endsection
