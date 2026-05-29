@extends('layouts.portal')

@section('content')

@if(session('success'))
    <div class="alert alert-success" style="margin-bottom:16px;padding:12px 16px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;color:#065f46;">
        {{ session('success') }}
    </div>
@endif

{{-- Filtri --}}
<form method="GET" action="{{ route('admin.webhook-deliveries') }}" style="margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Webhook</label>
        <select name="webhook_id" class="form-input" style="min-width:200px;">
            <option value="">— Tutti —</option>
            @foreach($webhooks as $wh)
                <option value="{{ $wh->id }}" {{ request('webhook_id') == $wh->id ? 'selected' : '' }}>
                    {{ $wh->company?->name ?? '—' }} — {{ Str::limit($wh->url, 40) }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Evento</label>
        <select name="event" class="form-input" style="min-width:160px;">
            <option value="">— Tutti —</option>
            @foreach($events as $ev)
                <option value="{{ $ev }}" {{ request('event') === $ev ? 'selected' : '' }}>{{ $ev }}</option>
            @endforeach
        </select>
    </div>
    <div style="display:flex;align-items:center;gap:6px;padding-bottom:2px;">
        <input type="checkbox" name="failed_only" value="1" id="failed_only" {{ request('failed_only') ? 'checked' : '' }}>
        <label for="failed_only" style="font-size:13px;">Solo falliti</label>
    </div>
    <button type="submit" class="cta secondary">Filtra</button>
    <a href="{{ route('admin.webhook-deliveries') }}" class="cta secondary">Reset</a>
</form>

{{-- Statistiche rapide --}}
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
    <div class="card light-card card-pad" style="flex:1;min-width:120px;text-align:center;">
        <div style="font-size:22px;font-weight:800;">{{ $deliveries->total() }}</div>
        <div style="font-size:12px;color:var(--text-muted);">Totale (filtrati)</div>
    </div>
    <div class="card light-card card-pad" style="flex:1;min-width:120px;text-align:center;">
        <div style="font-size:22px;font-weight:800;color:#16a34a;">{{ $deliveries->where('success', true)->count() }}</div>
        <div style="font-size:12px;color:var(--text-muted);">Successi (pagina)</div>
    </div>
    <div class="card light-card card-pad" style="flex:1;min-width:120px;text-align:center;">
        <div style="font-size:22px;font-weight:800;color:#dc2626;">{{ $deliveries->where('success', false)->count() }}</div>
        <div style="font-size:12px;color:var(--text-muted);">Falliti (pagina)</div>
    </div>
</div>

{{-- Tabella --}}
<section class="card card-pad" style="overflow-x:auto;">
    @if($deliveries->isEmpty())
        <p style="color:var(--text-muted);text-align:center;padding:32px;">Nessuna consegna trovata con i filtri selezionati.</p>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="border-bottom:2px solid var(--border);">
                <th style="text-align:left;padding:8px 10px;white-space:nowrap;">Data</th>
                <th style="text-align:left;padding:8px 10px;">Azienda</th>
                <th style="text-align:left;padding:8px 10px;">URL</th>
                <th style="text-align:left;padding:8px 10px;">Evento</th>
                <th style="text-align:center;padding:8px 10px;">Status HTTP</th>
                <th style="text-align:center;padding:8px 10px;">Esito</th>
                <th style="text-align:center;padding:8px 10px;">Azione</th>
            </tr>
        </thead>
        <tbody>
        @foreach($deliveries as $d)
            <tr style="border-bottom:1px solid var(--border);{{ $d->success ? '' : 'background:rgba(220,38,38,.04);' }}">
                <td style="padding:7px 10px;white-space:nowrap;color:var(--text-muted);font-size:12px;">
                    {{ $d->delivered_at?->format('d/m/Y H:i:s') ?? $d->created_at->format('d/m/Y H:i:s') }}
                </td>
                <td style="padding:7px 10px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    {{ $d->webhook?->company?->name ?? '—' }}
                </td>
                <td style="padding:7px 10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <span title="{{ $d->webhook?->url }}">{{ Str::limit($d->webhook?->url, 35) }}</span>
                </td>
                <td style="padding:7px 10px;">
                    <span class="chip" style="font-size:11px;background:var(--primary-soft,#ede9fe);color:var(--primary);">{{ $d->event }}</span>
                </td>
                <td style="text-align:center;padding:7px 10px;">
                    @if($d->response_status)
                        <span style="font-weight:700;color:{{ $d->response_status >= 200 && $d->response_status < 300 ? '#16a34a' : '#dc2626' }};">
                            {{ $d->response_status }}
                        </span>
                    @else
                        <span style="color:var(--text-muted);">—</span>
                    @endif
                </td>
                <td style="text-align:center;padding:7px 10px;">
                    @if($d->success)
                        <span class="chip success" style="font-size:11px;">✓ OK</span>
                    @else
                        <span class="chip pink" style="font-size:11px;">✗ Fail</span>
                    @endif
                </td>
                <td style="text-align:center;padding:7px 10px;">
                    <div style="display:flex;gap:6px;justify-content:center;align-items:center;">
                        {{-- Retry --}}
                        @if($d->webhook)
                        <form method="POST" action="{{ route('admin.webhook-deliveries.retry', $d) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="cta secondary" style="font-size:11px;padding:3px 10px;"
                                title="Re-invia questo evento alla coda">Retry</button>
                        </form>
                        @endif

                        {{-- Expand response body --}}
                        @if($d->response_body)
                        <button type="button"
                            style="font-size:11px;padding:3px 10px;background:none;border:1px solid var(--border);border-radius:6px;cursor:pointer;color:var(--text-muted);"
                            onclick="document.getElementById('resp-{{ $d->id }}').style.display = document.getElementById('resp-{{ $d->id }}').style.display === 'none' ? 'block' : 'none'">
                            Risposta
                        </button>
                        @endif
                    </div>

                    {{-- Response body inline --}}
                    @if($d->response_body)
                    <div id="resp-{{ $d->id }}" style="display:none;margin-top:6px;text-align:left;">
                        <pre style="font-size:10px;background:#f8f8f8;border:1px solid var(--border);border-radius:6px;padding:8px;max-height:120px;overflow:auto;white-space:pre-wrap;word-break:break-all;">{{ $d->response_body }}</pre>
                    </div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="margin-top:20px;">
        {{ $deliveries->links() }}
    </div>
    @endif
</section>

@endsection
