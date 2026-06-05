@extends('layouts.portal')

@section('content')
<div class="form-shell">

    {{-- Intestazione merchant --}}
    <div class="form-card">
        <div class="form-header" style="display:flex;align-items:center;gap:12px;">
            {{-- Icona NFC / tap --}}
            <span style="font-size:28px;line-height:1;">📲</span>
            <div>
                <div>{{ $toAccount->company?->name ?? $toAccount->display_name }}</div>
                <div style="font-size:13px;font-weight:400;color:var(--text-muted);margin-top:2px;">
                    {{ $toAccount->account_number }}
                </div>
            </div>
        </div>
    </div>

    {{-- Form pagamento --}}
    <div class="form-card">
        <div class="form-body">

            <div class="subtle" style="margin-bottom:6px;">
                Stai pagando con: <strong>{{ $fromAccount->display_name }}</strong>
            </div>
            <div class="subtle" style="margin-bottom:20px;">
                Saldo disponibile: <strong>{{ ky_format($fromAccount->saldoDisponibile()) }} KY</strong>
            </div>

            <form method="post" action="{{ route('portal.pay.submit') }}">
                @csrf
                <input type="hidden" name="to_account_id" value="{{ $toAccount->id }}">

                <div class="field-grid">
                    <div class="field">
                        <label for="amount">Importo in KY</label>
                        <input
                            id="amount"
                            name="amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                            value="{{ old('amount', request('amount')) }}"
                            placeholder="Es. 25,00"
                            required
                            autofocus
                            inputmode="decimal"
                        >
                    </div>
                    <div class="field">
                        <label for="description">Causale <span style="font-weight:400;color:var(--text-muted);">(opzionale)</span></label>
                        <textarea
                            id="description"
                            name="description"
                            placeholder="Es. Cena tavolo 4"
                            rows="2"
                        >{{ old('description') }}</textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="{{ route('portal.dashboard') }}" class="cta secondary">ANNULLA</a>
                    <button type="submit" class="cta">PAGA ORA</button>
                </div>
            </form>

        </div>
    </div>

    {{-- Nota informativa --}}
    <p style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:8px;">
        Pagamento in valuta KY — Circuito KMoney
    </p>

</div>
@endsection
