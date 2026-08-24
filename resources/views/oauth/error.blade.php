@extends('layouts.portal')

@section('content')
<div style="max-width:480px;margin:60px auto;">
    <section class="card card-pad" style="text-align:center;">

        <div style="font-size:44px;line-height:1;margin-bottom:12px;">🚫</div>

        <h2 style="font-size:20px;font-weight:800;margin:0 0 10px;">{{ $title }}</h2>

        <p style="font-size:14px;color:var(--text-muted);margin:0 0 22px;line-height:1.5;">
            {{ $detail }}
        </p>

        <p style="font-size:13px;color:var(--text-muted);margin:0 0 22px;line-height:1.5;">
            Per sicurezza non ti riportiamo indietro automaticamente: torna
            all'applicazione da cui sei arrivato e riprova da lì.
        </p>

        <a href="{{ route('portal.dashboard') }}" class="cta" style="display:inline-block;text-decoration:none;">
            Torna alla dashboard
        </a>

    </section>
</div>
@endsection
