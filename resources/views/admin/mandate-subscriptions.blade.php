@extends('layouts.portal')

@section('content')

<div style="margin-bottom:18px;">
    <p style="font-size:13px;color:var(--text-muted);margin:0;line-height:1.6;">
        Gli abbonamenti che i <strong>portali</strong> del circuito addebitano ai conti KMoney. Sono la copia che il portale
        manda a KMoney a ogni cambiamento: qui si guarda, non si decide. Il blocco è dell'utente, l'annullo e la pausa
        sono del portale.
    </p>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:20px;">
    <section class="card card-pad" style="margin:0;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Attivi</div>
        <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ $summary['active'] }}</div>
    </section>
    <section class="card card-pad" style="margin:0;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">In pausa</div>
        <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ $summary['paused'] }}</div>
    </section>
    <section class="card card-pad" style="margin:0;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Bloccati dall'utente</div>
        <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ $summary['blocked'] }}</div>
    </section>
    <section class="card card-pad" style="margin:0;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Annullati</div>
        <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ $summary['cancelled'] }}</div>
    </section>
    <section class="card card-pad" style="margin:0;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Al mese (attivi)</div>
        <div style="font-size:26px;font-weight:800;line-height:1.1;">{{ ky_format($summary['monthly']) }} KY</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">{{ $summary['due_week'] }} rinnovi nei prossimi 7 giorni</div>
    </section>
</div>

<form method="GET" action="{{ route('admin.mandate-subscriptions.index') }}" style="margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Portale</label>
        <select name="client_id" class="form-input" style="min-width:180px;" data-no-search>
            <option value="">— Tutti —</option>
            @foreach($clientIds as $id)
                <option value="{{ $id }}" {{ request('client_id') === $id ? 'selected' : '' }}>{{ $appNames[$id] ?? $id }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Stato</label>
        <select name="stato" class="form-input" style="min-width:180px;" data-no-search>
            <option value="">— Tutti —</option>
            @foreach(['active' => 'Attivi', 'paused' => 'In pausa', 'blocked' => 'Bloccati', 'cancelled' => 'Annullati', 'error' => 'Con ultimo errore'] as $k => $v)
                <option value="{{ $k }}" {{ $stato === $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Cerca</label>
        <input type="text" name="q" value="{{ request('q') }}" class="form-input" placeholder="Nome, email, abbonamento" style="min-width:220px;">
    </div>
    <button type="submit" class="cta secondary">Filtra</button>
    <a href="{{ route('admin.mandate-subscriptions.index') }}" class="cta secondary">Reset</a>
</form>

<section class="card card-pad" style="overflow-x:auto;">
    @if($subscriptions->isEmpty())
        <p style="color:var(--text-muted);text-align:center;padding:32px;">Nessun abbonamento trovato.</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="text-align:left;color:var(--text-muted);">
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Portale</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Utente</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Abbonamento</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;">Importo</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Stato</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Prossimo rinnovo</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Ultimo addebito</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;">Addebiti</th>
                    <th style="padding:8px 10px;border-bottom:1px solid var(--border);">Dal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($subscriptions as $s)
                    @php
                        $colore = match (true) {
                            $s->isBlocked()   => '#dc2626',
                            $s->isCancelled() => '#6b7280',
                            $s->status === \App\Models\MandateSubscription::PAUSED => '#d97706',
                            default           => '#16a34a',
                        };
                    @endphp
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid var(--border);">
                            <div style="font-weight:700;">{{ $appNames[$s->client_id] ?? $s->client_id }}</div>
                            <div style="font-size:11px;color:var(--text-muted);font-family:monospace;">{{ $s->client_id }}</div>
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);">
                            @if($s->user)
                                <a href="{{ route('admin.users.show', $s->user) }}" style="font-weight:600;color:var(--ink);">{{ $s->user->name }}</a>
                                <div style="font-size:11px;color:var(--text-muted);">{{ $s->user->email }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);max-width:260px;">
                            <div style="font-weight:600;">{{ $s->title }}</div>
                            @foreach((array) $s->items as $riga)
                                <div style="font-size:11px;color:var(--text-muted);">{{ $riga }}</div>
                            @endforeach
                            <div style="font-size:11px;color:var(--text-muted);font-family:monospace;">#{{ $s->external_id }}@if($s->seller_account_number) · venditore {{ $s->seller_account_number }}@endif</div>
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;">
                            <div style="font-weight:700;">{{ $s->amount !== null ? ky_format($s->amount) . ' KY' : '—' }}</div>
                            <div style="font-size:11px;color:var(--text-muted);">{{ $s->intervalLabel() }}</div>
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">
                            <span style="color:{{ $colore }};font-weight:700;">{{ $s->statusLabel() }}</span>
                            @if($s->isBlocked())
                                <div style="font-size:11px;color:var(--text-muted);">dal {{ $s->blocked_at->format('d/m/Y') }}</div>
                            @elseif($s->isCancelled() && $s->cancelled_at)
                                <div style="font-size:11px;color:var(--text-muted);">dal {{ $s->cancelled_at->format('d/m/Y') }}</div>
                            @endif
                            @if($s->last_error)
                                <div style="font-size:11px;color:#b91c1c;max-width:200px;white-space:normal;">{{ $s->last_error }}</div>
                            @endif
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">
                            {{ $s->next_charge_at && $s->isLive() && $s->status === \App\Models\MandateSubscription::ACTIVE ? $s->next_charge_at->format('d/m/Y') : '—' }}
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">{{ $s->last_charged_at ? $s->last_charged_at->format('d/m/Y H:i') : '—' }}</td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;">
                            {{ $s->charges_count }}
                            @if($s->charges_count > 0)
                                <div style="font-size:11px;color:var(--text-muted);">{{ ky_format((int) $s->charges_sum_amount) }} KY</div>
                            @endif
                        </td>
                        <td style="padding:10px;border-bottom:1px solid var(--border);white-space:nowrap;">{{ $s->created_at->format('d/m/Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:16px;">{{ $subscriptions->links() }}</div>
    @endif
</section>

@endsection
