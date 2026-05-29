@extends('layouts.portal')

@section('content')
{{-- Filtri --}}
<form method="GET" action="{{ route('admin.audit') }}" id="audit-filters" style="margin-bottom:10px;">
    <div class="card card-pad" style="padding:10px 16px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Evento</label>
                <select name="event" onchange="document.getElementById('audit-filters').submit()"
                    style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;min-width:200px;">
                    <option value="">Tutti gli eventi</option>
                    @foreach($eventOptions as $opt)
                        <option value="{{ $opt }}" {{ $filters['event'] === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Da</label>
                <input type="date" name="from" value="{{ $filters['from'] }}"
                    onchange="document.getElementById('audit-filters').submit()"
                    style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
            </div>
            <div>
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">A</label>
                <input type="date" name="to" value="{{ $filters['to'] }}"
                    onchange="document.getElementById('audit-filters').submit()"
                    style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
            </div>
            <div>
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Utente (ID)</label>
                <input type="number" name="user_id" value="{{ $filters['userId'] ?: '' }}" placeholder="es. 42" min="1"
                    onchange="document.getElementById('audit-filters').submit()"
                    style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;width:100px;">
            </div>
            @if(array_filter([$filters['event'], $filters['from'], $filters['to'], $filters['userId']]))
                <a href="{{ route('admin.audit') }}"
                   style="padding:7px 14px;border-radius:8px;font-size:13px;background:var(--danger-soft);color:var(--danger);border:1px solid #fecdd3;text-decoration:none;font-weight:600;align-self:flex-end;">
                    Azzera filtri
                </a>
            @endif
            <div style="margin-left:auto;align-self:flex-end;display:flex;align-items:center;gap:12px;">
                <span style="font-size:13px;color:var(--ink-muted);">{{ $logs->total() }} eventi totali</span>
                <a href="{{ route('admin.audit.export-csv', request()->query()) }}"
                   style="padding:7px 14px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;text-decoration:none;font-weight:600;white-space:nowrap;">
                    &#8595; Scarica CSV
                </a>
            </div>
        </div>
    </div>
</form>

{{-- Tabella --}}
<section class="card light-card">
    <table class="transactions-table">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>Evento</th>
                <th>Attore</th>
                <th>Riferimento</th>
                <th>IP</th>
                <th>Contesto</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                @php
                    $isDestructive = str_contains($log->event, 'rejected') || str_contains($log->event, 'cancelled') || str_contains($log->event, 'blocked');
                    $isSuccess = str_contains($log->event, 'booked') || str_contains($log->event, 'approved') || str_contains($log->event, 'accepted') || str_contains($log->event, 'paid');
                    $chipClass = $isDestructive ? 'pink' : ($isSuccess ? 'success' : '');
                @endphp
                <tr>
                    <td style="white-space:nowrap;font-size:12px;">
                        <div style="font-weight:600;">{{ $log->created_at->format('d/m/Y') }}</div>
                        <div style="color:var(--ink-muted);">{{ $log->created_at->format('H:i:s') }}</div>
                    </td>
                    <td>
                        <span class="chip {{ $chipClass }}" style="font-size:11px;font-family:monospace;">
                            {{ $log->event }}
                        </span>
                    </td>
                    <td style="font-size:13px;">
                        @if($log->actor)
                            <div style="font-weight:600;">{{ $log->actor->name }}</div>
                            <div style="color:var(--ink-muted);font-size:11px;">{{ $log->actor->email }}</div>
                        @else
                            <span style="color:var(--ink-muted);">Sistema</span>
                        @endif
                    </td>
                    <td style="font-size:12px;color:var(--ink-muted);">
                        @if($log->auditable_type && $log->auditable_id)
                            {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                        @else
                            —
                        @endif
                    </td>
                    <td style="font-size:12px;color:var(--ink-muted);">{{ $log->ip_address ?? '—' }}</td>
                    <td>
                        @if($log->context)
                            <details style="font-size:11px;">
                                <summary style="cursor:pointer;color:var(--primary);font-weight:600;">Vedi</summary>
                                <pre style="margin-top:6px;background:var(--surface-soft);border-radius:6px;padding:8px;font-size:10px;overflow-x:auto;max-width:280px;white-space:pre-wrap;word-break:break-all;">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @else
                            <span style="color:var(--ink-muted);">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="subtle" style="text-align:center;padding:32px;">Nessun evento trovato con i filtri applicati.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div style="padding:16px 0 4px;">
        {{ $logs->links() }}
    </div>
</section>
@endsection
