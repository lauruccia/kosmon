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
