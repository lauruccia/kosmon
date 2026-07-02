# Integrazione pagamenti KMoney per WooCommerce

Guida per il programmatore incaricato di installare/adattare il nuovo plugin di pagamento
KMoney su WooCommerce. Il plugin allegato (`kmoney-woocommerce-plugin.zip`, cartella
`kmoney-payment/`) è completo e pronto all'uso — non c'è nulla da costruire da zero, solo da
installare, configurare e testare secondo la checklist alla fine di questo documento.

**Questo plugin sostituisce il vecchio plugin "KMoney"** (quello allegato inizialmente,
`kmoney_v1.4.5.zip`). Il vecchio plugin va disattivato — i due non possono coesistere (vedi
sezione 6).

---

## 1. Perché è stato riscritto da zero

Il vecchio plugin faceva scrivere email e password KMoney del cliente in un campo del checkout
WooCommerce e le inoltrava a un'API esterna diversa (`kosmomoney.com`, non l'attuale
`kmoney-app`). Questo bypassa 2FA/passkey e fa transitare credenziali di pagamento reali su un
sito terzo — un problema di sicurezza serio, non solo di manutenibilità. Il nuovo plugin non
raccoglie mai credenziali KMoney: il cliente si autentica solo sul dominio KMoney.

## 2. Sequenza del pagamento

```
Cliente              WooCommerce (questo plugin)          API KMoney / pagina hosted KMoney
   |                        |                                      |
   | checkout, sceglie      |                                      |
   | KMoney, conferma       |                                      |
   |----------------------->|                                      |
   |                        | ordine creato, stato "in attesa"      |
   |                        | POST /api/v1/payment-requests ------->|
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
   |                        | ordine completato                      |
   |<-----------------------|                                      |
```

Webhook e verifica-al-ritorno sono entrambi implementati e idempotenti
(`Kmoney_Order_Finalizer::mark_paid()` controlla `$order->is_paid()` prima di fare qualunque
cosa): qualunque dei due arrivi per primo completa l'ordine, l'altro non fa nulla.

## 3. Riferimento API (KMoney API v1)

Base URL: `https://<dominio-kmoney>/api/v1`. Autenticazione: header `Authorization: Bearer
km_xxxxxxxxxxxx` (token generato dal negoziante sul portale KMoney, sezione `/api-tokens`,
**ability `write` obbligatoria**). Rate limit: 60 richieste/minuto generali, 10/minuto su
`POST /payment-requests`.

### 3.1 `POST /payment-requests`

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

`amount` è sempre intero, centesimi di KY. `external_reference` è l'ID numerico dell'ordine
WooCommerce (`$order->get_id()`) — se esiste già una richiesta `pending` con lo stesso
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

### 3.2 `GET /payment-requests/{uuid}`

Stessa struttura, `status` in `pending|paid|expired|cancelled`. Usato per la verifica
server-side al ritorno del cliente — mai fidarsi dei soli parametri nell'URL di ritorno.

## 4. Webhook `payment_request.paid`

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

## 5. Installazione

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
   - Secret webhook (da Sezione 4)
5. Registrare il webhook sul portale KMoney come da Sezione 4.

Nessuna migrazione di database, nessuna dipendenza aggiuntiva oltre WooCommerce attivo. Il
plugin dichiara compatibilità HPOS (High-Performance Order Storage) di WooCommerce.

## 6. Cosa NON è incluso

- **Rimborsi non automatizzati.** L'API KMoney non ha ancora un endpoint pubblico di rimborso;
  vanno fatti manualmente dal portale KMoney lato negoziante.
- **Nessun pagamento parziale/split** (KY + altro metodo). Il vecchio plugin aveva questa
  funzione (percentuale KMoney per categoria prodotto); il nuovo plugin assume che l'intero
  totale ordine venga pagato in KY. Se questa funzionalità serve ancora, va discussa e
  progettata a parte — non è nell'ambito di questa consegna.
- **Cambio valuta**: la conversione importo in `process_payment()` assume 1 KY ≈ 1 unità di
  valuta del negozio (tipico per circuiti a valuta locale come questo) — verificare con il
  titolare del conto KMoney prima di andare live se non è così.

## 7. Checklist di test end-to-end

1. Plugin vecchio disattivato, nuovo plugin attivo e configurato, webhook registrato sul
   portale KMoney.
2. Ordine di prova con metodo "KMoney" → si atterra su una pagina con dominio KMoney (non
   WooCommerce).
3. Login con un account KMoney di test con saldo sufficiente, conferma pagamento.
4. Redirect di ritorno sulla pagina "grazie per l'ordine" di WooCommerce.
5. Verificare che l'ordine sia passato a "In lavorazione"/"Completato" con nota "KMoney:
   pagamento confermato" nella cronologia ordine.
