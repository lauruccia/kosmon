@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('portal.webhooks.create') }}" class="cta">+ Nuovo webhook</a>
@endsection



@section('content')
@forelse($webhooks as $wh)
    <div class="card card-pad" style="margin-bottom:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
            <div style="font-weight:700;font-size:14px;word-break:break-all;">{{ $wh->url }}</div>
            <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">
                {{ count($wh->events) }} eventi &middot; {{ $wh->deliveries_count }} consegne totali
                @if($wh->last_triggered_at)
                    &middot; Ultimo: {{ $wh->last_triggered_at->diffForHumans() }}
                @endif
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            @if($wh->failure_count > 0 && $wh->is_active)
                <span class="chip pink" style="font-size:11px;">{{ $wh->failure_count }} fallimenti</span>
            @endif
            <span class="chip {{ $wh->is_active ? 'success' : '' }}" style="font-size:11px;">
                {{ $wh->is_active ? 'Attivo' : 'Disattivato' }}
            </span>
            <a href="{{ route('portal.webhooks.show', $wh) }}"
               style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;">Dettagli</a>
        </div>
    </div>
@empty
    <div class="card card-pad" style="text-align:center;padding:40px;color:var(--ink-muted);">
        Nessun webhook configurato.
        <a href="{{ route('portal.webhooks.create') }}" style="color:var(--primary);font-weight:600;display:block;margin-top:8px;">
            Crea il primo &rarr;
        </a>
    </div>
@endforelse
@endsection
