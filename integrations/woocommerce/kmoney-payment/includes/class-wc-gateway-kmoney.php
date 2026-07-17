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
