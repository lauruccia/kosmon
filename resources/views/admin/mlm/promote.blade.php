@extends('layouts.portal')
{{-- Pagina "Promuovi agente": assegna punti/agenti omaggio a un singolo agente dalla scheda MLM (v2). --}}

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <a href="{{ route('admin.mlm.show', $agent) }}" style="color:var(--ink-muted);text-decoration:none;font-size:12px;">&larr; Torna a {{ $agent->name }}</a>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:8px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">Promuovi {{ $agent->name }}</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;max-width:720px;">
                Assegna in "omaggio" punti, agenti o colonne di qualsiasi qualifica (fino a Manager): si sommano ai valori reali e non scadono mai (vedi <a href="{{ route('admin.mlm.show', $agent) }}" style="color:var(--primary);">storico regali</a> nella scheda agente). Al salvataggio, qualifica e bonus economici vengono ricalcolati subito, senza aspettare il cron notturno.
            </p>
        </div>
        <span class="pill">{{ ucfirst($agent->mlm_rank) }}</span>
    </div>
</div>

@if(session('portal_success'))
    <div class="card card-pad" style="margin-bottom:14px;background:rgba(26,122,74,0.08);border:1px solid #bfe3cf;color:#1a7a4a;font-size:13px;">{{ session('portal_success') }}</div>
@endif
@if($errors->any())
    <div class="card card-pad" style="margin-bottom:14px;background:var(--danger-soft);border:1px solid #fecdd3;color:var(--danger);font-size:13px;">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px;">
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Punti attivi (di cui omaggio)</span>
        <strong style="font-size:22px;">{{ $agent->mlmActivePoints() }}</strong>
        @if($agent->mlmGrantedPoints() != 0)
            <span style="color:var(--ink-muted);font-size:12px;"> ({{ sprintf('%+d', $agent->mlmGrantedPoints()) }} omaggio)</span>
        @endif
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Basic al 1° livello (di cui omaggio)</span>
        <strong style="font-size:22px;">{{ $evaluation['level1_basic_count'] }}</strong>
        @if($agent->mlmGrantedLevel1Basic() != 0)
            <span style="color:var(--ink-muted);font-size:12px;"> ({{ sprintf('%+d', $agent->mlmGrantedLevel1Basic()) }} omaggio)</span>
        @endif
    </div>
    <div class="card card-pad">
        <span style="display:block;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted);margin-bottom:4px;">Qualifica raggiungibile ora</span>
        <strong style="font-size:22px;">{{ ucfirst($evaluation['eligible_rank']) }}</strong>
    </div>
</div>

@if($nextRank)
<section class="card light-card" style="margin-bottom:14px;">
    <div style="padding:14px 16px;">
        <h3 style="margin:0 0 4px;font-size:15px;">Cosa manca per: {{ ucfirst($nextRank['rank']) }}</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">
            Ogni voce sotto tiene già conto degli eventuali regali fatti sopra: assegna il "Tipo" giusto per far avanzare la voce mancante.
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            @foreach($nextRank['items'] as $item)
                <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:{{ $item['met'] ? 'rgba(26,122,74,0.08)' : 'var(--surface)' }};">
                    <span style="font-weight:700;font-size:13px;color:{{ $item['met'] ? '#1a7a4a' : '#c9313e' }};">{{ $item['met'] ? '✓' : '✗' }}</span>
                    <span style="font-size:12.5px;">{{ $item['label'] }}: {{ $item['current'] }} / {{ $item['required'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<form method="POST" action="{{ route('admin.mlm.metric-grants.store') }}">
    @csrf
    <input type="hidden" name="agent_ids[]" value="{{ $agent->id }}">
    <input type="hidden" name="redirect_agent_id" value="{{ $agent->id }}">

    <section class="card card-pad">
        <h3 style="margin:0 0 4px;font-size:14px;">Assegna punti/agenti omaggio</h3>
        <p style="margin:0 0 10px;color:var(--ink-muted);font-size:12.5px;">Quantità positiva per aggiungere, negativa per togliere (es. <code>-3</code>). Si sommano ai valori reali e non scadono mai finché non li revochi dalla scheda agente.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Tipo</label>
                <select name="metric" required style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;">
                    @foreach(\App\Models\MlmMetricGrant::METRICS as $metricValue => $metricLabel)
                        <option value="{{ $metricValue }}">{{ $metricLabel }} omaggio</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Quantità</label>
                <input type="number" name="amount" step="1" required value="1"
                    style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;width:100px;">
            </div>
            <div style="flex:1;min-width:200px;">
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Motivo (facoltativo)</label>
                <input type="text" name="reason" maxlength="255" placeholder="Es. promo lancio, correzione manuale…"
                    style="border:1px solid var(--line);border-radius:8px;padding:7px 10px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;width:100%;">
            </div>
            <button type="submit" style="padding:8px 16px;border-radius:8px;font-size:13px;background:var(--primary);color:#fff;border:none;font-weight:600;cursor:pointer;">Assegna a {{ $agent->name }}</button>
        </div>
    </section>
</form>
@endsection
