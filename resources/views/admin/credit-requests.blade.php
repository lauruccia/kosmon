@extends('layouts.portal')

@section('content')

@if(session('success'))
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;font-size:14px;color:#166534;margin-bottom:16px;">
    ✅ {{ session('success') }}
</div>
@endif

{{-- PENDING --}}
<section class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="card-title">Richieste in attesa</div>
            <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">{{ $pending->count() }} richiesta/e da valutare</div>
        </div>
        @if($pending->count())
        <span class="chip" style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;">{{ $pending->count() }} pending</span>
        @endif
    </div>

    @if($pending->isEmpty())
    <div style="padding:36px;text-align:center;color:var(--ink-muted);font-size:14px;">
        Nessuna richiesta in attesa. 🎉
    </div>
    @else
    @foreach($pending as $req)
    <div style="padding:20px 20px;border-bottom:1px solid var(--line);display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;">

        {{-- Info azienda + richiesta --}}
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--grad-hero);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;flex-shrink:0;">
                    {{ strtoupper(substr($req->account->ownerLabel ?? '?', 0, 2)) }}
                </div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:var(--ink);">{{ $req->account->ownerLabel ?? 'N/D' }}</div>
                    <div style="font-size:12px;color:var(--ink-muted);">Richiesta il {{ $req->created_at->format('d/m/Y H:i') }}</div>
                </div>
                <div style="margin-left:auto;text-align:right;">
                    <div style="font-size:22px;font-weight:900;color:var(--ink);">{{ ky_format($req->requested_amount) }} <span style="font-size:13px;color:var(--ink-muted);">KY</span></div>
                    <div style="font-size:11px;color:var(--ink-muted);">importo richiesto</div>
                </div>
            </div>

            @php
                $saldoConto   = (int) $req->account->available_balance;
                $fidoAttuale  = $req->account->massimale();
                $fidoTotale   = $fidoAttuale + $req->requested_amount;
            @endphp
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                <span style="background:var(--bg-soft,#f3f4f6);border-radius:6px;padding:4px 10px;font-size:12px;color:var(--ink-soft);">
                    Saldo: <strong>{{ ky_format($saldoConto) }} KY</strong>
                </span>
                <span style="background:var(--bg-soft,#f3f4f6);border-radius:6px;padding:4px 10px;font-size:12px;color:var(--ink-soft);">
                    Fido attuale: <strong>{{ $fidoAttuale > 0 ? ky_format($fidoAttuale) . ' KY' : 'nessuno' }}</strong>
                </span>
                <span style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:4px 10px;font-size:12px;color:#1e40af;font-weight:700;">
                    Se approvi {{ number_format($req->requested_amount, 0, ',', '.') }} KY → totale {{ number_format($fidoTotale, 0, ',', '.') }} KY
                </span>
            </div>

            @if($req->reason)
            <div style="background:var(--bg-soft,#f9fafb);border:1px solid var(--line);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--ink-soft);font-style:italic;">
                "{{ $req->reason }}"
            </div>
            @endif
        </div>

        {{-- Azioni --}}
        <div style="display:flex;flex-direction:column;gap:10px;min-width:280px;">

            {{-- Approva --}}
            <form method="POST" action="{{ route('admin.credit-requests.approve', $req) }}">
                @csrf
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <div style="font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.06em;">Approva</div>
                        <div id="totale-preview-{{ $req->id }}" style="font-size:11px;color:#166534;font-weight:600;">
                            Totale → <strong>{{ number_format($fidoTotale, 0, ',', '.') }} KY</strong>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                        <div>
                            <label style="font-size:11px;color:#166534;font-weight:600;display:block;margin-bottom:3px;">
                                Importo aggiuntivo (KY)
                                @if($fidoAttuale > 0)
                                <span style="font-weight:400;opacity:.8;">+ {{ number_format($fidoAttuale, 0, ',', '.') }} att.</span>
                                @endif
                            </label>
                            <input type="number" name="approved_amount" min="1" value="{{ $req->requested_amount }}"
                                id="approved-{{ $req->id }}"
                                data-current="{{ $fidoAttuale }}"
                                data-preview="totale-preview-{{ $req->id }}"
                                style="width:100%;padding:7px 10px;border:1px solid #86efac;border-radius:6px;font-size:14px;font-weight:700;background:#fff;box-sizing:border-box;"
                                required
                                oninput="(function(el){
                                    var add=parseInt(el.value)||0;
                                    var cur=parseInt(el.dataset.current)||0;
                                    var tot=cur+add;
                                    document.getElementById(el.dataset.preview).innerHTML='Totale &rarr; <strong>'+tot.toLocaleString('it-IT')+' KY</strong>';
                                })(this)">
                        </div>
                        <div>
                            <label style="font-size:11px;color:#166534;font-weight:600;display:block;margin-bottom:3px;">Limite giorn. (facolt.)</label>
                            <input type="number" name="daily_outgoing_limit" min="0" placeholder="illimitato"
                                style="width:100%;padding:7px 10px;border:1px solid #86efac;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box;">
                        </div>
                    </div>
                    <div style="margin-bottom:8px;">
                        <label style="font-size:11px;color:#166534;font-weight:600;display:block;margin-bottom:3px;">Nota per l'azienda (facolt.)</label>
                        <input type="text" name="admin_note" maxlength="500" placeholder="es. Approvato per 6 mesi in via sperimentale"
                            style="width:100%;padding:7px 10px;border:1px solid #86efac;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box;">
                    </div>
                    <button type="submit" style="width:100%;padding:9px;background:#16a34a;color:#fff;border:none;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer;">
                        ✅ Approva fido
                    </button>
                </div>
            </form>

            {{-- Rifiuta --}}
            <form method="POST" action="{{ route('admin.credit-requests.reject', $req) }}">
                @csrf
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px;">
                    <div style="font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">Rifiuta</div>
                    <div style="margin-bottom:8px;">
                        <label style="font-size:11px;color:#991b1b;font-weight:600;display:block;margin-bottom:3px;">Motivazione (obbligatoria)</label>
                        <input type="text" name="admin_note" maxlength="500" placeholder="es. Profilo non ancora sufficiente"
                            style="width:100%;padding:7px 10px;border:1px solid #fca5a5;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box;"
                            required>
                    </div>
                    <button type="submit"
                        onclick="return confirm('Confermi il rifiuto della richiesta di {{ $req->account->ownerLabel }}?')"
                        style="width:100%;padding:9px;background:#dc2626;color:#fff;border:none;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer;">
                        ❌ Rifiuta richiesta
                    </button>
                </div>
            </form>

        </div>
    </div>
    @endforeach
    @endif
