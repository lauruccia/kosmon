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
            $gradients = [
                'active'    => 'linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%)',
                'delivered' => 'linear-gradient(135deg, #4c1d95 0%, #6d28d9 60%, #7c3aed 100%)',
                'blocked'   => 'linear-gradient(135deg, #450a0a 0%, #7f1d1d 60%, #991b1b 100%)',
                'revoked'   => 'linear-gradient(135deg, #111827 0%, #1f2937 60%, #374151 100%)',
            ];
            $gradient = $gradients[$card->status] ?? $gradients['revoked'];

            $statusInfo = [
                'active'    => ['label' => 'Attiva',       'dot' => '#4ade80'],
                'delivered' => ['label' => 'Da attivare',  'dot' => '#facc15'],
                'blocked'   => ['label' => 'Bloccata',     'dot' => '#f87171'],
                'revoked'   => ['label' => 'Revocata',     'dot' => '#9ca3af'],
            ];
            $si = $statusInfo[$card->status] ?? ['label' => $card->status, 'dot' => '#9ca3af'];

            // Numero carta: serial → 4 gruppi separati da spazio (stile CC)
            $serial  = $card->serial_number ?? strtoupper(substr(str_replace('-', '', $card->uuid), 0, 16));
            $parts   = array_filter(explode('-', $serial));
            $cardNum = implode('  ', $parts);

            // Data validità
            $validDate = ($card->activated_at ?? $card->created_at)->format('m/Y');

            // Nome titolare
            $holder = mb_strtoupper($card->company?->name ?? 'TITOLARE');
        @endphp

        <div style="max-width:440px;">

            {{-- ── La carta fisica ───────────────────────────────────────────── --}}
            <div style="
                background: {{ $gradient }};
                border-radius: 20px 20px 0 0;
                padding: 22px 26px 20px;
                min-height: 220px;
                position: relative;
                overflow: hidden;
                box-shadow: 0 10px 40px rgba(0,0,0,0.30);
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                box-sizing: border-box;
            ">
                {{-- cerchi decorativi sfondo --}}
                <div style="position:absolute;top:-60px;right:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,0.05);pointer-events:none;"></div>
                <div style="position:absolute;bottom:-80px;left:-50px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,0.04);pointer-events:none;"></div>

                {{-- Riga 1: brand + NFC --}}
                <div style="display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1;">
                    <div style="font-size:18px;font-weight:900;color:#fff;letter-spacing:2px;text-shadow:0 2px 8px rgba(0,0,0,0.5);">
                        KM<span style="color:#facc15;">oney</span>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:center;gap:2px;">
                        {{-- Onde NFC --}}
                        <svg width="28" height="22" viewBox="0 0 28 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M14 10.5v1" stroke="white" stroke-width="2.2" stroke-linecap="round"/>
                            <path d="M11 8.5c-.83.83-.83 4.17 0 5" stroke="rgba(255,255,255,0.75)" stroke-width="1.8" stroke-linecap="round"/>
                            <path d="M8.5 6c-1.67 1.67-1.67 8.33 0 10" stroke="rgba(255,255,255,0.5)" stroke-width="1.7" stroke-linecap="round"/>
                            <path d="M17 8.5c.83.83.83 4.17 0 5" stroke="rgba(255,255,255,0.75)" stroke-width="1.8" stroke-linecap="round"/>
                            <path d="M19.5 6c1.67 1.67 1.67 8.33 0 10" stroke="rgba(255,255,255,0.5)" stroke-width="1.7" stroke-linecap="round"/>
                        </svg>
                        <span style="font-size:8px;color:rgba(255,255,255,0.5);letter-spacing:1.5px;font-weight:600;">NFC</span>
                    </div>
                </div>

                {{-- Chip --}}
                <div style="margin-top:14px;position:relative;z-index:1;">
                    <div style="
                        width: 46px; height: 36px;
                        background: linear-gradient(135deg, #c8a84b 0%, #f0d060 35%, #c8a84b 65%, #a07828 100%);
                        border-radius: 7px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.35);
                        position: relative;
                        overflow: hidden;
                    ">
                        <div style="position:absolute;top:12px;left:0;right:0;height:1px;background:rgba(100,70,0,0.25);"></div>
                        <div style="position:absolute;top:23px;left:0;right:0;height:1px;background:rgba(100,70,0,0.25);"></div>
                        <div style="position:absolute;left:15px;top:0;bottom:0;width:1px;background:rgba(100,70,0,0.2);"></div>
                        <div style="position:absolute;left:30px;top:0;bottom:0;width:1px;background:rgba(100,70,0,0.2);"></div>
                        <div style="position:absolute;top:10px;left:12px;width:22px;height:16px;border:1px solid rgba(100,70,0,0.3);border-radius:3px;"></div>
                    </div>
                </div>

                {{-- Numero carta --}}
                <div style="margin-top:16px;position:relative;z-index:1;">
                    <div style="
                        font-family: 'Courier New', 'Lucida Console', monospace;
                        font-size: 15px;
                        font-weight: 700;
                        color: rgba(255,255,255,0.95);
                        letter-spacing: 3px;
                        text-shadow: 0 1px 4px rgba(0,0,0,0.6);
                        word-break: break-all;
                    ">{{ $cardNum }}</div>
                </div>

                {{-- Riga inferiore: titolare + validità + status --}}
                <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-top:14px;position:relative;z-index:1;">
                    <div>
                        <div style="font-size:8px;color:rgba(255,255,255,0.45);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px;">Titolare</div>
                        <div style="font-size:13px;font-weight:700;color:#fff;letter-spacing:1px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            {{ $holder }}
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:8px;color:rgba(255,255,255,0.45);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px;">
                            {{ $card->status === 'active' ? 'Attivata' : 'Emessa' }}
                        </div>
                        <div style="font-size:13px;font-weight:700;color:#fff;letter-spacing:2px;">
                            {{ $validDate }}
                        </div>
                    </div>
                </div>

                {{-- Badge status (angolo alto destra, sotto NFC) --}}
                <div style="position:absolute;top:48px;right:26px;z-index:2;display:flex;align-items:center;gap:5px;">
                    <span style="width:7px;height:7px;border-radius:50%;background:{{ $si['dot'] }};display:inline-block;box-shadow:0 0 6px {{ $si['dot'] }};"></span>
                    <span style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.85);letter-spacing:0.5px;">{{ $si['label'] }}</span>
                </div>
            </div>

            {{-- ── Pannello sotto la carta ────────────────────────────────────── --}}
            <div style="
                background: var(--card, #fff);
                border: 1px solid var(--line);
                border-top: none;
                border-radius: 0 0 16px 16px;
                padding: 14px 20px 16px;
            ">
                @if($card->status === 'active')
                <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--ink-muted);margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid var(--line);">
                    <span>
                        Transaz.:&nbsp;
                        <strong style="color:var(--ink);">
                            {{ $card->limit_per_transaction ? '€ '.number_format($card->limit_per_transaction, 2, ',', '.') : '—' }}
                        </strong>
                    </span>
                    <span>
                        Giornaliero:&nbsp;
                        <strong style="color:var(--ink);">
                            {{ $card->limit_daily ? '€ '.number_format($card->limit_daily, 2, ',', '.') : '—' }}
                        </strong>
                    </span>
                    <span>
                        Mensile:&nbsp;
                        <strong style="color:var(--ink);">
                            {{ $card->limit_monthly ? '€ '.number_format($card->limit_monthly, 2, ',', '.') : '—' }}
                        </strong>
                    </span>
                </div>
                @endif

                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                    <div style="font-size:12px;color:var(--ink-muted);">
                        @if($card->last_used_at)
                            Ultimo uso {{ $card->last_used_at->diffForHumans() }}
                        @else
                            Emessa il {{ $card->created_at->format('d/m/Y') }}
                        @endif
                    </div>
                    <div>
                        @if($card->status === 'delivered')
                            <a href="{{ route('portal.nfc-cards.activate', $card->uuid) }}" class="cta" style="font-size:12px;padding:8px 18px;">
                                ⚡ Attiva ora
                            </a>
                        @elseif($card->status === 'active')
                            <a href="{{ route('portal.nfc-cards.show', $card->uuid) }}" class="cta" style="font-size:12px;padding:8px 18px;">
                                Gestisci
                            </a>
                        @else
                            <a href="{{ route('portal.nfc-cards.show', $card->uuid) }}" style="font-size:13px;font-weight:600;color:var(--primary);">
                                Dettagli →
                            </a>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    @empty
        <section class="card card-pad" style="text-align:center;padding:40px;">
            <div style="font-size:48px;margin-bottom:12px;">&#128246;</div>
            <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:6px;">Nessuna card assegnata</div>
            <div style="font-size:13px;color:var(--ink-muted);">Contatta l'amministratore del circuito per richiedere una card NFC.</div>
        </section>
    @endforelse

</div>
@endsection
