# Integrazione pagamenti KMoney per WooCommerce

Guida per il programmatore incaricato di installare/adattare il plugin di pagamento
KMoney su WooCommerce. Il plugin allegato (`kmoney-woocommerce-plugin.zip`, cartella
`kmoney-payment/`, **versione 2.1.0**) è completo e pronto all'uso — non c'è nulla da costruire
da zero, solo da installare, configurare e testare secondo la checklist alla fine di questo
documento. Il sorgente completo è anche nell'appendice in fondo e, nel repo kmoney-app, in
`integrations/woocommerce/kmoney-payment/`.

**Questo plugin sostituisce il vecchio plugin "KMoney"** (quello allegato inizialmente,
`kmoney_v1.4.5.zip`). Il vecchio plugin va disattivato — i due non possono coesistere (vedi
sezione 7).

---

## 1. Perché è stato riscritto da zero

Il vecchio plugin faceva scrivere email e password KMoney del cliente in un campo del checkout
WooCommerce e le inoltrava a un'API esterna diversa (`kosmomoney.com`, non l'attuale
`kmoney-app`). Questo bypassa 2FA/passkey e fa transitare credenziali di pagamento reali su un
sito terzo — un problema di sicurezza serio, non solo di manutenibilità. Il nuovo plugin non
raccoglie mai credenziali KMoney: il cliente si autentica solo sul dominio KMoney.

## 2. Cosa fa (v2.1.0)

- **Metodo di pagamento "KMoney" al checkout**, come PayPal/Stripe. Il cliente che ha un conto
  KMoney lo sceglie e paga la quota KY sulla pagina hosted KMoney (2FA/passkey); chi non ce l'ha
  trova il link **"Registrati su KMoney"** (checkout e pagina prodotto) oppure paga tutto in
  euro con gli altri metodi.
- **Percentuale KY decisa dal venditore**: 0/25/50/75/100, impostabile come % unica per tutto il
  negozio, per categoria di prodotto o per singolo prodotto. Priorità: **prodotto → categoria
  (vince la % più alta se il prodotto è in più categorie) → % globale del negozio**. Spedizione
  e costi extra seguono la % globale.
- **Regola del circuito — conto in negativo**: se il conto KMoney del venditore è sottozero,
  **tutti i prodotti sono venduti al 100% in KY** e le percentuali non sono né selezionabili né
  modificabili (i campi in admin sono bloccati con un avviso e gli eventuali salvataggi vengono
  ignorati) finché il saldo non torna positivo. Lo stato del conto viene letto da
  `GET /api/v1/balance` (campo `is_in_debit`) con cache di 5 minuti.
- **Pagamento misto** (quota KY < 100%): il cliente paga prima la quota KY su KMoney; al ritorno
  la quota pagata compare sull'ordine come riga "Pagato con KMoney (X KY)", il totale scende al
  saldo in euro e il cliente lo paga subito con qualunque altro gateway (pulsante nella pagina
  di ringraziamento + email automatica con il link "paga ordine", dove KMoney è escluso).
- **Badge pagina prodotto**: "✓ Pagabile al X% in KMoney" (dorato "★" al 100%) con link
  "Registrati su KMoney". Disattivabile dalle impostazioni.
- **Configurazione guidata**: la pagina impostazioni mostra in tempo reale lo stato della
  connessione API (conto, saldo) e l'avviso "conto in negativo → 100% forzato".

## 3. Sequenza del pagamento

```
Cliente              WooCommerce (questo plugin)          API KMoney / pagina hosted KMoney
   |                        |                                      |
   | checkout, sceglie      |                                      |
   | KMoney, conferma       |                                      |
   |----------------------->|                                      |
   |                        | ordine creato, stato "in attesa"      |
   |                        | calcola quota KY (% per prodotto)     |
   |                        | POST /api/v1/payment-requests  ------>|   (solo quota KY)
   |                        |<----- { uuid, token, pay_url } -------|
   |  redirect a pay_url                                            |
   |<-----------------------|                                      |
   |  login KMoney (2FA/passkey), conferma importo                  |
   |---------------------------------------------------------------->|
   |                        |  webhook: payment_request.paid  <-----|  (autorevole, asincrono)
   |                        |  ?wc-api=wc_gateway_kmoney             |
   |  redirect di ritorno con kmoney_pr_uuid                        |
   |<----------------------------------------------------------------|
   |  pagina "grazie per l'ordine"                                   |
   |                        | GET /api/v1/payment-requests/{uuid}    |
   |                        |   (mai fidarsi del solo redirect) ---->|
   |                        |<---------------- { status: paid } -----|
   |                        | quota KY registrata                    |
   |   se quota KY = 100%: ordine completato                         |
   |   se ordine misto: totale ridotto al saldo €, pulsante          |
   |   "Paga il saldo ora" + email col link → paga con Stripe/ecc.   |
   |<-----------------------|                                      |
```

Webhook e verifica-al-ritorno sono entrambi implementati e idempotenti
(`Kmoney_Order_Finalizer::mark_paid()` è protetto dal meta `_kmoney_ky_paid` e da
`$order->is_paid()`): qualunque dei due arrivi per primo registra la quota KY, l'altro non fa
nulla.

## 4. Riferimento API (KMoney API v1)

Base URL: `https://<dominio-kmoney>/api/v1`. Autenticazione: header `Authorization: Bearer
km_xxxxxxxxxxxx` (token generato dal negoziante sul portale KMoney, sezione `/api-tokens`,
**ability `write` obbligatoria**). Rate limit: 60 richieste/minuto generali, 10/minuto su
`POST /payment-requests`.

### 4.1 `POST /payment-requests`

```json
// Richiesta
{
  "amount": 4999,
  "description": "Ordine #123",
  "external_reference": "123",
  "return_url": "https://tuosito.com/checkout/order-received/123/?key=wc_order_...",
  "cancel_url": "https://tuosito.com/carrello/",
  "expires_in_minutes": 30
}
```

`amount` è sempre intero, centesimi di KY — dalla v2.1.0 è la **quota KY** dell'ordine, non
necessariamente il totale. `external_reference` è l'ID numerico dell'ordine WooCommerce
(`$order->get_id()`) — se esiste già una richiesta `pending` con lo stesso
`external_reference` + `amount`, l'API restituisce quella invece di crearne una nuova (sicuro
richiamarla di nuovo in caso di retry/timeout del checkout).

```json
// Risposta 201 (o 200 se già esistente per idempotenza)
{
  "data": {
    "uuid": "b7e1e6b0-....",
    "status": "pending",
    "amount": 4999,
    "currency": "KY",
    "external_reference": "123",
    "pay_url": "https://kmoney.example.com/pay/AbCdEf...",
    "expires_at": "2026-07-02T15:30:00+00:00"
  }
}
```

Reindirizzare il browser del cliente a `data.pay_url` (già fatto dal plugin — WooCommerce
gestisce il redirect esterno tramite il valore di ritorno di `process_payment()`).

### 4.2 `GET /payment-requests/{uuid}`

Stessa struttura, `status` in `pending|paid|expired|cancelled`. Usato per la verifica
server-side al ritorno del cliente — mai fidarsi dei soli parametri nell'URL di ritorno.

### 4.3 `GET /balance`

Saldo dettagliato del conto principale del negoziante. Campi usati dal plugin:
`is_in_debit` (bool — conto sottozero → 100% forzato), `balance`, `account_number`,
`allowed_ky_percentages`. Il plugin lo interroga con cache di 5 minuti (transient
`kmoney_merchant_status`) e conserva l'ultimo dato buono come fallback se l'API è
temporaneamente irraggiungibile — la regola del debito non "salta" per un timeout di rete.

## 5. Webhook `payment_request.paid`

Sul portale KMoney (login negoziante): **Impostazioni → Webhook → Nuovo webhook**

- URL: mostrato direttamente nella pagina di configurazione del plugin in WooCommerce
  (`WooCommerce → Impostazioni → Pagamenti → KMoney`), ha la forma
  `https://tuosito.com/?wc-api=wc_gateway_kmoney`
- Evento: `payment_request.paid`
- Copiare il **secret** mostrato una sola volta e incollarlo nel campo "Secret webhook" della
  configurazione del plugin.

Payload e verifica firma HMAC sono già implementati in
`includes/class-wc-gateway-kmoney.php::handle_webhook()`:

```php
$expected_signature = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
$is_valid = hash_equals( $expected_signature, $_SERVER['HTTP_X_KMONEY_SIGNATURE'] );
```

Risposte non-2xx fanno ritentare kmoney-app (fino a 3 volte) — normale solo in caso di errore
reale, non il percorso standard.

## 6. Installazione e configurazione

1. **Disattivare ed eliminare il vecchio plugin "KMoney"** (cartella `kmoney/`) da Plugin →
   Plugin installati. Il nuovo plugin registra lo stesso metodo di pagamento (`kmoney`); se
   entrambi restano attivi, WooCommerce mostrerà comunque un avviso di conflitto in bacheca.
