@extends('layouts.portal')

@section('content')

@if(session('success'))
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;font-size:14px;color:#166534;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
    ✅ {{ session('success') }}
</div>
@endif

@php
    $limit = $activeLimit;
    $saldo = (int) $currentAccount->available_balance;
    // $massimale viene dal controller: MAX(CreditLimit.credit_limit, User.negative_balance_limit)
    $fidoEffettivo = $limit ? (int) $limit->credit_limit : (int) $massimale;
    $isStandard = !$limit && $fidoEffettivo > 0; // fido da soglia utente, non da record CreditLimit
    $usato = $fidoEffettivo > 0 ? max(0, -$saldo) : 0;
    $disponibile = $fidoEffettivo > 0 ? max(0, $fidoEffettivo - $usato) : 0;
    $pct = $fidoEffettivo > 0 ? round($usato / $fidoEffettivo * 100) : 0;
@endphp

@if($limit || $fidoEffettivo > 0)
{{-- Hero fido --}}
<div style="background:var(--grad-hero);border-radius:var(--radius-lg);padding:24px 28px;margin-bottom:20px;color:#fff;border:1px solid rgba(255,255,255,.07);position:relative;overflow:hidden;">
    <div style="position:absolute;top:-50px;right:-50px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(79,70,229,.25),transparent 70%);pointer-events:none;"></div>
    <div style="position:relative;z-index:1;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.65;margin-bottom:8px;">
            {{ $isStandard ? 'Fido standard' : 'Fido attivo' }}
        </div>
        <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:18px;">
            <span style="font-size:42px;font-weight:900;line-height:1;">{{ ky_format($fidoEffettivo) }}</span>
            <span style="font-size:16px;font-weight:700;opacity:.75;">KY</span>
        </div>

        {{-- Barra utilizzo --}}
        <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;font-size:12px;opacity:.75;margin-bottom:6px;">
                <span>Utilizzato: <strong>{{ ky_format($usato) }} KY</strong></span>
                <span>Residuo: <strong>{{ ky_format($disponibile) }} KY</strong></span>
            </div>
            <div style="height:8px;background:rgba(255,255,255,.18);border-radius:999px;overflow:hidden;">
                <div style="height:100%;width:{{ min(100, $pct) }}%;background:{{ $pct > 80 ? '#f87171' : ($pct > 50 ? '#fbbf24' : '#34d399') }};border-radius:999px;transition:width .4s;"></div>
            </div>
            <div style="font-size:11px;opacity:.6;margin-top:5px;">{{ $pct }}% del fido utilizzato</div>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:12px;">
            @if(!$isStandard && $limit->daily_outgoing_limit)
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;">
                <div style="font-size:10px;opacity:.65;font-weight:700;margin-bottom:2px;">LIMITE GIORNALIERO</div>
                <div style="font-size:16px;font-weight:800;">{{ ky_format($limit->daily_outgoing_limit) }} <span style="font-size:11px;opacity:.7;">KY</span></div>
            </div>
            @endif
            @if(!$isStandard && $limit->single_transfer_limit)
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;">
                <div style="font-size:10px;opacity:.65;font-weight:700;margin-bottom:2px;">LIMITE PER MOVIMENTO</div>
                <div style="font-size:16px;font-weight:800;">{{ ky_format($limit->single_transfer_limit) }} <span style="font-size:11px;opacity:.7;">KY</span></div>
            </div>
            @endif
            @if(!$isStandard)
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;">
                <div style="font-size:10px;opacity:.65;font-weight:700;margin-bottom:2px;">ATTIVATO IL</div>
                <div style="font-size:14px;font-weight:700;">{{ $limit->approved_at?->format('d/m/Y') ?? '—' }}</div>
            </div>
            @else
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;">
                <div style="font-size:10px;opacity:.65;font-weight:700;margin-bottom:2px;">TIPO</div>
                <div style="font-size:14px;font-weight:700;">Soglia operativa standard</div>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Spiegazione --}}
