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
