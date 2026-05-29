@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <span class="eyebrow">
        <a href="{{ route('portal.webhooks.index') }}" style="color:var(--primary);text-decoration:none;">Webhook</a> &rsaquo;
    </span>
    <h2>{{ $pageTitle }}</h2>
</section>

@if(session('portal_success'))
    <div class="alert alert-success" style="margin-bottom:16px;">{{ session('portal_success') }}</div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:840px;margin-bottom:24px;">

    {{-- Dettagli --}}
    <div class="card card-pad">
        <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">Configurazione</div>
        <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;">
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">URL</div>
                <div style="word-break:break-all;font-weight:600;">{{ $webhook->url }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Segreto di firma</div>
                <code style="font-size:12px;background:var(--surface-soft);padding:4px 8px;border-radius:4px;display:block;word-break:break-all;">{{ $webhook->secret }}</code>
                <div style="font-size:10px;color:var(--ink-muted);margin-top:3px;">Usa questo per verificare la firma HMAC-SHA256.</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Events</div>
                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                    @foreach($webhook->events as $ev)
                        <span style="background:var(--primary-soft,#eff6ff);color:var(--primary);border:1px solid #bfdbfe;border-radius:4px;padding:2px 8px;font-size:11px;font-family:monospace;">
                            {{ $ev }}
                        </span>
                    @endforeach
                </div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Stato</div>
                <span class="chip {{ $webhook->is_active ? 'success' : 'pink' }}">{{ $webhook->is_active ? 'Attivo' : 'Disattivato' }}</span>
                @if($webhook->failure_count > 0)
                    <span style="font-size:11px;color:var(--danger);margin-left:8px;">{{ $webhook->failure_count }} fallimenti</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Azioni --}}
    <div style="display:flex;flex-direction:column;gap:10px;">
        {{-- Test --}}
        @if($webhook->is_active)
            <form method="POST" action="{{ route('portal.webhooks.test', $webhook) }}">
                @csrf
                <button type="submit" class="btn btn-secondary" style="width:100%;">
                    Invia evento di test (ping)
                </button>
            </form>
        @endif

        {{-- Toggle attivo/disattivato --}}
        <form method="POST" action="{{ route('portal.webhooks.toggle', $webhook) }}">
            @csrf
            <button type="submit" class="btn {{ $webhook->is_active ? 'btn-secondary' : 'btn-primary' }}" style="width:100%;">
                {{ $webhook->is_active ? 'Disattiva webhook' : 'Riattiva webhook' }}
            </button>
        </form>

        {{-- Elimina --}}
        <form method="POST" action="{{ route('portal.webhooks.destroy', $webhook) }}"
              onsubmit="return confirm('Eliminare questo webhook? Le consegne passate saranno cancellate.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger" style="width:100%;">Elimina webhook</button>
        </form>
    </div>
</div>

{{-- Log consegne --}}
<section class="card light-card">
    <div style="padding:16px 20px 0;font-size:15px;font-weight:700;">Ultime consegne</div>
    <table class="transactions-table" style="margin-top:8px;">
        <thead>
            <tr><th>Data</th><th>Evento</th><th>Stato HTTP</th><th>Esito</th></tr>
        </thead>
        <tbody>
            @forelse($deliveries as $d)
                <tr>
                    <td style="font-size:12px;white-space:nowrap;">{{ $d->created_at->format('d/m/Y H:i:s') }}</td>
                    <td><code style="font-size:11px;">{{ $d->event }}</code></td>
                    <td style="font-size:13px;">{{ $d->response_status ?? '—' }}</td>
                    <td>
                        <span class="chip {{ $d->success ? 'success' : 'pink' }}" style="font-size:11px;">
                            {{ $d->success ? 'OK' : 'Fallito' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="subtle" style="text-align:center;padding:20px;">Nessuna consegna ancora.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
@endsection
