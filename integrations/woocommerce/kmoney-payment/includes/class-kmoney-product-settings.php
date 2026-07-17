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
