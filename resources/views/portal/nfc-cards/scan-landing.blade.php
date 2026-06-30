@extends('layouts.portal')

@section('content')
<div style="max-width:400px;margin:0 auto;text-align:center;padding:40px 20px;">
    <div style="font-size:72px;margin-bottom:16px;">&#128246;</div>
    <h1 style="font-size:22px;font-weight:800;color:var(--ink);margin:0 0 8px;">Card NFC KMoney</h1>
    <div style="font-size:14px;color:var(--ink-muted);margin-bottom:24px;">
        Card di <strong>{{ $card->ownerName() }}</strong>
    </div>

    <div style="background:var(--surface-soft);border:1px solid var(--line);border-radius:12px;padding:16px;font-size:13px;color:var(--ink-muted);margin-bottom:24px;">
        Sei un merchant KMoney? Accedi all'app e usa la funzione <strong>"Richiedi pagamento CARD NFC"</strong> per incassare tramite questa card.
    </div>

    <a href="{{ route('login') }}" class="cta" style="display:inline-block;">
        Accedi al portale
    </a>
</div>
@endsection
