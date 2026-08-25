@extends('layouts.portal')

@section('content')
<div style="max-width:760px;margin:0 auto;">

    <div style="margin-bottom:20px;">
        <h2 style="font-size:20px;font-weight:800;margin:0 0 6px;">App collegate</h2>
        <p style="font-size:14px;color:var(--text-muted);margin:0;">
            Le applicazioni del circuito a cui hai dato il permesso di addebitare KY sul tuo conto senza chiederti conferma ogni volta.
        </p>
    </div>

    @if(session('status'))
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#166534;">
            {{ session('status') }}
        </div>
    @endif

    @if(session('portal_error'))
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#b91c1c;">
            {{ session('portal_error') }}
        </div>
    @endif

    @if($errors->has('max_per_transaction'))
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#b91c1c;">
            {{ $errors->first('max_per_transaction') }}
        </div>
    @endif

    @forelse($mandates as $mandate)
        @php
            $attiva = $mandate->isActive();
        @endphp
        <section class="card card-pad" style="margin-bottom:16px;">

            <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">
                <div style="width:44px;height:44px;border-radius:12px;background:{{ $attiva ? '#eff6ff' : '#f8fafc' }};display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;">
                    &#x1F6CD;
                </div>
                <div style="flex:1;">
                    <div style="font-weight:700;font-size:15px;">{{ $appNames[$mandate->client_id] ?? $mandate->client_id }}</div>
                    <div style="font-size:13px;color:{{ $attiva ? 'var(--text-muted)' : '#b91c1c' }};">
                        {{ $mandate->statusLabel() }}
                        @if($attiva)
                            &middot; scade il {{ $mandate->expires_at->format('d/m/Y') }}
                        @endif
                    </div>
                </div>
                @if($mandate->isSuspended() && ! $mandate->isRevoked() && ! $mandate->isExpired())
                    {{-- Sospeso dall'antifurto: senza questo bottone l'unica via
                         d'uscita sarebbe revocare e rifare tutto da capo. --}}
                    <form method="POST" action="{{ route('portal.connected-apps.reactivate', $mandate->uuid) }}" style="margin:0;">
                        @csrf
                        <button type="submit"
                                style="padding:8px 16px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;font-size:13px;font-weight:600;color:#047857;cursor:pointer;">
                            Riattiva
                        </button>
                    </form>
                @endif
                @if(! $mandate->isRevoked())
                    <form method="POST" action="{{ route('portal.connected-apps.revoke', $mandate->uuid) }}" style="margin:0;">
                        @csrf
                        <button type="submit"
                                style="padding:8px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:13px;font-weight:600;color:#b91c1c;cursor:pointer;">
                            Revoca
                        </button>
                    </form>
                @endif
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;font-size:13px;">
                <div>
                    <div style="color:var(--text-muted);margin-bottom:2px;">Tetto per acquisto</div>
                    <div style="font-weight:700;">{{ ky_format($mandate->max_per_transaction) }} KY</div>
                </div>
                <div>
                    <div style="color:var(--text-muted);margin-bottom:2px;">Venditori autorizzati</div>
                    <div style="font-weight:700;">{{ count($mandate->authorized_sellers ?? []) }}</div>
                </div>
                <div>
                    {{-- Non più "automatici": da quando c'è il ramo della
                         conferma, questo contatore comprende anche gli acquisti
                         che l'utente ha confermato a mano. L'elenco qui sotto
                         distingue i due casi uno per uno. --}}
                    <div style="color:var(--text-muted);margin-bottom:2px;">Addebiti</div>
                    <div style="font-weight:700;">{{ $mandate->charges_count }}</div>
                </div>
                <div>
                    <div style="color:var(--text-muted);margin-bottom:2px;">Ultimo utilizzo</div>
                    <div style="font-weight:700;">{{ $mandate->last_used_at?->format('d/m/Y H:i') ?? 'mai' }}</div>
                </div>
            </div>

            @if($attiva)
                <form method="POST" action="{{ route('portal.connected-apps.limit', $mandate->uuid) }}"
                      style="margin:16px 0 0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    @csrf
                    <label style="font-size:13px;color:var(--text-muted);">Cambia il tetto:</label>
                    <input type="number" name="max_per_transaction" step="0.01"
                           min="{{ number_format($minCap / 100, 2, '.', '') }}"
                           max="{{ number_format($maxCap / 100, 2, '.', '') }}"
                           value="{{ number_format($mandate->max_per_transaction / 100, 2, '.', '') }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:14px;">
                    <span style="font-size:13px;font-weight:700;color:var(--text-muted);">KY</span>
                    <button type="submit"
                            style="padding:8px 14px;background:var(--surface-soft);border:1px solid var(--line);border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                        Salva
                    </button>
                </form>
            @endif

        </section>
    @empty
        <section class="card card-pad" style="text-align:center;padding:40px 20px;">
            <div style="font-size:40px;line-height:1;margin-bottom:12px;">&#x1F517;</div>
            <div style="font-weight:700;font-size:15px;margin-bottom:6px;">Nessuna app collegata</div>
            <p style="font-size:13px;color:var(--text-muted);margin:0;line-height:1.5;">
                Quando autorizzerai un'applicazione del circuito a pagare per tuo conto,
                comparirà qui — con il suo tetto di spesa e un pulsante per revocarla.
            </p>
        </section>
    @endforelse

    @if($charges->isNotEmpty())
        <section class="card card-pad" style="margin-top:24px;">
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Ultimi addebiti</div>
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;">
                Quelli eseguiti in un clic e quelli che hai confermato tu, distinti riga per riga.
            </p>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:left;color:var(--text-muted);">
                            <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Quando</th>
                            <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Cosa</th>
                            <th style="padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;">Importo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($charges as $charge)
                            <tr>
                                <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">
                                    {{ $charge->created_at->format('d/m/Y H:i') }}
                                </td>
                                <td style="padding:10px;border-bottom:1px solid var(--border);">
                                    {{ $charge->order_title ?? 'Acquisto' }}
                                    @if($charge->quantity > 1)
                                        <span style="color:var(--text-muted);">(x{{ $charge->quantity }})</span>
                                    @endif
                                    @if($charge->mandatePaymentRequest)
                                        <span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:600;">confermato da te</span>
                                    @else
                                        <span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:600;">in un clic</span>
                                    @endif
                                </td>
                                <td style="padding:10px;border-bottom:1px solid var(--border);text-align:right;font-weight:700;white-space:nowrap;">
                                    {{ ky_format($charge->amount) }} KY
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

</div>
@endsection