6. Ripetere il test chiudendo la scheda subito dopo la conferma su KMoney, prima del redirect:
   l'ordine deve comunque completarsi entro pochi secondi tramite il solo webhook — controllare
   il log consegne webhook sul portale KMoney (Impostazioni → Webhook → il tuo webhook →
   consegne) per un `200` da WooCommerce.
7. Provare un pagamento con saldo insufficiente: KMoney blocca il pagamento sulla propria
   pagina, l'ordine WooCommerce deve restare "In attesa" e non fatturato.
8. Manomettere la firma del webhook (curl con `X-KMoney-Signature` sbagliata): il plugin deve
   rispondere `401` e non completare l'ordine.
9. Far scadere una richiesta di pagamento (creare l'ordine, aspettare oltre `expires_in_minutes`
   senza pagare): KMoney mostra "scaduto", l'ordine resta non pagato.

## 8. Struttura file

```
kmoney-payment/
├── kmoney-payment.php                       (bootstrap, hook attivazione, HPOS)
├── readme.txt
└── includes/
    ├── class-wc-gateway-kmoney.php          (gateway: process_payment, webhook, verifica ritorno)
    ├── class-kmoney-api-client.php          (client HTTP verso l'API KMoney)
    └── class-kmoney-order-finalizer.php     (logica condivisa di completamento ordine, idempotente)
```

---

## Appendice — Codice sorgente completo

Incluso anche, pronto all'uso, in `kmoney-woocommerce-plugin.zip`.

### `kmoney-payment/kmoney-payment.php`

