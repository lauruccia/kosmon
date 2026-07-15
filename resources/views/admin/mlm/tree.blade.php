@extends('layouts.portal')

@section('content')
<div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;font-size:18px;">
                {{ $root ? 'Albero di ' . $root->name : 'Albero agenti' }}
            </h2>
            <p style="margin:0;color:var(--ink-muted);font-size:13px;">
                Il colore indica la qualifica. Clicca su un nodo per i dettagli e per aprire l'albero di quell'agente.
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            @if($root)
                @if($sponsor)
                    <a href="{{ route('admin.mlm.tree', $sponsor) }}" class="btn btn-secondary">&uarr; Sponsor: {{ $sponsor->name }}</a>
                @endif
                @if($systemRoot && $systemRoot->id !== $root->id)
                    <a href="{{ route('admin.mlm.tree', $systemRoot) }}" class="btn btn-secondary">🏠 Radice del sistema</a>
                @endif
                <a href="{{ route('admin.mlm.show', $root) }}" class="btn btn-secondary">Scheda agente</a>
                <a href="{{ route('admin.mlm.tree.move-form', $root) }}" class="btn btn-secondary">Sposta sponsor</a>
                <a href="{{ route('admin.mlm.settings.root-agent') }}" class="btn btn-secondary">Impostazioni radice</a>
            @endif
            <a href="{{ route('admin.mlm.index') }}" class="btn btn-secondary">&larr; Elenco agenti</a>
        </div>
    </div>
</div>

@if($root)
    <section class="card light-card" style="padding:18px;">
        @if(empty($tree))
            <p style="text-align:center;color:var(--ink-muted);padding:24px;margin:0;">Nessun dato albero per questo agente.</p>
        @else
            @include('partials.mlm-tree', ['tree' => $tree, 'mode' => 'admin'])
        @endif
    </section>
@else
    <div class="card card-pad" style="margin-bottom:14px;border:1px solid #fde68a;background:#fffbeb;">
        <p style="margin:0;color:#92400e;font-size:13px;">
            Nessuna radice di sistema ancora designata: il sistema MLM dovrebbe avere un unico grande albero con un'unica radice scelta dall'admin.
            Scegli un agente come radice unica in <a href="{{ route('admin.mlm.settings.root-agent') }}" style="color:#92400e;font-weight:700;">Impostazioni MLM → Agente radice</a> — l'operazione consolida automaticamente anche gli alberi indipendenti elencati qui sotto.
        </p>
    </div>
    <section class="card light-card">
        <table class="admin-table transactions-table">
            <thead>
                <tr>
                    <th>Agente radice</th>
                    <th>Qualifica</th>
                    <th>Attivato il</th>
                    <th style="text-align:right;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($roots as $rootAgent)
                    <tr>
                        <td>
                            <strong style="display:block;">{{ $rootAgent->name }}</strong>
                            <span style="color:var(--ink-muted);font-size:12px;">{{ $rootAgent->email }}</span>
                        </td>
                        <td><span class="pill">{{ ucfirst($rootAgent->mlm_rank ?: 'start') }}</span></td>
                        <td>{{ $rootAgent->mlm_activated_at?->format('d/m/Y') ?? '—' }}</td>
                        <td style="text-align:right;">
                            <a href="{{ route('admin.mlm.tree', $rootAgent) }}" style="color:var(--primary);text-decoration:none;font-weight:600;font-size:13px;">Vedi albero &rarr;</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun agente radice.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
@endif
@endsection
