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