```php
<?php
/**
 * Plugin Name: KMoney Payment Gateway
 * Description: Accetta pagamenti KMoney (KY) su WooCommerce tramite checkout hosted sicuro: il cliente viene reindirizzato su KMoney per autenticarsi (2FA/passkey) e confermare l'importo. Nessuna credenziale KMoney del cliente viene mai raccolta o gestita da questo sito.
 * Version: 2.0.0
 * Requires Plugins: woocommerce
 * Author: KMoney
 * Text Domain: kmoney-payment
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KMONEY_PAYMENT_VERSION', '2.0.0' );
define( 'KMONEY_PAYMENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

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
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-order-finalizer.php';
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-wc-gateway-kmoney.php';

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = 'WC_Gateway_Kmoney';
			return $gateways;
		}
	);
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
Stable tag: 2.0.0
License: GPLv2 or later

Accetta pagamenti in KY (KMoney) su WooCommerce tramite checkout hosted sicuro: redirect + webhook,
nessuna credenziale KMoney del cliente gestita da questo sito.

== Description ==

Questo plugin sostituisce il vecchio plugin "KMoney" (che raccoglieva email e password KMoney del
cliente direttamente nel checkout WooCommerce e le inviava a un'API legacy separata su
kosmomoney.com). Questo modulo non fa mai nulla del genere:

1. Alla conferma ordine, crea una richiesta di pagamento sull'API ufficiale KMoney (kmoney-app),
   server-to-server, con il token del negoziante.
2. Reindirizza il cliente sulla pagina di pagamento ospitata da KMoney, dove si autentica con le
   proprie credenziali (2FA / passkey inclusi) e conferma l'importo.
3. Riceve conferma del pagamento in due modi indipendenti: un webhook autorevole
   (payment_request.paid) e una verifica server-side quando il cliente torna sul sito. Nessuno dei
   due si fida dei soli parametri nell'URL di ritorno.

Vedi KMONEY_WOOCOMMERCE_INTEGRATION.md per la guida completa (installazione, configurazione,
riferimento API, checklist di test).

== Installation ==

1. Disattiva ed elimina il vecchio plugin "KMoney" (cartella kmoney/), se presente.
2. Carica la cartella kmoney-payment/ in wp-content/plugins/.
3. Attiva "KMoney Payment Gateway" da Plugin.
4. Configura in WooCommerce > Impostazioni > Pagamenti > KMoney.
5. Registra il webhook sul portale KMoney (Impostazioni > Webhook): URL e secret sono mostrati
   nella pagina di configurazione del plugin.

== Changelog ==

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
 *      merchant's own Bearer token) when the customer places the order.
 *   2. Redirects the customer's browser to KMoney's own hosted checkout page
 *      (pay_url), where they log in with their own KMoney credentials
 *      (2FA/passkey supported) and confirm the amount.
 *   3. Confirms the payment through two independent, idempotent paths: a
 *      webhook (authoritative, asynchronous — see handle_webhook()) and a
 *      server-side status check when the customer returns to the store (see
 *      maybe_verify_return()). Neither trusts the browser redirect query
 *      string on its own.
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
		$this->has_fields         = false;
		$this->method_title       = __( 'KMoney', 'kmoney-payment' );
		$this->method_description = __( 'Accetta pagamenti in KY tramite il circuito KMoney. Il cliente viene reindirizzato su KMoney per autenticarsi e confermare: nessun dato KMoney transita su questo sito.', 'kmoney-payment' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->instructions = $this->get_option( 'instructions' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'maybe_verify_return' ), 5 );

		// Endpoint webhook: https://tuosito.com/?wc-api=wc_gateway_kmoney
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_webhook' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Abilita/Disabilita', 'kmoney-payment' ),
				'label'   => __( 'Abilita KMoney', 'kmoney-payment' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Titolo', 'kmoney-payment' ),
				'type'        => 'text',
				'description' => __( 'Il nome del metodo di pagamento mostrato al cliente al checkout.', 'kmoney-payment' ),
				'default'     => __( 'KMoney', 'kmoney-payment' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Descrizione', 'kmoney-payment' ),
				'type'        => 'textarea',
				'description' => __( 'Descrizione mostrata al checkout, sotto il nome del metodo.', 'kmoney-payment' ),
				'default'     => __( 'Paga con il tuo conto KMoney. Verrai reindirizzato su KMoney per confermare il pagamento in sicurezza.', 'kmoney-payment' ),
			),
			'instructions'   => array(
				'title'       => __( 'Istruzioni', 'kmoney-payment' ),
				'type'        => 'textarea',
				'description' => __( 'Mostrate nella pagina di ringraziamento e nell\'email dell\'ordine.', 'kmoney-payment' ),
				'default'     => __( 'Grazie per aver pagato con KMoney.', 'kmoney-payment' ),
			),
			'api_base_url'   => array(
				'title'       => __( 'URL base API KMoney', 'kmoney-payment' ),
				'type'        => 'text',
				'description' => __( 'Es. https://kmoney.tuodominio.com/api/v1 (senza slash finale).', 'kmoney-payment' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'api_token'      => array(
				'title'       => __( 'Token API KMoney', 'kmoney-payment' ),
				'type'        => 'password',
				'description' => __( 'Token generato dal portale KMoney in /api-tokens, con ability "write" abilitata.', 'kmoney-payment' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'webhook_secret' => array(
				'title'       => __( 'Secret webhook', 'kmoney-payment' ),
				'type'        => 'password',
				/* translators: %s: webhook callback URL */
				'description' => sprintf(
					__( 'Il "secret" mostrato una sola volta alla creazione del webhook sul portale KMoney (Impostazioni &gt; Webhook, evento payment_request.paid). URL webhook da registrare: %s', 'kmoney-payment' ),
					'<code>' . esc_html( home_url( '/?wc-api=' . strtolower( get_class( $this ) ) ) ) . '</code>'
				),
				'default'     => '',
			),
		);
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! $this->get_option( 'api_base_url' ) || ! $this->get_option( 'api_token' ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Avvia il pagamento: crea la richiesta di pagamento su KMoney e restituisce
	 * l'URL su cui WooCommerce reindirizzerà il browser del cliente.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Ordine non trovato.', 'kmoney-payment' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// KMoney amounts are integer cents of KY. This assumes a 1:1 KY <> store
		// currency mapping (typical for local-currency circuits) — adjust here
		// if that is not the case for this store.
		$amount_cents = (int) round( ( (float) $order->get_total() ) * 100 );

		$client = new Kmoney_Api_Client( $this->get_option( 'api_base_url' ), $this->get_option( 'api_token' ) );

		try {
			$payment_request = $client->create_payment_request(
				$amount_cents,
				/* translators: %s: order number */
				sprintf( __( 'Ordine #%s', 'kmoney-payment' ), $order->get_order_number() ),
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

		$order->update_meta_data( '_kmoney_pr_uuid', sanitize_text_field( $payment_request['uuid'] ) );
		if ( ! empty( $payment_request['token'] ) ) {
			$order->update_meta_data( '_kmoney_pr_token', sanitize_text_field( $payment_request['token'] ) );
		}
		$order->update_status( 'on-hold', __( 'KMoney: in attesa di conferma del pagamento.', 'kmoney-payment' ) );
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
		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
		}
	}

	/**
	 * Quando il cliente torna sulla pagina di ringraziamento (redirect da KMoney
	 * dopo un pagamento riuscito), verifica lo stato server-side prima di
	 * considerare l'ordine pagato — non ci si fida mai dei soli parametri
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
		if ( $order->is_paid() ) {
			return;
		}

		$pr_uuid = isset( $_GET['kmoney_pr_uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['kmoney_pr_uuid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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

### `kmoney-payment/includes/class-kmoney-order-finalizer.php`

```php
<?php
/**
 * Turns a confirmed KMoney payment_request.paid event into a completed
 * WooCommerce order payment.
 *
 * Shared by the "return" verification (customer comes back from KMoney,
 * WC_Gateway_Kmoney::maybe_verify_return) and the webhook handler
 * (WC_Gateway_Kmoney::handle_webhook). Both paths can race each other, so
 * this is written to be idempotent: it does nothing if the order is already
 * marked as paid.
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
		if ( $order->is_paid() || $order->get_meta( '_kmoney_transfer_uuid' ) ) {
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
}
```