</section>

{{-- STORICO --}}
@if($recent->isNotEmpty())
<section class="card" style="padding:0;overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid var(--line);">
        <div class="card-title">Ultime richieste evase</div>
    </div>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Azienda</th>
                <th style="text-align:right;">Richiesto</th>
                <th style="text-align:right;">Approvato</th>
                <th style="text-align:center;">Stato</th>
                <th>Nota admin</th>
                <th>Valutato il</th>
                <th>Da</th>
            </tr>
        </thead>
        <tbody>
            @foreach($recent as $r)
            <tr>
                <td style="font-weight:600;">{{ $r->account->ownerLabel ?? 'N/D' }}</td>
                <td style="text-align:right;">{{ ky_format($r->requested_amount) }} KY</td>
                <td style="text-align:right;font-weight:700;">
                    @if($r->isApproved())
                        {{ ky_format($r->approved_amount) }} KY
                        @if($r->approved_amount != $r->requested_amount)
                        <span style="font-size:10px;color:var(--ink-muted);">(mod.)</span>
                        @endif
                    @else
                        <span style="color:var(--ink-muted);">—</span>
                    @endif
                </td>
                <td style="text-align:center;">
                    <span class="chip {{ $r->isApproved() ? 'success' : '' }}" style="font-size:10px;{{ $r->isRejected() ? 'background:#fef2f2;color:#991b1b;' : '' }}">
                        {{ $r->isApproved() ? 'Approvata' : 'Rifiutata' }}
                    </span>
                </td>
                <td style="font-size:12px;color:var(--ink-soft);max-width:180px;">{{ $r->admin_note ?? '—' }}</td>
                <td style="font-size:12px;color:var(--ink-muted);">{{ $r->actioned_at?->format('d/m/Y') ?? '—' }}</td>
                <td style="font-size:12px;color:var(--ink-muted);">{{ $r->admin?->name ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>
@endif

@endsection
