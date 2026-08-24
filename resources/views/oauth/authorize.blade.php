@extends('layouts.portal')

@section('content')
<div style="max-width:520px;margin:48px auto;">
    <section class="card card-pad">

        <div style="text-align:center;margin-bottom:26px;">
            <div style="font-size:44px;line-height:1;margin-bottom:12px;">🔗</div>
            <h2 style="font-size:20px;font-weight:800;margin:0 0 8px;">
                Collega {{ $clientName }} al tuo conto KMoney
            </h2>
            <p style="font-size:14px;color:var(--text-muted);margin:0;">
                Stai entrando come <strong>{{ $user->name }}</strong> ({{ $user->email }}).
            </p>
        </div>

        <p style="font-size:14px;margin:0 0 14px;">
            {{ $clientName }} chiede il permesso di:
        </p>

        <ul style="list-style:none;padding:0;margin:0 0 22px;">
            @foreach($scopes as $scope)
                <li style="display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border:1px solid var(--border);border-radius:10px;margin-bottom:10px;">
                    <span style="font-size:16px;line-height:1.4;">✅</span>
                    <span style="font-size:14px;line-height:1.4;">
                        {{ $scopeLabels[$scope] ?? $scope }}
                    </span>
                </li>
            @endforeach
        </ul>

        <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:22px;">
            <p style="font-size:13px;color:var(--text-muted);margin:0;line-height:1.5;">
                Non stai dando a {{ $clientName }} né la tua password né la possibilità
                di muovere KY da sola: ogni pagamento resterà da confermare qui su KMoney.
                Puoi interrompere il collegamento quando vuoi.
            </p>
        </div>

        <form method="POST" action="{{ route('oauth.authorize.approve') }}" style="margin:0 0 10px;">
            @csrf
            <button type="submit" class="cta" style="width:100%;">
                Consenti e continua
            </button>
        </form>

        <form method="POST" action="{{ route('oauth.authorize.deny') }}" style="margin:0;">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-outline" style="width:100%;background:none;">
                Annulla
            </button>
        </form>

    </section>
</div>
@endsection
