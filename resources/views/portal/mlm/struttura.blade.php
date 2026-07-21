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

@if(!empty($nextRank))
<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px;">
        <h3 style="margin:0 0 4px;font-size:15px;">Verso la qualifica {{ ucfirst($nextRank['rank']) }}</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">
            Cosa ti manca per il prossimo grado. I requisiti vengono verificati automaticamente ogni notte: appena sono tutti soddisfatti, la promozione scatta da sola.
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            @foreach($nextRank['items'] as $item)
                <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:{{ $item['met'] ? 'rgba(26,122,74,0.08)' : 'var(--surface)' }};">
                    <span style="font-weight:700;font-size:13px;color:{{ $item['met'] ? '#1a7a4a' : '#c9313e' }};">{{ $item['met'] ? '✓' : '✗' }}</span>
                    <span style="font-size:12.5px;">{{ $item['label'] }}: <strong>{{ mlm_points_format($item['current']) }} / {{ $item['required'] }}</strong></span>
                </div>
            @endforeach
        </div>
        @php $allMet = collect($nextRank['items'])->every(fn ($i) => $i['met']); @endphp
        @if($allMet && count($nextRank['items']))
            <p style="margin:10px 0 0;font-size:12.5px;color:#1a7a4a;font-weight:600;">Tutti i requisiti sono soddisfatti: la promozione a {{ ucfirst($nextRank['rank']) }} arriverà con il prossimo ricalcolo automatico.</p>
        @endif
    </div>
</section>
@elseif(($agent->mlm_rank ?? 'start') === 'manager')
<div class="card card-pad" style="margin-bottom:14px;border-left:4px solid #16a34a;">
    <p style="margin:0;font-size:13px;"><strong>Complimenti:</strong> hai raggiunto la qualifica massima (Manager).</p>
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
