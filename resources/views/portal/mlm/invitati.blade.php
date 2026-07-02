@extends('layouts.portal')

@section('content')
@if(session('status'))
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(22,163,74,.09);border:1px solid rgba(22,163,74,.3);color:#166534;font-size:13px;font-weight:600;">
        {{ session('status') }}
    </div>
@endif
@if($errors->any())
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);color:#b91c1c;font-size:13px;font-weight:600;">
        {{ $errors->first() }}
    </div>
@endif

<div class="card card-pad" style="margin-bottom:14px;">
    <h2 style="margin:0 0 4px;font-size:18px;">I miei inviti</h2>
    <p style="margin:0 0 14px;color:var(--ink-muted);font-size:13px;">
        Invita nuovi agenti o clienti via email: vedrai qui se si sono registrati. Chi si registra con il tuo link entra nella tua rete.
    </p>

    <div style="display:flex;gap:8px;align-items:center;background:var(--surface-soft,#f8fafc);border:1.5px solid var(--line);border-radius:10px;padding:10px 14px;margin-bottom:16px;">
        <span id="mlmRefLink" style="flex:1;font-size:13px;font-family:monospace;color:var(--ink-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $referralUrl }}</span>
        <button type="button" id="mlmCopyBtn" style="padding:8px 16px;background:var(--ink);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">Copia link</button>
    </div>

    <form method="POST" action="{{ route('portal.mlm.invitati.store') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        @csrf
        <div>
            <label class="form-label" style="display:block;margin-bottom:4px;">Email da invitare *</label>
            <input type="email" name="email" value="{{ old('email') }}" required maxlength="190" class="form-control" placeholder="nome@esempio.it" style="min-width:240px;">
        </div>
        <div>
            <label class="form-label" style="display:block;margin-bottom:4px;">Nome (opzionale)</label>
            <input type="text" name="name" value="{{ old('name') }}" maxlength="120" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">Invia invito</button>
    </form>
</div>

<div class="card card-pad" style="margin-bottom:14px;padding-bottom:0;">
    <h3 style="margin:0 0 10px;font-size:15px;">Inviti email</h3>
</div>
<section class="card light-card" style="margin-bottom:14px;">
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Email</th>
                <th>Nome</th>
                <th>Inviato il</th>
                <th>Stato</th>
                <th style="text-align:right;">Azione</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invitations as $invitation)
                <tr>
                    <td>{{ $invitation->email }}</td>
                    <td>{{ $invitation->name ?? '—' }}</td>
                    <td>{{ $invitation->sent_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td>
                        @if($invitation->status === 'registered')
                            <span class="pill" style="background:rgba(22,163,74,.12);color:#166534;">Registrato</span>
                            @if($invitation->registeredUser)
                                <span style="display:block;color:var(--ink-muted);font-size:12px;">{{ $invitation->registeredUser->name }}</span>
                            @endif
                        @else
                            <span class="pill" style="background:rgba(217,119,6,.12);color:#b45309;">In attesa</span>
                        @endif
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        @if($invitation->isPending())
                            <form method="POST" action="{{ route('portal.mlm.invitati.resend', $invitation) }}" style="display:inline;">
                                @csrf
                                <button type="submit" style="border:none;background:none;color:var(--primary);font-weight:600;font-size:13px;cursor:pointer;">Reinvia</button>
                            </form>
                            <form method="POST" action="{{ route('portal.mlm.invitati.destroy', $invitation) }}" style="display:inline;" onsubmit="return confirm('Eliminare questo invito?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="border:none;background:none;color:#b91c1c;font-weight:600;font-size:13px;cursor:pointer;">Elimina</button>
                            </form>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessun invito inviato finora.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
<div style="margin-bottom:20px;">{{ $invitations->links() }}</div>

<div class="card card-pad" style="margin-bottom:14px;padding-bottom:0;">
    <h3 style="margin:0 0 10px;font-size:15px;">Registrati con il tuo link</h3>
</div>
<section class="card light-card">
    <table class="admin-table transactions-table">
        <thead>
            <tr>
                <th>Nome</th>
                <th>Tipo</th>
                <th>Registrato il</th>
            </tr>
        </thead>
        <tbody>
            @forelse($referrals as $referral)
                <tr>
                    <td>
                        <strong style="display:block;">{{ $referral->name }}</strong>
                        <span style="color:var(--ink-muted);font-size:12px;">{{ $referral->email }}</span>
                    </td>
                    <td>
                        @if($referral->mlm_role === 'agente')
                            <span class="pill" style="background:rgba(12,74,134,.1);color:#0c4a86;">Agente</span>
                        @else
                            <span class="pill">Cliente</span>
                        @endif
                    </td>
                    <td>
                        {{ $referral->created_at?->format('d/m/Y H:i') }}
                        <span style="display:block;color:var(--ink-muted);font-size:12px;">{{ $referral->created_at?->diffForHumans() }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align:center;color:var(--ink-muted);padding:24px;">Nessuno si è ancora registrato con il tuo link.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
<div style="margin-top:14px;">{{ $referrals->links() }}</div>

<script>
(function () {
    var btn = document.getElementById('mlmCopyBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var text = document.getElementById('mlmRefLink').textContent.trim();
        navigator.clipboard.writeText(text).then(function () {
            btn.textContent = 'Copiato!';
            setTimeout(function () { btn.textContent = 'Copia link'; }, 2000);
        });
    });
})();
</script>
@endsection
