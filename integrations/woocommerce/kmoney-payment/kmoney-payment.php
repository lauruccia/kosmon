<?php
/**
 * Plugin Name: KMoney Payment Gateway
 * Description: Accetta pagamenti KMoney (KY) su WooCommerce tramite checkout hosted sicuro: il cliente viene reindirizzato su KMoney per autenticarsi (2FA/passkey) e confermare l'importo. Supporta pagamento misto KY + euro con percentuale configurabile per negozio, categoria o singolo prodotto. Nessuna credenziale KMoney del cliente viene mai raccolta o gestita da questo sito.
 * Version: 2.2.0
 * Requires Plugins: woocommerce
 * Author: KMoney
 * Text Domain: kmoney-payment
 *
 * @package KMoneyPayment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KMONEY_PAYMENT_VERSION', '2.2.0' );
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
	require_once KMONEY_PAYMENT_PLUGIN_DIR . 'includes/class-kmoney-pairing.php';
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
