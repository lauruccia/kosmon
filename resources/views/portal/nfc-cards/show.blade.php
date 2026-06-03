@extends('layouts.portal')

@section('content')
    <div class="portal-grid" style="max-width:600px;">
        <div class="stack">

            {{-- Breadcrumb --}}
            <div style="font-size:13px;color:var(--ink-muted);">
                <a href="{{ route('portal.nfc-cards.index') }}" style="color:var(--primary);text-decoration:none;">Card NFC</a>
                <span style="margin:0 6px;">&#8250;</span>
                <span>{{ $card->serial_number ?? substr($card->uuid, 0, 8) }}</span>
            </div>

            @if(session('portal_success'))
                <div style="padding:12px 16px;background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;color:#166534;font-size:14px;font-weight:600;">
                    &#10003; {{ session('portal_success') }}
                </div>
            @endif
            @if(session('portal_info'))
                <div style="padding:12px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;font-size:14px;">
                    {{ session('portal_info') }}
                </div>
            @endif

            {{-- Carta di credito visuale --}}
            @php
                $statusMap = [
                    'active'    => ['bg' => '#22c55e', 'text' => '#fff', 'label' => 'Attiva'],
                    'delivered' => ['bg' => '#eab308', 'text' => '#fff', 'label' => 'Da attivare'],
                    'blocked'   => ['bg' => '#ef4444', 'text' => '#fff', 'label' => 'Bloccata'],
                    'revoked'   => ['bg' => '#6b7280', 'text' => '#fff', 'label' => 'Revocata'],
                ];
                $st = $statusMap[$card->status] ?? ['bg' => '#6b7280', 'text' => '#fff', 'label' => ucfirst($card->status)];
                $holderName = $card->company->name ?? auth()->user()->name ?? 'Titolare';
                $serial = $card->serial_number ?? 'KMY-????-??????-?';
            @endphp

            <div style="
                background: linear-gradient(135deg, #1e3a5f 0%, #2d5282 40%, #1a365d 70%, #0f2942 100%);
                border-radius: 20px;
                padding: 28px 28px 24px;
                position: relative;
                overflow: hidden;
                box-shadow: 0 8px 32px rgba(0,0,0,.28);
                min-height: 190px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                color: #fff;
            ">
                {{-- Cerchi decorativi sfondo --}}
                <div style="position:absolute;top:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
                <div style="position:absolute;bottom:-60px;left:-30px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>

                {{-- Top row: brand + status --}}
                <div style="display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;">
                            &#128179;
                        </div>
                        <div>
                            <div style="font-size:15px;font-weight:800;letter-spacing:.03em;">KMoney</div>
                            <div style="font-size:10px;opacity:.65;letter-spacing:.08em;text-transform:uppercase;">Card NFC</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;background:{{ $st['bg'] }};color:{{ $st['text'] }};letter-spacing:.04em;">
                            {{ $st['label'] }}
                        </span>
                        {{-- NFC icon --}}
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="opacity:.7;">
                            <path d="M1 12C1 12 4 5 12 5s11 7 11 7" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            <path d="M5 12C5 12 7.5 8 12 8s7 4 7 4" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            <path d="M9 12c0 0 1-.5 3-.5s3 .5 3 .5" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="14" r="1.2" fill="white"/>
                        </svg>
                    </div>
                </div>

                {{-- Chip + numero seriale --}}
                <div style="position:relative;z-index:1;margin-top:18px;">
                    {{-- Chip SIM --}}
                    <div style="width:38px;height:28px;background:linear-gradient(135deg,#d4a843,#f5cc6a,#b8860b);border-radius:5px;margin-bottom:14px;position:relative;overflow:hidden;">
                        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:26px;height:18px;border:1.5px solid rgba(0,0,0,.2);border-radius:3px;"></div>
                        <div style="position:absolute;top:50%;left:0;right:0;height:1.5px;background:rgba(0,0,0,.15);transform:translateY(-50%);"></div>
                        <div style="position:absolute;left:50%;top:0;bottom:0;width:1.5px;background:rgba(0,0,0,.15);transform:translateX(-50%);"></div>
                    </div>
                    {{-- Numero card --}}
                    <div style="font-size:17px;font-weight:700;letter-spacing:.18em;font-family:monospace;opacity:.95;text-shadow:0 1px 4px rgba(0,0,0,.3);">
                        {{ $serial }}
                    </div>
                </div>

                {{-- Bottom: titolare + date --}}
                <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-top:16px;position:relative;z-index:1;">
                    <div>
                        <div style="font-size:9px;opacity:.55;text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px;">Titolare</div>
                        <div style="font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            {{ strtoupper($holderName) }}
                        </div>
                    </div>
                    @if($card->activated_at)
                        <div style="text-align:right;">
                            <div style="font-size:9px;opacity:.55;text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px;">Attivata</div>
                            <div style="font-size:13px;font-weight:600;letter-spacing:.04em;">{{ $card->activated_at->format('m/Y') }}</div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Statistiche compatte --}}
            <div style="display:grid;grid-template-columns:1fr 1fr{{ $card->last_used_at ? ' 1fr' : '' }};gap:10px;">
                <div style="padding:12px 14px;background:var(--surface-soft);border-radius:12px;border:1px solid var(--line);">
                    <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Speso oggi</div>
                    <div style="font-size:16px;font-weight:800;color:var(--ink);">{{ ky_format($card->daily_spent ?? 0) }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:1px;">KY</div>
                </div>
                <div style="padding:12px 14px;background:var(--surface-soft);border-radius:12px;border:1px solid var(--line);">
                    <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Questo mese</div>
                    <div style="font-size:16px;font-weight:800;color:var(--ink);">{{ ky_format($card->monthly_spent ?? 0) }}</div>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:1px;">KY</div>
                </div>
                @if($card->last_used_at)
                    <div style="padding:12px 14px;background:var(--surface-soft);border-radius:12px;border:1px solid var(--line);">
                        <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Ultimo uso</div>
                        <div style="font-size:14px;font-weight:700;color:var(--ink);">{{ $card->last_used_at->format('d/m/Y') }}</div>
                        <div style="font-size:11px;color:var(--ink-muted);margin-top:1px;">{{ $card->last_used_at->format('H:i') }}</div>
                    </div>
                @endif
            </div>

            {{-- PIN bloccato --}}
            @if($card->isPinLocked())
                <div style="padding:12px 14px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:13px;">
                    &#9888; PIN bloccato fino alle {{ $card->pin_locked_until->format('H:i') }} del {{ $card->pin_locked_until->format('d/m/Y') }}.
                </div>
            @endif

            {{-- Attiva card --}}
            @if($card->status === 'delivered')
                <a href="{{ route('portal.nfc-cards.activate', $card->uuid) }}" class="cta" style="display:block;text-align:center;">
                    &#128274; Attiva card con PIN
                </a>
            @endif

            {{-- Limiti di spesa --}}
            @if($card->status === 'active')
                <section class="card card-pad">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:14px;">&#128203; Limiti di spesa</div>
                    <form method="POST" action="{{ route('portal.nfc-cards.limits', $card->uuid) }}">
                        @csrf
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                            <div>
                                <label style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:5px;">Per transazione</label>
                                <input type="number" name="limit_per_transaction" min="1" max="9999999"
                                       value="{{ $card->limit_per_transaction }}"
                                       placeholder="∞"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);background:var(--surface-soft);outline:none;">
                            </div>
                            <div>
                                <label style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:5px;">Giornaliero</label>
                                <input type="number" name="limit_daily" min="1" max="9999999"
                                       value="{{ $card->limit_daily }}"
                                       placeholder="∞"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);background:var(--surface-soft);outline:none;">
                            </div>
                            <div>
                                <label style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:5px;">Mensile</label>
                                <input type="number" name="limit_monthly" min="1" max="9999999"
                                       value="{{ $card->limit_monthly }}"
                                       placeholder="∞"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);background:var(--surface-soft);outline:none;">
                            </div>
                        </div>
                        <button type="submit" class="cta secondary" style="margin-top:12px;width:100%;">
                            Salva limiti
                        </button>
                    </form>
                </section>

                {{-- Blocco card --}}
                <section class="card card-pad">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--ink);">&#128274; Blocca card</div>
                            <div style="font-size:12px;color:var(--ink-muted);margin-top:3px;">Impedisce nuovi pagamenti. Potrai sbloccarla in qualsiasi momento.</div>
                        </div>
                        <form method="POST" action="{{ route('portal.nfc-cards.block', $card->uuid) }}" style="flex-shrink:0;">
                            @csrf
                            <button type="submit"
                                    style="background:#dc2626;border:none;border-radius:9px;color:#fff;font-size:13px;font-weight:700;padding:9px 18px;cursor:pointer;white-space:nowrap;"
                                    onclick="return confirm('Bloccare la card {{ $card->serial_number }}? Nessun pagamento sarà accettato fino allo sblocco.')">
                                Blocca
                            </button>
                        </form>
                    </div>
                </section>
            @endif

            @if($card->status === 'blocked')
                <section class="card card-pad">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--ink);">&#128275; Sblocca card</div>
                            <div style="font-size:12px;color:var(--ink-muted);margin-top:3px;">La card è bloccata. Sbloccala per riprendere i pagamenti.</div>
                        </div>
                        <form method="POST" action="{{ route('portal.nfc-cards.unblock', $card->uuid) }}" style="flex-shrink:0;">
                            @csrf
                            <button type="submit" style="background:var(--primary);border:none;border-radius:9px;color:#fff;font-size:13px;font-weight:700;padding:9px 18px;cursor:pointer;">
                                Sblocca
                            </button>
                        </form>
                    </div>
                </section>
            @endif

        </div>
    </div>
@endsection
