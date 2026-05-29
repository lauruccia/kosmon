@extends('emails.layout')

@section('content')
    <p class="greeting">Benvenuto/a, {{ $user->name }}!</p>

    <p>Il tuo conto KMoney è stato aperto con successo. Puoi già accedere al portale e iniziare a operare nel circuito.</p>

    @if($account)
    <div class="amount-block">
        <div class="amount-label">Il tuo numero di conto</div>
        <div style="font-size:20px;font-weight:700;color:#0c4a86;letter-spacing:.08em;">
            {{ $account->account_number }}
        </div>
        @if($company)
            <div style="margin-top:8px;font-size:13px;color:#64748b;">
                Azienda: <strong>{{ $company->name }}</strong>
            </div>
        @endif
    </div>
    @endif

    <table class="info-table">
        <tr>
            <td>Tipo conto</td>
            <td>{{ $user->account_holder_type === 'company' ? 'Aziendale' : 'Personale' }}</td>
        </tr>
        <tr>
            <td>Email registrata</td>
            <td>{{ $user->email }}</td>
        </tr>
        <tr>
            <td>Valuta del circuito</td>
            <td>KY (KMoney)</td>
        </tr>
        <tr>
            <td>Stato account</td>
            <td style="color:#166534;">✓ Attivo</td>
        </tr>
    </table>

    @if($user->account_holder_type === 'company')
    <div class="alert info">
        <strong>Prossimo passo — verifica KYC</strong><br>
        Per operare a pieno regime nel circuito, completa la verifica della tua identità aziendale caricando i documenti richiesti dal tuo portale.
    </div>
    @endif

    <div class="cta-wrap">
        <a href="{{ config('app.url') }}/dashboard" class="cta-btn">Accedi al portale →</a>
    </div>

    <hr class="divider">
    <p style="font-size:13px;color:#94a3b8;">
        Se non hai creato questo account, ignora questa email o contattaci tramite il portale.
    </p>
@endsection
