@extends('layouts.portal')

@section('content')

    <section class="card light-card" style="margin-bottom:22px;">
        <div class="section-head">
            <div>
                <span class="eyebrow">Diagnosi</span>
                <h3 class="section-title">Perch&eacute; il pagamento con carta non parte</h3>
            </div>
        </div>

        <div class="notice" style="margin-bottom:16px;">
            Pagina di sola lettura: non tocca conti, non muove KY, non registra pagamenti.
            Le chiavi sono mostrate solo a met&agrave;, quel tanto che basta per riconoscerle.
        </div>

        <div class="table-scroll">
            <table class="admin-table">
                <thead><tr><th style="width:34%;">Controllo</th><th>Esito</th></tr></thead>
                <tbody>
                @foreach($esiti as $titolo => [$stato, $dettaglio])
                    <tr>
                        <td style="font-weight:700;">{{ $titolo }}</td>
                        <td>
                            <span class="pill {{ $stato === 'ok' ? 'success' : ($stato === 'ko' ? 'danger' : 'warn') }}">
                                {{ $stato === 'ok' ? 'ok' : ($stato === 'ko' ? 'problema' : ($stato === 'warn' ? 'attenzione' : 'info')) }}
                            </span>
                            <div style="margin-top:6px;word-break:break-word;">{{ $dettaglio }}</div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @unless($sessione)
        <div style="margin-top:18px;">
            <a href="{{ route('admin.stripe-diagnostics', ['sessione' => 1]) }}" class="cta secondary">
                Prova anche a creare una sessione di pagamento
            </a>
            <div class="table-muted" style="margin-top:8px;font-size:12px;">
                Crea su Stripe un modulo di pagamento da 1,00 &euro; che nessuno compiler&agrave;: non &egrave; un incasso e scade da solo.
                Serve a vedere l'errore esatto che il checkout restituisce.
            </div>
        </div>
        @endunless
    </section>

@endsection
