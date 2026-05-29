@extends('layouts.portal')

@section('content')
<div class="page-header">
    <h1 class="page-title">{{ $pageTitle }}</h1>
<div style="margin:12px 0 20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <a href="{{ route('api.openapi-json') }}" target="_blank" class="cta secondary" style="font-size:12.5px;min-height:30px;padding:0 14px;display:inline-flex;align-items:center;gap:6px;">
        &#x1F4C4; Scarica openapi.json
    </a>
    <span style="font-size:12px;color:var(--ink-muted);">Importabile in Postman, Insomnia, Swagger UI</span>
</div>
    <p class="page-subtitle">Integra il circuito KMoney nelle tue applicazioni tramite l'API REST v1.</p>
</div>

<style>
.docs-section { margin-bottom: 2.5rem; }
.docs-section h2 { font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem; padding-bottom: .5rem; border-bottom: 2px solid var(--border-color); }
.docs-section h3 { font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 1.5rem 0 .5rem; }
.endpoint-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 1rem; overflow: hidden; }
.endpoint-header { display: flex; align-items: center; gap: .75rem; padding: .9rem 1.25rem; cursor: pointer; user-select: none; }
.endpoint-header:hover { background: var(--hover-bg, rgba(0,0,0,.04)); }
.method-badge { font-size: .7rem; font-weight: 700; padding: .2rem .6rem; border-radius: 4px; min-width: 50px; text-align: center; letter-spacing: .03em; }
.method-get { background:#e8f5e9; color:#2e7d32; }
.method-post { background:#e3f2fd; color:#1565c0; }
.endpoint-path { font-family: monospace; font-size: .9rem; color: var(--text-primary); font-weight: 600; }
.endpoint-desc { font-size: .85rem; color: var(--text-secondary); margin-left: auto; }
.endpoint-body { padding: 0 1.25rem 1.25rem; border-top: 1px solid var(--border-color); }
.endpoint-body.hidden { display: none; }
.params-table { width: 100%; border-collapse: collapse; font-size: .85rem; margin: .75rem 0; }
.params-table th { text-align: left; padding: .4rem .6rem; background: var(--table-header-bg, rgba(0,0,0,.04)); font-weight: 600; }
.params-table td { padding: .4rem .6rem; border-top: 1px solid var(--border-color); vertical-align: top; }
.badge-required { background: #fce4ec; color: #c62828; font-size: .7rem; padding: .1rem .4rem; border-radius: 3px; }
.badge-optional { background: #f3e5f5; color: #6a1b9a; font-size: .7rem; padding: .1rem .4rem; border-radius: 3px; }
code { font-family: monospace; background: var(--code-bg, rgba(0,0,0,.06)); padding: .15rem .4rem; border-radius: 4px; font-size: .85rem; }
pre.code-block { background: #1e1e2e; color: #cdd6f4; padding: 1rem 1.25rem; border-radius: 8px; overflow-x: auto; font-size: .82rem; line-height: 1.6; margin: .75rem 0; }
.info-box { background: #e3f2fd; border-left: 4px solid #1976d2; padding: .75rem 1rem; border-radius: 0 6px 6px 0; font-size: .875rem; margin: .75rem 0; }
.warning-box { background: #fff8e1; border-left: 4px solid #f9a825; padding: .75rem 1rem; border-radius: 0 6px 6px 0; font-size: .875rem; margin: .75rem 0; }
.toc-list { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem; }
.toc-list li a { display: inline-block; padding: .3rem .8rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 20px; font-size: .82rem; text-decoration: none; color: var(--text-primary); transition: all .2s; }
.toc-list li a:hover { background: var(--primary-color, #6c47ff); color: #fff; border-color: transparent; }
.response-label { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); margin-top: 1rem; margin-bottom: .3rem; }
</style>

{{-- TOC --}}
<div class="docs-section">
    <ul class="toc-list">
        <li><a href="#autenticazione">Autenticazione</a></li>
        <li><a href="#endpoint-me">GET /me</a></li>
        <li><a href="#endpoint-transfers">GET /transfers</a></li>
        <li><a href="#endpoint-transfer-show">GET /transfers/{uuid}</a></li>
        <li><a href="#endpoint-transfer-store">POST /transfers</a></li>
        <li><a href="#errori">Errori</a></li>
        <li><a href="#esempi">Esempi</a></li>
        <li><a href="#limiti">Limiti di velocità</a></li>
    </ul>
</div>

{{-- OVERVIEW --}}
<div class="docs-section">
    <h2>Panoramica</h2>
    <p style="font-size:.9rem;color:var(--text-secondary);margin-bottom:.75rem;">
        L'API KMoney v1 è una REST API che restituisce JSON. Tutte le richieste devono essere effettuate
        tramite <strong>HTTPS</strong>. L'URL base è:
    </p>
    <pre class="code-block">{{ config('app.url') }}/api/v1</pre>

    <div class="info-box">
        I token API si creano dal portale nella sezione
        <strong><a href="{{ route('portal.api-tokens.index') }}">Token API</a></strong>.
        Ogni token ha abilità <code>read</code> e/o <code>write</code> e può avere una scadenza opzionale.
    </div>
</div>

{{-- AUTENTICAZIONE --}}
<div class="docs-section" id="autenticazione">
    <h2>Autenticazione</h2>
    <p style="font-size:.9rem;color:var(--text-secondary);margin-bottom:.75rem;">
        Ogni richiesta deve includere il token nell'header <code>Authorization</code>:
    </p>
    <pre class="code-block">Authorization: Bearer km_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</pre>

    <div class="warning-box">
        Il token viene mostrato <strong>una sola volta</strong> al momento della creazione.
        Conservalo in un luogo sicuro (es. variabile d'ambiente). Non è possibile recuperarlo in seguito.
    </div>

    <h3>Abilità del token</h3>
    <table class="params-table">
        <thead><tr><th>Abilità</th><th>Operazioni permesse</th></tr></thead>
        <tbody>
            <tr><td><code>read</code></td><td>GET /me, GET /transfers, GET /transfers/{uuid}</td></tr>
            <tr><td><code>write</code></td><td>Tutte le operazioni read + POST /transfers</td></tr>
        </tbody>
    </table>
</div>

{{-- GET /me --}}
<div class="docs-section" id="endpoint-me">
    <h2>Endpoint</h2>

    <div class="endpoint-card">
        <div class="endpoint-header" onclick="toggleBody(this)">
            <span class="method-badge method-get">GET</span>
            <span class="endpoint-path">/api/v1/me</span>
            <span class="endpoint-desc">Informazioni azienda e saldo</span>
        </div>
        <div class="endpoint-body hidden">
            <p style="font-size:.875rem;margin-top:.75rem;color:var(--text-secondary);">
                Restituisce i dati dell'azienda associata al token e il saldo del conto principale.
            </p>
            <p class="response-label">Risposta di esempio (200 OK)</p>
            <pre class="code-block">{
  "company": {
    "id": 12,
    "name": "Acme Srl",
    "slug": "acme-srl"
  },
  "account": {
    "id": 45,
    "account_number": "KY-00045",
    "currency": "KY",
    "balance": 15000,
    "available_balance": 14200,
    "status": "active"
  }
}</pre>
        </div>
    </div>

    {{-- GET /transfers --}}
    <div class="endpoint-card" id="endpoint-transfers">
        <div class="endpoint-header" onclick="toggleBody(this)">
            <span class="method-badge method-get">GET</span>
            <span class="endpoint-path">/api/v1/transfers</span>
            <span class="endpoint-desc">Lista trasferimenti (paginata)</span>
        </div>
        <div class="endpoint-body hidden">
            <p style="font-size:.875rem;margin-top:.75rem;color:var(--text-secondary);">
                Restituisce i trasferimenti <code>booked</code> del conto principale, ordinati per data
                decrescente, paginati a 50 per pagina.
            </p>
            <h3>Query string</h3>
            <table class="params-table">
                <thead><tr><th>Parametro</th><th>Tipo</th><th>Descrizione</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>page</code></td>
                        <td>integer</td>
                        <td>Numero di pagina (default: 1)</td>
                    </tr>
                </tbody>
            </table>
            <p class="response-label">Risposta di esempio (200 OK)</p>
            <pre class="code-block">{
  "data": [
    {
      "uuid": "a1b2c3d4-...",
      "amount": 500,
      "currency": "KY",
      "direction": "debit",
      "status": "booked",
      "kind": "api_payment",
      "description": "Fornitura servizi",
      "booked_at": "2026-05-27T10:30:00+02:00",
      "from": { "account_id": 45, "company": "Acme Srl" },
      "to":   { "account_id": 78, "company": "Beta Srl" }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "total": 112
  }
}</pre>
        </div>
    </div>

    {{-- GET /transfers/{uuid} --}}
    <div class="endpoint-card" id="endpoint-transfer-show">
        <div class="endpoint-header" onclick="toggleBody(this)">
            <span class="method-badge method-get">GET</span>
            <span class="endpoint-path">/api/v1/transfers/{uuid}</span>
            <span class="endpoint-desc">Dettaglio singolo trasferimento</span>
        </div>
        <div class="endpoint-body hidden">
            <p style="font-size:.875rem;margin-top:.75rem;color:var(--text-secondary);">
                Recupera un trasferimento tramite il suo UUID. Restituisce 404 se non appartiene al conto.
            </p>
            <h3>Path parameters</h3>
            <table class="params-table">
                <thead><tr><th>Parametro</th><th>Tipo</th><th>Note</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>uuid</code></td>
                        <td>string (UUID v4)</td>
                        <td>UUID del trasferimento</td>
                    </tr>
                </tbody>
            </table>
            <p class="response-label">Risposta di esempio (200 OK)</p>
            <pre class="code-block">{
  "data": {
    "uuid": "a1b2c3d4-e5f6-...",
    "amount": 500,
    "currency": "KY",
    "direction": "debit",
    "status": "booked",
    "kind": "api_payment",
    "description": "Fornitura servizi",
    "booked_at": "2026-05-27T10:30:00+02:00",
    "from": { "account_id": 45, "company": "Acme Srl" },
    "to":   { "account_id": 78, "company": "Beta Srl" }
  }
}</pre>
        </div>
    </div>

    {{-- POST /transfers --}}
    <div class="endpoint-card" id="endpoint-transfer-store">
        <div class="endpoint-header" onclick="toggleBody(this)">
            <span class="method-badge method-post">POST</span>
            <span class="endpoint-path">/api/v1/transfers</span>
            <span class="endpoint-desc">Esegui un pagamento <span style="font-size:.75rem;font-weight:400;">(richiede write)</span></span>
        </div>
        <div class="endpoint-body hidden">
            <p style="font-size:.875rem;margin-top:.75rem;color:var(--text-secondary);">
                Avvia un pagamento dal conto principale dell'azienda verso un altro conto.
                Richiede abilità <code>write</code> sul token.
            </p>
            <div class="warning-box">
                Rate limit: <strong>10 richieste per minuto</strong>. Superato il limite si riceve 429.
            </div>
            <h3>Body (JSON)</h3>
            <table class="params-table">
                <thead><tr><th>Campo</th><th>Tipo</th><th>Validazione</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>to_account_id</code></td>
                        <td>integer</td>
                        <td><span class="badge-required">richiesto</span> ID conto destinatario</td>
                    </tr>
                    <tr>
                        <td><code>amount</code></td>
                        <td>integer</td>
                        <td><span class="badge-required">richiesto</span> Importo in KY (minimo 1)</td>
                    </tr>
                    <tr>
                        <td><code>description</code></td>
                        <td>string</td>
                        <td><span class="badge-optional">opzionale</span> Causale (max 255 caratteri)</td>
                    </tr>
                </tbody>
            </table>
            <p class="response-label">Risposta (201 Created)</p>
            <pre class="code-block">{
  "data": {
    "uuid": "f7e8d9c0-...",
    "amount": 500,
    "currency": "KY",
    "direction": "debit",
    "status": "booked",
    "kind": "api_payment",
    ...
  }
}</pre>
        </div>
    </div>
</div>

{{-- ERRORI --}}
<div class="docs-section" id="errori">
    <h2>Gestione errori</h2>
    <p style="font-size:.9rem;color:var(--text-secondary);margin-bottom:.75rem;">
        Tutti gli errori restituiscono JSON con chiave <code>error</code> e l'HTTP status code appropriato.
    </p>
    <table class="params-table">
        <thead><tr><th>Codice</th><th>Significato</th><th>Causa tipica</th></tr></thead>
        <tbody>
            <tr><td>200</td><td>OK</td><td>Successo</td></tr>
            <tr><td>201</td><td>Created</td><td>Risorsa creata (trasferimento)</td></tr>
            <tr><td>401</td><td>Unauthorized</td><td>Token mancante, non valido o scaduto</td></tr>
            <tr><td>403</td><td>Forbidden</td><td>Abilità <code>write</code> assente sul token</td></tr>
            <tr><td>404</td><td>Not Found</td><td>UUID non trovato o non appartiene al conto</td></tr>
            <tr><td>422</td><td>Unprocessable</td><td>Validazione fallita, saldo insufficiente</td></tr>
            <tr><td>429</td><td>Too Many Requests</td><td>Rate limit superato</td></tr>
        </tbody>
    </table>
    <pre class="code-block">{ "error": "Insufficient balance" }</pre>
</div>

{{-- ESEMPI --}}
<div class="docs-section" id="esempi">
    <h2>Esempi di utilizzo</h2>

    <h3>cURL</h3>
    <pre class="code-block"># Saldo e info azienda
curl -H "Authorization: Bearer km_your_token" \
     {{ config('app.url') }}/api/v1/me

# Lista trasferimenti
curl -H "Authorization: Bearer km_your_token" \
     "{{ config('app.url') }}/api/v1/transfers?page=1"

# Esegui un pagamento
curl -X POST \
     -H "Authorization: Bearer km_your_token" \
     -H "Content-Type: application/json" \
     -d '{"to_account_id": 78, "amount": 500, "description": "Fornitura"}' \
     {{ config('app.url') }}/api/v1/transfers</pre>

    <h3>PHP (con Guzzle)</h3>
    <pre class="code-block">use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => '{{ config('app.url') }}/api/v1/',
    'headers'  => [
        'Authorization' => 'Bearer km_your_token',
        'Accept'        => 'application/json',
    ],
]);

// Saldo
$me = $client->get('me')->getBody();
$data = json_decode($me, true);
echo $data['account']['balance']; // es. 15000

// Pagamento
$response = $client->post('transfers', [
    'json' => [
        'to_account_id' => 78,
        'amount'        => 500,
        'description'   => 'Fornitura servizi',
    ],
]);
$transfer = json_decode($response->getBody(), true)['data'];
echo $transfer['uuid'];</pre>

    <h3>JavaScript (fetch)</h3>
    <pre class="code-block">const BASE = '{{ config('app.url') }}/api/v1';
const TOKEN = 'km_your_token';

const headers = {
    'Authorization': `Bearer ${TOKEN}`,
    'Content-Type': 'application/json',
};

// Saldo
const res = await fetch(`${BASE}/me`, { headers });
const { account } = await res.json();
console.log(account.balance);

// Pagamento
const pay = await fetch(`${BASE}/transfers`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ to_account_id: 78, amount: 500 }),
});
const { data } = await pay.json();
console.log(data.uuid);</pre>
</div>

{{-- RATE LIMITS --}}
<div class="docs-section" id="limiti">
    <h2>Limiti di velocità (Rate limiting)</h2>
    <table class="params-table">
        <thead><tr><th>Endpoint</th><th>Limite</th></tr></thead>
        <tbody>
            <tr><td>GET /api/v1/me, GET /api/v1/transfers*</td><td>Nessun limite specifico</td></tr>
            <tr><td>POST /api/v1/transfers</td><td>10 richieste / minuto per token</td></tr>
        </tbody>
    </table>
    <p style="font-size:.85rem;color:var(--text-secondary);">
        Superato il limite si riceve HTTP 429 con header <code>Retry-After</code>.
    </p>
</div>

{{-- LINK TOKEN --}}
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:10px;padding:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div>
        <strong>Gestisci i tuoi token API</strong>
        <p style="font-size:.85rem;color:var(--text-secondary);margin:.2rem 0 0;">Crea, visualizza o revoca i token dalla sezione Token API del portale.</p>
    </div>
    <a href="{{ route('portal.api-tokens.index') }}" class="btn btn-primary">Token API</a>
</div>

<script>
function toggleBody(header) {
    const body = header.nextElementSibling;
    body.classList.toggle('hidden');
}
// Apri automaticamente il primo endpoint
document.addEventListener('DOMContentLoaded', () => {
    const first = document.querySelector('.endpoint-header');
    if (first) first.click();
});
</script>
@endsection
