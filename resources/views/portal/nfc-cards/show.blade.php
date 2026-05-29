@extends('layouts.portal')

@section('content')
<div class="stack">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <a href="{{ route('portal.nfc-cards.index') }}" style="font-size:13px;color:var(--ink-muted);">&#8592; Le mie card</a>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:6px 0 0;">
                Card {{ $card->serial_number ?? substr($card->uuid, 0, 8) }}
            </h1>
        </div>
    </div>

    @foreach(['portal_success','portal_error','portal_info'] as $key)
        @if(session($key))
            <div style="background:{{ $key==='portal_success' ? '#dcfce7' : ($key==='portal_error' ? '#fee2e2' : '#eff6ff') }};border:1px solid {{ $key==='portal_success' ? '#bbf7d0' : ($key==='portal_error' ? '#fecaca' : '#bfdbfe') }};border-radius:10px;padding:12px 16px;font-size:13px;color:{{ $key==='portal_success' ? '#166534' : ($key==='portal_error' ? '#991b1b' : '#1e40af') }};">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    @php
        $isActive  = $card->status === 'active';
        $isBlocked = $card->status === 'blocked';
    @endphp

    {{-- Layout 2 colonne: stato+uso | limiti --}}
    <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);gap:12px;align-items:start;">

        {{-- Colonna sinistra: stato + uso --}}
        <div class="stack">

            {{-- Stato card --}}
            <section class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="font-size:36px;line-height:1;">&#128246;</div>
                    <div>
                        <div style="font-size:15px;font-weight:700;color:var(--ink);">
                            {{ $isActive ? '✓ Card attiva' : ($isBlocked ? '⊘ Card bloccata' : 'Card revocata') }}
                        </div>
                        <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">
                            @if($card->last_used_at) Ultimo uso {{ $card->last_used_at->diffForHumans() }}
                            @else Mai usata @endif
                        </div>
                    </div>
                </div>
                @if($isActive)
                    <form method="POST" action="{{ route('portal.nfc-cards.block', $card->uuid) }}"
                          onsubmit="return confirm('Bloccare la card? Non sarà più usabile fino allo sblocco.')">
                        @csrf
                        <button type="submit" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">
                            &#128274; Blocca card
                        </button>
                    </form>
                @elseif($isBlocked)
                    <form method="POST" action="{{ route('portal.nfc-cards.unblock', $card->uuid) }}">
                        @csrf
                        <button type="submit" class="cta" style="font-size:13px;padding:8px 16px;white-space:nowrap;">
                            Sblocca card
                        </button>
                    </form>
                @endif
            </section>

            {{-- Uso corrente --}}
            @if($isActive || $isBlocked)
            <section class="card card-pad" style="background:var(--surface-soft);">
                <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;">Uso oggi / questo mese</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div>
                        <div style="font-size:11px;color:var(--ink-muted);margin-bottom:4px;">Speso oggi</div>
                        <div style="font-size:20px;font-weight:800;color:var(--ink);">{{ $card->daily_spent }} <span style="font-size:13px;font-weight:600;">KY</span></div>
                        @if($card->limit_daily)
                            <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">limite {{ $card->limit_daily }} KY</div>
                        @endif
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--ink-muted);margin-bottom:4px;">Speso questo mese</div>
                        <div style="font-size:20px;font-weight:800;color:var(--ink);">{{ $card->monthly_spent }} <span style="font-size:13px;font-weight:600;">KY</span></div>
                        @if($card->limit_monthly)
                            <div style="font-size:11px;color:var(--ink-muted);margin-top:2px;">limite {{ $card->limit_monthly }} KY</div>
                        @endif
                    </div>
                </div>
            </section>
            @endif

        </div>

        {{-- Colonna destra: limiti --}}
        @if($isActive || $isBlocked)
        <section class="card card-pad">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:16px;">Limiti di spesa</div>
            <form method="POST" action="{{ route('portal.nfc-cards.limits', $card->uuid) }}" class="stack">
                @csrf

                @php
                    $fields = [
                        ['name'=>'limit_per_transaction', 'label'=>'Limite per transazione', 'hint'=>'Max KY per singolo pagamento — lascia vuoto per nessun limite'],
                        ['name'=>'limit_daily',           'label'=>'Limite giornaliero',      'hint'=>'Max KY al giorno (reset mezzanotte) — lascia vuoto per nessun limite'],
                        ['name'=>'limit_monthly',         'label'=>'Limite mensile',           'hint'=>'Max KY nel mese solare — lascia vuoto per nessun limite'],
                    ];
                @endphp

                @foreach($fields as $f)
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:6px;">
                        {{ $f['label'] }}
                    </label>
                    <div style="display:flex;align-items:center;background:var(--surface-soft);border:1.5px solid var(--line);border-radius:10px;padding:4px 14px 4px 4px;">
                        <input type="number" name="{{ $f['name'] }}" min="1" max="9999999"
                               value="{{ $card->{$f['name']} }}"
                               placeholder="Nessun limite"
                               style="flex:1;border:none;background:transparent;padding:10px 10px;font-size:16px;font-weight:700;color:var(--ink);outline:none;min-width:0;">
                        <span style="font-size:13px;font-weight:700;color:var(--ink-muted);">KY</span>
                    </div>
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">{{ $f['hint'] }}</div>
                </div>
                @endforeach

                <button type="submit" class="cta" style="width:100%;margin-top:4px;">Salva limiti</button>
            </form>
        </section>
        @endif

    </div>

</div>
@endsection
