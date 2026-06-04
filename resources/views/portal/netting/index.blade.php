@extends('layouts.portal')

@section('content')
@if(session('portal_success'))
    <div class="alert-banner success">{{ session('portal_success') }}</div>
@endif
@if(session('portal_error'))
    <div class="alert-banner error">{{ session('portal_error') }}</div>
@endif

{{-- KPI strip --}}
@php
    $allProposals = $asProposer->merge($asCounterparty);
    $pendingAsCounterparty = $asCounterparty->where('status', 'pending')->count();
    $acceptedTotal = $allProposals->where('status', 'accepted')->count();
    $myProposalsPending = $asProposer->where('status', 'pending')->count();
@endphp

<section class="hero-strip" style="margin-bottom:22px;align-items:stretch;grid-template-columns:repeat(5,minmax(0,1fr));">
    <article class="stat-card" style="border-left:4px solid #dc2626;flex:1;min-width:0;">
        <div class="eyebrow">Da confermare</div>
        <div class="section-title" style="font-size:34px;color:#dc2626;">{{ $pendingAsCounterparty }}</div>
        <div class="table-muted">Proposte in attesa della tua risposta</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #f59e0b;flex:1;min-width:0;">
        <div class="eyebrow">Tue proposte attive</div>
        <div class="section-title" style="font-size:34px;color:#f59e0b;">{{ $myProposalsPending }}</div>
        <div class="table-muted">In attesa di risposta</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #16a34a;flex:1;min-width:0;">
        <div class="eyebrow">Compensazioni eseguite</div>
        <div class="section-title" style="font-size:34px;color:#16a34a;">{{ $acceptedTotal }}</div>
        <div class="table-muted">Totale accettate</div>
    </article>
    <article class="stat-card" style="border-left:4px solid #0284c7;flex:1;min-width:0;">
        <div class="eyebrow">Totale proposte</div>
        <div class="section-title" style="font-size:34px;color:#0284c7;">{{ $allProposals->count() }}</div>
        <div class="table-muted">Inviate e ricevute</div>
    </article>
    <article class="stat-card" style="border-left:4px solid var(--accent);display:flex;flex-direction:column;justify-content:flex-start;gap:10px;flex:1;min-width:0;">
        <a class="cta" href="{{ route('portal.netting.create') }}" style="width:100%;text-align:center;">+ Nuova compensazione</a>
        <a class="cta secondary" href="{{ route('portal.movements') }}" style="width:100%;text-align:center;">Movimenti</a>
    </article>
</section>

{{-- Tabelle affiancate: Ricevute | Inviate --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

{{-- Proposte ricevute --}}
<section class="card light-card">
    <div class="section-head">
        <div>
            <span class="eyebrow">Azione richiesta</span>
            <h3 class="section-title">Proposte ricevute</h3>
        </div>
        <span style="font-size:22px;">&#128229;</span>
    </div>

    @if($asCounterparty->isEmpty())
        <div style="text-align:center;padding:32px;color:var(--ink-muted);">
            <div style="font-size:28px;margin-bottom:8px;">&#128237;</div>
            <div style="font-weight:600;">Nessuna proposta ricevuta.</div>
        </div>
    @else
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Proponente</th>
                        <th>Crediti loro</th>
                        <th>Crediti tuoi</th>
                        <th>Saldo netto</th>
                        <th>Stato</th>
                        <th>Scade il</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($asCounterparty as $proposal)
                    <tr style="{{ $proposal->status === 'pending' ? 'background:#fefce8;' : '' }}">
                        <td>
                            <div style="font-weight:600;">{{ $proposal->proposerAccount?->display_name ?? '&mdash;' }}</div>
                            @if($proposal->description)
                                <div class="table-muted" style="font-size:11px;">{{ Str::limit($proposal->description, 40) }}</div>
                            @endif
                        </td>
                        <td style="font-weight:700;color:#0284c7;">{{ ky_format($proposal->proposer_total) }} KY</td>
                        <td style="font-weight:700;color:#16a34a;">{{ ky_format($proposal->counterparty_total) }} KY</td>
                        <td>
                            @if($proposal->net_amount === 0)
                                <span style="color:#16a34a;font-weight:600;">Pareggio</span>
                            @else
                                <span style="font-weight:700;color:{{ $proposal->net_payer_account_id === $currentAccount->id ? '#dc2626' : '#16a34a' }};">
                                    {{ $proposal->net_payer_account_id === $currentAccount->id ? '-' : '+' }}{{ ky_format($proposal->net_amount) }} KY
                                </span>
                            @endif
                        </td>
                        <td>
                            @if($proposal->status === 'pending')
                                <span class="chip" style="background:#fef9c3;color:#854d0e;border-color:#fef08a;">In attesa</span>
                            @elseif($proposal->status === 'accepted')
                                <span class="chip success">Accettata</span>
                            @elseif($proposal->status === 'rejected')
                                <span class="chip pink">Rifiutata</span>
                            @else
                                <span class="chip">Scaduta</span>
                            @endif
                        </td>
                        <td class="table-muted" style="font-size:12px;">
                            {{ $proposal->expires_at?->format('d/m/Y') ?? '&mdash;' }}
                        </td>
                        <td><a href="{{ route('portal.netting.show', $proposal) }}" class="cta secondary" style="font-size:12px;padding:6px 12px;min-height:30px;">Dettaglio</a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- Proposte inviate --}}
