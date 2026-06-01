@extends('layouts.portal')

@section('content')
    <div class="portal-grid" style="max-width:760px;">
        <div class="stack">

            {{-- Header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div>
                    <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0 0 4px;">Le mie Card NFC</h1>
                    <p style="font-size:13px;color:var(--ink-muted);margin:0;">Card fisiche collegate al tuo account.</p>
                </div>
            </div>

            @if(session('portal_success'))
                <div style="padding:12px 16px;background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;color:#166534;font-size:14px;font-weight:600;">
                    &#10003; {{ session('portal_success') }}
                </div>
            @endif

            @if($cards->isEmpty())
                <section class="card card-pad" style="text-align:center;padding:48px 24px;">
                    <div style="font-size:48px;margin-bottom:16px;">&#128246;</div>
                    <div style="font-size:17px;font-weight:700;color:var(--ink);margin-bottom:8px;">Nessuna card NFC</div>
                    <div style="font-size:14px;color:var(--ink-muted);max-width:360px;margin:0 auto;">
                        Non hai ancora card NFC attive. Le card vengono emesse dall'amministratore del circuito e consegnate fisicamente.
                    </div>
                </section>
            @else
                <section class="card" style="overflow:hidden;">
                    @foreach($cards as $card)
                        @php
                            $statusColor = match($card->status) {
                                'active'    => ['bg' => '#dcfce7', 'text' => '#166534', 'label' => 'Attiva'],
                                'delivered' => ['bg' => '#fef9c3', 'text' => '#854d0e', 'label' => 'Da attivare'],
                                'blocked'   => ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => 'Bloccata'],
                                'revoked'   => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'label' => 'Revocata'],
                                default     => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'label' => ucfirst($card->status)],
                            };
                        @endphp
                        <a href="{{ route('portal.nfc-cards.show', $card->uuid) }}"
                           style="display:flex;align-items:center;gap:16px;padding:16px 20px;border-bottom:1px solid var(--line);text-decoration:none;transition:background .15s;"
                           onmouseover="this.style.background='var(--surface-soft)'" onmouseout="this.style.background=''">

                            <div style="width:44px;height:44px;border-radius:12px;background:var(--primary-soft,#eff6ff);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;">
                                &#128179;
                            </div>

                            <div style="flex:1;min-width:0;">
                                <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:2px;">
                                    {{ $card->serial_number ?? 'Card ' . substr($card->uuid, 0, 8) }}
                                </div>
                                @if($card->last_used_at)
                                    <div style="font-size:12px;color:var(--ink-muted);">
                                        Ultimo uso: {{ $card->last_used_at->format('d/m/Y H:i') }}
                                    </div>
                                @else
                                    <div style="font-size:12px;color:var(--ink-muted);">Mai utilizzata</div>
                                @endif
                            </div>

                            <div style="padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;background:{{ $statusColor['bg'] }};color:{{ $statusColor['text'] }};white-space:nowrap;flex-shrink:0;">
                                {{ $statusColor['label'] }}
                            </div>

                            <div style="color:var(--ink-muted);flex-shrink:0;">&#8250;</div>
                        </a>
                    @endforeach
                </section>
            @endif

        </div>
    </div>
@endsection
