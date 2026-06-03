@extends('layouts.portal')

@section('content')

<div style="max-width:900px; margin:0 auto; padding:8px 0 40px;">

    {{-- Flash --}}
    @if(session('portal_success'))
        <div style="background:var(--success-soft);border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;font-weight:600;color:var(--success);">
            {{ session('portal_success') }}
        </div>
    @endif
    @if(session('portal_error'))
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;font-weight:600;color:#dc2626;">
            {{ session('portal_error') }}
        </div>
    @endif

    {{-- Header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin-bottom:4px;">Sessioni e accessi</h1>
            <p style="font-size:14px;color:var(--ink-muted);">
                Dispositivi attivi e cronologia accessi al tuo account.
                @if($activeCount > 0)
                    <span style="background:var(--primary-light);color:var(--primary);font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px;">
                        {{ $activeCount }} {{ $activeCount === 1 ? 'sessione attiva' : 'sessioni attive' }}
                    </span>
                @endif
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <a href="{{ route('portal.security') }}"
               style="font-size:13px;font-weight:600;color:var(--ink-muted);padding:8px 14px;border:1px solid var(--line);border-radius:8px;text-decoration:none;">
                &#x2190; Sicurezza
            </a>
            @if($activeCount > 1)
            <form method="POST" action="{{ route('portal.login-logs.logout-all') }}"
                  onsubmit="return confirm('Disconnettere tutti gli altri dispositivi?');">
                @csrf
                <button type="submit"
                        style="font-size:13px;font-weight:600;color:#fff;background:var(--danger);padding:8px 16px;border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Esci da tutti i dispositivi
                </button>
            </form>
            @endif
        </div>
    </div>

    {{-- Info banner --}}
    <div style="background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:14px 18px;margin-bottom:28px;display:flex;align-items:flex-start;gap:12px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--ink-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <p style="font-size:13px;color:var(--ink-soft);line-height:1.6;margin:0;">
            Se vedi un dispositivo o accesso sospetto, disconnettilo subito e <a href="{{ route('portal.security') }}" style="color:var(--primary);font-weight:600;">cambia la password</a>.
            Gli accessi da IP mai visti in precedenza ricevono una notifica email automatica.
        </p>
    </div>

    {{-- ===== SESSIONI ATTIVE ===== --}}
    <h2 style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:12px;display:flex;align-items:center;gap:8px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
        Dispositivi attivi
    </h2>

    @if($activeSessions->isEmpty())
        <div class="card card-pad" style="margin-bottom:28px;text-align:center;padding:32px 24px;">
            <div style="font-size:13px;color:var(--ink-muted);">Nessuna sessione attiva rilevata.</div>
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:32px;">
            @foreach($activeSessions as $session)
            @php
                $ua        = $session->user_agent ?? '';
                $isMobile  = stripos($ua, 'mobile') !== false || stripos($ua, 'android') !== false;
                $isTablet  = stripos($ua, 'tablet') !== false || stripos($ua, 'ipad') !== false;
                $deviceIcon = $isMobile ? '📱' : ($isTablet ? '📟' : '🖥️');
                $lastSeen  = \Carbon\Carbon::createFromTimestamp($session->last_activity);
            @endphp
            <div style="background:var(--surface);border:1.5px solid {{ $session->is_current ? 'var(--primary)' : 'var(--line)' }};border-radius:12px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="font-size:26px;line-height:1;">{{ $deviceIcon }}</div>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;">
                            {{ $session->ip_address ?? 'IP sconosciuto' }}
                            @if($session->is_current)
                                <span style="background:var(--primary-light);color:var(--primary);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px;">
                                    Questo dispositivo
                                </span>
                            @endif
                        </div>
                        <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">
                            Ultima attività: {{ $lastSeen->diffForHumans() }} &middot;
                            <span style="font-family:monospace;font-size:11px;">{{ $lastSeen->format('d/m/Y H:i') }}</span>
                        </div>
                        @if($ua)
                        <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;max-width:500px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $ua }}">
                            {{ Str::limit($ua, 80) }}
                        </div>
                        @endif
                    </div>
                </div>
                @if(!$session->is_current)
                <form method="POST" action="{{ route('portal.login-logs.logout-session', $session->id) }}"
                      onsubmit="return confirm('Disconnettere questo dispositivo?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            style="font-size:12px;font-weight:600;color:var(--danger);background:transparent;border:1.5px solid var(--danger);padding:6px 14px;border-radius:8px;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:6px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                        Disconnetti
                    </button>
                </form>
                @else
                <div style="font-size:12px;color:var(--ink-muted);font-style:italic;">Sessione corrente</div>
                @endif
            </div>
            @endforeach
        </div>
    @endif

    {{-- ===== STORICO ACCESSI ===== --}}
    <h2 style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:12px;display:flex;align-items:center;gap:8px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
        Cronologia accessi
    </h2>

    @if($logs->isEmpty())
        <div class="card card-pad" style="text-align:center;padding:48px 24px;">
            <div style="font-size:32px;margin-bottom:12px;">🔒</div>
            <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:8px;">Nessun accesso registrato</div>
            <div style="font-size:14px;color:var(--ink-muted);">I tuoi futuri accessi appariranno qui.</div>
        </div>
    @else
        <div class="card" style="overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--surface-soft);border-bottom:1px solid var(--line);">
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Data e ora</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">IP</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Posizione</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Dispositivo</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;">Browser / OS</th>
                        <th style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-muted);text-align:left;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                    <tr style="border-bottom:1px solid var(--line-soft);{{ $loop->first ? 'background:rgba(var(--primary-rgb),0.03);' : '' }}">

                        <td style="padding:12px 16px;white-space:nowrap;">
                            <div style="font-size:13px;font-weight:700;color:var(--ink);">{{ $log->logged_in_at?->format('d/m/Y') }}</div>
                            <div style="font-size:11px;color:var(--ink-muted);">{{ $log->logged_in_at?->format('H:i:s') }}</div>
                        </td>

                        <td style="padding:12px 16px;">
                            <span style="font-family:monospace;font-size:12px;color:var(--ink);background:var(--surface-soft);padding:3px 7px;border-radius:5px;border:1px solid var(--line);">
                                {{ $log->ip_address ?? '—' }}
                            </span>
                        </td>

                        <td style="padding:12px 16px;">
                            @if($log->city || $log->country)
                                <div style="font-size:13px;color:var(--ink);">{{ implode(', ', array_filter([$log->city, $log->country])) }}</div>
                            @else
                                <span style="font-size:12px;color:var(--ink-muted);">N/D</span>
                            @endif
                        </td>

                        <td style="padding:12px 16px;">
                            @php $deviceIcon = match($log->device_type) { 'mobile' => '📱', 'tablet' => '📟', 'desktop' => '🖥️', default => '💻' }; @endphp
                            <span style="font-size:13px;">{{ $deviceIcon }} {{ ucfirst($log->device_type ?? 'desktop') }}</span>
                        </td>

                        <td style="padding:12px 16px;">
                            <div style="font-size:13px;color:var(--ink);">{{ $log->browser ?? '—' }}</div>
                            <div style="font-size:11px;color:var(--ink-muted);">{{ $log->os ?? '—' }}</div>
                        </td>

                        <td style="padding:12px 16px;text-align:right;white-space:nowrap;">
                            @if($loop->first)
                                <span style="background:#dbeafe;color:#1d4ed8;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Più recente</span>
                            @elseif($log->is_new_ip)
                                <span style="background:#fef3c7;color:#92400e;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;" title="Primo accesso da questo IP">⚠ Nuovo IP</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            @if($logs->hasPages())
                <div style="padding:14px 16px;border-top:1px solid var(--line);">
                    {{ $logs->links() }}
                </div>
            @endif
        </div>
    @endif

</div>

@endsection