<section class="card light-card">
    <div class="section-head">
        <div>
            <span class="eyebrow">Inviate da te</span>
            <h3 class="section-title">Le tue proposte</h3>
        </div>
        <span style="font-size:22px;">&#128228;</span>
    </div>

    @if($asProposer->isEmpty())
        <div style="text-align:center;padding:32px;color:var(--ink-muted);">
            <div style="font-size:28px;margin-bottom:8px;">&#128260;</div>
            <div style="font-weight:600;">Nessuna proposta inviata.</div>
            <div style="font-size:13px;margin-top:4px;">
                <a href="{{ route('portal.netting.create') }}" style="color:var(--accent);">Crea la prima compensazione &rarr;</a>
            </div>
        </div>
    @else
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Controparte</th>
                        <th>Tuoi crediti</th>
                        <th>Loro crediti</th>
                        <th>Saldo netto</th>
                        <th>Stato</th>
                        <th>Proposta il</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($asProposer as $proposal)
                    <tr>
                        <td>
                            <div style="font-weight:600;">{{ $proposal->counterpartyAccount?->display_name ?? '&mdash;' }}</div>
                            @if($proposal->description)
                                <div class="table-muted" style="font-size:11px;">{{ Str::limit($proposal->description, 40) }}</div>
                            @endif
                        </td>
                        <td style="font-weight:700;color:#0284c7;">{{ ky_format($proposal->proposer_total) }} KY</td>
                        <td style="font-weight:700;color:#16a34a;">{{ ky_format($proposal->counterparty_total) }} KY</td>
                        <td>
                            @if($proposal->net_amount === 0)
                                <span style="color:#16a34a;font-weight:600;">Pareggio</span>
                            @else
                                <span style="font-weight:700;color:{{ $proposal->net_payer_account_id === $currentAccount->id ? '#dc2626' : '#16a34a' }};">
                                    {{ $proposal->net_payer_account_id === $currentAccount->id ? '-' : '+' }}{{ ky_format($proposal->net_amount) }} KY
                                </span>
                            @endif
                        </td>
                        <td>
                            @if($proposal->status === 'pending')
                                <span class="chip" style="background:#fef9c3;color:#854d0e;border-color:#fef08a;">In attesa</span>
                            @elseif($proposal->status === 'accepted')
                                <span class="chip success">Accettata</span>
                            @elseif($proposal->status === 'rejected')
                                <span class="chip pink">Rifiutata</span>
                            @else
                                <span class="chip">Scaduta</span>
                            @endif
                        </td>
                        <td class="table-muted" style="font-size:12px;">{{ $proposal->created_at->format('d/m/Y') }}</td>
                        <td><a href="{{ route('portal.netting.show', $proposal) }}" class="cta secondary" style="font-size:12px;padding:6px 12px;min-height:30px;">Dettaglio</a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

</div>{{-- end grid --}}

@endsection
