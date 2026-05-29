@extends('layouts.portal')

@section('content')
<div class="portal-grid">
    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Genera PDF</div>
            <h3 class="card-title" style="margin-top:12px;">Seleziona periodo</h3>

            @if($months)
                <form method="get" action="{{ route('portal.statement.download') }}" style="margin-top:18px;">
                    <div class="field-grid">
                        <div class="field">
                            <label for="mese">Mese di riferimento</label>
                            <select id="mese" name="mese" required>
                                @foreach($months as $m)
                                    <option value="{{ $m['value'] }}">{{ $m['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="cta">Scarica PDF</button>
                    </div>
                </form>
            @else
                <div class="empty-state" style="margin-top:24px;">
                    <p>Nessun movimento registrato ancora su questo conto.<br>L'estratto conto sarà disponibile dopo il primo movimento contabilizzato.</p>
                </div>
            @endif
        </section>
    </div>

    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Informazioni</div>
            <h3 class="card-title" style="margin-top:12px;">Cosa contiene</h3>
            <ul style="margin-top:14px;line-height:2;color:var(--text-muted);font-size:14px;padding-left:18px;">
                <li>Intestazione conto e numero KY</li>
                <li>Saldo iniziale del periodo</li>
                <li>Elenco completo movimenti con data, controparte, causale e importo</li>
                <li>Saldo progressivo dopo ogni movimento</li>
                <li>Saldo finale del periodo</li>
            </ul>
            <div class="table-muted" style="margin-top:16px;">Il documento è generato in formato PDF e non ha valore fiscale.</div>
        </section>
    </div>
</div>
@endsection
