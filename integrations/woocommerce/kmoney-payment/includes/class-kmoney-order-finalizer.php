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