<div class="card light-card" style="padding:18px 20px;margin-bottom:20px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Come funziona il fido</div>
            <p style="font-size:13px;color:var(--ink-soft);line-height:1.55;margin:0;">
                Il fido ti consente di operare in negativo fino al limite concesso. Il saldo disponibile è calcolato come <strong>saldo reale + fido residuo</strong>.
            </p>
        </div>
        <div>
            <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Rientro del fido</div>
            <p style="font-size:13px;color:var(--ink-soft);line-height:1.55;margin:0;">
                Ogni pagamento ricevuto riduce automaticamente il fido utilizzato. Non è richiesta alcuna azione manuale.
            </p>
        </div>
        <div>
            <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Modifica limite</div>
            <p style="font-size:13px;color:var(--ink-soft);line-height:1.55;margin:0;">
                Per richiedere un aumento o una riduzione del fido, contatta il tuo operatore di riferimento.
            </p>
        </div>
    </div>
</div>

@else
{{-- Nessun fido attivo --}}
<div class="card light-card" style="padding:20px 24px;margin-bottom:20px;text-align:center;">
    <div style="font-size:36px;margin-bottom:8px;">🏦</div>
    <p style="font-size:14px;color:var(--ink-soft);margin:0;">Il tuo conto opera attualmente <strong>senza fido</strong>. Non puoi andare in negativo.</p>
</div>
@endif

{{-- ═══ SEZIONE RICHIESTA — sempre visibile ═══ --}}

@if($recentRequest?->isRejected())
{{-- Esito ultima richiesta: rifiutata --}}
<div class="card light-card" style="padding:18px 22px;margin-bottom:16px;border-left:4px solid #f87171;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <span style="font-size:18px;">❌</span>
        <span style="font-size:14px;font-weight:700;color:var(--ink);">Ultima richiesta non approvata</span>
        <span style="margin-left:auto;font-size:12px;color:var(--ink-muted);">{{ $recentRequest->actioned_at?->format('d/m/Y') }}</span>
    </div>
    <p style="font-size:13px;color:var(--ink-soft);margin:0 0 2px;">
        Importo richiesto: <strong>{{ ky_format($recentRequest->requested_amount) }} KY</strong>
    </p>
    @if($recentRequest->admin_note)
    <p style="font-size:13px;color:var(--ink-soft);margin:0;">Motivazione: <em>{{ $recentRequest->admin_note }}</em></p>
    @endif
</div>
@endif

@if($pendingRequest)
{{-- Richiesta in attesa --}}
<div class="card light-card" style="padding:22px;margin-bottom:20px;border-left:4px solid #f59e0b;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
        <span style="font-size:20px;">⏳</span>
        <span style="font-size:15px;font-weight:700;color:var(--ink);">Richiesta in valutazione</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:10px;">
        <div>
            <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Importo richiesto</div>
            <div style="font-size:20px;font-weight:800;color:var(--ink);">{{ ky_format($pendingRequest->requested_amount) }} <span style="font-size:13px;font-weight:600;color:var(--ink-soft);">KY</span></div>
        </div>
        <div>
            <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Data invio</div>
            <div style="font-size:15px;font-weight:600;color:var(--ink);">{{ $pendingRequest->created_at->format('d/m/Y H:i') }}</div>
        </div>
        @if($pendingRequest->reason)
        <div>
            <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Motivazione</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $pendingRequest->reason }}</div>
        </div>
        @endif
    </div>
    <p style="font-size:13px;color:var(--ink-soft);margin:0;">Riceverai una notifica appena l'operatore prenderà una decisione.</p>
</div>

