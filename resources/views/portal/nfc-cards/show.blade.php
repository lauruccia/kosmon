@extends('layouts.portal')

@section('content')
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

    <div class="portal-grid">

        {{-- ── COLONNA SINISTRA: carta visuale + statistiche ─────────────── --}}
        <div class="stack">

            {{-- Breadcrumb --}}
            <div style="font-size:13px;color:var(--ink-muted);">
                <a href="{{ route('portal.nfc-cards.index') }}" style="color:var(--primary);text-decoration:none;">Card NFC</a>
                <span style="margin:0 6px;">&#8250;</span>
                <span>{{ $serial }}</span>
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

            {{-- Credit card visuale --}}
            <div style="
                background: linear-gradient(135deg, #1e3a5f 0%, #2d5282 40%, #1a365d 70%, #0f2942 100%);
                border-radius: 18px;
                padding: 22px 22px 20px;
                position: relative;
                overflow: hidden;
                box-shadow: 0 8px 28px rgba(0,0,0,.28);
                color: #fff;
            ">
                {{-- Cerchi decorativi --}}
                <div style="position:absolute;top:-40px;right:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
                <div style="position:absolute;bottom:-50px;left:-20px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>

                {{-- Top: brand + status + NFC --}}
                <div style="display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1;margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:9px;">
                        <div style="width:32px;height:32px;background:rgba(255,255,255,.15);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;">
                            &#128179;
                        </div>
                        <div>
                            <div style="font-size:14px;font-weight:800;letter-spacing:.03em;">KMoney</div>
                            <div style="font-size:9px;opacity:.6;letter-spacing:.1em;text-transform:uppercase;">Card NFC</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:7px;">
                        <span style="padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;background:{{ $st['bg'] }};color:{{ $st['text'] }};">
                            {{ $st['label'] }}
                        </span>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="opacity:.65;">
                            <path d="M1 12C1 12 4 5 12 5s11 7 11 7" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            <path d="M5 12C5 12 7.5 8 12 8s7 4 7 4" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            <path d="M9 12c0 0 1-.5 3-.5s3 .5 3 .5" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="14" r="1.2" fill="white"/>
                        </svg>
                    </div>
                </div>

                {{-- Chip + numero --}}
                <div style="position:relative;z-index:1;margin-bottom:14px;">
                    <div style="width:34px;height:25px;background:linear-gradient(135deg,#d4a843,#f5cc6a,#b8860b);border-radius:4px;margin-bottom:12px;position:relative;overflow:hidden;">
                        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:22px;height:15px;border:1.5px solid rgba(0,0,0,.2);border-radius:2px;"></div>
                        <div style="position:absolute;top:50%;left:0;right:0;height:1px;background:rgba(0,0,0,.15);transform:translateY(-50%);"></div>
                        <div style="position:absolute;left:50%;top:0;bottom:0;width:1px;background:rgba(0,0,0,.15);transform:translateX(-50%);"></div>
                    </div>
                    <div style="font-size:15px;font-weight:700;letter-spacing:.15em;font-family:monospace;text-shadow:0 1px 4px rgba(0,0,0,.3);">
                        {{ $serial }}
                    </div>
                </div>

                {{-- Bottom: titolare + data --}}
                <div style="display:flex;align-items:flex-end;justify-content:space-between;position:relative;z-index:1;">
                    <div style="min-width:0;flex:1;">
                        <div style="font-size:8px;opacity:.5;text-transform:uppercase;letter-spacing:.1em;margin-bottom:2px;">Titolare</div>
                        <div style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px;">
                            {{ strtoupper($holderName) }}
                        </div>
                    </div>
                    @if($card->activated_at)
                        <div style="text-align:right;flex-shrink:0;margin-left:12px;">
                            <div style="font-size:8px;opacity:.5;text-transform:uppercase;letter-spacing:.1em;margin-bottom:2px;">Attivata</div>
                            <div style="font-size:11px;font-weight:700;letter-spacing:.04em;">{{ $card->activated_at->format('m/Y') }}</div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Statistiche --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="padding:12px 14px;background:var(--surface-soft);border-radius:12px;border:1px solid var(--line);">
                    <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Speso oggi</div>
                    <div style="font-size:18px;font-weight:800;color:var(--ink);line-height:1;">{{ ky_format($card->daily_spent ?? 0) }} <span style="font-size:11px;font-weight:600;color:var(--ink-muted);">KY</span></div>
                </div>
                <div style="padding:12px 14px;background:var(--surface-soft);border-radius:12px;border:1px solid var(--line);">
                    <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Questo mese</div>
                    <div style="font-size:18px;font-weight:800;color:var(--ink);line-height:1;">{{ ky_format($card->monthly_spent ?? 0) }} <span style="font-size:11px;font-weight:600;color:var(--ink-muted);">KY</span></div>
                </div>
            </div>

            @if($card->last_used_at)
                <div style="padding:10px 14px;background:var(--surface-soft);border-radius:10px;border:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:12px;color:var(--ink-muted);">Ultimo utilizzo</span>
                    <span style="font-size:12px;font-weight:600;color:var(--ink);">{{ $card->last_used_at->format('d/m/Y \a\l\l\e H:i') }}</span>
                </div>
            @endif

        </div>

        {{-- ── COLONNA DESTRA: controlli ──────────────────────────────────── --}}
        <div class="stack">

            {{-- PIN bloccato --}}
            @if($card->isPinLocked())
                <div style="padding:12px 14px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:13px;">
                    &#9888; PIN bloccato fino alle <strong>{{ $card->pin_locked_until->format('H:i') }}</strong> del {{ $card->pin_locked_until->format('d/m/Y') }}.
                </div>
            @endif

            {{-- Attiva card --}}
            @if($card->status === 'delivered')
                <section class="card card-pad" style="text-align:center;padding:32px 24px;">
                    <div style="font-size:40px;margin-bottom:12px;">&#128274;</div>
                    <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:6px;">Card da attivare</div>
                    <div style="font-size:13px;color:var(--ink-muted);margin-bottom:18px;">Imposta il PIN per iniziare ad usare la card.</div>
                    <a href="{{ route('portal.nfc-cards.activate', $card->uuid) }}" class="cta" style="display:inline-block;">
                        Attiva con PIN
                    </a>
                </section>
            @endif

            {{-- Limiti di spesa --}}
            @if($card->status === 'active')
                <section class="card card-pad">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:14px;">&#128203; Limiti di spesa</div>
                    <form method="POST" action="{{ route('portal.nfc-cards.limits', $card->uuid) }}">
                        @csrf
                        <div style="display:grid;gap:10px;">
                            <div>
                                <label style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:5px;">Per transazione (KY)</label>
                                <input type="number" name="limit_per_transaction" min="1" max="9999999"
                                       value="{{ $card->limit_per_transaction }}"
                                       placeholder="Nessun limite"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:9px;padding:9px 12px;font-size:14px;color:var(--ink);background:var(--surface-soft);outline:none;box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:5px;">Giornaliero (KY)</label>
                                <input type="number" name="limit_daily" min="1" max="9999999"
                                       value="{{ $card->limit_daily }}"
                                       placeholder="Nessun limite"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:9px;padding:9px 12px;font-size:14px;color:var(--ink);background:var(--surface-soft);outline:none;box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:5px;">Mensile (KY)</label>
                                <input type="number" name="limit_monthly" min="1" max="9999999"
                                       value="{{ $card->limit_monthly }}"
                                       placeholder="Nessun limite"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:9px;padding:9px 12px;font-size:14px;color:var(--ink);background:var(--surface-soft);outline:none;box-sizing:border-box;">
                            </div>
                        </div>
                        <button type="submit" class="cta secondary" style="margin-top:14px;width:100%;">
                            Salva limiti
                        </button>
                    </form>
                </section>

                {{-- Blocco card --}}
                <section class="card card-pad">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:6px;">&#128274; Blocca card</div>
                    <div style="font-size:12px;color:var(--ink-muted);margin-bottom:14px;">
                        Impedisce nuovi pagamenti. Potrai sbloccarla in qualsiasi momento.
                    </div>
                    <form method="POST" action="{{ route('portal.nfc-cards.block', $card->uuid) }}">
                        @csrf
                        <button type="submit"
                                style="width:100%;background:#dc2626;border:none;border-radius:9px;color:#fff;font-size:14px;font-weight:700;padding:10px;cursor:pointer;"
                                onclick="return confirm('Bloccare la card {{ $card->serial_number }}?')">
                            Blocca card
                        </button>
                    </form>
                </section>
            @endif

            @if($card->status === 'blocked')
                <section class="card card-pad">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:6px;">&#128275; Sblocca card</div>
                    <div style="font-size:12px;color:var(--ink-muted);margin-bottom:14px;">
                        La card è bloccata. Sbloccala per riprendere i pagamenti.
                    </div>
                    <form method="POST" action="{{ route('portal.nfc-cards.unblock', $card->uuid) }}">
                        @csrf
                        <button type="submit" style="width:100%;background:var(--primary);border:none;border-radius:9px;color:#fff;font-size:14px;font-weight:700;padding:10px;cursor:pointer;">
                            Sblocca card
                        </button>
                    </form>
                </section>
            @endif

        </div>

    </div>
@endsection
