@extends('layouts.portal')

@section('content')
<div style="max-width:720px;">

    <div style="margin-bottom:20px;">
        <a href="{{ route('portal.shop') }}" style="font-size:13px;color:var(--primary,#0c4a86);text-decoration:none;">&larr; Torna allo shop</a>
    </div>

    <section class="card light-card" style="margin-bottom:22px;padding:20px 24px;">
        <h2 style="margin:0 0 6px;">Metodi di pagamento EUR</h2>
        <p class="subtle" style="margin:0;line-height:1.7;">
            Quando pubblichi un prodotto con una quota in euro (percentuale KY inferiore al 100%), l'acquirente paga quella quota
            direttamente sul metodo che configuri qui sotto — <strong>sul tuo conto, non su un conto Kosmopay</strong>. Kosmopay non intermedia
            mai questi pagamenti: usa le tue credenziali solo per generare la richiesta di pagamento.
        </p>
    </section>

    @if(session('portal_success'))
        <div class="alert alert-success" style="margin-bottom:20px;">{{ session('portal_success') }}</div>
    @endif
    @if(session('portal_error'))
        <div class="alert alert-error" style="margin-bottom:20px;">{{ session('portal_error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-error" style="margin-bottom:20px;">Controlla i campi evidenziati.</div>
    @endif

    @foreach($providers as $providerKey => $providerLabel)
        @include('partials.payment-gateway-provider-card', [
            'provider'      => $providerKey,
            'providerLabel' => $providerLabel,
            'fieldSpecs'    => $fields[$providerKey],
            'gateway'       => $gateways->get($providerKey),
            'updateUrl'     => route($updateRoute, [...$routeParams, $providerKey]),
            'toggleUrl'     => route($toggleRoute, [...$routeParams, $providerKey]),
            'destroyUrl'    => route($destroyRoute, [...$routeParams, $providerKey]),
        ])
    @endforeach

</div>
@endsection