@else
{{-- Form richiesta (nuova o aumento) --}}
<div class="card light-card" style="padding:22px;margin-bottom:20px;">
    <div style="margin-bottom:14px;">
        <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px;">
            {{ $fidoEffettivo > 0 ? 'Richiedi una modifica al fido' : 'Richiedi un fido personalizzato' }}
        </div>
        <p style="font-size:13px;color:var(--ink-soft);margin:0;">
            {{ $fidoEffettivo > 0
                ? 'Indica il nuovo importo desiderato. Il tuo operatore potrà approvarlo, modificarlo o rifiutarlo.'
                : 'Indica l\'importo desiderato. Il tuo operatore valuterà la richiesta.' }}
        </p>
    </div>

    @if(session('error'))
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:13px;color:#b91c1c;margin-bottom:14px;">
        {{ session('error') }}
    </div>
    @endif

    <form method="POST" action="{{ route('portal.fido.request') }}">
        @csrf
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">

            {{-- Importo --}}
            <div style="flex:0 0 160px;">
                <label style="display:flex;justify-content:space-between;align-items:baseline;font-size:11px;font-weight:700;color:var(--ink-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;">
                    <span>{{ $fidoEffettivo > 0 ? 'Aggiuntivo' : 'Importo' }}</span>
                    @if($fidoEffettivo > 0)
                    <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:10px;">att. {{ number_format($fidoEffettivo, 0, ',', '.') }} KY</span>
                    @endif
                </label>
                <div style="position:relative;">
                    <input type="number" name="requested_amount" min="1" max="9999999" step="1"
                        value="{{ old('requested_amount') }}"
                        placeholder="es. 100"
                        style="width:100%;padding:9px 36px 9px 10px;border:1px solid var(--line);border-radius:8px;font-size:15px;font-weight:700;background:var(--bg);color:var(--ink);box-sizing:border-box;"
                        required>
                    <span style="position:absolute;right:9px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:700;color:var(--ink-muted);">KY</span>
                </div>
                @error('requested_amount')
                <div style="font-size:11px;color:#ef4444;margin-top:2px;">{{ $message }}</div>
                @enderror
            </div>

            {{-- Motivazione --}}
            <div style="flex:1;min-width:180px;">
                <label style="display:block;font-size:11px;font-weight:700;color:var(--ink-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;">
                    Motivazione <span style="font-weight:400;">(facoltativa)</span>
                </label>
                <input type="text" name="reason" maxlength="500"
                    placeholder="Descrivi brevemente lo scopo del fido..."
                    value="{{ old('reason') }}"
                    style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--bg);color:var(--ink);box-sizing:border-box;">
            </div>

            {{-- Pulsante --}}
            <div style="flex:0 0 auto;">
                <button type="submit" class="btn btn-primary" style="padding:9px 22px;white-space:nowrap;">
                    Invia richiesta
                </button>
            </div>

        </div>
    </form>
</div>
@endif

{{-- Storico limiti --}}
@if(!$isStandard && $limitHistory->count() > 0)
<section class="card" style="padding:0;overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--line);">
        <div class="card-title">Storico limiti</div>
    </div>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Data impostazione</th>
                <th style="text-align:right;">Fido</th>
                <th style="text-align:right;">Giornaliero</th>
                <th style="text-align:right;">Per movimento</th>
                <th style="text-align:center;">Stato</th>
            </tr>
        </thead>
        <tbody>
            @foreach($limitHistory as $l)
            <tr>
                <td style="font-size:12px;">{{ $l->approved_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td style="text-align:right;font-weight:700;">{{ ky_format($l->credit_limit) }} KY</td>
                <td style="text-align:right;color:var(--ink-soft);">{{ $l->daily_outgoing_limit ? ky_format($l->daily_outgoing_limit) . ' KY' : '—' }}</td>
                <td style="text-align:right;color:var(--ink-soft);">{{ $l->single_transfer_limit ? ky_format($l->single_transfer_limit) . ' KY' : '—' }}</td>
                <td style="text-align:center;">
                    <span class="chip {{ $l->status === 'active' ? 'success' : '' }}" style="font-size:10px;">
                        {{ $l->status === 'active' ? 'Attivo' : 'Storico' }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>
@endif

@endsection
