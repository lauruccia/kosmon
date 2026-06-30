@extends('layouts.portal')

@section('content')

@if(session('success'))
    <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger" style="margin-bottom:16px;">{{ session('error') }}</div>
@endif

{{-- ── CATALOGO CARD ────────────────────────────────────────────────────── --}}
@if($cards->isEmpty())
    <div class="card" style="padding:60px;text-align:center;color:var(--ink-muted);">
        <div style="font-size:48px;margin-bottom:14px;">💳</div>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Nessuna KYCard disponibile</div>
        <div style="font-size:13px;">Torna presto — nuove ricariche in arrivo.</div>
    </div>
@else
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;margin-bottom:32px;">
        @foreach($cards as $card)
        <a href="{{ route('portal.ky-cards.checkout', $card) }}"
           style="text-decoration:none;display:block;border-radius:14px;overflow:hidden;
                  border:2px solid {{ $card->ky_bonus > 0 ? '#bbf7d0' : 'var(--border)' }};
                  background:var(--card-bg);box-shadow:var(--shadow);
                  transition:transform .18s,box-shadow .18s;position:relative;"
           onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 10px 28px rgba(0,0,0,.11)'"
           onmouseout="this.style.transform='';this.style.boxShadow='var(--shadow)'">

            @if($card->ky_bonus > 0)
            <div style="position:absolute;top:10px;right:10px;
                        background:linear-gradient(135deg,#16a34a,#15803d);
                        color:#fff;font-size:10px;font-weight:800;
                        padding:3px 9px;border-radius:20px;letter-spacing:.04em;">
                {{ $card->bonus_label }}
            </div>
            @endif

            {{-- Header --}}
            <div style="background:linear-gradient(135deg,#0b2244 0%,#1e40af 100%);padding:16px 18px 14px;color:#fff;">
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;opacity:.6;margin-bottom:4px;">KYCard</div>
                <div style="font-size:17px;font-weight:800;letter-spacing:-.02em;">{{ $card->name }}</div>
                @if($card->description)
                    <div style="font-size:11.5px;opacity:.72;margin-top:2px;line-height:1.35;">{{ $card->description }}</div>
                @endif
                <div style="display:flex;gap:5px;margin-top:10px;flex-wrap:wrap;">
                    @if($card->stripe_price_id && config('services.stripe.key'))
                    <span style="background:rgba(255,255,255,.15);border-radius:5px;padding:2px 7px;font-size:10px;font-weight:600;">💳 Carta</span>
                    @endif
                    @if(config('services.paypal.client_id'))
                    <span style="background:rgba(255,255,255,.15);border-radius:5px;padding:2px 7px;font-size:10px;font-weight:600;">🅿 PayPal</span>
                    @endif
                    <span style="background:rgba(255,255,255,.15);border-radius:5px;padding:2px 7px;font-size:10px;font-weight:600;">🏦 Bonifico</span>
                </div>
            </div>

            {{-- Body --}}
            <div style="padding:14px 18px 16px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:{{ $card->ky_bonus > 0 ? '10px' : '14px' }};">
                    <div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:1px;">Paghi</div>
                        <div style="font-size:24px;font-weight:800;color:var(--ink);line-height:1;">{{ number_format($card->price_eur, 2, ',', '.') }}<span style="font-size:13px;font-weight:600;color:var(--ink-soft);"> €</span></div>
                    </div>
                    <div style="font-size:18px;color:var(--ink-muted);flex-shrink:0;">→</div>
                    <div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);margin-bottom:1px;">Ricevi</div>
                        <div style="font-size:24px;font-weight:800;color:#1d4ed8;line-height:1;">{{ ky_format($card->ky_total) }}<span style="font-size:13px;font-weight:600;"> KY</span></div>
                    </div>
                </div>

                @if($card->ky_bonus > 0)
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:6px 11px;margin-bottom:12px;font-size:12px;color:#166534;">
                    🎁 <strong>{{ ky_format($card->ky_base_amount) }} KY</strong> + <strong>{{ ky_format($card->ky_bonus) }} KY</strong> cashback
                </div>
                @endif

                <div style="background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;
                            border-radius:9px;padding:10px 14px;
                            display:flex;align-items:center;justify-content:space-between;
                            font-size:13px;font-weight:700;">
                    <span>Acquista ora</span>
                    <span style="font-size:16px;">›</span>
                </div>
            </div>
        </a>
        @endforeach
    </div>
@endif

{{-- ── ULTIMI ACQUISTI ─────────────────────────────────────────────────── --}}
@if($recentPurchases->isNotEmpty())
<div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);">I tuoi ultimi acquisti</div>
        <a href="{{ route('portal.ky-cards.storico') }}" style="font-size:12.5px;font-weight:600;color:var(--primary);text-decoration:none;">Vedi tutto lo storico →</a>
    </div>
    <div class="card" style="padding:0;overflow:hidden;">
        @foreach($recentPurchases as $p)
        <div style="display:flex;justify-content:space-between;align-items:center;
                    padding:12px 16px;
                    {{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:32px;height:32px;border-radius:8px;flex-shrink:0;
                            background:{{ $p->isCompleted() ? '#eff6ff' : ($p->isPendingBankTransfer() ? '#fffbeb' : '#fef2f2') }};
                            display:flex;align-items:center;justify-content:center;font-size:15px;">
                    {{ $p->isCompleted() ? '✅' : ($p->isPendingBankTransfer() ? '⏳' : '❌') }}
                </div>
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $p->kyCard->name ?? '—' }}</div>
                    <div style="font-size:11.5px;color:var(--ink-muted);">
                        {{ $p->created_at->format('d/m/Y H:i') }} &middot;
                        @if($p->payment_method === 'stripe') 💳 Carta
                        @elseif($p->payment_method === 'paypal') 🅿 PayPal
                        @else 🏦 Bonifico
                        @endif
                    </div>
                </div>
            </div>
            <div style="text-align:right;">
                @if($p->isCompleted())
                    <div style="font-size:14px;font-weight:800;color:#1d4ed8;">+{{ ky_format($p->ky_amount) }} KY</div>
                @elseif($p->isPendingBankTransfer())
                    <div style="font-size:12.5px;font-weight:700;color:#d97706;">In attesa bonifico</div>
                @elseif($p->isFailed())
                    <div style="font-size:12.5px;font-weight:700;color:#dc2626;">Fallito</div>
                @else
                    <div style="font-size:12.5px;color:var(--ink-muted);">In elaborazione…</div>
                @endif
                <div style="font-size:11.5px;color:var(--ink-muted);">{{ number_format($p->price_eur, 2, ',', '.') }} €</div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

@endsection
