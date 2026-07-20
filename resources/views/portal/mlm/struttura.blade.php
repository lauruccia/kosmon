@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">La mia struttura</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">
                L'albero della tua rete: il colore indica la qualifica. Clicca su un nodo per vedere i dettagli.
            </p>
        </div>
        <span class="pill">Qualifica: {{ ucfirst($agent->mlm_rank ?: 'start') }} &bull; {{ mlm_points_format($activePoints) }} punti</span>
    </div>
</div>

@if((($grantedPoints ?? 0) > 0) || (($grantedLevel1Basic ?? 0) > 0))
<div class="card card-pad" style="margin-bottom:14px;border-left:4px solid var(--primary);">
    <p style="margin:0;font-size:13px;">
        <strong>Punti bonus assegnati dall'amministrazione:</strong>
        @if(($grantedPoints ?? 0) > 0)
            {{ $grantedPoints }} {{ $grantedPoints === 1 ? 'punto cliente' : 'punti cliente' }}
        @endif
        @if(($grantedPoints ?? 0) > 0 && ($grantedLevel1Basic ?? 0) > 0), @endif
        @if(($grantedLevel1Basic ?? 0) > 0)
            {{ $grantedLevel1Basic }} {{ $grantedLevel1Basic === 1 ? 'agente Basic' : 'agenti Basic' }} al 1° livello
        @endif
        — inclusi nei totali qui sopra, non scadono mai.
    </p>
</div>
@endif

@if(($expiringPoints ?? 0) > 0)
<div class="card card-pad" style="margin-bottom:14px;border-left:4px solid {{ ($rankAtRisk ?? false) ? '#dc2626' : '#d97706' }};">
    <p style="margin:0;font-size:13px;">
        <strong>{{ mlm_points_format($expiringPoints) }} {{ $expiringPoints == 1 ? 'punto scade' : 'punti scadono' }} nei prossimi 30 giorni.</strong>
        @if($rankAtRisk ?? false)
            Senza nuovi punti la tua qualifica <strong>{{ ucfirst($agent->mlm_rank) }}</strong> non sarà più coperta e verrà ricalcolata al ribasso: genera nuovi punti con i tuoi clienti per mantenerla.
        @else
            Dopo la scadenza avrai {{ mlm_points_format(max(0, $activePoints - $expiringPoints)) }} punti attivi.
        @endif
    </p>
</div>
@endif

<section class="card light-card" style="padding:18px;">
    @if(empty($tree))
        <p style="text-align:center;color:var(--ink-muted);padding:24px;margin:0;">
            La tua struttura è ancora vuota: invita nuovi agenti con il tuo link per iniziare a costruirla.
        </p>
    @else
        @include('partials.mlm-tree', ['tree' => $tree, 'mode' => 'portal'])
    @endif
</section>
@endsection
