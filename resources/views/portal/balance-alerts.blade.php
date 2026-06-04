@extends('layouts.portal')

@section('content')
<div style="max-width:760px;margin:0 auto;padding:8px 0 48px;">

    {{-- Flash --}}
    @if(session('portal_success'))
        <div style="background:var(--success-soft);border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;font-weight:600;color:var(--success);">
            {{ session('portal_success') }}
        </div>
    @endif
    @if($errors->any())
        <div style="background:var(--danger-soft);border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;font-weight:600;color:var(--danger);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin-bottom:4px;">Avvisi saldo</h1>
            <p style="font-size:14px;color:var(--ink-muted);">
                Ricevi una notifica quando il saldo scende sotto una soglia impostata da te.
            </p>
        </div>
        <a href="{{ route('portal.notification-preferences') }}"
           style="font-size:13px;font-weight:600;color:var(--ink-muted);padding:8px 14px;border:1px solid var(--line);border-radius:8px;text-decoration:none;">
            &#x2190; Preferenze notifiche
        </a>
    </div>

    {{-- Saldo corrente --}}
    <div style="background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
        <div style="background:var(--teal-soft);border-radius:10px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <span style="font-size:15px;font-weight:900;color:var(--teal);letter-spacing:-.02em;">KY</span>
        </div>
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:3px;">Saldo attuale</div>
            <div style="font-size:24px;font-weight:900;color:var(--teal);letter-spacing:-.03em;">
                {{ ky_format($account->available_balance) }} <span style="font-size:14px;font-weight:600;color:var(--ink-muted);">KY</span>
            </div>
        </div>
    </div>

    {{-- Avvisi esistenti --}}
    @if($alerts->isNotEmpty())
    <div class="card" style="margin-bottom:24px;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid var(--line);background:var(--surface-soft);">
            <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);">
                Avvisi configurati ({{ $alerts->count() }}/5)
            </div>
        </div>
        @foreach($alerts as $alert)
        <div style="padding:16px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;{{ !$alert->is_active ? 'opacity:.55;' : '' }}">
            <div style="display:flex;align-items:center;gap:14px;">
                {{-- Stato badge --}}
                <div style="width:10px;height:10px;border-radius:50%;background:{{ $alert->is_active ? 'var(--success)' : 'var(--ink-muted)' }};flex-shrink:0;"></div>
                <div>
                    <div style="font-size:15px;font-weight:800;color:var(--ink);">
                        Sotto <span style="color:var(--danger);">{{ $alert->thresholdFormatted() }}</span>
                    </div>
                    <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">
                        Cooldown {{ $alert->cooldown_hours }}h
                        &middot;
                        @if($alert->notify_email) Email @endif
                        @if($alert->notify_email && $alert->notify_inapp) + @endif
                        @if($alert->notify_inapp) In-app @endif
                        @if($alert->last_triggered_at)
                            &middot; Ultima notifica: {{ $alert->last_triggered_at->diffForHumans() }}
                        @else
                            &middot; Mai sparato
                        @endif
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                {{-- Toggle --}}
                <form method="POST" action="{{ route('portal.balance-alerts.toggle', $alert) }}">
                    @csrf @method('PATCH')
                    <button type="submit" style="font-size:12px;font-weight:600;padding:6px 12px;border-radius:6px;border:1px solid var(--line);background:var(--surface);color:var(--ink-soft);cursor:pointer;">
                        {{ $alert->is_active ? 'Disattiva' : 'Attiva' }}
                    </button>
                </form>
                {{-- Delete --}}
                <form method="POST" action="{{ route('portal.balance-alerts.destroy', $alert) }}"
                      onsubmit="return confirm('Eliminare questo avviso?');">
                    @csrf @method('DELETE')
                    <button type="submit" style="font-size:12px;font-weight:600;padding:6px 10px;border-radius:6px;border:1px solid var(--danger-soft);background:var(--danger-soft);color:var(--danger);cursor:pointer;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Form nuovo avviso --}}
    @if($alerts->count() < 5)
    <div class="card" style="padding:24px;">
        <div style="font-size:14px;font-weight:800;color:var(--ink);margin-bottom:18px;">
            {{ $alerts->isEmpty() ? 'Crea il tuo primo avviso' : 'Aggiungi un avviso' }}
        </div>
        <form method="POST" action="{{ route('portal.balance-alerts.store') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">

                {{-- Soglia --}}
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-muted);display:block;margin-bottom:6px;">
                        Avvisami quando il saldo scende sotto
                    </label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="threshold_ky" min="0.01" max="9999999" step="0.01"
                               value="{{ old('threshold_ky') }}"
                               placeholder="es. 100"
                               style="flex:1;padding:10px 14px;border:1px solid var(--line);border-radius:8px;font-size:15px;font-weight:700;background:var(--surface);color:var(--ink);outline:none;"
                               required>
                        <span style="font-size:14px;font-weight:700;color:var(--ink-muted);">KY</span>
                    </div>
                </div>

                {{-- Cooldown --}}
                <div>
                    <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-muted);display:block;margin-bottom:6px;">
                        Non ri-notificare prima di
                    </label>
                    <select name="cooldown_hours"
                            style="width:100%;padding:10px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:var(--surface);color:var(--ink);outline:none;">
                        <option value="6">6 ore</option>
                        <option value="12">12 ore</option>
                        <option value="24" selected>24 ore (consigliato)</option>
                        <option value="48">48 ore</option>
                        <option value="168">1 settimana</option>
                    </select>
                </div>

                {{-- Canali --}}
                <div>
                    <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-muted);display:block;margin-bottom:8px;">
                        Canali di notifica
                    </label>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;">
                            <input type="checkbox" name="notify_email" value="1" checked
                                   style="width:16px;height:16px;accent-color:var(--primary);">
                            Email
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;">
                            <input type="checkbox" name="notify_inapp" value="1" checked
                                   style="width:16px;height:16px;accent-color:var(--primary);">
                            Notifica in-app
                        </label>
                    </div>
                </div>
            </div>

            <button type="submit"
                    style="background:var(--primary);color:#fff;font-size:14px;font-weight:700;padding:11px 24px;border:none;border-radius:10px;cursor:pointer;width:100%;">
                Crea avviso saldo
            </button>
        </form>
    </div>
    @else
    <div style="background:var(--warning-soft);border:1px solid #fcd34d;border-radius:10px;padding:14px 18px;font-size:13px;color:var(--warning);font-weight:600;text-align:center;">
        Hai raggiunto il limite massimo di 5 avvisi per conto. Elimina uno per aggiungerne un altro.
    </div>
    @endif

</div>
@endsection
