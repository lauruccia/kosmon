@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('admin.transfers.index') }}">← Torna ai movimenti</a>
@endsection

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <span class="eyebrow">Movimento {{ $transfer->reference }}</span>
    <h2 style="margin:8px 0 0;font-size:18px;">{{ $origin['title'] ?? 'Origine non disponibile' }}</h2>
    <p style="margin:4px 0 0;color:var(--ink-muted);font-size:13px;">
        {{ ky_format($transfer->amount) }} {{ $transfer->currency_code }}
        · {{ $transfer->booked_at?->format('d/m/Y H:i') ?? '—' }}
        @if($transfer->description)
            · {{ $transfer->description }}
        @endif
    </p>
</div>

@if ($origin === null)
    <section class="card light-card">
        <div style="padding:14px 16px;">
            <h3 style="margin:0 0 8px;font-size:15px;">A cosa è dovuto questo movimento</h3>
            <p style="margin:0;font-size:13px;color:var(--ink-muted);line-height:1.6;">
                Non è stato possibile risalire automaticamente all'origine di questo movimento — i dati collegati
                (commissione, bonus o liquidazione) potrebbero essere stati eliminati, non essere ancora stati
                assegnati a una liquidazione, o il movimento potrebbe essere troppo vecchio per il tracciamento
                automatico. La causale registrata sul movimento è:
            </p>
            <p style="margin:10px 0 0;font-size:14px;font-weight:600;">
                {{ $transfer->description ?? '— nessuna causale registrata —' }}
            </p>
        </div>
    </section>
@else
    <section class="card light-card">
        <div style="padding:14px 16px;">
            <h3 style="margin:0 0 12px;font-size:15px;">A cosa è dovuto questo movimento</h3>
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;font-size:13px;">
                @foreach ($origin['lines'] as $line)
                    <div>
                        <span style="display:block;color:var(--ink-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">{{ $line['label'] }}</span>
                        {{ $line['value'] }}
                    </div>
                @endforeach
            </div>

            @if (!empty($origin['admin_route']))
                <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line);">
                    <a href="{{ route($origin['admin_route'], $origin['admin_route_params']) }}" class="cta">
                        Vai alla liquidazione completa →
                    </a>
                </div>
            @endif
        </div>
    </section>
@endif
@endsection
