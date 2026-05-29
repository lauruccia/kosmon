@extends('layouts.portal')

@section('page-actions')
<a href="{{ route('portal.api-tokens.create') }}" class="cta">+ Nuovo token</a>
@endsection



@section('content')
{{-- Documentazione rapida --}}
<div class="card card-pad" style="margin-bottom:20px;background:var(--surface-soft);border:1px solid var(--line);">
    <div style="font-size:13px;font-weight:700;margin-bottom:8px;">Utilizzo rapido</div>
    <pre style="font-size:11px;background:var(--ink);color:#e2e8f0;border-radius:6px;padding:10px 14px;overflow-x:auto;margin:0;line-height:1.6;">curl https://{{ request()->host() }}/api/v1/me \
  -H "Authorization: Bearer km_il_tuo_token"</pre>
    <div style="font-size:11px;color:var(--ink-muted);margin-top:8px;">
        Base URL: <code>{{ request()->schemeAndHttpHost() }}/api/v1</code>
        &middot; Endpoint: <code>GET /me</code> &middot; <code>GET /transfers</code> &middot; <code>POST /transfers</code>
    </div>
</div>

<section class="card light-card">
    <table class="transactions-table">
        <thead>
            <tr>
                <th>Nome</th>
                <th>Prefisso</th>
                <th>Permessi</th>
                <th>Ultimo uso</th>
                <th>Scadenza</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($tokens as $tok)
                <tr>
                    <td style="font-weight:600;">{{ $tok->name }}</td>
                    <td>
                        <code style="font-size:12px;background:var(--surface-soft);padding:2px 6px;border-radius:4px;">
                            {{ $tok->token_prefix }}…
                        </code>
                    </td>
                    <td>
                        @foreach($tok->abilities as $ab)
                            <span class="chip {{ $ab === 'write' ? 'pink' : '' }}" style="font-size:11px;margin-right:2px;">{{ $ab }}</span>
                        @endforeach
                    </td>
                    <td style="font-size:12px;color:var(--ink-muted);">
                        {{ $tok->last_used_at ? $tok->last_used_at->diffForHumans() : 'Mai' }}
                    </td>
                    <td style="font-size:12px;">
                        @if($tok->expires_at)
                            <span style="{{ $tok->isExpired() ? 'color:var(--danger);font-weight:600;' : '' }}">
                                {{ $tok->expires_at->format('d/m/Y') }}
                            </span>
                        @else
                            <span style="color:var(--ink-muted);">Nessuna</span>
                        @endif
                    </td>
                    <td style="display:flex;gap:10px;align-items:center;">
                        <a href="{{ route('portal.api-tokens.show', $tok) }}"
                           style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;">Info</a>
                        <form method="POST" action="{{ route('portal.api-tokens.destroy', $tok) }}"
                              onsubmit="return confirm('Revocare questo token? Tutte le integrazioni che lo usano smetteranno di funzionare.')">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:none;border:none;cursor:pointer;font-size:12px;font-weight:600;color:var(--danger);padding:0;">
                                Revoca
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="subtle" style="text-align:center;padding:32px;">
                        Nessun token creato.
                        <a href="{{ route('portal.api-tokens.create') }}" style="color:var(--primary);font-weight:600;">Crea il primo &rarr;</a>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</section>
@endsection
