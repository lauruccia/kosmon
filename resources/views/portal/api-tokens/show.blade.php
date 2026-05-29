@extends('layouts.portal')

@section('content')
<section class="page-intro--row page-intro">
    <span class="eyebrow">
        <a href="{{ route('portal.api-tokens.index') }}" style="color:var(--primary);text-decoration:none;">Token API</a> &rsaquo;
    </span>
    <h2>{{ $pageTitle }}</h2>
</section>

{{-- Banner token in chiaro (mostrato una sola volta) --}}
@if($plainToken)
    <div style="background:#f0fdf4;border:2px solid #22c55e;border-radius:10px;padding:18px 20px;margin-bottom:24px;">
        <div style="font-weight:700;color:#15803d;margin-bottom:8px;">
            ✓ Token generato — copia subito, non sarà più mostrato
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <code id="token-plain" style="font-size:13px;background:#fff;border:1px solid #bbf7d0;border-radius:6px;padding:8px 14px;word-break:break-all;flex:1;">{{ $plainToken }}</code>
            <button onclick="navigator.clipboard.writeText('{{ $plainToken }}');this.textContent='✓ Copiato!';setTimeout(()=>this.textContent='Copia',2000);"
                    style="padding:8px 14px;border-radius:6px;border:1px solid #22c55e;background:#dcfce7;color:#15803d;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;">
                Copia
            </button>
        </div>
        <div style="font-size:11px;color:#15803d;margin-top:8px;">
            Usa questo valore nell'header: <code>Authorization: Bearer {{ $plainToken }}</code>
        </div>
    </div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:700px;">
    <div class="card card-pad">
        <div style="font-size:11px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;">Dettagli token</div>
        <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;">
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Nome</div>
                <div style="font-weight:700;">{{ $token->name }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Prefisso identificativo</div>
                <code style="font-size:12px;background:var(--surface-soft);padding:3px 8px;border-radius:4px;">{{ $token->token_prefix }}…</code>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Permessi</div>
                <div style="display:flex;gap:4px;margin-top:4px;">
                    @foreach($token->abilities as $ab)
                        <span class="chip {{ $ab === 'write' ? 'pink' : '' }}" style="font-size:11px;">{{ $ab }}</span>
                    @endforeach
                </div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Creato il</div>
                <div>{{ $token->created_at->format('d/m/Y H:i') }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Ultimo uso</div>
                <div>{{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Mai utilizzato' }}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--ink-muted);">Scadenza</div>
                <div style="{{ $token->isExpired() ? 'color:var(--danger);font-weight:600;' : '' }}">
                    {{ $token->expires_at ? $token->expires_at->format('d/m/Y') . ($token->isExpired() ? ' (scaduto)' : '') : 'Nessuna scadenza' }}
                </div>
            </div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:12px;">
        <div class="card card-pad" style="background:var(--surface-soft);">
            <div style="font-size:13px;font-weight:700;margin-bottom:8px;">Esempio di utilizzo</div>
            <pre style="font-size:10px;overflow-x:auto;margin:0;line-height:1.7;white-space:pre-wrap;word-break:break-all;">GET {{ request()->schemeAndHttpHost() }}/api/v1/me
Authorization: Bearer {{ $token->token_prefix }}…

# Risposta
{
  "company": { "name": "…" },
  "account": { "balance": 0, "currency": "KY" }
}</pre>
        </div>

        <div class="card card-pad">
            <div style="font-weight:700;margin-bottom:8px;color:var(--danger);">Revoca token</div>
            <p style="font-size:13px;color:var(--ink-muted);margin-bottom:12px;">
                Le integrazioni che usano questo token smetteranno di funzionare immediatamente.
            </p>
            <form method="POST" action="{{ route('portal.api-tokens.destroy', $token) }}"
                  onsubmit="return confirm('Revocare il token \'{{ $token->name }}\'?')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-danger" style="width:100%;">Revoca token</button>
            </form>
        </div>
    </div>
</div>
@endsection
