{{--
    LE TRE QUOTE IN UNA PAGINA SOLA (04/09/2026).

    In cima le tre impostazioni, una sotto l'altra e tutte uguali: interruttore,
    importo, restituzione in KY a chi paga in euro, fido a chi paga in KY,
    metodi di pagamento. Sotto, gli elenchi dei pagamenti in tre schede.

    I tre riquadri sono lo STESSO partial ripetuto: se una riga va corretta, si
    corregge una volta. Cio' che cambia da quota a quota — i nomi dei campi, le
    rotte, chi paga — arriva dall'array `$quote` costruito in
    QuoteAdminController, non da tre copie di questo file.
--}}
@extends('layouts.portal')

@section('content')

    @if($errors->any())
        <div class="notice error" style="margin-bottom:16px;">
            @foreach($errors->all() as $errore)<div>{{ $errore }}</div>@endforeach
        </div>
    @endif

    <section style="margin-bottom:20px;">
        <div class="notice" style="margin-bottom:16px;">
            Le tre quote del circuito sono indipendenti: possono essere accese insieme, riguardano
            persone diverse e ognuna ha i suoi metodi di pagamento. Qui si decide
            <strong>quanto costano</strong> e <strong>cosa si riceve in cambio</strong>; chi paga e chi
            no si vede nelle schede in fondo, e la singola persona si tratta a parte dalla sua scheda.
            <br>
            <strong>Ogni riquadro si salva per conto suo</strong>: salvare una quota non tocca le altre due.
        </div>

        @foreach($quote as $q)
            @include('admin.quote.impostazioni', ['q' => $q])
        @endforeach
    </section>

    <section>
        <article class="card" style="padding:22px;">
            <div class="section-head" style="align-items:flex-start;">
                <div>
                    <span class="eyebrow">Pagamenti</span>
                    <h3 class="section-title" style="font-size:15px;">Quote registrate</h3>
                </div>
                <form method="GET" action="{{ route('admin.quote.index') }}" style="display:flex;gap:8px;align-items:center;">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <select name="stato" data-no-search onchange="this.form.submit()">
                        <option value="">Tutti gli stati</option>
                        @foreach(['pending' => 'In corso', 'pending_bank_transfer' => 'Attesa bonifico', 'completed' => 'Saldata', 'failed' => 'Fallita', 'cancelled' => 'Annullata'] as $valore => $etichetta)
                            <option value="{{ $valore }}" @selected($stato === $valore)>{{ $etichetta }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            {{-- Le schede sono link veri e non JavaScript: cosi' la paginazione e
                 il filtro per stato se le portano dietro, e ricaricando la pagina
                 si resta dove si era. --}}
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:12px;">
                @foreach($quote as $chiave => $altra)
                    <a href="{{ route('admin.quote.index', ['tab' => $chiave]) }}"
                       class="cta {{ $tab === $chiave ? '' : 'secondary' }}"
                       style="padding:7px 14px;font-size:12.5px;">{{ $altra['titolo'] }}</a>
                @endforeach
            </div>

            @include('admin.quote.pagamenti', ['q' => $quote[$tab]])
        </article>
    </section>

@endsection
