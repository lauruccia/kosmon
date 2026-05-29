@extends('layouts.portal')

@section('content')
<div class="stack">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0;">Le mie Card NFC</h1>
            <p style="font-size:13px;color:var(--ink-muted);margin:4px 0 0;">Card fisiche emesse dall'amministratore del circuito.</p>
        </div>
    </div>

    @if(session('portal_success'))
        <div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;font-size:13px;color:#166534;">
            &#10003; {{ session('portal_success') }}
        </div>
    @endif

    @forelse($cards as $card)
        @php
            $statusLabels = [
                'delivered' => ['label'=>'Da attivare','color'=>'#6d28d9','bg'=>'#ede9fe','icon'=>'⚠️'],
                'active'    => ['label'=>'Attiva','color'=>'#166534','bg'=>'#dcfce7','icon'=>'✓'],
                'blocked'   => ['label'=>'Bloccata','color'=>'#991b1b','bg'=>'#fee2e2','icon'=>'⊘'],
                'revoked'   => ['label'=>'Revocata','color'=>'#6b7280','bg'=>'#f3f4f6','icon'=>'✕'],
            ];
            $sl = $statusLabels[$card->status] ?? ['label'=>$card->status,'color'=>'#374151','bg'=>'#f3f4f6','icon'=>'?'];
        @endphp
        <section class="card card-pad">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="width:52px;height:52px;background:var(--primary-soft,#eff6ff);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:26px;">
                        &#128246;
                    </div>
                    <div>
                        <div style="font-size:16px;font-weight:800;color:var(--ink);">
                            Card {{ $card->serial_number ?? substr($card->uuid, 0, 8) }}
                        </div>
                        <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">
                            Emessa il {{ $card->created_at->format('d/m/Y') }}
                            @if($card->last_used_at) · Ultimo uso {{ $card->last_used_at->diffForHumans() }} @endif
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;background:{{ $sl['bg'] }};color:{{ $sl['color'] }};">
                        {{ $sl['icon'] }} {{ $sl['label'] }}
                    </span>
                    @if($card->status === 'delivered')
                        <a href="{{ route('portal.nfc-cards.activate', $card->uuid) }}" class="cta" style="font-size:12px;padding:7px 14px;">
                            Attiva ora
                        </a>
                    @else
                        <a href="{{ route('portal.nfc-cards.show', $card->uuid) }}" style="font-size:13px;font-weight:600;color:var(--primary);">
                            Gestisci
                        </a>
                    @endif
                </div>
            </div>

            @if($card->status === 'active')
            <div style="display:flex;gap:16px;margin-top:16px;padding-top:14px;border-top:1px solid var(--line);font-size:12px;color:var(--ink-muted);">
                <span>Limite/transaz.: <strong style="color:var(--ink);">{{ $card->limit_per_transaction ? $card->limit_per_transaction.' KY' : 'Nessuno' }}</strong></span>
                <span>Giornaliero: <strong style="color:var(--ink);">{{ $card->limit_daily ? $card->limit_daily.' KY' : 'Nessuno' }}</strong></span>
                <span>Mensile: <strong style="color:var(--ink);">{{ $card->limit_monthly ? $card->limit_monthly.' KY' : 'Nessuno' }}</strong></span>
            </div>
            @endif
        </section>
    @empty
        <section class="card card-pad" style="text-align:center;padding:40px;">
            <div style="font-size:48px;margin-bottom:12px;">&#128246;</div>
            <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:6px;">Nessuna card assegnata</div>
            <div style="font-size:13px;color:var(--ink-muted);">Contatta l'amministratore del circuito per richiedere una card NFC.</div>
        </section>
    @endforelse

</div>
@endsection
