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
