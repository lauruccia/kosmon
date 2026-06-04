@extends('layouts.portal')

@section('content')
<div class="form-shell">
    <div class="form-card">
        <div class="form-header">
            Paga {{ $toAccount->company?->name ?? $toAccount->display_name }}
        </div>
    </div>
    <div class="form-card">
        <div class="form-body">
            <div class="subtle" style="margin-bottom:6px;">Stai pagando con: <strong>{{ $fromAccount->display_name }}</strong></div>
            <div class="subtle" style="margin-bottom:18px;">Destinatario: <strong>{{ $toAccount->company?->name ?? $toAccount->display_name }}</strong>
                <span style="font-family:monospace;font-size:12px;color:var(--text-muted);">&nbsp;{{ $toAccount->account_number }}</span>
            </div>

            <form method="post" action="{{ route('portal.pay.submit') }}">
                @csrf
                <input type="hidden" name="to_account_id" value="{{ $toAccount->id }}">
                <div class="field-grid">
                    <div class="field">
                        <label for="amount">Importo in KY</label>
                        <input id="amount" name="amount" type="number" min="1"
                               value="{{ old('amount', request('amount')) }}"
                               placeholder="Es. 500" required autofocus>
                        <div class="table-muted">Saldo disponibile: {{ ky_format($fromAccount->saldoDisponibile()) }} KY</div>
                    </div>
                    <div class="field">
                        <label for="description">Causale</label>
                        <textarea id="description" name="description"
                                  placeholder="Inserisci riferimento fattura o descrizione">{{ old('description') }}</textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="{{ route('portal.dashboard') }}" class="cta secondary">ANNULLA</a>
                    <button type="submit" class="cta">PAGA ORA</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
