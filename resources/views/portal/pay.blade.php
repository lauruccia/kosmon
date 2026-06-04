@extends('layouts.portal')

@section('page-actions')
<a class="cta secondary" href="{{ route('portal.movements') }}">Movimenti</a>
<a class="cta secondary" href="{{ route('portal.companies') }}">Rubrica</a>
@endsection




@section('content')
    <div class="summary-grid">
        <section class="card account-hero card-pad">
            <span class="k-tag">Conto di addebito</span>
            <h1 style="position:relative;z-index:1;margin:16px 0 18px;">{{ $currentAccount->display_name }}</h1>
            <div class="metric">
                <div class="metric-label">Circuito</div>
                <div class="metric-value">{{ $currentAccount->currency_code }}</div>
            </div>
            <div class="metric">
                <div class="metric-label">Operazione</div>
                <div class="metric-value">Pagamento</div>
            </div>
            <div class="stat-note">La causale viene registrata nel ledger del conto mittente e destinatario.</div>
        </section>

        <section class="card light-card">
            <div class="section-head">
                <div>
                    <span class="eyebrow">Disposizione</span>
                    <h3 class="section-title">Nuovo pagamento</h3>
                </div>
                <span class="pill">KY transfer</span>
            </div>
            <div class="form-body">
                <form method="post" action="{{ route('portal.pay.submit') }}">
                    @csrf
                    <div class="field-grid">
                        <div class="field">
                            <label for="to_account_id">Destinatario del pagamento</label>
                            <div class="field-inline">
                                <select id="to_account_id" name="to_account_id" required>
                                    <option value="">Seleziona conto destinatario</option>
                                    @foreach ($counterpartyAccounts as $account)
                                        <option value="{{ $account->id }}" @selected(old('to_account_id') == $account->id)>{{ $account->display_name }} · {{ $account->owner_type }} · {{ $account->currency_code }}</option>
                                    @endforeach
                                </select>
                                <a href="{{ route('portal.companies') }}" class="cta secondary">Rubrica</a>
                            </div>
                        </div>
                        <div class="field"><label for="amount">Importo in KY</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" value="{{ old('amount') }}" placeholder="Es. 15,00" required></div>
                        <div class="field"><label for="description">Causale</label><textarea id="description" name="description" placeholder="Inserisci riferimento fattura o descrizione breve">{{ old('description') }}</textarea></div>
                    </div>
                    <div class="form-actions"><a href="{{ route('portal.dashboard') }}" class="cta secondary">Annulla</a><button type="submit" class="cta">Prosegui</button></div>
                </form>
            </div>
        </section>
    </div>
@endsection
