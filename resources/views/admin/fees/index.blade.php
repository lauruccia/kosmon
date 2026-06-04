@extends('layouts.portal')
@section('content')
<div style="max-width:900px;margin:0 auto;padding:0 16px 48px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <div class="eyebrow">Admin</div>
            <h1 class="page-title">Commissioni transazioni</h1>
        </div>
        <a href="{{ route('admin.fees.create') }}" class="cta">+ Nuova commissione</a>
    </div>

    @if(session('success'))
        <div class="alert success" style="margin-bottom:20px;">{{ session('success') }}</div>
    @endif

    @if($fees->isEmpty())
        <div class="card card-pad empty-state">
            <strong>Nessuna commissione configurata.</strong>
            <p>Le transazioni non subiscono commissioni. Aggiungi una regola per applicare fee.</p>
        </div>
    @else
        <section class="card" style="padding:0;overflow:hidden;">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Tipo operazione</th>
                        <th>Tipo fee</th>
                        <th style="text-align:right;">Valore</th>
                        <th style="text-align:right;">Min / Max KY</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($fees as $fee)
                    <tr>
                        <td>{{ $kindOptions[$fee->operation_kind] ?? $fee->operation_kind }}</td>
                        <td>{{ $fee->fee_type === 'flat' ? 'Flat (KY fisso)' : 'Percentuale (%)' }}</td>
                        <td style="text-align:right;">
                            @if($fee->fee_type === 'percentage')
                                {{ $fee->fee_value }}%
                            @else
                                {{ ky_format($fee->fee_value) }} KY
                            @endif
                        </td>
                        <td style="text-align:right;font-size:12.5px;color:var(--ink-soft);">
                            {{ $fee->min_fee ? ky_format($fee->min_fee) : '—' }}
                            /
                            {{ $fee->max_fee ? ky_format($fee->max_fee) : '—' }}
                        </td>
                        <td>
                            <span class="chip {{ $fee->is_active ? 'success' : 'default' }}">{{ $fee->is_active ? 'Attiva' : 'Disattiva' }}</span>
                        </td>
                        <td style="display:flex;gap:6px;flex-wrap:wrap;padding:8px 12px;">
                            <a href="{{ route('admin.fees.edit', $fee) }}" class="cta secondary" style="font-size:11.5px;min-height:26px;padding:0 10px;">Modifica</a>
                            <form method="POST" action="{{ route('admin.fees.toggle', $fee) }}" style="display:inline;">
                                @csrf
                                <button class="cta secondary" style="font-size:11.5px;min-height:26px;padding:0 10px;">{{ $fee->is_active ? 'Disattiva' : 'Attiva' }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.fees.destroy', $fee) }}" style="display:inline;" onsubmit="return confirm('Eliminare questa commissione?')">
                                @csrf @method('DELETE')
                                <button class="cta danger" style="font-size:11.5px;min-height:26px;padding:0 10px;">Elimina</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