2. Caricare la cartella `kmoney-payment/` in `wp-content/plugins/`.
3. Attivare "KMoney Payment Gateway" da Plugin.
4. Configurare in **WooCommerce → Impostazioni → Pagamenti → KMoney**:
   - Abilita
   - Titolo / Descrizione / Istruzioni (testo libero mostrato al cliente)
   - URL base API KMoney (es. `https://kmoney.tuodominio.com/api/v1`)
   - Token API KMoney (da `/api-tokens` sul portale, ability `write`)
   - Secret webhook (da Sezione 5)
   - **% KMoney predefinita** (0/25/50/75/100 — vale per tutto il negozio, spedizione inclusa)
   - URL registrazione KMoney (vuoto = ricavato dall'URL base API: `https://dominio/register`)
   - Badge pagina prodotto (on/off)

   In cima alla pagina compare lo **stato della connessione**: conto, saldo e l'eventuale
   avviso "conto in negativo → 100% forzato". Se c'è un errore di URL/token si vede subito qui.
5. Registrare il webhook sul portale KMoney come da Sezione 5.
6. Percentuali più fini (facoltative, solo con conto in positivo):
   - **Per categoria**: Prodotti → Categorie → modifica categoria → "% pagabile in KMoney".
     Se un prodotto è in più categorie con % diverse vale la **più alta**.
   - **Per prodotto**: modifica prodotto → Dati prodotto → Generale → "% pagabile in KMoney".
     Ha la precedenza su categoria e % globale. Le variazioni ereditano dal prodotto padre.
   - "Predefinita" = eredita dal livello superiore. `0%` esplicito = prodotto/categoria non
     pagabile in KY (il badge non compare; se tutto il carrello è a 0%, KMoney non appare al
     checkout).

Nessuna migrazione di database, nessuna dipendenza aggiuntiva oltre WooCommerce attivo. Il
plugin dichiara compatibilità HPOS (High-Performance Order Storage) di WooCommerce.

## 7. Cosa NON è incluso

- **Rimborsi non automatizzati.** L'API KMoney non ha ancora un endpoint pubblico di rimborso;
  vanno fatti manualmente dal portale KMoney lato negoziante. Attenzione agli ordini misti: se
  il cliente paga la quota KY ma non salda mai la parte in euro, l'ordine resta "In attesa di
  pagamento" con la quota KY già incassata — il negoziante decide se sollecitare (rimandare
  l'email col link di pagamento) o annullare e rimborsare la quota KY dal portale.
- **Cambio valuta**: la conversione importo assume 1 KY ≈ 1 unità di valuta del negozio
  (tipico per circuiti a valuta locale come questo) — verificare con il titolare del conto
  KMoney prima di andare live se non è così.
- **Sconti/coupon WooCommerce**: le % si applicano ai totali di riga già scontati; un eventuale
  sconto a livello di carrello non modellato per riga viene assorbito dal clamp (la quota KY
  non supera mai il totale ordine).

## 8. Checklist di test end-to-end

1. Plugin vecchio disattivato, nuovo plugin attivo e configurato, webhook registrato sul
   portale KMoney. La pagina impostazioni mostra "Connessione API KMoney: OK".
2. Ordine di prova **tutto al 100%** con metodo "KMoney" → si atterra su una pagina con dominio
   KMoney (non WooCommerce); login con account KMoney di test con saldo sufficiente, conferma;
   redirect di ritorno sulla pagina "grazie per l'ordine"; ordine "In lavorazione"/"Completato"
   con nota "KMoney: pagamento confermato" nella cronologia.
3. **Ordine misto**: imposta % globale 50, fai un ordine da 100€ → al checkout il box KMoney
   dice "Pagherai ora 50,00 KY su KMoney; il saldo di 50,00 € si paga subito dopo con un altro
   metodo". Dopo la conferma su KMoney: pagina di ringraziamento con "Parte KMoney pagata ✔" e
   pulsante "Paga il saldo di 50,00 € ora"; l'ordine ha la riga "Pagato con KMoney (50,00 KY)",
   totale 50€, stato "In attesa di pagamento"; arriva l'email con il link. Sulla pagina "paga
   ordine" KMoney NON è tra i metodi; paga con un altro metodo → ordine completato con nota
   "saldo in euro ricevuto".
4. **Percentuali**: prodotto con % propria batte la categoria; prodotto in due categorie con %
   diverse prende la più alta; prodotto a 0% non mostra il badge e non contribuisce alla quota
   KY; carrello tutto a 0% → KMoney non compare al checkout.
5. **Conto in negativo**: porta il conto di test sottozero → entro 5 minuti (o svuotando il
   transient `kmoney_merchant_status`) il badge prodotto mostra 100%, il checkout chiede tutto
   in KY, e in admin i campi % (impostazioni, prodotto, categoria) sono sostituiti dall'avviso
   di blocco. Un salvataggio forzato del campo % non deve avere effetto.
6. **Badge e registrazione**: pagina prodotto con % 25/50/75 → badge "✓ Pagabile al X% in
   KMoney" + link "Registrati su KMoney" (apre il portale in nuova scheda); al 100% il badge è
   dorato con "★".
7. Ripetere il test 2 chiudendo la scheda subito dopo la conferma su KMoney, prima del
   redirect: l'ordine deve comunque avanzare entro pochi secondi tramite il solo webhook —
   controllare il log consegne webhook sul portale KMoney (Impostazioni → Webhook → il tuo
   webhook → consegne) per un `200` da WooCommerce.
8. Provare un pagamento con saldo insufficiente: KMoney blocca il pagamento sulla propria
   pagina, l'ordine WooCommerce deve restare "In attesa" e non fatturato.
9. Manomettere la firma del webhook (curl con `X-KMoney-Signature` sbagliata): il plugin deve
   rispondere `401` e non completare l'ordine.
10. Far scadere una richiesta di pagamento (creare l'ordine, aspettare oltre
    `expires_in_minutes` senza pagare): KMoney mostra "scaduto", l'ordine resta non pagato.

## 9. Struttura file

```
kmoney-payment/
├── kmoney-payment.php                       (bootstrap, hook attivazione, HPOS)
├── readme.txt
└── includes/
    ├── class-wc-gateway-kmoney.php          (gateway: process_payment, webhook, verifica ritorno,
    │                                         pannello stato admin, esclusione su "paga ordine")
    ├── class-kmoney-api-client.php          (client HTTP: payment-requests + balance)
    ├── class-kmoney-merchant-status.php     (cache stato conto: is_in_debit, 100% forzato)
    ├── class-kmoney-percentages.php         (risoluzione % prodotto/categoria/globale, split KY/€)
    ├── class-kmoney-order-finalizer.php     (completamento ordine idempotente, gestione saldo €)
    ├── class-kmoney-product-settings.php    (campi % su prodotto e categoria in admin)
    └── class-kmoney-frontend.php            (badge pagina prodotto, link "Registrati su KMoney")
```

Meta ordine usati: `_kmoney_pr_uuid`, `_kmoney_pr_token`, `_kmoney_ky_amount_cents`,
`_kmoney_residual_eur`, `_kmoney_ky_paid`, `_kmoney_transfer_uuid`,
`_kmoney_balance_invoice_sent`, `_kmoney_balance_noted`.
Meta prodotto: `_kmoney_ky_percentage`. Meta categoria (term): `kmoney_ky_percentage`.

---

## Appendice — Codice sorgente completo

Incluso anche, pronto all'uso, in `kmoney-woocommerce-plugin.zip` e in
`integrations/woocommerce/kmoney-payment/` nel repo kmoney-app.

### `kmoney-payment/kmoney-payment.php`

```php
<?php
/**
 * Plugin Name: KMoney Payment Gateway
 * Description: Accetta pagamenti KMoney (KY) su WooCommerce tramite checkout hosted sicuro: il cliente viene reindirizzato su KMoney per autenticarsi (2FA/passkey) e confermare l'importo. Supporta pagamento misto KY + euro con percentuale configurabile per negozio, categoria o singolo prodotto. Nessuna credenziale KMoney del cliente viene mai raccolta o gestita da questo sito.
 * Version: 2.1.0
 * Requires Plugins: woocommerce
 * Author: KMoney
 * Text Domain: kmoney-payment
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KMONEY_PAYMENT_VERSION', '2.1.0' );
define( 'KMONEY_PAYMENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Prezzo in valuta negozio come testo semplice ("50,00 €"), senza markup né
 * entità HTML — adatto dentro esc_html()/note ordine/email.
 *
 * @param float $amount
 * @return string
 */
function kmoney_price_text( $amount ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Avvisa se il vecchio plugin "KMoney" (kmoney/kmoney.php) è ancora attivo:
 * registra lo stesso id gateway ('kmoney') e può creare conflitti di
 * configurazione. Va disattivato prima di usare questo plugin.
 */
add_action( 'admin_init', 'kmoney_payment_check_legacy_plugin' );
function kmoney_payment_check_legacy_plugin() {
	$active_plugins = (array) get_option( 'active_plugins', array() );
	if ( in_array( 'kmoney/kmoney.php', $active_plugins, true ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'Disattiva il vecchio plugin "KMoney" (kmoney/kmoney.php) prima di usare "KMoney Payment Gateway": entrambi registrano lo stesso metodo di pagamento e possono confliggere.', 'kmoney-payment' ) .
					'</p></div>';
			}
		);
	}
}

add_action( 'plugins_loaded', 'kmoney_payment_init', 11 );
function kmoney_payment_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'KMoney Payment Gateway richiede WooCommerce attivo.', 'kmoney-payment' ) .
					'</p></div>';
			}
		);
		return;
	}

	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-api-client.php';
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-merchant-status.php';
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-percentages.php';
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-order-finalizer.php';
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-product-settings.php';
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-frontend.php';
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-wc-gateway-kmoney.php';

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = 'WC_Gateway_Kmoney';
			return $gateways;
		}
	);

	Kmoney_Product_Settings::init();
	Kmoney_Frontend::init();
}

/**
 * Dichiara compatibilità High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);
```

### `kmoney-payment/readme.txt`

```text
=== KMoney Payment Gateway ===
Contributors: kmoney
Tags: woocommerce, payment gateway, kmoney
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 2.1.0
License: GPLv2 or later

Accetta pagamenti in KY (KMoney) su WooCommerce tramite checkout hosted sicuro: redirect + webhook,
percentuale KY per negozio/categoria/prodotto con pagamento misto KY + euro, nessuna credenziale
KMoney del cliente gestita da questo sito.

== Description ==

Questo plugin sostituisce il vecchio plugin "KMoney" (che raccoglieva email e password KMoney del
cliente direttamente nel checkout WooCommerce e le inviava a un'API legacy separata su
kosmomoney.com). Questo modulo non fa mai nulla del genere:

1. Alla conferma ordine, crea una richiesta di pagamento sull'API ufficiale KMoney (kmoney-app),
   server-to-server, con il token del negoziante — per la sola quota KY dell'ordine, calcolata
   dalla % impostata dal negoziante (globale, per categoria o per prodotto).
2. Reindirizza il cliente sulla pagina di pagamento ospitata da KMoney, dove si autentica con le
   proprie credenziali (2FA / passkey inclusi) e conferma l'importo.
3. Riceve conferma del pagamento in due modi indipendenti: un webhook autorevole
   (payment_request.paid) e una verifica server-side quando il cliente torna sul sito. Nessuno dei
   due si fida dei soli parametri nell'URL di ritorno.
4. Sugli ordini misti (quota KY < 100%) il totale scende al saldo in euro e il cliente lo paga
   subito dopo con qualunque altro metodo (pulsante in pagina di ringraziamento + email con link).

Regola del circuito: se il conto KMoney del negoziante è in negativo, tutto viene venduto al 100%
in KY e le percentuali non sono modificabili finché il saldo non torna positivo. Sulla pagina
prodotto viene mostrato il badge "Pagabile al X% in KMoney" con il link "Registrati su KMoney".

Vedi KMONEY_WOOCOMMERCE_INTEGRATION.md per la guida completa (installazione, configurazione,
riferimento API, checklist di test).

== Installation ==

1. Disattiva ed elimina il vecchio plugin "KMoney" (cartella kmoney/), se presente.
2. Carica la cartella kmoney-payment/ in wp-content/plugins/.
3. Attiva "KMoney Payment Gateway" da Plugin.
4. Configura in WooCommerce > Impostazioni > Pagamenti > KMoney: la pagina mostra subito lo stato
   della connessione API e del conto (incluso l'avviso "conto in negativo → 100% forzato").
5. Registra il webhook sul portale KMoney (Impostazioni > Webhook): URL e secret sono mostrati
   nella pagina di configurazione del plugin.
6. (Facoltativo) Imposta la % KMoney per categoria (Prodotti > Categorie) o per singolo prodotto
   (Dati prodotto > Generale).

== Changelog ==

= 2.1.0 =
* Percentuale KY configurabile: globale, per categoria (vince la più alta) o per singolo prodotto.
* Pagamento misto: quota KY su KMoney, saldo in euro con qualunque altro gateway sullo stesso ordine.
* Regola conto in negativo: 100% KY forzato ovunque, percentuali bloccate anche in admin.
* Badge "Pagabile al X% in KMoney" sulla pagina prodotto + link "Registrati su KMoney" (anche al checkout).
* Pannello di stato conto/connessione nella pagina impostazioni; spedizione e costi extra seguono la % globale.

= 2.0.0 =
* Riscrittura completa: checkout hosted via redirect + webhook, nessuna credenziale cliente gestita
  dal sito. Sostituisce il vecchio flusso che chiedeva email/password KMoney nel checkout.
```

### `kmoney-payment/includes/class-wc-gateway-kmoney.php`

```php
<?php
/**
 * KMoney payment gateway for WooCommerce.
 *
 * This gateway never collects the customer's KMoney credentials. It:
 *   1. Creates a hosted payment request on KMoney (server-to-server, using the
 *      merchant's own Bearer token) when the customer places the order — for
 *      the KY share of the order only (the percentage is decided by the
 *      merchant globally, per category or per product; forced to 100% when
 *      the merchant's account is below zero).
 *   2. Redirects the customer's browser to KMoney's own hosted checkout page
 *      (pay_url), where they log in with their own KMoney credentials
 *      (2FA/passkey supported) and confirm the amount.
 *   3. Confirms the payment through two independent, idempotent paths: a
 *      webhook (authoritative, asynchronous — see handle_webhook()) and a
 *      server-side status check when the customer returns to the store (see
 *      maybe_verify_return()). Neither trusts the browser redirect query
 *      string on its own.
 *   4. On mixed orders (KY < 100%), once the KY share is confirmed the order
 *      total drops to the EUR residual and the customer pays the rest with
 *      any other gateway (thank-you page button + invoice email link).
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_Kmoney extends WC_Payment_Gateway {

	public $instructions;

	public function __construct() {
		$this->id                 = 'kmoney';
		$this->icon               = apply_filters( 'woocommerce_kmoney_icon', '' );
		$this->has_fields         = true;
		$this->method_title       = __( 'KMoney', 'kmoney-payment' );
		$this->method_description = __( 'Accetta pagamenti in KY tramite il circuito KMoney, anche in modalità mista KY + euro con percentuale per negozio, categoria o prodotto. Il cliente viene reindirizzato su KMoney per autenticarsi e confermare: nessun dato KMoney transita su questo sito.', 'kmoney-payment' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->instructions = $this->get_option( 'instructions' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'maybe_verify_return' ), 5 );

		// Sulla pagina "paga ordine" del saldo in euro, KMoney va escluso.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'exclude_self_on_balance_payment' ) );

		// Nota in cronologia quando arriva anche il saldo in euro.
		add_action( 'woocommerce_payment_complete', array( $this, 'note_balance_received' ), 20 );

		// Endpoint webhook: https://tuosito.com/?wc-api=wc_gateway_kmoney
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_webhook' ) );
	}

	public function init_form_fields() {
		$percent_options = array();
		foreach ( Kmoney_Percentages::ALLOWED as $pct ) {
			$percent_options[ (string) $pct ] = $pct . '%';
		}

		$this->form_fields = array(
			'enabled'               => array(
				'title'   => __( 'Abilita/Disabilita', 'kmoney-payment' ),
				'label'   => __( 'Abilita KMoney', 'kmoney-payment' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'                 => array(
				'title'       => __( 'Titolo', 'kmoney-payment' ),
				'type'        => 'text',
				'description' => __( 'Il nome del metodo di pagamento mostrato al cliente al checkout.', 'kmoney-payment' ),
				'default'     => __( 'KMoney', 'kmoney-payment' ),
				'desc_tip'    => true,
			),
			'description'           => array(
				'title'       => __( 'Descrizione', 'kmoney-payment' ),
				'type'        => 'textarea',
				'description' => __( 'Descrizione mostrata al checkout, sotto il nome del metodo. Il dettaglio KY/euro viene aggiunto automaticamente.', 'kmoney-payment' ),
				'default'     => __( 'Paga con il tuo conto KMoney. Verrai reindirizzato su KMoney per confermare il pagamento in sicurezza.', 'kmoney-payment' ),
			),
			'instructions'          => array(
				'title'       => __( 'Istruzioni', 'kmoney-payment' ),
				'type'        => 'textarea',
				'description' => __( 'Mostrate nella pagina di ringraziamento e nell\'email dell\'ordine.', 'kmoney-payment' ),
				'default'     => __( 'Grazie per aver pagato con KMoney.', 'kmoney-payment' ),
			),
			'api_base_url'          => array(
				'title'       => __( 'URL base API KMoney', 'kmoney-payment' ),
				'type'        => 'text',
				'description' => __( 'Es. https://kmoney.tuodominio.com/api/v1 (senza slash finale).', 'kmoney-payment' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'api_token'             => array(
				'title'       => __( 'Token API KMoney', 'kmoney-payment' ),
				'type'        => 'password',
				'description' => __( 'Token generato dal portale KMoney in /api-tokens, con ability "write" abilitata.', 'kmoney-payment' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'webhook_secret'        => array(
				'title'       => __( 'Secret webhook', 'kmoney-payment' ),
				'type'        => 'password',
				/* translators: %s: webhook callback URL */
				'description' => sprintf(
					__( 'Il "secret" mostrato una sola volta alla creazione del webhook sul portale KMoney (Impostazioni &gt; Webhook, evento payment_request.paid). URL webhook da registrare: %s', 'kmoney-payment' ),
					'<code>' . esc_html( home_url( '/?wc-api=' . strtolower( get_class( $this ) ) ) ) . '</code>'
				),
				'default'     => '',
			),
			'default_ky_percentage' => array(
				'title'       => __( '% KMoney predefinita', 'kmoney-payment' ),
				'type'        => 'select',
				'description' => __( 'Quota dell\'ordine pagabile in KY quando prodotto e categoria non hanno una % propria. Si applica anche a spedizione e costi extra. Se il conto KMoney è in negativo questa impostazione è ignorata: tutto viene venduto al 100% in KY.', 'kmoney-payment' ),
				'default'     => '100',
				'options'     => $percent_options,
			),
			'registration_url'      => array(
				'title'       => __( 'URL registrazione KMoney', 'kmoney-payment' ),
				'type'        => 'text',
				'description' => __( 'Link "Registrati su KMoney" mostrato ai clienti (pagina prodotto e checkout). Se vuoto viene ricavato dall\'URL base API (es. https://kmoney.tuodominio.com/register).', 'kmoney-payment' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'show_product_badge'    => array(
				'title'   => __( 'Badge pagina prodotto', 'kmoney-payment' ),
				'label'   => __( 'Mostra "Pagabile al X% in KMoney" sulla pagina prodotto', 'kmoney-payment' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		);
	}

	/**
	 * Pagina impostazioni: pannello di stato del conto KMoney sopra i campi.
	 * Configurazione "guidata": si vede subito se URL/token funzionano e se il
	 * conto è in debito (→ 100% forzato).
	 */
	public function admin_options() {
		$status = Kmoney_Merchant_Status::get( true );

		echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
		echo '<p>' . esc_html( $this->get_method_description() ) . '</p>';

		if ( ! $this->get_option( 'api_base_url' ) || ! $this->get_option( 'api_token' ) ) {
			echo '<div class="notice notice-info inline"><p>' .
				esc_html__( 'Per iniziare: inserisci URL base API e token (dal portale KMoney, sezione /api-tokens, ability "write"), salva, poi registra il webhook come indicato nel campo "Secret webhook".', 'kmoney-payment' ) .
				'</p></div>';
		} elseif ( ! empty( $status['ok'] ) ) {
			$balance_html = Kmoney_Percentages::format_ky( isset( $status['balance'] ) ? $status['balance'] : 0 ) . ' KY';
			echo '<div class="notice notice-success inline"><p><strong>' .
				esc_html__( 'Connessione API KMoney: OK', 'kmoney-payment' ) . '</strong> — ' .
				esc_html( sprintf(
					/* translators: 1: account number, 2: formatted balance */
					__( 'Conto %1$s, saldo %2$s.', 'kmoney-payment' ),
					isset( $status['account_number'] ) ? $status['account_number'] : 'n/d',
					$balance_html
				) ) .
				'</p></div>';

			if ( ! empty( $status['is_in_debit'] ) ) {
				echo '<div class="notice notice-warning inline"><p><strong>' .
					esc_html__( 'Conto in negativo:', 'kmoney-payment' ) . '</strong> ' .
					esc_html__( 'tutti i prodotti sono venduti al 100% in KMoney. La "% KMoney predefinita" e le % di categorie e prodotti sono ignorate (e non modificabili) finché il saldo non torna positivo.', 'kmoney-payment' ) .
					'</p></div>';
			}
		} else {
			echo '<div class="notice notice-error inline"><p><strong>' .
				esc_html__( 'Connessione API KMoney non riuscita:', 'kmoney-payment' ) . '</strong> ' .
				esc_html( isset( $status['error'] ) ? $status['error'] : '' ) .
				'</p></div>';
		}

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	public function process_admin_options() {
		$saved = parent::process_admin_options();
		Kmoney_Merchant_Status::flush_cache();
		return $saved;
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! $this->get_option( 'api_base_url' ) || ! $this->get_option( 'api_token' ) ) {
			return false;
		}

		// Al checkout: disponibile solo se almeno una parte dell'ordine è
		// pagabile in KY (tutte le % a 0 → il metodo non appare). Sulla pagina
		// "paga ordine" (retry) il carrello è vuoto: lì il controllo non si
		// applica (l'esclusione a saldo KY già pagato è gestita altrove).
		if ( function_exists( 'is_checkout' ) && is_checkout()
			&& ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() )
			&& function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$split = Kmoney_Percentages::split_for_cart( WC()->cart );
			if ( $split['ky_cents'] <= 0 ) {
				return false;
			}
		}

		return parent::is_available();
	}

	/**
	 * Box del metodo al checkout: descrizione + dettaglio KY/euro calcolato
	 * sul carrello + link di registrazione per chi non ha un conto.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		$split = null;
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			// Pagina "paga ordine" (retry): calcola dallo specifico ordine.
			global $wp;
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( $order ) {
				$split = Kmoney_Percentages::split_for_order( $order );
			}
		} elseif ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$split = Kmoney_Percentages::split_for_cart( WC()->cart );
		}

		if ( is_array( $split ) ) {
			if ( $split['ky_cents'] > 0 ) {
				echo '<p class="kmoney-checkout-breakdown"><strong>';
				if ( $split['mixed'] ) {
					echo esc_html( sprintf(
						/* translators: 1: KY amount, 2: EUR residual */
						__( 'Pagherai ora %1$s KY su KMoney; il saldo di %2$s si paga subito dopo con un altro metodo.', 'kmoney-payment' ),
						Kmoney_Percentages::format_ky( $split['ky_cents'] ),
						kmoney_price_text( $split['eur_residual'] )
					) );
				} else {
					echo esc_html( sprintf(
						/* translators: %s: KY amount */
						__( 'Pagherai %s KY sul circuito KMoney.', 'kmoney-payment' ),
						Kmoney_Percentages::format_ky( $split['ky_cents'] )
					) );
				}
				echo '</strong></p>';
			}
		}

		$register = Kmoney_Frontend::registration_link_html();
		if ( $register ) {
			echo '<p>' . $register . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Avvia il pagamento: calcola la quota KY dell'ordine, crea la richiesta
	 * di pagamento su KMoney per quella quota e restituisce l'URL su cui
	 * WooCommerce reindirizzerà il browser del cliente.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Ordine non trovato.', 'kmoney-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// KMoney amounts are integer cents of KY. This assumes a 1:1 KY <> store
		// currency mapping (typical for local-currency circuits) — adjust in
		// Kmoney_Percentages if that is not the case for this store.
		$split = Kmoney_Percentages::split_for_order( $order );

		if ( $split['ky_cents'] <= 0 ) {
			wc_add_notice( __( 'Questo ordine non contiene prodotti pagabili in KMoney: scegli un altro metodo di pagamento.', 'kmoney-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = new Kmoney_Api_Client( $this->get_option( 'api_base_url' ), $this->get_option( 'api_token' ) );

		$description = $split['mixed']
			/* translators: 1: order number, 2: EUR residual */
			? sprintf( __( 'Ordine #%1$s (quota KMoney; saldo di %2$s con altro metodo)', 'kmoney-payment' ), $order->get_order_number(), kmoney_price_text( $split['eur_residual'] ) )
			/* translators: %s: order number */
			: sprintf( __( 'Ordine #%s', 'kmoney-payment' ), $order->get_order_number() );

		try {
			$payment_request = $client->create_payment_request(
				$split['ky_cents'],
				$description,
				(string) $order->get_id(),
				$order->get_checkout_order_received_url(),
				wc_get_cart_url(),
				30
			);
		} catch ( Exception $e ) {
			$this->log_error( 'create_payment_request fallita per ordine ' . $order_id . ': ' . $e->getMessage() );
			$order->add_order_note( 'KMoney: impossibile creare la richiesta di pagamento (' . $e->getMessage() . ').' );
			wc_add_notice( __( 'Il pagamento KMoney non è al momento disponibile. Scegli un altro metodo di pagamento.', 'kmoney-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $payment_request['pay_url'] ) || empty( $payment_request['uuid'] ) ) {
			$this->log_error( 'risposta create_payment_request senza pay_url per ordine ' . $order_id );
			wc_add_notice( __( 'Il pagamento KMoney non è al momento disponibile. Scegli un altro metodo di pagamento.', 'kmoney-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Snapshot dello split: la conferma (webhook/ritorno) usa questi valori,
		// non un ricalcolo — le % potrebbero cambiare nel frattempo.
		$order->update_meta_data( '_kmoney_pr_uuid', sanitize_text_field( $payment_request['uuid'] ) );
		if ( ! empty( $payment_request['token'] ) ) {
			$order->update_meta_data( '_kmoney_pr_token', sanitize_text_field( $payment_request['token'] ) );
		}
		$order->update_meta_data( '_kmoney_ky_amount_cents', (int) $split['ky_cents'] );
		$order->update_meta_data( '_kmoney_residual_eur', wc_format_decimal( $split['eur_residual'], 2 ) );

		$hold_note = $split['mixed']
			? sprintf(
				/* translators: 1: KY amount, 2: EUR residual */
				__( 'KMoney: in attesa di conferma della quota KY (%1$s KY). Saldo previsto in euro: %2$s.', 'kmoney-payment' ),
				Kmoney_Percentages::format_ky( $split['ky_cents'] ),
				kmoney_price_text( $split['eur_residual'] )
			)
			: __( 'KMoney: in attesa di conferma del pagamento.', 'kmoney-payment' );

		$order->update_status( 'on-hold', $hold_note );
		$order->save();

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $payment_request['pay_url'],
		);
	}

	public function thankyou_page( $order_id ) {
		// L'hook "woocommerce_thankyou_kmoney" scatta PRIMA del generico
		// "woocommerce_thankyou": verifica subito la quota KY, così il box
		// del saldo qui sotto è già aggiornato al primo caricamento.
		$this->maybe_verify_return( $order_id );

		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
		}

		// Ordine misto: la parte KY è stata pagata, invita a saldare il resto.
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_meta( '_kmoney_ky_paid' ) && ! $order->is_paid() && $order->has_status( 'pending' ) ) {
			$residual = (float) $order->get_total();
			echo '<div class="kmoney-balance-due" style="margin:1em 0;padding:1em 1.25em;border:1px solid #e3c96f;border-radius:8px;background:#fdf6e3;">';
			echo '<p style="margin:0 0 .75em;"><strong>' .
				esc_html__( 'Parte KMoney pagata ✔', 'kmoney-payment' ) . '</strong><br>' .
				esc_html( sprintf(
					/* translators: %s: formatted EUR residual */
					__( 'Per completare l\'ordine resta da pagare il saldo di %s con un altro metodo.', 'kmoney-payment' ),
					kmoney_price_text( $residual )
				) ) .
				'</p>';
			echo '<a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' .
				esc_html( sprintf(
					/* translators: %s: formatted EUR residual */
					__( 'Paga il saldo di %s ora', 'kmoney-payment' ),
					kmoney_price_text( $residual )
				) ) .
				'</a>';
			echo '</div>';
		}
	}

	/**
	 * Quando il cliente torna sulla pagina di ringraziamento (redirect da KMoney
	 * dopo un pagamento riuscito), verifica lo stato server-side prima di
	 * considerare la quota KY pagata — non ci si fida mai dei soli parametri
	 * nell'URL, che potrebbero essere manomessi.
	 *
	 * Hook generico "woocommerce_thankyou" (non solo "_kmoney"): serve a
	 * intercettare il ritorno anche se, per qualsiasi motivo, l'ordine risulta
	 * con un metodo di pagamento diverso da quello atteso; il controllo sotto
	 * scarta subito i casi non pertinenti.
	 */
	public function maybe_verify_return( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		if ( $order->is_paid() || $order->get_meta( '_kmoney_ky_paid' ) ) {
			return;
		}

		$pr_uuid = isset( $_GET['kmoney_pr_uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['kmoney_pr_uuid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $pr_uuid ) {
			// Fallback: usa la richiesta creata da questo stesso ordine.
			$pr_uuid = (string) $order->get_meta( '_kmoney_pr_uuid' );
		}

		if ( ! $pr_uuid ) {
			return;
		}

		$client = new Kmoney_Api_Client( $this->get_option( 'api_base_url' ), $this->get_option( 'api_token' ) );

		try {
			$payment_request = $client->get_payment_request( $pr_uuid );
		} catch ( Exception $e ) {
			$this->log_error( 'verifica al ritorno fallita per ordine ' . $order_id . ': ' . $e->getMessage() );
			return;
		}

		$is_paid       = isset( $payment_request['status'] ) && 'paid' === $payment_request['status'];
		$matches_order = isset( $payment_request['external_reference'] ) && (string) $payment_request['external_reference'] === (string) $order->get_id();

		if ( $is_paid && $matches_order ) {
			Kmoney_Order_Finalizer::mark_paid( $order, $payment_request );
		}
	}

	/**
	 * Sulla pagina "paga ordine" del saldo in euro (quota KY già incassata),
	 * KMoney non deve essere riproposto fra i metodi disponibili.
	 *
	 * @param array $gateways
	 * @return array
	 */
	public function exclude_self_on_balance_payment( $gateways ) {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return $gateways;
		}

		global $wp;
		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		if ( ! $order_id ) {
			return $gateways;
		}

		$order = wc_get_order( $order_id );
		if ( $order && $order->get_meta( '_kmoney_ky_paid' ) ) {
			unset( $gateways[ $this->id ] );
		}

		return $gateways;
	}

	/**
	 * Quando l'ordine misto viene saldato in euro con un altro gateway,
	 * lascia una nota riepilogativa in cronologia.
	 *
	 * @param int $order_id
	 */
	public function note_balance_received( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_kmoney_ky_paid' ) ) {
			return;
		}
		if ( $order->get_payment_method() === $this->id ) {
			return; // Ordine interamente KY: nessun saldo in euro.
		}
		if ( $order->get_meta( '_kmoney_balance_noted' ) ) {
			return;
		}

		$ky_cents = (int) $order->get_meta( '_kmoney_ky_amount_cents' );
		$order->add_order_note(
			sprintf(
				/* translators: %s: formatted KY amount */
				__( 'KMoney: saldo in euro ricevuto — ordine interamente pagato (quota KMoney %s KY + saldo con altro metodo).', 'kmoney-payment' ),
				Kmoney_Percentages::format_ky( $ky_cents )
			)
		);
		$order->update_meta_data( '_kmoney_balance_noted', 'yes' );
		$order->save();
	}

	/**
	 * Riceve il webhook "payment_request.paid" da kmoney-app.
	 * URL: https://tuosito.com/?wc-api=wc_gateway_kmoney
	 *
	 * Questa è la conferma autorevole e asincrona del pagamento, indipendente
	 * dal ritorno del browser del cliente (che potrebbe non avvenire mai, es.
	 * tab chiusa). Non richiede nonce/sessione WordPress: è una chiamata
	 * server-to-server autenticata tramite firma HMAC.
	 */
	public function handle_webhook() {
		$raw_body          = file_get_contents( 'php://input' );
		$signature_header  = isset( $_SERVER['HTTP_X_KMONEY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_KMONEY_SIGNATURE'] ) ) : '';
		$secret            = $this->get_option( 'webhook_secret' );

		if ( empty( $secret ) ) {
			$this->log_error( 'webhook: nessun secret configurato in WooCommerce' );
			$this->webhook_response( 500, array( 'error' => 'webhook not configured' ) );
		}

		$expected_signature = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );

		if ( empty( $signature_header ) || ! hash_equals( $expected_signature, $signature_header ) ) {
			$this->log_error( 'webhook: firma mancante o non valida' );
			$this->webhook_response( 401, array( 'error' => 'invalid signature' ) );
		}

		$payload = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) || ! isset( $payload['event'] ) || 'payment_request.paid' !== $payload['event'] ) {
			// 200 per non far ritentare eventi che non ci interessano.
			$this->webhook_response( 200, array( 'ignored' => true ) );
		}

		$pr                  = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();
		$external_reference  = isset( $pr['external_reference'] ) ? $pr['external_reference'] : null;

		if ( ! $external_reference ) {
			$this->webhook_response( 200, array( 'ignored' => true, 'reason' => 'no external_reference' ) );
		}

		$order = wc_get_order( (int) $external_reference );

		if ( ! $order ) {
			$this->log_error( 'webhook: nessun ordine trovato per external_reference=' . $external_reference );
			$this->webhook_response( 200, array( 'ignored' => true, 'reason' => 'order not found' ) );
		}

		try {
			Kmoney_Order_Finalizer::mark_paid( $order, $pr );
		} catch ( Exception $e ) {
			$this->log_error( 'webhook: finalizzazione ordine fallita: ' . $e->getMessage() );
			// Non-2xx: kmoney-app ritenta (fino a 3 volte) tramite SendWebhookJob.
			$this->webhook_response( 500, array( 'error' => 'processing failed' ) );
		}

		$this->webhook_response( 200, array( 'ok' => true ) );
	}

	/**
	 * @return never
	 */
	private function webhook_response( $http_status, $data ) {
		status_header( $http_status );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $data );
		exit;
	}

	private function log_error( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'kmoney-payment' ) );
		} else {
			error_log( '[KMoney] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
```

### `kmoney-payment/includes/class-kmoney-api-client.php`

```php
<?php
/**
 * Thin client for KMoney API v1 (https://<host>/api/v1).
 *
 * Auth: "Authorization: Bearer km_..." token, generated on the KMoney portal
 * under /api-tokens with the "write" ability. Amounts are always integer
 * cents of KY (e.g. 5000 = 50,00 KY) — never send floats.
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kmoney_Api_Client {

	/** @var string */
	private $base_url;

	/** @var string */
	private $token;

	public function __construct( $base_url, $token ) {
		$this->base_url = rtrim( (string) $base_url, '/' );
		$this->token    = (string) $token;
	}

	/**
	 * POST /payment-requests
	 *
	 * Creates a hosted payment request and returns the decoded "data" object,
	 * which includes "uuid", "token" and "pay_url" — redirect the customer's
	 * browser to pay_url to complete the payment on KMoney's own domain.
	 *
	 * external_reference should be the WooCommerce order ID: if a pending
	 * request with the same external_reference + amount already exists, the
	 * API returns that one instead of creating a duplicate (safe to call
	 * again on checkout retries).
	 *
	 * @throws Exception
	 */
	public function create_payment_request( $amount_cents, $description, $external_reference, $return_url, $cancel_url, $expires_in_minutes = 30 ) {
		return $this->request(
			'POST',
			'/payment-requests',
			array(
				'amount'              => (int) $amount_cents,
				'description'         => (string) $description,
				'external_reference'  => (string) $external_reference,
				'return_url'          => (string) $return_url,
				'cancel_url'          => (string) $cancel_url,
				'expires_in_minutes'  => (int) $expires_in_minutes,
			)
		);
	}

	/**
	 * GET /payment-requests/{uuid}
	 *
	 * Used to verify server-side that a payment request is really "paid"
	 * before completing the order — never trust the browser redirect query
	 * string alone.
	 *
	 * @throws Exception
	 */
	public function get_payment_request( $uuid ) {
		return $this->request( 'GET', '/payment-requests/' . rawurlencode( (string) $uuid ) );
	}

	/**
	 * GET /balance
	 *
	 * Detailed balance of the merchant's main KY account. Returns (among
	 * others): balance, available_balance, is_in_debit, is_at_ceiling,
	 * can_sell, allowed_ky_percentages. Used to enforce the circuit rule:
	 * a merchant whose account is below zero must sell at 100% KY.
	 *
	 * @throws Exception
	 */
	public function get_balance() {
		return $this->request( 'GET', '/balance' );
	}

	/**
	 * @throws Exception
	 */
	private function request( $method, $path, $body = null ) {
		if ( empty( $this->base_url ) || empty( $this->token ) ) {
			throw new Exception( 'KMoney API non configurata (URL base / token mancante).' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$response                        = wp_remote_post( $this->base_url . $path, $args );
		} else {
			$response = wp_remote_get( $this->base_url . $path, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Richiesta API KMoney fallita: ' . $response->get_error_message() );
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			throw new Exception( 'Risposta API KMoney non valida (HTTP ' . $status . ').' );
		}

		if ( $status >= 400 ) {
			$message = isset( $decoded['error'] ) ? $decoded['error'] : ( isset( $decoded['message'] ) ? $decoded['message'] : ( 'HTTP ' . $status ) );
			throw new Exception( 'Errore API KMoney (' . $status . '): ' . $message );
		}

		return isset( $decoded['data'] ) ? $decoded['data'] : $decoded;
	}
}
```

### `kmoney-payment/includes/class-kmoney-merchant-status.php`

```php
<?php
/**
 * Cached view of the merchant's KMoney account status (GET /balance).
 *
 * The circuit rule enforced here: if the merchant's KY account is below zero
 * (is_in_debit), every product MUST be sold at 100% KY — the merchant cannot
 * choose or change any percentage until the balance is positive again. When
 * the balance is positive the merchant may pick 0/25/50/75/100 globally, per
 * category or per product.
 *
 * The status is cached in a transient (5 minutes) to avoid one API call per
 * page view; the last successful response is also stored in an option as a
 * fallback when the API is temporarily unreachable.
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kmoney_Merchant_Status {

	const TRANSIENT_KEY   = 'kmoney_merchant_status';
	const LAST_GOOD_KEY   = 'kmoney_merchant_status_last_good';
	const CACHE_TTL       = 5 * MINUTE_IN_SECONDS;

	/**
	 * Returns the merchant status array:
	 *   ok                     bool   API raggiungibile (ora o ultimo dato buono)
	 *   is_in_debit            bool   conto sottozero → 100% forzato
	 *   balance                int    saldo in centesimi di KY
	 *   available_balance      int
	 *   account_number         string
	 *   allowed_ky_percentages int[]
	 *   error                  string ultimo errore API (se non ok)
	 *   fetched_at             int    timestamp del dato
	 *
	 * @param bool $force_refresh Bypassa la cache (usato nella pagina impostazioni).
	 * @return array
	 */
	public static function get( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$settings = get_option( 'woocommerce_kmoney_settings', array() );
		$base_url = isset( $settings['api_base_url'] ) ? $settings['api_base_url'] : '';
		$token    = isset( $settings['api_token'] ) ? $settings['api_token'] : '';

		if ( ! $base_url || ! $token ) {
			return array(
				'ok'          => false,
				'is_in_debit' => false,
				'error'       => __( 'API KMoney non configurata.', 'kmoney-payment' ),
			);
		}

		$client = new Kmoney_Api_Client( $base_url, $token );

		try {
			$balance = $client->get_balance();
			$status  = array(
				'ok'                     => true,
				'is_in_debit'            => ! empty( $balance['is_in_debit'] ),
				'balance'                => isset( $balance['balance'] ) ? (int) $balance['balance'] : 0,
				'available_balance'      => isset( $balance['available_balance'] ) ? (int) $balance['available_balance'] : 0,
				'account_number'         => isset( $balance['account_number'] ) ? (string) $balance['account_number'] : '',
				'allowed_ky_percentages' => isset( $balance['allowed_ky_percentages'] ) && is_array( $balance['allowed_ky_percentages'] )
					? array_map( 'intval', $balance['allowed_ky_percentages'] )
					: array( 0, 25, 50, 75, 100 ),
				'error'                  => '',
				'fetched_at'             => time(),
			);

			set_transient( self::TRANSIENT_KEY, $status, self::CACHE_TTL );
			update_option( self::LAST_GOOD_KEY, $status, false );

			return $status;
		} catch ( Exception $e ) {
			// API irraggiungibile: usa l'ultimo dato buono se esiste, così la
			// regola del conto in debito non "salta" per un timeout di rete.
			$last_good = get_option( self::LAST_GOOD_KEY );

			if ( is_array( $last_good ) ) {
				$last_good['ok']    = false;
				$last_good['error'] = $e->getMessage();
				set_transient( self::TRANSIENT_KEY, $last_good, MINUTE_IN_SECONDS );
				return $last_good;
			}

			$status = array(
				'ok'          => false,
				'is_in_debit' => false,
				'error'       => $e->getMessage(),
			);
			set_transient( self::TRANSIENT_KEY, $status, MINUTE_IN_SECONDS );
			return $status;
		}
	}

	/**
	 * True se il conto del negoziante è sottozero: 100% KY forzato ovunque.
	 *
	 * @return bool
	 */
	public static function is_in_debit() {
		$status = self::get();
		return ! empty( $status['is_in_debit'] );
	}

	/**
	 * Percentuali selezionabili dal negoziante in questo momento.
	 *
	 * @return int[]
	 */
	public static function allowed_percentages() {
		if ( self::is_in_debit() ) {
			return array( 100 );
		}
		return Kmoney_Percentages::ALLOWED;
	}

	/**
	 * Invalida la cache (chiamato al salvataggio delle impostazioni).
	 */
	public static function flush_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}
}
```

### `kmoney-payment/includes/class-kmoney-percentages.php`

```php
<?php
/**
 * Resolves the KY percentage applicable to products, cart and orders.
 *
 * Resolution order (first match wins):
 *   1. Merchant account below zero  → 100% forced on everything.
 *   2. Product meta _kmoney_ky_percentage ('' = inherit).
 *   3. Highest kmoney_ky_percentage among the product's categories
 *      ('' / missing = inherit; the HIGHEST wins to maximise KY circulation).
 *   4. Global default from the gateway settings (default 100).
 *
 * Percentages are always one of 0/25/50/75/100. Amounts are computed in
 * integer cents of KY: cents = round(euro_amount * pct) — assumes the usual
 * 1 KY = 1 EUR mapping of the circuit.
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kmoney_Percentages {

	const ALLOWED      = array( 0, 25, 50, 75, 100 );
	const PRODUCT_META = '_kmoney_ky_percentage';
	const TERM_META    = 'kmoney_ky_percentage';

	/**
	 * Global default % from the gateway settings (fallback 100).
	 *
	 * @return int
	 */
	public static function global_default() {
		$settings = get_option( 'woocommerce_kmoney_settings', array() );
		$value    = isset( $settings['default_ky_percentage'] ) ? $settings['default_ky_percentage'] : '';
		return self::sanitize( $value, 100 );
	}

	/**
	 * Clamp any input to one of the allowed percentages.
	 *
	 * @param mixed $value
	 * @param int   $fallback
	 * @return int
	 */
	public static function sanitize( $value, $fallback = 100 ) {
		if ( '' === $value || null === $value || false === $value ) {
			return (int) $fallback;
		}
		$value = (int) $value;
		return in_array( $value, self::ALLOWED, true ) ? $value : (int) $fallback;
	}

	/**
	 * KY percentage for a single product (or variation — the parent's
	 * settings apply). Enforces the in-debit rule.
	 *
	 * @param WC_Product|int $product
	 * @return int 0-100
	 */
	public static function for_product( $product ) {
		if ( Kmoney_Merchant_Status::is_in_debit() ) {
			return 100;
		}

		$product = is_object( $product ) ? $product : wc_get_product( $product );
		if ( ! $product ) {
			return self::global_default();
		}

		// Le variazioni ereditano dal prodotto padre.
		$parent_id  = $product->get_parent_id();
		$product_id = $parent_id ? $parent_id : $product->get_id();

		// 1. Override sul singolo prodotto.
		$own = get_post_meta( $product_id, self::PRODUCT_META, true );
		if ( '' !== $own && null !== $own && in_array( (int) $own, self::ALLOWED, true ) ) {
			return (int) $own;
		}

		// 2. Migliore % fra le categorie del prodotto (vince la più alta).
		$term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
		$best     = null;
		foreach ( $term_ids as $term_id ) {
			$term_pct = get_term_meta( $term_id, self::TERM_META, true );
			if ( '' === $term_pct || null === $term_pct ) {
				continue;
			}
			if ( in_array( (int) $term_pct, self::ALLOWED, true ) ) {
				$best = ( null === $best ) ? (int) $term_pct : max( $best, (int) $term_pct );
			}
		}
		if ( null !== $best ) {
			return $best;
		}

		// 3. Predefinita del negozio.
		return self::global_default();
	}

	/**
	 * KY/EUR split for the current cart.
	 *
	 * Shipping, fees and their taxes follow the GLOBAL default percentage
	 * (with the in-debit rule applied), item lines follow their product's
	 * percentage (tax included).
	 *
	 * @param WC_Cart $cart
	 * @return array { ky_cents:int, eur_residual:float, total:float, mixed:bool }
	 */
	public static function split_for_cart( $cart ) {
		$ky_cents = 0;

		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$pct       = self::for_product( $product );
			$line_eur  = (float) $item['line_total'] + (float) $item['line_tax'];
			$ky_cents += (int) round( $line_eur * $pct );
		}

		$overhead_pct = Kmoney_Merchant_Status::is_in_debit() ? 100 : self::global_default();
		$overhead_eur = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax()
			+ (float) $cart->get_fee_total() + (float) $cart->get_fee_tax();
		$ky_cents    += (int) round( $overhead_eur * $overhead_pct );

		return self::finalize_split( $ky_cents, (float) $cart->get_total( 'edit' ) );
	}

	/**
	 * KY/EUR split for an order (same rules as the cart — used at
	 * process_payment time, then snapshotted in order meta).
	 *
	 * @param WC_Order $order
	 * @return array { ky_cents:int, eur_residual:float, total:float, mixed:bool }
	 */
	public static function split_for_order( $order ) {
		$ky_cents = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product   = $item->get_product();
			$pct       = $product ? self::for_product( $product ) : self::global_default();
			$line_eur  = (float) $item->get_total() + (float) $item->get_total_tax();
			$ky_cents += (int) round( $line_eur * $pct );
		}

		$overhead_pct = Kmoney_Merchant_Status::is_in_debit() ? 100 : self::global_default();
		$overhead_eur = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$overhead_eur += (float) $fee->get_total() + (float) $fee->get_total_tax();
		}
		$ky_cents += (int) round( $overhead_eur * $overhead_pct );

		return self::finalize_split( $ky_cents, (float) $order->get_total() );
	}

	/**
	 * @param int   $ky_cents
	 * @param float $total_eur
	 * @return array
	 */
	private static function finalize_split( $ky_cents, $total_eur ) {
		$total_cents = (int) round( $total_eur * 100 );

		// Mai chiedere in KY più del totale ordine (arrotondamenti).
		$ky_cents = max( 0, min( $ky_cents, $total_cents ) );

		$residual_cents = $total_cents - $ky_cents;
		// Sotto il centesimo → considera tutto pagato in KY.
		if ( $residual_cents < 1 ) {
			$residual_cents = 0;
			$ky_cents       = $total_cents;
		}

		return array(
			'ky_cents'     => $ky_cents,
			'eur_residual' => (float) ( $residual_cents / 100 ),
			'total'        => (float) ( $total_cents / 100 ),
			'mixed'        => ( $ky_cents > 0 && $residual_cents > 0 ),
		);
	}

	/**
	 * Formatta centesimi KY come "1.234,56".
	 *
	 * @param int $cents
	 * @return string
	 */
	public static function format_ky( $cents ) {
		return number_format( ( (int) $cents ) / 100, 2, ',', '.' );
	}
}
```

### `kmoney-payment/includes/class-kmoney-order-finalizer.php`

```php
<?php
/**
 * Turns a confirmed KMoney payment_request.paid event into order progress.
 *
 * Two cases:
 *   - Full-KY order (residual = 0): the order is completed, exactly like
 *     version 2.0.0 did.
 *   - Mixed order (KY part + EUR residual): the paid KY part is recorded as
 *     a negative fee line ("Pagato con KMoney"), the order total drops to
 *     the EUR residual and the order goes to "pending payment" so the
 *     customer can pay the rest with any other gateway (thank-you page
 *     button + invoice email with the payment link).
 *
 * Shared by the "return" verification (customer comes back from KMoney,
 * WC_Gateway_Kmoney::maybe_verify_return) and the webhook handler
 * (WC_Gateway_Kmoney::handle_webhook). Both paths can race each other, so
 * this is written to be idempotent: the _kmoney_ky_paid meta guards against
 * double processing.
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kmoney_Order_Finalizer {

	/**
	 * @param WC_Order $order
	 * @param array    $payment_request Decoded "PaymentRequest" payload from the
	 *                                  KMoney API (either the GET /payment-requests/{uuid}
	 *                                  response, or the "payload" object of a
	 *                                  payment_request.paid webhook).
	 */
	public static function mark_paid( $order, $payment_request ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Già gestito dall'altro percorso (return vs. webhook, possono correre
		// in parallelo) — non fare nulla, non è un errore.
		if ( $order->is_paid() || $order->get_meta( '_kmoney_ky_paid' ) || $order->get_meta( '_kmoney_transfer_uuid' ) ) {
			return;
		}

		$transfer_uuid = isset( $payment_request['transfer_uuid'] )
			? $payment_request['transfer_uuid']
			: ( isset( $payment_request['uuid'] ) ? $payment_request['uuid'] : null );

		if ( $transfer_uuid ) {
			$order->update_meta_data( '_kmoney_transfer_uuid', sanitize_text_field( $transfer_uuid ) );
		}

		if ( isset( $payment_request['uuid'] ) ) {
			$order->update_meta_data( '_kmoney_pr_uuid', sanitize_text_field( $payment_request['uuid'] ) );
		}

		$order->update_meta_data( '_kmoney_ky_paid', 'yes' );

		$ky_cents = (int) $order->get_meta( '_kmoney_ky_amount_cents' );
		if ( $ky_cents <= 0 && isset( $payment_request['amount'] ) ) {
			$ky_cents = (int) $payment_request['amount'];
		}

		$residual = (float) $order->get_meta( '_kmoney_residual_eur' );

		if ( $residual > 0 ) {
			self::apply_mixed_payment( $order, $ky_cents, $residual, $transfer_uuid );
			return;
		}

		// Ordine interamente in KY: completa come in v2.0.0.
		$order->payment_complete( $transfer_uuid ? $transfer_uuid : '' );

		$order->add_order_note(
			sprintf(
				/* translators: %s: KMoney transfer UUID */
				__( 'KMoney: pagamento confermato (transfer %s).', 'kmoney-payment' ),
				$transfer_uuid ? $transfer_uuid : 'n/d'
			)
		);

		$order->save();
	}

	/**
	 * Registra la parte KY pagata e mette l'ordine in attesa del saldo in euro.
	 *
	 * @param WC_Order    $order
	 * @param int         $ky_cents
	 * @param float       $residual
	 * @param string|null $transfer_uuid
	 */
	private static function apply_mixed_payment( $order, $ky_cents, $residual, $transfer_uuid ) {
		$ky_label = sprintf(
			/* translators: %s: formatted KY amount */
			__( 'Pagato con KMoney (%s KY)', 'kmoney-payment' ),
			Kmoney_Percentages::format_ky( $ky_cents )
		);

		// Riga negativa: il totale ordine scende al residuo in euro, così il
		// cliente paga il resto con un metodo normale sulla pagina "paga ordine".
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( $ky_label );
		$fee->set_amount( -1 * ( $ky_cents / 100 ) );
		$fee->set_total( -1 * ( $ky_cents / 100 ) );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );

		$order->calculate_totals( false );

		$order->add_order_note(
			sprintf(
				/* translators: 1: formatted KY amount, 2: KMoney transfer UUID, 3: formatted EUR residual */
				__( 'KMoney: pagata la parte KY (%1$s KY, transfer %2$s). Resta da pagare il saldo di %3$s con un altro metodo.', 'kmoney-payment' ),
				Kmoney_Percentages::format_ky( $ky_cents ),
				$transfer_uuid ? $transfer_uuid : 'n/d',
				kmoney_price_text( $residual )
			)
		);

		// "pending" = in attesa di pagamento → il cliente può usare il link
		// "paga ordine" con qualunque altro gateway (KMoney viene escluso).
		$order->update_status( 'pending', __( 'KMoney: parte KY incassata, in attesa del saldo in euro.', 'kmoney-payment' ) );
		$order->save();

		// Email al cliente con il link di pagamento del saldo.
		self::send_balance_invoice( $order );
	}

	/**
	 * @param WC_Order $order
	 */
	private static function send_balance_invoice( $order ) {
		if ( $order->get_meta( '_kmoney_balance_invoice_sent' ) ) {
			return;
		}

		$mailer = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer() : null;
		if ( $mailer ) {
			$emails = $mailer->get_emails();
			if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
				$emails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
				$order->update_meta_data( '_kmoney_balance_invoice_sent', 'yes' );
				$order->save();
			}
		}
	}
}
```

### `kmoney-payment/includes/class-kmoney-product-settings.php`

```php
<?php
/**
 * Admin fields to configure the KY percentage per product and per category.
 *
 * Both fields offer "Predefinita" (inherit) plus 0/25/50/75/100. When the
 * merchant's KY account is below zero the fields are replaced by a locked
 * notice: everything is sold at 100% KY and nothing can be changed until the
 * balance is positive again (server-side saves are ignored too).
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kmoney_Product_Settings {

	public static function init() {
		// Campo sul prodotto (tab "Generale" di Dati prodotto).
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );

		// Campo sulle categorie prodotto.
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_category_add_field' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_category_edit_field' ), 10, 1 );
		add_action( 'created_product_cat', array( __CLASS__, 'save_category_field' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_category_field' ) );
	}

	/**
	 * @return array<string,string> value => label
	 */
	private static function options( $inherit_label ) {
		$options = array( '' => $inherit_label );
		foreach ( Kmoney_Percentages::ALLOWED as $pct ) {
			$options[ (string) $pct ] = $pct . '%';
		}
		return $options;
	}

	private static function locked_notice() {
		return '<p style="margin:8px 12px;padding:8px 12px;background:#fcf0e4;border-left:4px solid #d63638;">' .
			esc_html__( 'Conto KMoney in negativo: tutti i prodotti sono venduti al 100% in KMoney. Le percentuali non sono modificabili finché il saldo non torna positivo.', 'kmoney-payment' ) .
			'</p>';
	}

	/* ---------------------------------------------------------------------
	 * Prodotto
	 * ------------------------------------------------------------------- */

	public static function render_product_field() {
		echo '<div class="options_group">';

		if ( Kmoney_Merchant_Status::is_in_debit() ) {
			echo self::locked_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			return;
		}

		woocommerce_wp_select(
			array(
				'id'          => Kmoney_Percentages::PRODUCT_META,
				'label'       => __( '% pagabile in KMoney', 'kmoney-payment' ),
				'description' => __( 'Quota del prezzo pagabile in KY per questo prodotto. "Predefinita" usa la % della categoria (se impostata) o quella globale del negozio.', 'kmoney-payment' ),
				'desc_tip'    => true,
				'options'     => self::options( __( 'Predefinita (categoria o negozio)', 'kmoney-payment' ) ),
			)
		);

		echo '</div>';
	}

	/**
	 * @param WC_Product $product
	 */
	public static function save_product_field( $product ) {
		// Conto in debito: la % non è modificabile — ignora qualunque input.
		if ( Kmoney_Merchant_Status::is_in_debit() ) {
			return;
		}

		if ( ! isset( $_POST[ Kmoney_Percentages::PRODUCT_META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_POST[ Kmoney_Percentages::PRODUCT_META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $raw ) {
			$product->delete_meta_data( Kmoney_Percentages::PRODUCT_META );
			return;
		}

		$product->update_meta_data(
			Kmoney_Percentages::PRODUCT_META,
			Kmoney_Percentages::sanitize( $raw, Kmoney_Percentages::global_default() )
		);
	}

	/* ---------------------------------------------------------------------
	 * Categoria
	 * ------------------------------------------------------------------- */

	public static function render_category_add_field() {
		if ( Kmoney_Merchant_Status::is_in_debit() ) {
			echo '<div class="form-field">' . self::locked_notice() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		?>
		<div class="form-field">
			<label for="kmoney_ky_percentage"><?php esc_html_e( '% pagabile in KMoney', 'kmoney-payment' ); ?></label>
			<?php self::render_select( '' ); ?>
			<p class="description"><?php esc_html_e( 'Quota del prezzo pagabile in KY per i prodotti di questa categoria. Il singolo prodotto può avere una % propria che ha la precedenza; se un prodotto è in più categorie vale la % più alta.', 'kmoney-payment' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param WP_Term $term
	 */
	public static function render_category_edit_field( $term ) {
		if ( Kmoney_Merchant_Status::is_in_debit() ) {
			echo '<tr class="form-field"><th></th><td>' . self::locked_notice() . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$current = get_term_meta( $term->term_id, Kmoney_Percentages::TERM_META, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="kmoney_ky_percentage"><?php esc_html_e( '% pagabile in KMoney', 'kmoney-payment' ); ?></label></th>
			<td>
				<?php self::render_select( $current ); ?>
				<p class="description"><?php esc_html_e( 'Quota del prezzo pagabile in KY per i prodotti di questa categoria. Il singolo prodotto può avere una % propria che ha la precedenza; se un prodotto è in più categorie vale la % più alta.', 'kmoney-payment' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function render_select( $current ) {
		echo '<select name="kmoney_ky_percentage" id="kmoney_ky_percentage">';
		foreach ( self::options( __( 'Predefinita (negozio)', 'kmoney-payment' ) ) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( (string) $current, (string) $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * @param int $term_id
	 */
	public static function save_category_field( $term_id ) {
		if ( Kmoney_Merchant_Status::is_in_debit() ) {
			return;
		}

		if ( ! isset( $_POST['kmoney_ky_percentage'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_POST['kmoney_ky_percentage'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $raw ) {
			delete_term_meta( $term_id, Kmoney_Percentages::TERM_META );
			return;
		}

		update_term_meta(
			$term_id,
			Kmoney_Percentages::TERM_META,
			Kmoney_Percentages::sanitize( $raw, Kmoney_Percentages::global_default() )
		);
	}
}
```

### `kmoney-payment/includes/class-kmoney-frontend.php`

```php
<?php
/**
 * Storefront output: "payable in KMoney" badge on the product page and the
 * "Registrati su KMoney" link for customers who don't have an account yet.
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kmoney_Frontend {

	public static function init() {
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_product_badge' ), 25 );
	}

	/**
	 * @return array Gateway settings.
	 */
	private static function settings() {
		return get_option( 'woocommerce_kmoney_settings', array() );
	}

	private static function gateway_enabled() {
		$s = self::settings();
		return isset( $s['enabled'], $s['api_base_url'], $s['api_token'] )
			&& 'yes' === $s['enabled']
			&& '' !== $s['api_base_url']
			&& '' !== $s['api_token'];
	}

	/**
	 * URL di registrazione KMoney: impostazione dedicata, altrimenti derivato
	 * dall'URL base API (https://kmoney.example.com/api/v1 → /register).
	 *
	 * @return string
	 */
	public static function registration_url() {
		$s = self::settings();

		if ( ! empty( $s['registration_url'] ) ) {
			return $s['registration_url'];
		}

		if ( ! empty( $s['api_base_url'] ) ) {
			$base = preg_replace( '#/api/v\d+/?$#', '', rtrim( $s['api_base_url'], '/' ) );
			return $base . '/register';
		}

		return '';
	}

	/**
	 * Link "Non hai un conto? Registrati su KMoney" (stringa HTML, già escaped).
	 *
	 * @return string
	 */
	public static function registration_link_html() {
		$url = self::registration_url();
		if ( ! $url ) {
			return '';
		}
		return sprintf(
			'<a class="kmoney-register-link" href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( $url ),
			esc_html__( 'Non hai un conto? Registrati su KMoney', 'kmoney-payment' )
		);
	}

	/**
	 * Badge sulla pagina prodotto: "Pagabile in KMoney al X%".
	 */
	public static function render_product_badge() {
		if ( ! self::gateway_enabled() ) {
			return;
		}

		$s = self::settings();
		if ( isset( $s['show_product_badge'] ) && 'no' === $s['show_product_badge'] ) {
			return;
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$pct = Kmoney_Percentages::for_product( $product );
		if ( $pct <= 0 ) {
			return;
		}

		$is_full = ( 100 === $pct );
		$label   = $is_full
			? __( 'Pagabile al 100% in KMoney', 'kmoney-payment' )
			/* translators: %d: KY percentage */
			: sprintf( __( 'Pagabile al %d%% in KMoney', 'kmoney-payment' ), $pct );

		echo '<div class="kmoney-product-badge' . ( $is_full ? ' kmoney-product-badge--full' : '' ) . '">';
		echo '<span class="kmoney-product-badge__pill">' . esc_html( ( $is_full ? '★ ' : '✓ ' ) . $label ) . '</span> ';
		echo self::registration_link_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		self::print_badge_css_once();
	}

	private static function print_badge_css_once() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			.kmoney-product-badge { margin: .5em 0 1em; display: flex; align-items: center; gap: .75em; flex-wrap: wrap; }
			.kmoney-product-badge__pill {
				display: inline-block; padding: .25em .85em; border-radius: 999px;
				font-size: .85em; font-weight: 600; letter-spacing: .01em;
				background: #eef4ff; color: #1d4ed8; border: 1px solid #bfd3ff;
			}
			.kmoney-product-badge--full .kmoney-product-badge__pill {
				background: linear-gradient(135deg, #fdf6e3, #f7e6b5); color: #7a5b00; border-color: #e3c96f;
			}
			.kmoney-register-link { font-size: .85em; text-decoration: underline; opacity: .85; }
		</style>
		<?php
	}
}
```
