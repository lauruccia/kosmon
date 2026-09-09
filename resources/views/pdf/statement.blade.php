{{--
  Estratto conto — documento da consegnare a terzi (commercialista, banca, revisore).

  PERCHE' TABELLE E NON FLEXBOX (09/09/2026). La versione precedente impaginava
  testata, riquadri anagrafici e saldi con `display: flex`. Dompdf il flexbox non
  lo implementa: quelle regole venivano semplicemente ignorate e i blocchi si
  incolonnavano uno sotto l'altro. Qui ogni affiancamento e' una `<table>` con
  larghezze in percentuale — brutto da leggere, ma e' l'unico costrutto che
  dompdf impagina come ci si aspetta.

  TESTATA E PIEDE SU OGNI PAGINA. `position: fixed` con offset negativi dentro il
  margine di `@page`. **I margini sono in millimetri, non in pixel**: con
  `@page { margin: 132px ... }` dompdf non applicava alcun margine, e testata e
  piede — piazzati con `top` negativo — finivano fuori dal foglio senza lasciare
  traccia. Si vede solo rendendo il PDF davvero, non leggendo il template.

  IL NUMERO DI PAGINA NON E' QUI. `counter(pages)` in dompdf restituisce sempre
  0 ("Pagina 2 di 0"): il totale non e' noto mentre la pagina viene composta.
  Lo scrive il controller a rendering finito, con `Canvas::page_text()` e i
  segnaposto {PAGE_NUM}/{PAGE_COUNT}. L'altra strada — `isPhpEnabled` e uno
  `<script type="text/php">` — vorrebbe dire abilitare l'esecuzione di PHP
  dentro il renderer per tutti i PDF dell'applicazione: troppo per un numero di
  pagina. La fascia in basso a destra resta vuota apposta: e' lo spazio in cui
  quel testo viene disegnato.

  L'INTESTAZIONE DELLA TABELLA SI RIPETE. `thead { display: table-header-group }`:
  su un estratto annuale il dettaglio occupa decine di pagine, e una pagina di
  numeri senza intestazione di colonna non e' leggibile.

  NIENTE COLORI PIENI DIETRO LE RIGHE. Un documento del genere si stampa, spesso
  in bianco e nero e spesso in fotocopia: le righe alternate sono di un grigio
  appena percepibile e ogni informazione resta leggibile anche senza colore.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $documentTitle }} — {{ $holder['name'] }} — {{ $period->label }}</title>
