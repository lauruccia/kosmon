@extends('layouts.portal')

@section('content')
    <div class="portal-grid" style="max-width:640px;">
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

            {{-- Card principale --}}
            <section class="card card-pad">
                @php
                    $statusMap = [
                        'active'    => ['bg' => '#dcfce7', 'text' => '#166534', 'label' => 'Attiva'],
                        'delivered' => ['bg' => '#fef9c3', 'text' => '#854d0e', 'label' => 'Da attivare'],
                        'blocked'   => ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => 'Bloccata'],
                        'revoked'   => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'label' => 'Revocata'],
                    ];
                    $st = $statusMap[$card->status] ?? ['bg' => '#f3f4f6', 'text' => '#6b7280', 'label' => ucfirst($card->status)];
                @endphp

                <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
                    <div style="width:56px;height:56px;border-radius:16px;background:var(--primary-soft,#eff6ff);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0;">
                        &#128179;
                    </div>
                    <div>
                        <div style="font-size:18px;font-weight:800;color:var(--ink);">
                            {{ $card->serial_number ?? 'Card ' . substr($card->uuid, 0, 8) }}
                        </div>
                        <div style="margin-top:4px;">
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:{{ $st['bg'] }};color:{{ $st['text'] }};">
                                {{ $st['label'] }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Info card --}}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
                    @if($card->activated_at)
                        <div style="padding:12px 14px;background:var(--surface-soft);border-radius:10px;border:1px solid var(--line);">
                            <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Attivata</div>
                            <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $card->activated_at->format('d/m/Y') }}</div>
                        </div>
                    @endif
                    @if($card->last_used_at)
                        <div style="padding:12px 14px;background:var(--surface-soft);border-radius:10px;border:1px solid var(--line);">
                            <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Ultimo uso</div>
                            <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $card->last_used_at->format('d/m/Y H:i') }}</div>
                        </div>
                    @endif
                    <div style="padding:12px 14px;background:var(--surface-soft);border-radius:10px;border:1px solid var(--line);">
                        <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Speso oggi</div>
                        <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ number_format($card->daily_spent ?? 0, 0, ',', '.') }} KY</div>
                    </div>
                    <div style="padding:12px 14px;background:var(--surface-soft);border-radius:10px;border:1px solid var(--line);">
                        <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">Speso questo mese</div>
                        <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ number_format($card->monthly_spent ?? 0, 0, ',', '.') }} KY</div>
                    </div>
                </div>

                {{-- PIN bloccato --}}
                @if($card->isPinLocked())
                    <div style="padding:12px 14px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:13px;margin-bottom:16px;">
                        &#9888; PIN bloccato fino alle {{ $card->pin_locked_until->format('H:i') }} del {{ $card->pin_locked_until->format('d/m/Y') }}.
                    </div>
                @endif

                {{-- Azioni da attivare --}}
                @if($card->status === 'delivered')
                    <a href="{{ route('portal.nfc-cards.activate', $card->uuid) }}" class="cta" style="display:block;text-align:center;margin-bottom:0;">
                        &#128274; Attiva card con PIN
                    </a>
                @endif
            </section>

            {{-- Limiti --}}
            @if($card->status === 'active')
                <section class="card card-pad">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:16px;">&#128203; Limiti di spesa</div>
                    <form method="POST" action="{{ route('portal.nfc-cards.limits', $card->uuid) }}">
                        @csrf
                        <div style="display:grid;gap:14px;">
                            <div>
                                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px;">
                                    Per transazione (KY)
                                </label>
                                <input type="number" name="limit_per_transaction" min="1" max="9999999"
                                       value="{{ $card->limit_per_transaction }}"
                                       placeholder="Nessun limite"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;font-size:14px;color:var(--ink);background:var(--surface-soft);outline:none;">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px;">
                                    Giornaliero (KY)
                                </label>
                                <input type="number" name="limit_daily" min="1" max="9999999"
                                       value="{{ $card->limit_daily }}"
                                       placeholder="Nessun limite"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;font-size:14px;color:var(--ink);background:var(--surface-soft);outline:none;">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px;">
                                    Mensile (KY)
                                </label>
                                <input type="number" name="limit_monthly" min="1" max="9999999"
                                       value="{{ $card->limit_monthly }}"
                                       placeholder="Nessun limite"
                                       style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;font-size:14px;color:var(--ink);background:var(--surface-soft);outline:none;">
                            </div>
                        </div>
                        <button type="submit" class="cta secondary" style="margin-top:16px;width:100%;">
                            Salva limiti
                        </button>
                    </form>
                </section>

                {{-- Blocco/sblocco --}}
                <section class="card card-pad">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:8px;">&#128274; Stato card</div>
                    <div style="font-size:13px;color:var(--ink-muted);margin-bottom:16px;">
                        Blocca la card per impedire nuovi pagamenti. Potrai sbloccarla in qualsiasi momento.
                    </div>
                    <form method="POST" action="{{ route('portal.nfc-cards.block', $card->uuid) }}">
                        @csrf
                        <button type="submit" class="cta"
                                style="background:#dc2626;border-color:#dc2626;width:100%;"
                                onclick="return confirm('Bloccare la card {{ $card->serial_number }}? Nessun pagamento sarà accettato fino allo sblocco.')">
                            Blocca card
                        </button>
                    </form>
                </section>
            @endif

            @if($card->status === 'blocked')
                <section class="card card-pad">
                    <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:8px;">&#128275; Sblocca card</div>
                    <div style="font-size:13px;color:var(--ink-muted);margin-bottom:16px;">
                        La card è attualmente bloccata. Sbloccala per riprendere i pagamenti.
                    </div>
                    <form method="POST" action="{{ route('portal.nfc-cards.unblock', $card->uuid) }}">
                        @csrf
                        <button type="submit" class="cta" style="width:100%;">
                            Sblocca card
                        </button>
                    </form>
                </section>
            @endif

        </div>
    </div>
@endsection
