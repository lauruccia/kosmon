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
                <a href="{{ route('admin.mlm.show', $root) }}" class="btn btn-secondary">Scheda agente</a>
                <a href="{{ route('admin.mlm.tree.roots') }}" class="btn btn-secondary">Tutte le radici</a>
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
