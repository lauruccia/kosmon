@extends('layouts.portal')

@section('content')

@if(session('success'))
    <div class="alert alert-success" style="margin-bottom:16px;padding:12px 16px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;color:#065f46;">
        {{ session('success') }}
    </div>
@endif

@if(session('portal_error'))
    <div style="margin-bottom:16px;padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#b91c1c;">
        {{ session('portal_error') }}
    </div>
@endif

<div style="margin-bottom:18px;">
    <p style="font-size:13px;color:var(--text-muted);margin:0;line-height:1.6;">
        Gli eventi che KMoney manda alle <strong>applicazioni</strong> del circuito (Kosmoshop), non alle singole aziende.
        L'indirizzo non si configura da qui: vive nel <code>.env</code> del server, ed è vuoto finché l'applicazione non esiste.
    </p>
</div>

{{-- Stato dei canali configurati: la prima cosa da guardare quando
     "non arriva niente" è se il canale è acceso. --}}
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
    @foreach($endpoints->configuredClientIds() as $clientId)
        @php $canale = $endpoints->endpointFor($clientId); @endphp
        <div class="card light-card card-pad" style="flex:1;min-width:220px;">
            <div style="font-weight:700;font-size:14px;margin-bottom:4px;">{{ $clientId }}</div>
            @if($canale)
                <div style="font-size:12px;color:#16a34a;font-weight:600;">Canale attivo</div>
                <div style="font-size:11px;color:var(--text-muted);font-family:monospace;word-break:break-all;margin-top:4px;">{{ $canale['url'] }}</div>
            @else
                <div style="font-size:12px;color:var(--text-muted);">Canale spento (URL o segreto mancante nel .env)</div>
            @endif
        </div>
    @endforeach
    @if(count($endpoints->configuredClientIds()) === 0)
        <div class="card light-card card-pad" style="flex:1;">
            <div style="font-size:13px;color:var(--text-muted);">Nessuna applicazione configurata.</div>
        </div>
    @endif
</div>

{{-- Filtri --}}
<form method="GET" action="{{ route('admin.client-webhook-deliveries') }}" style="margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Applicazione</label>
        <select name="client_id" class="form-input" style="min-width:180px;" data-no-search>
            <option value="">— Tutte —</option>
            @foreach($clientIds as $id)
                <option value="{{ $id }}" {{ request('client_id') === $id ? 'selected' : '' }}>{{ $id }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Evento</label>
        <select name="event" class="form-input" style="min-width:220px;" data-no-search>
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
    <a href="{{ route('admin.client-webhook-deliveries') }}" class="cta secondary">Reset</a>
</form>

<section class="card card-pad" style="overflow-x:auto;">
    @if($deliveries->isEmpty())
        <p style="color:var(--text-muted);text-align:center;padding:32px;">Nessuna consegna trovata.</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="text-align:left;color:var(--text-muted);">
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Quando</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Applicazione</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Evento</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Esito</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Risposta</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($deliveries as $d)
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">{{ $d->created_at->format('d/m/Y H:i:s') }}</td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);">{{ $d->client_id }}</td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);font-family:monospace;font-size:12px;">{{ $d->event }}</td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);">
                            @if($d->success)
                                <span style="color:#16a34a;font-weight:700;">OK {{ $d->response_status }}</span>
                            @else
                                <span style="color:#dc2626;font-weight:700;">KO {{ $d->response_status ?? '—' }}</span>
                            @endif
                            <div style="font-size:11px;color:var(--text-muted);">tentativo {{ $d->attempts }}</div>
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);max-width:320px;">
                            <div style="font-size:11px;color:var(--text-muted);word-break:break-word;">{{ Str::limit($d->response_body, 160) }}</div>
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">
                            @unless($d->success)
                                <form method="POST" action="{{ route('admin.client-webhook-deliveries.retry', $d) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="cta secondary" style="padding:6px 12px;font-size:12px;">Riprova</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:16px;">{{ $deliveries->links() }}</div>
    @endif
</section>

@endsection
