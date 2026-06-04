@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('admin.cashback.create') }}" class="cta">+ Nuova regola</a>
@endsection




@section('content')
<div class="portal-grid" style="grid-template-columns:1fr;">
    <div class="stack">

        @if(session('portal_success'))
            <div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;">
                {{ session('portal_success') }}
            </div>
        @endif

        <section class="card light-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
                <div>
                    <div class="k-tag">Circuito</div>
                    <h2 class="card-title" style="margin-top:12px;">Regole attive</h2>
                </div>
                <a href="{{ route('admin.cashback.create') }}" class="cta">
                    + Nuova regola
                </a>
            </div>

            <table class="transactions-table" style="margin-top:18px;">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th style="text-align:right;">Soglia min</th>
                        <th style="text-align:right;">%</th>
                        <th style="text-align:right;">Cap max</th>
                        <th>Tipi validi</th>
                        <th>Target</th>
                        <th>Validita</th>
                        <th style="text-align:center;">Stato</th>
                        <th style="text-align:center;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr>
                            <td>
                                <div style="font-weight:700;">{{ $rule->name }}</div>
                                <div class="subtle" style="font-size:11px;">creata da {{ $rule->creator?->name ?? '—' }}</div>
                            </td>
                            <td style="text-align:right;">{{ ky_format($rule->min_amount) }} KY</td>
                            <td style="text-align:right;font-weight:700;color:var(--primary);">{{ number_format($rule->percentage, 2, ',', '.') }}%</td>
                            <td style="text-align:right;">
                                @if($rule->max_cashback)
                                    {{ ky_format($rule->max_cashback) }} KY
                                @else
                                    <span class="subtle">—</span>
                                @endif
                            </td>
                            <td>
                                @foreach($rule->applicable_kinds ?? [] as $k)
                                    <span style="display:inline-block;background:var(--surface-soft);border:1px solid var(--line);border-radius:6px;padding:2px 7px;font-size:11px;font-weight:600;margin:1px;">
                                        {{ $k === '*' ? 'Tutti' : $k }}
                                    </span>
                                @endforeach
                            </td>
                            <td style="font-size:12px;">
                                @php
                                    $targetLabel = match($rule->target_type ?? 'all') {
                                        'company'       => 'Solo aziende',
                                        'personal'      => 'Solo privati',
                                        'specific_user' => $rule->targetUser?->name ?? 'Utente #' . $rule->target_user_id,
                                        default         => 'Tutti',
                                    };
                                    $targetColor = match($rule->target_type ?? 'all') {
                                        'company'       => 'background:#eff6ff;border-color:#93c5fd;color:#1d4ed8;',
                                        'personal'      => 'background:#fdf4ff;border-color:#d8b4fe;color:#7e22ce;',
                                        'specific_user' => 'background:#fff7ed;border-color:#fdba74;color:#c2410c;',
                                        default         => 'background:var(--surface-soft);border-color:var(--line);color:var(--ink-muted);',
                                    };
                                @endphp
                                <span style="display:inline-block;border:1px solid;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:600;{{ $targetColor }}">
                                    {{ $targetLabel }}
                                </span>
                            </td>
                            <td style="font-size:12px;">
                                @if($rule->valid_from || $rule->valid_until)
                                    {{ $rule->valid_from?->format('d/m/Y') ?? '∞' }}
                                    →
                                    {{ $rule->valid_until?->format('d/m/Y') ?? '∞' }}
                                @else
                                    <span class="subtle">Sempre</span>
                                @endif
                            </td>
                            <td style="text-align:center;">
                                @if($rule->isCurrentlyActive())
                                    <span class="chip success">Attiva</span>
                                @elseif(! $rule->is_active)
                                    <span class="chip">Disattiva</span>
                                @else
                                    <span class="chip pink">Scaduta</span>
                                @endif
                            </td>
                            <td style="text-align:center;white-space:nowrap;">
                                <a href="{{ route('admin.cashback.edit', $rule) }}"
                                   style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;margin-right:10px;">
                                    Modifica
                                </a>
                                <form method="POST" action="{{ route('admin.cashback.toggle', $rule) }}"
                                      style="display:inline;">
                                    @csrf
                                    <button type="submit"
                                            style="font-size:12px;font-weight:600;background:none;border:none;cursor:pointer;color:{{ $rule->is_active ? 'var(--ink-muted)' : 'var(--success)' }};">
                                        {{ $rule->is_active ? 'Disattiva' : 'Attiva' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.cashback.destroy', $rule) }}"
                                      style="display:inline;"
                                      onsubmit="return confirm('Eliminare questa regola cashback?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            style="font-size:12px;font-weight:600;background:none;border:none;cursor:pointer;color:var(--danger);margin-left:8px;">
                                        Elimina
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="subtle" style="text-align:center;padding:32px;">
                                Nessuna regola cashback configurata. <a href="{{ route('admin.cashback.create') }}" style="color:var(--primary);">Crea la prima.</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="card light-card" style="font-size:13px;color:var(--ink-muted);">
            <div class="k-tag">Come funziona</div>
            <p style="margin-top:10px;line-height:1.6;">
                Quando un trasferimento viene contabilizzato, il sistema valuta tutte le regole attive e applica quella piu vantaggiosa per il pagante.
                Il cashback viene erogato automaticamente dal <strong>conto di sistema</strong> come trasferimento separato di tipo <code>portal_cashback</code>.
                Se il conto sistema non ha saldo sufficiente, l'erogazione viene saltata silenziosamente (il pagamento principale non e mai bloccato).
            </p>
        </section>

    </div>
</div>
@endsection
