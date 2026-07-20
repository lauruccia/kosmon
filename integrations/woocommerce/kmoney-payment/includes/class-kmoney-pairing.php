<?php
/**
 * Collegamento del conto KMoney con il solo numero di conto.
 *
 * Il negoziante inserisce il numero di conto (KYB...) nelle impostazioni del
 * gateway e salva: il plugin invia una richiesta di collegamento a KMoney
 * (POST /api/v1/ecommerce/pairings) con un claim_secret generato qui.
 * L'amministratore del circuito approva da /admin/companies/{id}; alla
 * successiva apertura (o salvataggio) delle impostazioni il plugin ritira
 * token API e secret webhook — una sola volta, autenticandosi con il
 * claim_secret — e li salva da solo. Nessun copia-incolla.
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kmoney_Pairing {

	const OPTION = 'kmoney_pairing_state';

	/**
	 * Stato corrente del collegamento.
	 *
	 * @return array{uuid?:string,claim_secret?:string,account_number?:string,status?:string,message?:string}
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function save_state( $state ) {
		update_option( self::OPTION, $state, false );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Dopo il salvataggio delle impostazioni: avvia (o riavvia) il pairing se
	 * c'è un numero di conto ma non ancora un token, oppure se il numero di
	 * conto è cambiato rispetto alla richiesta precedente.
	 *
	 * @param WC_Payment_Gateway $gateway
	 */
	public static function maybe_start( $gateway ) {
		$account_number = strtoupper( preg_replace( '/\s+/', '', (string) $gateway->get_option( 'account_number' ) ) );

		if ( '' === $account_number ) {
			self::clear();
			return;
		}

		$state = self::state();

		$account_changed = isset( $state['account_number'] ) && $state['account_number'] !== $account_number;
		$has_token       = (string) $gateway->get_option( 'api_token' ) !== '';

		// Già collegato con lo stesso conto, o richiesta già in corso: nulla da fare.
		if ( ! $account_changed && ( $has_token || ( isset( $state['status'] ) && 'pending' === $state['status'] ) ) ) {
			return;
		}

		$base_url = rtrim( (string) $gateway->get_option( 'api_base_url' ), '/' );
		if ( '' === $base_url ) {
			self::save_state(
				array(
					'status'  => 'error',
					'message' => __( 'URL base API KMoney mancante.', 'kmoney-payment' ),
				)
			);
			return;
		}

		$claim_secret = wp_generate_password( 40, false, false );

		$response = wp_remote_post(
			$base_url . '/ecommerce/pairings',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'account_number' => $account_number,
						'site_url'       => home_url( '/' ),
						'webhook_url'    => home_url( '/?wc-api=wc_gateway_kmoney' ),
						'claim_secret'   => $claim_secret,
						'platform'       => 'woocommerce',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::save_state(
				array(
					'account_number' => $account_number,
					'status'         => 'error',
					'message'        => sprintf(
						/* translators: %s: error message */
						__( 'Impossibile contattare KMoney: %s', 'kmoney-payment' ),
						$response->get_error_message()
					),
				)
			);
			return;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 === $status && is_array( $decoded ) && ! empty( $decoded['uuid'] ) ) {
			self::save_state(
				array(
					'uuid'           => sanitize_text_field( $decoded['uuid'] ),
					'claim_secret'   => $claim_secret,
					'account_number' => $account_number,
					'status'         => 'pending',
				)
			);
			return;
		}

		$message = is_array( $decoded ) && ! empty( $decoded['error'] )
			? $decoded['error']
			: sprintf(
				/* translators: %d: HTTP status code */
				__( 'Risposta inattesa da KMoney (HTTP %d).', 'kmoney-payment' ),
				$status
			);

		self::save_state(
			array(
				'account_number' => $account_number,
				'status'         => 'error',
				'message'        => sanitize_text_field( $message ),
			)
		);
	}

	/**
	 * All'apertura della pagina impostazioni: se c'è una richiesta in attesa,
	 * verifica lo stato; se approvata, ritira le credenziali e le salva nelle
	 * impostazioni del gateway.
	 *
	 * @param WC_Payment_Gateway $gateway
	 */
	public static function maybe_poll( $gateway ) {
		$state = self::state();

		if ( ! isset( $state['status'] ) || 'pending' !== $state['status'] || empty( $state['uuid'] ) || empty( $state['claim_secret'] ) ) {
			return;
		}

		$base_url = rtrim( (string) $gateway->get_option( 'api_base_url' ), '/' );
		if ( '' === $base_url ) {
			return;
		}

		$response = wp_remote_get(
			$base_url . '/ecommerce/pairings/' . rawurlencode( $state['uuid'] ) . '?claim_secret=' . rawurlencode( $state['claim_secret'] ),
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return; // Errore di rete temporaneo: riproverà alla prossima apertura.
		}

		$http    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		if ( 404 === $http ) {
			// La richiesta non esiste più lato KMoney (es. sostituita): riparte
			// da capo al prossimo salvataggio.
			$state['status']  = 'error';
			$state['message'] = __( 'La richiesta di collegamento non è più valida: salva di nuovo le impostazioni per inviarne una nuova.', 'kmoney-payment' );
			self::save_state( $state );
			return;
		}

		$remote_status = isset( $decoded['status'] ) ? $decoded['status'] : '';

		if ( 'approved' === $remote_status && ! empty( $decoded['api_token'] ) ) {
			// Consegna una tantum: salva subito le credenziali nel gateway.
			$gateway->update_option( 'api_token', sanitize_text_field( $decoded['api_token'] ) );
			if ( ! empty( $decoded['webhook_secret'] ) ) {
				$gateway->update_option( 'webhook_secret', sanitize_text_field( $decoded['webhook_secret'] ) );
			}

			if ( class_exists( 'Kmoney_Merchant_Status' ) ) {
				Kmoney_Merchant_Status::flush_cache();
			}

			self::save_state(
				array(
					'account_number' => isset( $state['account_number'] ) ? $state['account_number'] : '',
					'status'         => 'linked',
					'just_linked'    => 1, // per mostrare l'avviso di successo una volta
				)
			);
			return;
		}

		if ( 'approved' === $remote_status && ! empty( $decoded['claimed'] ) ) {
			// Credenziali già ritirate (es. da un'altra installazione) ma qui non
			// presenti: serve un nuovo collegamento.
			$state['status']  = 'error';
			$state['message'] = __( 'Le credenziali di questo collegamento risultano già ritirate. Salva di nuovo le impostazioni per inviare una nuova richiesta di collegamento.', 'kmoney-payment' );
			self::save_state( $state );
			return;
		}

		if ( 'rejected' === $remote_status ) {
			$state['status']  = 'rejected';
			$state['message'] = __( 'L\'amministratore del circuito ha rifiutato la richiesta di collegamento. Controlla il numero di conto o contatta l\'assistenza KMoney.', 'kmoney-payment' );
			self::save_state( $state );
		}
		// "pending": nessun cambiamento, si riproverà alla prossima apertura.
	}

	/**
	 * Avvisi da mostrare in testa alla pagina impostazioni del gateway.
	 * Restituisce HTML già escapato.
	 *
	 * @return string
	 */
	public static function admin_notice_html() {
		$state = self::state();

		if ( empty( $state['status'] ) ) {
			return '';
		}

		if ( 'pending' === $state['status'] ) {
			return '<div class="notice notice-warning inline"><p><strong>' .
				esc_html__( 'Collegamento in attesa di approvazione.', 'kmoney-payment' ) . '</strong> ' .
				esc_html( sprintf(
					/* translators: %s: account number */
					__( 'La richiesta per il conto %s è stata inviata all\'amministratore del circuito KMoney. Appena approvata, il plugin si configurerà da solo: ricarica questa pagina (o salva) per verificare.', 'kmoney-payment' ),
					isset( $state['account_number'] ) ? $state['account_number'] : ''
				) ) .
				'</p></div>';
		}

		if ( 'linked' === $state['status'] && ! empty( $state['just_linked'] ) ) {
			// Mostra il successo una volta sola.
			unset( $state['just_linked'] );
			self::save_state( $state );

			return '<div class="notice notice-success inline"><p><strong>' .
				esc_html__( 'Conto collegato!', 'kmoney-payment' ) . '</strong> ' .
				esc_html__( 'Token API e webhook sono stati configurati automaticamente. Ricordati di abilitare il metodo di pagamento se non l\'hai già fatto.', 'kmoney-payment' ) .
				'</p></div>';
		}

		if ( in_array( $state['status'], array( 'error', 'rejected' ), true ) ) {
			return '<div class="notice notice-error inline"><p><strong>' .
				esc_html__( 'Collegamento non riuscito:', 'kmoney-payment' ) . '</strong> ' .
				esc_html( isset( $state['message'] ) ? $state['message'] : '' ) .
				'</p></div>';
		}

		return '';
	}
}
