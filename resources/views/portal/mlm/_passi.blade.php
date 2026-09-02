{{-- ── La barra dei passi del percorso agente (02/09/2026) ──────────────────

     Il percorso e' fatto di tre pagine diverse e fin qui nessuna diceva a che
     punto fossi: si pagava una quota senza sapere che dopo c'era una firma, e
     si arrivava alla firma senza sapere che era l'ultimo passo. Questa barra
     e' l'unica cosa che le tiene insieme.

     Si include con  @include('portal.mlm._passi', ['passo' => 1])  — 1 quota,
     2 firma, 3 attivo. I colori sono scritti con un valore di riserva
     (`var(--ink, #1e293b)`) perche' la pagina della firma e' un documento a
     se' e le variabili del portale li' non esistono. --}}
@php
    $passi = [1 => 'Quota', 2 => 'Firma', 3 => 'Attivo'];
    $passo = (int) ($passo ?? 1);
@endphp
<div style="display:flex;align-items:center;gap:8px;margin-bottom:24px;" aria-label="Passo {{ $passo }} di 3">
    @foreach($passi as $numero => $nome)
        @php $stato = $numero < $passo ? 'fatto' : ($numero === $passo ? 'ora' : 'dopo'); @endphp
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <span style="width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;line-height:1;{{ $stato === 'fatto' ? 'background:#dcfce7;color:#15803d;' : ($stato === 'ora' ? 'background:var(--teal-strong, #0f766e);color:#fff;' : 'background:#f1f5f9;color:#94a3b8;') }}">{{ $stato === 'fatto' ? '✓' : $numero }}</span>
            <span style="font-size:13px;font-weight:{{ $stato === 'ora' ? '800' : '600' }};color:{{ $stato === 'dopo' ? 'var(--ink-muted, #94a3b8)' : 'var(--ink, #1e293b)' }};">{{ $nome }}</span>
        </div>
        @if(! $loop->last)
            <span style="flex:1;height:2px;min-width:14px;background:{{ $numero < $passo ? '#86efac' : 'var(--border, #e2e8f0)' }};"></span>
        @endif
    @endforeach
</div>
