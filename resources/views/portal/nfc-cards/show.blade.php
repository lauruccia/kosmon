@extends('layouts.portal')

@section('content')
<div style="max-width:520px;">
    <div class="stack">

        <div>
            <a href="{{ route('portal.nfc-cards.index') }}" style="font-size:13px;color:var(--ink-muted);">&#8592; Le mie card</a>
            <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:8px 0 4px;">
                Card {{ $card->serial_number ?? substr($card->uuid, 0, 8) }}
            </h1>
        </div>

        @foreach(['portal_success','portal_error','portal_info'] as $key)
            @if(session($key))
                <div style="background:{{ $key==='portal_success' ? '#dcfce7' : ($key==='portal_error' ? '#fee2e2' : '#eff6ff') }};border:1px solid {{ $key==='portal_success' ? '#bbf7d0' : ($key==='portal_error' ? '#fecaca' : '#bfdbfe') }};border-radius:10px;padding:12px 16px;font-size:13px;color:{{ $key==='portal_success' ? '#166534' : ($key==='portal_error' ? '#991b1b' : '#1e40af') }};">
                    {{ session($key) }}
                </div>
            @endif
        @endforeach

        {{-- Stato --}}
        @php
            $isActive  = $card->status === 'active';
            $isBlocked = $card->status === 'blocked';
        @endphp
        <section class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="font-size:40px;">&#128246;</div>
                <div>
                    <div style="font-size:14px;font-weight:700;color:var(--ink);">
                        {{ $isActive ? '✓ Card attiva' : ($isBlocked ? '⊘ Card bloccata' : 'Card revocata') }}
                    </div>
                    <div style="font-size:12px;color:var(--ink-muted);">
                        @if($card->last_used_at) Ultimo uso {{ $card->last_used_at->diffForHumans() }}
                        @else Mai usata @endif
                    </div>
                </div>
            </div>
            @if($isActive)
                <form method="POST" action="{{ route('portal.nfc-cards.block', $card->uuid) }}"
                      onsubmit="return confirm('Bloccare la card? Non sarà più usabile fino allo sblocco.')">
                    @csrf
                    <button type="submit" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;">
                        &#128274; Blocca card
                    </button>
                </form>
            @elseif($isBlocked)
                <form method="POST" action="{{ route('portal.nfc-cards.unblock', $card->uuid) }}">
                    @csrf
                    <button type="submit" class="cta" style="font-size:13px;padding:8px 16px;">
                        Sblocca card
                    </button>
                </form>
            @endif
        </section>

        {{-- Limiti --}}
        @if($isActive || $isBlocked)
        <section class="card card-pad">
            <div style="font-size:14px;font-weight:700;color:var(--ink);margin-bottom:14px;">Limiti di spesa</div>
            <form method="POST" action="{{ route('portal.nfc-cards.limits', $card->uuid) }}" class="stack">
                @csrf

                @php
                    $fields = [
                        ['name'=>'limit_per_transaction', 'label'=>'Limite per transazione', 'hint'=>'Max KY per singolo pagamento'],
                        ['name'=>'limit_daily',           'label'=>'Limite giornaliero',      'hint'=>'Max KY al giorno (reset mezzanotte)'],
                        ['name'=>'limit_monthly',         'label'=>'Limite mensile',           'hint'=>'Max KY nel mese solare'],
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
                    <div style="font-size:11px;color:var(--ink-muted);margin-top:3px;">{{ $f['hint'] }} — lascia vuoto per nessun limite</div>
                </div>
                @endforeach

                <button type="submit" class="cta" style="width:100%;">Salva limiti</button>
            </form>
        </section>

        {{-- Uso corrente --}}
        <section class="card card-pad" style="background:var(--surface-soft);">
            <div style="font-size:13px;font-weight:700;color:var(--ink-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.06em;">Uso oggi / questo mese</div>
            <div style="display:flex;gap:24px;font-size:14px;">
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">Speso oggi</div>
                    <div style="font-weight:800;color:var(--ink);">{{ $card->daily_spent }} KY
                        @if($card->limit_daily) <span style="font-weight:400;color:var(--ink-muted);">/ {{ $card->limit_daily }}</span> @endif
                    </div>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--ink-muted);margin-bottom:2px;">Speso questo mese</div>
                    <div style="font-weight:800;color:var(--ink);">{{ $card->monthly_spent }} KY
                        @if($card->limit_monthly) <span style="font-weight:400;color:var(--ink-muted);">/ {{ $card->limit_monthly }}</span> @endif
                    </div>
                </div>
            </div>
        </section>
        @endif

    </div>
</div>
@endsection