<style>
  @page { margin: 34mm 12mm 20mm 12mm; }

  /* NIENTE `* { margin: 0 }` QUI. Il selettore universale in dompdf colpisce
     anche il box di pagina e AZZERA i margini di `@page`: il documento veniva
     stampato attaccato al bordo del foglio e testata e piede, posizionati con
     offset negativi dentro quei margini, finivano fuori pagina. Si azzera solo
     cio' che serve, elencandolo. */
  body { margin: 0; padding: 0; }
  div, p, table, td, th, span, ul, li, header, footer, main, section { box-sizing: border-box; }
  header, footer, main, section { display: block; }

  body {
    font-family: "DejaVu Sans", sans-serif;
    font-size: 9pt;
    line-height: 1.35;
    color: #16181d;
  }

  /* ── Testata ripetuta su ogni pagina ─────────────────────────────────── */
  header {
    position: fixed;
    top: -27mm; left: 0; right: 0; height: 22mm;
    border-bottom: 1.6pt solid #4c1d95;
    padding-bottom: 8px;
  }
  .hd-brand { font-size: 19pt; font-weight: bold; color: #4c1d95; letter-spacing: -0.5pt; }
  .hd-claim { font-size: 7.5pt; color: #6b7280; margin-top: 1px; }
  .hd-right { text-align: right; }
  .hd-doc { font-size: 10.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6pt; }
  .hd-period { font-size: 9pt; margin-top: 2px; }
  .hd-account { font-size: 7.5pt; color: #6b7280; margin-top: 2px; }

  /* ── Piede ripetuto su ogni pagina ───────────────────────────────────── */
  footer {
    position: fixed;
    bottom: -13mm; left: 0; right: 0; height: 10mm;
    border-top: 0.6pt solid #d8dade;
    padding-top: 6px;
    font-size: 7pt; color: #7a7f88;
  }

  /* ── Impianto ────────────────────────────────────────────────────────── */
  .layout { width: 100%; border-collapse: collapse; }
  .layout td { vertical-align: top; }

  .doc-title { font-size: 15pt; font-weight: bold; letter-spacing: -0.3pt; }
  .doc-sub { font-size: 8.5pt; color: #6b7280; margin-top: 2px; }

  .section { margin-top: 18px; page-break-inside: avoid; }
  .section-title {
    font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1pt;
    font-weight: bold; color: #4c1d95;
    border-bottom: 0.6pt solid #d8dade;
    padding-bottom: 3px; margin-bottom: 7px;
  }

  /* ── Riquadri anagrafici ─────────────────────────────────────────────── */
  .box { border: 0.6pt solid #d8dade; border-radius: 3px; padding: 9px 11px; }
  .box-label { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #6b7280; font-weight: bold; }
  .box-name { font-size: 10.5pt; font-weight: bold; margin-top: 3px; }
  .box-line { font-size: 8pt; color: #414750; margin-top: 2px; }
  .box-line .k { color: #7a7f88; }
  .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; letter-spacing: -0.2pt; }

  /* ── Riepilogo saldi ─────────────────────────────────────────────────── */
  .saldi { width: 100%; border-collapse: collapse; }
  .saldi td {
    border: 0.6pt solid #d8dade;
    padding: 8px 6px;
    text-align: center;
    width: 20%;
  }
  .saldi td.evidenza { border: 1.4pt solid #4c1d95; }
  .saldi .s-label { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #6b7280; font-weight: bold; }
  .saldi .s-value { font-size: 12pt; font-weight: bold; margin-top: 4px; }
  .saldi .s-note { font-size: 6.5pt; color: #7a7f88; margin-top: 3px; }
  .saldi .evidenza .s-value { color: #4c1d95; }
  .pos { color: #146c43; }
  .neg { color: #a52834; }

  /* ── Tabelle dati ────────────────────────────────────────────────────── */
  table.dati { width: 100%; border-collapse: collapse; }
  table.dati thead { display: table-header-group; }
  table.dati thead th {
    background: #efedf7;
    border-top: 0.6pt solid #c9c5dd;
    border-bottom: 0.6pt solid #c9c5dd;
    padding: 5px 6px;
    font-size: 6.8pt; text-transform: uppercase; letter-spacing: 0.5pt;
    text-align: left; color: #3b3260;
  }
  table.dati tbody td { padding: 5px 6px; border-bottom: 0.5pt solid #eceef1; font-size: 8pt; }
  table.dati tbody tr.zebra td { background: #fafafb; }
  table.dati tfoot td {
    padding: 6px; border-top: 1pt solid #4c1d95;
    font-size: 8.5pt; font-weight: bold;
  }
  .num { text-align: right; white-space: nowrap; }
  .c-data { white-space: nowrap; color: #414750; }
  .c-rif { font-size: 6.8pt; color: #9298a2; }
  .c-tipo { font-size: 6.8pt; color: #7a7f88; }
  .c-nome { font-weight: bold; }
  .c-causale { color: #414750; }

  .vuoto { text-align: center; padding: 26px 0; color: #8a9099; font-style: italic; border: 0.6pt dashed #d8dade; border-radius: 3px; }

  .nota { margin-top: 16px; border-top: 0.6pt solid #d8dade; padding-top: 8px; font-size: 7pt; color: #7a7f88; line-height: 1.5; }
  .avviso { margin-top: 10px; border: 0.6pt solid #e6c66a; background: #fdf8e8; border-radius: 3px; padding: 8px 10px; font-size: 7.5pt; color: #6b5312; }
</style>
</head>
<body>

<header>
  <table class="layout">
    <tr>
      <td style="width:55%;">
        <div class="hd-brand">KMoney</div>
        <div class="hd-claim">Circuito di credito reciproco · valuta interna KY</div>
      </td>
      <td class="hd-right">
        <div class="hd-doc">{{ $documentTitle }}</div>
        <div class="hd-period">{{ $period->label }}</div>
        <div class="hd-account">Conto {{ $account->account_number }} · {{ $holder['name'] }}</div>
      </td>
    </tr>
  </table>
</header>

<footer>
  <table class="layout">
    <tr>
      <td style="width:42%;">KMoney — documento privo di valore fiscale</td>
      <td style="width:33%; text-align:center;">Emesso il {{ $generatedAt->format('d/m/Y \a\l\l\e H:i') }}</td>
      {{-- Vuota di proposito: "Pagina X di Y" ci viene disegnato sopra dal controller. --}}
      <td style="width:25%;"></td>
    </tr>
  </table>
</footer>

<main>

  <div class="doc-title">Estratto conto</div>
  <div class="doc-sub">
    Movimenti dal {{ $period->start->format('d/m/Y') }} al {{ $period->end->format('d/m/Y') }}
  </div>

  {{-- ── Intestatario e conto ──────────────────────────────────────────── --}}
  <div class="section">
    <table class="layout">
      <tr>
        <td style="width:50%; padding-right:6px;">
          <div class="box">
            <div class="box-label">Intestatario</div>
            <div class="box-name">{{ $holder['name'] }}</div>
            @foreach($holder['lines'] as $line)
              <div class="box-line"><span class="k">{{ $line['label'] }}:</span> {{ $line['value'] }}</div>
            @endforeach
          </div>
        </td>
        <td style="width:50%; padding-left:6px;">
          <div class="box">
            <div class="box-label">Rapporto</div>
            <div class="box-name mono">{{ $account->account_number }}</div>
            <div class="box-line"><span class="k">Tipo:</span> {{ $holder['type'] }}@if($account->account_name) · {{ $account->account_name }}@endif</div>
            <div class="box-line"><span class="k">Valuta:</span> {{ $account->currency_code ?? 'KY' }}</div>
            <div class="box-line"><span class="k">Movimenti nel periodo:</span> {{ $totals['count'] }}</div>
          </div>
        </td>
      </tr>
    </table>
  </div>

  {{-- ── Riepilogo del periodo ─────────────────────────────────────────── --}}
  <div class="section">
    <div class="section-title">Riepilogo del periodo</div>
    <table class="saldi">
      <tr>
        <td>
          <div class="s-label">Saldo iniziale</div>
          <div class="s-value">{{ ky_format($opening) }}</div>
          <div class="s-note">al {{ $period->start->format('d/m/Y') }}</div>
        </td>
        <td>
          <div class="s-label">Totale entrate</div>
          <div class="s-value pos">+{{ ky_format($totals['entrate']) }}</div>
          <div class="s-note">{{ $totals['countEntrate'] }} {{ $totals['countEntrate'] === 1 ? 'movimento' : 'movimenti' }}</div>
        </td>
        <td>
          <div class="s-label">Totale uscite</div>
          <div class="s-value neg">-{{ ky_format($totals['uscite']) }}</div>
          <div class="s-note">{{ $totals['countUscite'] }} {{ $totals['countUscite'] === 1 ? 'movimento' : 'movimenti' }}</div>
        </td>
        <td>
          <div class="s-label">Variazione</div>
          <div class="s-value {{ $totals['netto'] >= 0 ? 'pos' : 'neg' }}">{{ $totals['netto'] >= 0 ? '+' : '-' }}{{ ky_format(abs($totals['netto'])) }}</div>
          <div class="s-note">nel periodo</div>
        </td>
        <td class="evidenza">
          <div class="s-label">Saldo finale</div>
          <div class="s-value">{{ ky_format($closing) }}</div>
          <div class="s-note">al {{ $period->end->format('d/m/Y') }}</div>
        </td>
      </tr>
    </table>
  </div>

  {{-- ── Riepilogo per mese (solo se il periodo ne copre piu' di uno) ───── --}}
  @if(! empty($monthly))
  <div class="section">
    <div class="section-title">Andamento per mese</div>
    <table class="dati">
      <thead>
        <tr>
          <th style="width:34%;">Mese</th>
          <th style="width:14%;" class="num">Movimenti</th>
          <th style="width:17%;" class="num">Entrate</th>
          <th style="width:17%;" class="num">Uscite</th>
          <th style="width:18%;" class="num">Saldo a fine mese</th>
        </tr>
      </thead>
      <tbody>
        @foreach($monthly as $i => $mese)
        <tr class="{{ $i % 2 ? 'zebra' : '' }}">
          <td>{{ $mese['label'] }}</td>
          <td class="num">{{ $mese['count'] }}</td>
          <td class="num pos">{{ $mese['entrate'] ? '+' . ky_format($mese['entrate']) : '—' }}</td>
          <td class="num neg">{{ $mese['uscite'] ? '-' . ky_format($mese['uscite']) : '—' }}</td>
          <td class="num">{{ ky_format($mese['closing']) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif

  {{-- ── Riepilogo per tipologia ───────────────────────────────────────── --}}
  @if(count($byKind) > 1)
  <div class="section">
    <div class="section-title">Composizione per tipologia di operazione</div>
    <table class="dati">
      <thead>
        <tr>
          <th style="width:46%;">Tipologia</th>
          <th style="width:14%;" class="num">Movimenti</th>
          <th style="width:20%;" class="num">Entrate</th>
          <th style="width:20%;" class="num">Uscite</th>
        </tr>
      </thead>
      <tbody>
        @foreach($byKind as $i => $tipo)
        <tr class="{{ $i % 2 ? 'zebra' : '' }}">
          <td>{{ $tipo['label'] }}</td>
          <td class="num">{{ $tipo['count'] }}</td>
          <td class="num pos">{{ $tipo['entrate'] ? '+' . ky_format($tipo['entrate']) : '—' }}</td>
          <td class="num neg">{{ $tipo['uscite'] ? '-' . ky_format($tipo['uscite']) : '—' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif

  {{-- ── Dettaglio movimenti ───────────────────────────────────────────── --}}
  <div class="section" style="page-break-inside:auto;">
    <div class="section-title">Dettaglio movimenti</div>

    @if($totals['count'] === 0)
      <div class="vuoto">Nessun movimento contabilizzato nel periodo selezionato.</div>
    @elseif($righeOmesse)
      <div class="avviso">
        Il periodo selezionato contiene {{ $totals['count'] }} movimenti: oltre {{ $maxRighe }} righe
        il dettaglio non e' piu' leggibile su carta e non viene stampato. I riepiloghi qui sopra
        restano completi e comprendono tutti i movimenti del periodo; per l'elenco riga per riga
        scaricare lo stesso periodo in formato CSV o prima nota.
      </div>
    @else
    <table class="dati">
      <thead>
        <tr>
          <th style="width:12%;">Data</th>
          <th style="width:23%;">Controparte</th>
          <th style="width:27%;">Causale</th>
          <th style="width:13%;" class="num">Entrate</th>
          <th style="width:13%;" class="num">Uscite</th>
          <th style="width:12%;" class="num">Saldo</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $i => $row)
        <tr class="{{ $i % 2 ? 'zebra' : '' }}">
          <td class="c-data">
            {{ $row['date']->format('d/m/Y') }}
            @if($row['reference'])<br><span class="c-rif">{{ $row['reference'] }}</span>@endif
          </td>
          <td>
            <span class="c-nome">{{ $row['counterparty'] }}</span><br>
            <span class="c-tipo">{{ $row['kindLabel'] }}</span>
          </td>
          <td class="c-causale">{{ $row['description'] !== '' ? $row['description'] : '—' }}</td>
          <td class="num pos">{{ $row['credit'] ? '+' . ky_format($row['amount']) : '' }}</td>
          <td class="num neg">{{ $row['credit'] ? '' : '-' . ky_format($row['amount']) }}</td>
          <td class="num">{{ ky_format($row['balance']) }}</td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3">Totali del periodo — {{ $totals['count'] }} movimenti</td>
          <td class="num pos">+{{ ky_format($totals['entrate']) }}</td>
          <td class="num neg">-{{ ky_format($totals['uscite']) }}</td>
          <td class="num">{{ ky_format($closing) }}</td>
        </tr>
      </tfoot>
    </table>
    @endif
  </div>

  <div class="nota">
    Tutti gli importi sono espressi in KY, la valuta interna del circuito KMoney, con due decimali.
    L'estratto riporta i soli movimenti contabilizzati alla data di emissione: le operazioni in attesa
    di conferma e quelle annullate non concorrono ai saldi.
    @if($account->childAccounts()->exists())
      Gli eventuali sottoconti collegati hanno contabilita' separata e un proprio estratto conto.
    @endif
    Documento generato automaticamente dalla piattaforma KMoney: non ha valore fiscale e non
    sostituisce le scritture contabili obbligatorie.
  </div>

</main>

</body>
</html>
