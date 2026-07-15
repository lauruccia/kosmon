@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">MLM — Impostazioni qualifiche</h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">Requisiti per grado e scadenza dei punti cliente — normalmente fissi nel codice, qui editabili per fare test rapidi senza aspettare mesi.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="{{ route('admin.mlm.index') }}" class="btn btn-secondary">← Torna agli agenti</a>
        </div>
    </div>
</div>

<section class="card card-pad" style="margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
        <strong style="display:block;font-size:14px;">Agente radice del sistema</strong>
        <span style="color:var(--ink-muted);font-size:12px;">
            @if($currentRootAgent)
                Radice attuale: {{ $currentRootAgent->name }}
            @else
                Nessuna radice ancora designata
            @endif
        </span>
    </div>
    <a href="{{ route('admin.mlm.settings.root-agent') }}" class="btn btn-secondary">{{ $currentRootAgent ? 'Cambia radice' : 'Scegli radice' }}</a>
</section>

<form method="POST" action="{{ route('admin.mlm.settings.update') }}">
    @csrf

    {{-- ── Scadenza punti ── --}}
    <section class="card card-pad" style="margin-bottom:14px;">
        <h3 style="margin:0 0 6px;font-size:15px;">Scadenza punti cliente (PC)</h3>
        <p style="margin:0 0 14px;color:var(--ink-muted);font-size:13px;">
            In produzione i punti restano attivi per 1/12/24/36 mesi a seconda dello scaglione di deposito (vedi <code>MlmPointsService</code>).
            Per verificare subito il ricalcolo qualifiche puoi forzare qui una scadenza breve in MINUTI, valida per i <strong>nuovi</strong> punti assegnati d'ora in poi
            (i punti già esistenti mantengono la loro scadenza originale). Lascia vuoto per usare la durata normale di produzione.
        </p>

        <div style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;">
            <div>
                <label style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:4px;">Scadenza (minuti)</label>
                <input type="number" id="points_validity_override_minutes" name="points_validity_override_minutes" min="1" step="1"
                    value="{{ old('points_validity_override_minutes', $pointsValidityOverrideMinutes) }}"
                    placeholder="vuoto = durata normale"
                    style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:14px;background:var(--surface-soft);color:var(--ink);outline:none;width:220px;">
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;padding-bottom:2px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('points_validity_override_minutes').value=1">1 minuto</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('points_validity_override_minutes').value=60">1 ora</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('points_validity_override_minutes').value=1440">1 giorno</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('points_validity_override_minutes').value=10080">7 giorni</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('points_validity_override_minutes').value=''">Nessun override (produzione)</button>
            </div>
        </div>

        @if($pointsValidityOverrideMinutes)
            <p style="margin:12px 0 0;font-size:12px;color:var(--danger);font-weight:600;">
                ⚠ Override attivo: {{ $pointsValidityOverrideMinutes }} minuti. Tutti i nuovi punti assegnati (registrazioni, depositi) scadranno dopo questo tempo, non dopo mesi. Ricordati di svuotare il campo a fine test.
            </p>
        @endif
    </section>

    {{-- ── Requisiti per grado ── --}}
    <section class="card light-card" style="margin-bottom:14px;">
        <div style="padding:14px 16px 0;">
            <h3 style="margin:0 0 6px;font-size:15px;">Requisiti per grado</h3>
            <p style="margin:0 0 14px;color:var(--ink-muted);font-size:13px;">
                Ogni grado è un requisito indipendente (non una progressione stretta): il sistema valuta tutte le righe e assegna il grado più alto soddisfatto.
                Lascia a 0 le colonne non richieste per un grado (es. "Colonne Key" non serve per Basic).
            </p>
        </div>
        <div style="overflow-x:auto;">
            <table class="admin-table transactions-table">
                <thead>
                    <tr>
                        <th>Grado</th>
                        <th>Punti attivi</th>
                        <th>Basic al 1° liv.</th>
                        <th>Colonne Key+</th>
                        <th>Colonne Senior+</th>
                        <th>Colonne Top+</th>
                        <th>Colonne SuperVisor+</th>
                        <th>Colonne ≥300pt</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ranks as $rank)
                        @php($req = $requirements->get($rank))
                        <tr>
                            <td><strong>{{ ucfirst($rank) }}</strong></td>
                            @foreach(['min_points','min_level1_basic','min_branches_with_key','min_branches_with_senior','min_branches_with_top','min_branches_with_supervisor','min_branches_300pt'] as $field)
                                <td>
                                    <input type="number" min="0" step="1"
                                        name="requirements[{{ $rank }}][{{ $field }}]"
                                        value="{{ old('requirements.'.$rank.'.'.$field, $req?->{$field} ?? 0) }}"
                                        style="width:70px;border:1px solid var(--line);border-radius:6px;padding:6px 8px;font-size:13px;background:var(--surface-soft);color:var(--ink);outline:none;text-align:center;">
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <button type="submit" style="padding:10px 20px;border-radius:8px;font-size:14px;background:var(--primary);color:#fff;border:none;font-weight:700;cursor:pointer;">Salva impostazioni</button>
</form>

<form method="POST" action="{{ route('admin.mlm.settings.recalculate') }}" style="margin-top:14px;" onsubmit="return confirm('Eseguire subito il ricalcolo qualifiche (mlm:recalculate-points) su tutti gli agenti? Normalmente gira di notte alle 03:00.');">
    @csrf
    <div class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <strong style="display:block;font-size:14px;">Ricalcolo qualifiche immediato</strong>
            <span style="color:var(--ink-muted);font-size:12px;">Esegue subito il job notturno (rileva nuovi BasiQ, promuove/retrocede tutti gli agenti secondo i requisiti sopra) invece di aspettare le 03:00.</span>
        </div>
        <button type="submit" class="btn btn-secondary">Ricalcola ora</button>
    </div>
</form>
@endsection
