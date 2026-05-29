@extends('emails.layout')

@php $emailTitle = 'Avviso saldo KY'; @endphp

@section('hero_type', 'Avviso automatico')
@section('hero_title', 'Saldo sotto la soglia impostata')

@section('body')
<p class="greeting">Ciao {{ $notifiable->name ?? 'Cliente' }},</p>
<p>
    Il saldo del tuo conto KMoney e' sceso sotto la soglia di avviso che hai configurato.
</p>

<div class="amount-block">
    <div class="amount-label">Saldo attuale</div>
    <div class="amount-value" style="color:#dc2626;">{{ $currentBalance }}</div>
    <div style="font-size:12px;color:#64748b;margin-top:8px;">
        Soglia impostata: <strong>{{ $threshold }}</strong>
    </div>
</div>

<p>
    Se questo calo e' inatteso, accedi al portale per verificare i movimenti recenti e assicurarti che tutto sia in ordine.
</p>
<p style="font-size:13px;color:#64748b;">
    Riceverai al massimo una notifica ogni {{ $alert->cooldown_hours }} ore per questo avviso.
    Puoi modificare o disattivare gli avvisi saldo in qualsiasi momento dalla sezione <strong>Avvisi saldo</strong> del portale.
</p>
@endsection

@section('cta_url', route('portal.balance-alerts.index'))
@section('cta_label', 'Gestisci avvisi saldo')
