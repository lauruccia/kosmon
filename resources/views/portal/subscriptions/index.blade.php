@extends('layouts.portal')

@section('content')
<div style="max-width:860px;margin:0 auto;">

    <div style="margin-bottom:20px;">
        <h2 style="font-size:20px;font-weight:800;margin:0 0 6px;">Abbonamenti</h2>
        <p style="font-size:14px;color:var(--text-muted);margin:0;">
            Ciò che i portali del circuito ti addebitano ogni mese sul conto KMoney. Se blocchi un abbonamento,
            KMoney rifiuta i suoi addebiti anche se il sito ci riprova.
        </p>
        <p style="font-size:13px;margin:8px 0 0;">
            <a href="{{ route('portal.connected-apps.index') }}" style="font-weight:600;color:var(--ink);">&#x2190; App collegate</a>
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

    @if($subscriptions->isNotEmpty())
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:20px;">
            <section class="card card-pad" style="margin:0;">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Attivi</div>
                <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ $activeCount }}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">{{ $pausedCount }} in pausa</div>
            </section>
            <section class="card card-pad" style="margin:0;">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Prossimo addebito</div>
                @if($next)
                    @php $giorni = max(0, (int) now()->startOfDay()->diffInDays($next->next_charge_at->copy()->startOfDay(), false)); @endphp
                    <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ $next->next_charge_at->format('d/m/Y') }}</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                        {{ $giorni === 0 ? 'oggi' : ($giorni === 1 ? 'domani' : 'tra ' . $giorni . ' giorni') }}
                        @if($next->amount !== null) &middot; {{ ky_format($next->amount) }} KY @endif
                    </div>
                @else
                    <div style="font-size:26px;font-weight:800;line-height:1.1;">&mdash;</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">nessuna data prevista</div>
                @endif
            </section>
            <section class="card card-pad" style="margin:0;">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Al mese circa</div>
                <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ ky_format($monthly) }} KY</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">somma degli abbonamenti attivi</div>
            </section>
        </div>
    @endif

    @forelse($subscriptions as $sub)
        @php
            $inPiedi   = $sub->isLive();
            $autorizzata = in_array($sub->client_id, $authorizedClients, true);
            $colore = match (true) {
                $sub->isBlocked()   => ['#fef2f2', '#b91c1c'],
                $sub->isCancelled() => ['#f1f5f9', '#475569'],
                $sub->status === 'paused' => ['#fffbeb', '#b45309'],
                default             => ['#ecfdf5', '#047857'],
            };
        @endphp
        <section class="card card-pad" style="margin-bottom:16px;{{ $inPiedi ? '' : 'opacity:.92;' }}">

            <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:14px;flex-wrap:wrap;">
                <div style="width:44px;height:44px;border-radius:12px;background:#f8fafc;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;">
                    &#x1F501;
                </div>
                <div style="flex:1;min-width:200px;">
                    <div style="font-weight:700;font-size:15px;">{{ $sub->title }}</div>
                    <div style="font-size:13px;color:var(--text-muted);">
                        {{ $appNames[$sub->client_id] ?? $sub->client_id }} &middot; {{ $sub->intervalLabel() }}
                    </div>
                    <div style="margin-top:6px;">
                        <span style="display:inline-block;padding:2px 9px;border-radius:999px;background:{{ $colore[0] }};color:{{ $colore[1] }};font-size:12px;font-weight:700;">{{ $sub->statusLabel() }}</span>
                    </div>
                </div>

                @if(! $sub->isCancelled())
                    @if($sub->isBlocked())
                        {{-- Sbloccare è ridare un permesso: la route chiede lo step-up. --}}
                        <form method="POST" action="{{ route('portal.subscriptions.unblock', $sub->uuid) }}" style="margin:0;">
                            @csrf
                            <button type="submit"
                                    style="padding:8px 16px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;font-size:13px;font-weight:600;color:#047857;cursor:pointer;">
                                Sblocca
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('portal.subscriptions.block', $sub->uuid) }}" style="margin:0;">
                            @csrf
                            <button type="submit"
                                    style="padding:8px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:13px;font-weight:600;color:#b91c1c;cursor:pointer;">
                                Blocca
                            </button>
                        </form>
                    @endif
                @endif
            </div>

            @if(! empty($sub->items))
                <ul style="margin:0 0 14px;padding-left:18px;font-size:13px;color:var(--text);">
                    @foreach($sub->items as $riga)
                        <li>{{ $riga }}</li>
                    @endforeach
                </ul>
            @endif

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;font-size:13px;">
                <div>
                    <div style="color:var(--text-muted);margin-bottom:2px;">Importo</div>
                    <div style="font-weight:700;">{{ $sub->amount !== null ? ky_format($sub->amount) . ' KY' : '—' }}</div>
                </div>
                <div>
                    <div style="color:var(--text-muted);margin-bottom:2px;">Prossimo addebito</div>
                    <div style="font-weight:700;">
                        @if($inPiedi && $sub->status === 'active' && $sub->next_charge_at)
                            {{ $sub->next_charge_at->format('d/m/Y') }}
                        @else
                            &mdash;
                        @endif
                    </div>
                </div>
                <div>
                    <div style="color:var(--text-muted);margin-bottom:2px;">Addebiti eseguiti</div>
                    <div style="font-weight:700;">{{ $sub->charges_count }}</div>
                </div>
                <div>
                    <div style="color:var(--text-muted);margin-bottom:2px;">Ultimo addebito</div>
                    <div style="font-weight:700;">{{ $sub->last_charged_at?->format('d/m/Y H:i') ?? 'mai' }}</div>
                </div>
            </div>

            @if($sub->isBlocked())
                <p style="margin:14px 0 0;font-size:12.5px;color:#b91c1c;line-height:1.5;">
                    Bloccato il {{ $sub->blocked_at->format('d/m/Y') }}: KMoney rifiuta gli addebiti di questo abbonamento.
                    Sul sito risulta ancora attivo finché non lo annulli o lo metti in pausa anche lì.
                </p>
            @elseif($inPiedi && ! $autorizzata)
                <p style="margin:14px 0 0;font-size:12.5px;color:#b45309;line-height:1.5;">
                    L'autorizzazione di {{ $appNames[$sub->client_id] ?? $sub->client_id }} non è più attiva: il prossimo addebito non partirà da solo e ti verrà chiesto di confermarlo.
                </p>
            @elseif($sub->last_error && $inPiedi)
                <p style="margin:14px 0 0;font-size:12.5px;color:#b45309;line-height:1.5;">
                    Ultimo tentativo non riuscito: {{ $sub->last_error }}
                </p>
            @endif

        </section>
    @empty
        <section class="card card-pad" style="text-align:center;padding:40px 20px;">
            <div style="font-size:40px;line-height:1;margin-bottom:12px;">&#x1F501;</div>
            <div style="font-weight:700;font-size:15px;margin-bottom:6px;">Nessun abbonamento</div>
            <p style="font-size:13px;color:var(--text-muted);margin:0;line-height:1.5;">
                Quando un portale del circuito ti addebiterà un abbonamento sul conto KMoney,
                lo troverai qui — con importi, date e il pulsante per bloccarlo.
            </p>
        </section>
    @endforelse

    @if($charges->isNotEmpty())
        <section class="card card-pad" style="margin-top:24px;">
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Ultimi addebiti</div>
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;">Quelli degli abbonamenti, dal più recente.</p>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:left;color:var(--text-muted);">
                            <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Quando</th>
                            <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Abbonamento</th>
                            <th style="padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;">Importo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($charges as $charge)
                            <tr>
                                <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">{{ $charge->created_at->format('d/m/Y H:i') }}</td>
                                <td style="padding:10px;border-bottom:1px solid var(--border);">
                                    {{ $charge->subscription?->title ?? 'Abbonamento' }}
                                    @if($charge->order_title)
                                        <span style="color:var(--text-muted);">&middot; {{ $charge->order_title }}</span>
                                    @endif
                                </td>
                                <td style="padding:10px;border-bottom:1px solid var(--border);text-align:right;font-weight:700;white-space:nowrap;">{{ ky_format($charge->amount) }} KY</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

</div>
@endsection
