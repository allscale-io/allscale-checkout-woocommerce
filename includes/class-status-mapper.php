<?php
/**
 * Apply an Allscale status to a WooCommerce order.
 *
 * One decision table, shared between the webhook handler and the return-URL
 * fallback so both paths produce identical behavior.
 *
 * The mapper short-circuits on already-final orders and on identical
 * status transitions to avoid duplicate notes.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Status_Mapper {

	const META_INTENT_ID            = '_allscale_intent_id';
	const META_CHECKOUT_URL         = '_allscale_checkout_url';
	const META_INTENT_AMOUNT_CENTS  = '_allscale_intent_amount_cents';
	const META_SETTLED_INTENT_ID    = '_allscale_settled_intent_id';
	const META_DUPLICATE_PAYMENT    = '_allscale_duplicate_payment_intent_id';
	const META_LATE_PAYMENT         = '_allscale_late_payment_intent_id';
	const META_PROCESSED_WEBHOOK_ID = '_allscale_processed_webhook_id';
	// Non-unique meta: one row per superseded intent. When the customer
	// re-enters process_payment (double-click, pay-for-order retry) a fresh
	// intent replaces META_INTENT_ID, but the old one is still live on the
	// Allscale side and can still be paid. Keeping every prior id lets the
	// webhook and the thank-you fallback recognise that payment instead of
	// dropping it as "no order matches".
	const META_PRIOR_INTENT_ID      = '_allscale_prior_intent_id';
	const META_TX_HASH              = '_allscale_tx_hash';
	const META_STATUS               = '_allscale_status';
	const META_PAYMENT_METHOD_TYPE  = '_allscale_payment_method_type';
	const META_CHAIN_ID             = '_allscale_chain_id';
	const META_ACTUAL_PAID_AMOUNT   = '_allscale_actual_paid_amount';
	const META_SERVICE_FEE_AMOUNT   = '_allscale_service_fee_amount';
	const META_NET_INCOME_AMOUNT    = '_allscale_net_income_amount';
	const META_COIN_SYMBOL          = '_allscale_coin_symbol';
	const META_AMOUNT_COINS         = '_allscale_amount_coins';

	/**
	 * Apply a status change to the order.
	 *
	 * @param \WC_Order $order   The order.
	 * @param int       $status  Allscale status integer.
	 * @param array     $context Extra fields from the webhook payload or details endpoint.
	 *                           Keys: intent_id, tx_hash, paid_cents, payment_method_type, chain_id,
	 *                           actual_paid_amount, service_fee_amount, net_income_amount,
	 *                           coin_symbol, amount_coins, source ('webhook'|'return_url').
	 * @param Logger    $logger  Logger.
	 */
	public static function apply( \WC_Order $order, $status, array $context, Logger $logger ) {
		$status = (int) $status;
		$incoming_intent_id = isset( $context['intent_id'] ) ? (string) $context['intent_id'] : '';
		$current_status = $order->get_status();

		// Never auto-fulfil an order whose stock has already been restored, but
		// do not lose a real payment that confirms after WooCommerce cancelled it.
		if ( $status === Status_Codes::CONFIRMED && $current_status === 'cancelled' ) {
			self::record_late_payment( $order, $incoming_intent_id, $context, $logger );
			return;
		}

		// A second confirmed intent means funds arrived twice. Never run payment
		// completion again, but persist an explicit reconciliation signal instead
		// of silently discarding the later settlement as "already paid".
		if ( $status === Status_Codes::CONFIRMED && $order->is_paid() ) {
			self::record_duplicate_payment( $order, $incoming_intent_id, $context, $logger );
			return;
		}

		// Don't touch terminally-finished orders.
		// `cancelled` is included: if an order was auto-cancelled by WC's
		// "Hold stock" timeout and Allscale later confirms a payment, we don't
		// want to re-process — stock was already restored, and the merchant
		// must intervene manually.
		if ( in_array( $current_status, array( 'completed', 'refunded', 'cancelled' ), true ) ) {
			$logger->info(
				'Skipping Allscale status update on terminal order',
				array(
					'order_id'       => $order->get_id(),
					'current_status' => $current_status,
					'incoming'       => $status,
				)
			);
			return;
		}

		// Persist the rich metadata before we mutate status, so the order detail
		// box always reflects the latest data even if the status branch is a no-op.
		self::persist_meta( $order, $status, $context );

		$tx_hash = isset( $context['tx_hash'] ) ? (string) $context['tx_hash'] : '';
		$source  = isset( $context['source'] ) ? (string) $context['source'] : 'unknown';

		$logger->info(
			'Applying Allscale status to order',
			array(
				'order_id' => $order->get_id(),
				'status'   => $status,
				'source'   => $source,
				'tx_hash'  => $tx_hash,
			)
		);

		switch ( $status ) {
			case Status_Codes::CREATED:
				$order->add_order_note(
					__( 'Allscale: checkout intent created.', 'allscale-checkout' )
				);
				$order->save();
				return;

			case Status_Codes::PAYING:
				$order->add_order_note(
					__( 'Allscale: customer is on the checkout page.', 'allscale-checkout' )
				);
				$order->save();
				return;

			case Status_Codes::TEMP_WALLET_RECEIVED:
				$order->add_order_note(
					__( 'Allscale: deposit wallet assigned for the payment.', 'allscale-checkout' )
				);
				$order->save();
				return;

			case Status_Codes::PENDING_MANUAL_OPERATION:
				if ( $current_status !== 'on-hold' ) {
					$order->update_status(
						'on-hold',
						__( 'Allscale: pending manual review.', 'allscale-checkout' )
					);
				}
				return;

			case Status_Codes::SEND_BACK:
				$order->add_order_note(
					__( 'Allscale: refund in progress on the Allscale side.', 'allscale-checkout' )
				);
				$order->save();
				return;

			case Status_Codes::ON_CHAIN:
				$order->add_order_note(
					__( 'Allscale: transaction detected on-chain, awaiting confirmation.', 'allscale-checkout' )
				);
				$order->save();
				return;

			case Status_Codes::CONFIRMED:
				self::handle_confirmed( $order, $context, $tx_hash, $logger );
				return;

			case Status_Codes::FAILED:
				if ( self::guard_paid_order( $order, $status, $logger ) ) {
					return;
				}
				if ( $current_status !== 'failed' ) {
					$order->update_status(
						'failed',
						__( 'Allscale: payment failed.', 'allscale-checkout' )
					);
				}
				return;

			case Status_Codes::REJECTED:
				if ( self::guard_paid_order( $order, $status, $logger ) ) {
					return;
				}
				if ( $current_status !== 'failed' ) {
					$order->update_status(
						'failed',
						__( 'Allscale: payment rejected by compliance check (KYT).', 'allscale-checkout' )
					);
				}
				return;

			case Status_Codes::UNDERPAID:
				if ( self::guard_paid_order( $order, $status, $logger ) ) {
					return;
				}
				if ( $current_status !== 'on-hold' ) {
					$paid_cents = isset( $context['paid_cents'] ) ? (int) $context['paid_cents'] : 0;
					$order->update_status(
						'on-hold',
						sprintf(
							/* translators: %d is the amount received, in cents */
							__( 'Allscale: payment underpaid. Received %d cents.', 'allscale-checkout' ),
							$paid_cents
						)
					);
				}
				return;

			case Status_Codes::CANCELED:
				if ( self::guard_paid_order( $order, $status, $logger ) ) {
					return;
				}
				if ( $current_status !== 'cancelled' ) {
					$order->update_status(
						'cancelled',
						__( 'Allscale: payment canceled by the customer.', 'allscale-checkout' )
					);
				}
				return;

			case Status_Codes::TIMEOUT:
				if ( self::guard_paid_order( $order, $status, $logger ) ) {
					return;
				}
				if ( $current_status !== 'cancelled' ) {
					$order->update_status(
						'cancelled',
						__( 'Allscale: payment intent timed out without a transaction.', 'allscale-checkout' )
					);
				}
				return;

			default:
				$logger->warning(
					'Unmapped Allscale status; ignoring',
					array(
						'order_id' => $order->get_id(),
						'status'   => $status,
					)
				);
				return;
		}
	}

	/**
	 * Confirmed path — verify amount, complete the order.
	 */
	private static function handle_confirmed( \WC_Order $order, array $context, $tx_hash, Logger $logger ) {
		if ( $order->is_paid() ) {
			$logger->debug( 'Order already paid; skipping payment_complete', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$expected_cents = (int) round( (float) $order->get_total() * 100 );
		$paid_cents     = isset( $context['paid_cents'] ) ? (int) $context['paid_cents'] : 0;

		// If the API only returned the stable-coin amount, accept on the
		// confirmed-status signal alone — Allscale already validated the amount
		// against what we sent.
		if ( $paid_cents > 0 && $paid_cents < $expected_cents ) {
			// persist_meta() already stamped META_STATUS = CONFIRMED before we
			// got here, and the thank-you block / order meta box render from
			// that meta. Left as-is, an underpaying customer sees "Payment
			// confirmed" while the order sits on-hold. Record the outcome we
			// actually applied.
			$order->update_meta_data( self::META_STATUS, Status_Codes::UNDERPAID );
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: expected cents, 2: received cents */
					__( 'Allscale: payment amount mismatch. Expected %1$d cents, received %2$d cents.', 'allscale-checkout' ),
					$expected_cents,
					$paid_cents
				)
			);
			return;
		}

		$intent_id = isset( $context['intent_id'] ) ? (string) $context['intent_id'] : '';
		if ( $intent_id !== '' ) {
			$order->update_meta_data( self::META_SETTLED_INTENT_ID, $intent_id );
			$order->save();
		}

		$order->payment_complete( (string) $tx_hash );
		$order->add_order_note(
			$tx_hash
				? sprintf(
					/* translators: %s is the on-chain transaction hash */
					__( 'Allscale payment confirmed. Tx: %s', 'allscale-checkout' ),
					$tx_hash
				)
				: __( 'Allscale payment confirmed.', 'allscale-checkout' )
		);
	}

	/**
	 * Record a second confirmed intent on an order that is already paid.
	 *
	 * @param \WC_Order $order              Paid order.
	 * @param string    $incoming_intent_id Incoming intent id.
	 * @param array     $context            Payment context.
	 * @param Logger    $logger             Logger.
	 */
	private static function record_duplicate_payment( \WC_Order $order, $incoming_intent_id, array $context, Logger $logger ) {
		$settled_intent_id = (string) $order->get_meta( self::META_SETTLED_INTENT_ID );
		$existing_tx_hash  = (string) $order->get_meta( self::META_TX_HASH );
		$incoming_tx_hash  = isset( $context['tx_hash'] ) ? (string) $context['tx_hash'] : '';

		$is_same_intent = $incoming_intent_id !== '' && $settled_intent_id !== '' && $incoming_intent_id === $settled_intent_id;
		$is_same_tx     = $incoming_tx_hash !== '' && $existing_tx_hash !== '' && $incoming_tx_hash === $existing_tx_hash;
		if ( $is_same_intent || $is_same_tx ) {
			$logger->debug( 'Order already paid; duplicate confirmation ignored', array( 'order_id' => $order->get_id() ) );
			return;
		}

		// Older orders may not have META_SETTLED_INTENT_ID. If neither identifier
		// can prove this is a different payment, keep the existing paid state and
		// avoid a false merchant alert.
		if ( $settled_intent_id === '' && ( $existing_tx_hash === '' || $incoming_tx_hash === '' ) ) {
			$logger->warning(
				'Could not determine whether confirmation on paid order is a duplicate settlement',
				array(
					'order_id'  => $order->get_id(),
					'intent_id' => $incoming_intent_id,
				)
			);
			return;
		}

		$recorded = array();
		foreach ( (array) $order->get_meta( self::META_DUPLICATE_PAYMENT, false ) as $meta ) {
			$recorded[] = is_object( $meta ) && isset( $meta->value ) ? (string) $meta->value : (string) $meta;
		}
		if ( $incoming_intent_id !== '' && in_array( $incoming_intent_id, $recorded, true ) ) {
			return;
		}

		if ( $incoming_intent_id !== '' ) {
			$order->add_meta_data( self::META_DUPLICATE_PAYMENT, $incoming_intent_id, false );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: Allscale intent id, 2: transaction hash */
				__( 'Allscale: possible duplicate payment received. Intent: %1$s; transaction: %2$s. Review and refund manually if necessary.', 'allscale-checkout' ),
				$incoming_intent_id !== '' ? $incoming_intent_id : __( 'unknown', 'allscale-checkout' ),
				$incoming_tx_hash !== '' ? $incoming_tx_hash : __( 'unknown', 'allscale-checkout' )
			)
		);
		$order->save();

		$logger->warning(
			'Duplicate payment settlement detected',
			array(
				'order_id'          => $order->get_id(),
				'settled_intent_id' => $settled_intent_id,
				'incoming_intent_id' => $incoming_intent_id,
				'tx_hash'           => $incoming_tx_hash,
			)
		);
		do_action( 'allscale_checkout_duplicate_payment_detected', $order, $context );
	}

	/**
	 * Persist and flag a payment that confirms after order cancellation.
	 *
	 * Stock may already have been restored, so this deliberately leaves the
	 * WooCommerce status unchanged and requires merchant reconciliation.
	 *
	 * @param \WC_Order $order              Cancelled order.
	 * @param string    $incoming_intent_id Incoming intent id.
	 * @param array     $context            Payment context.
	 * @param Logger    $logger             Logger.
	 */
	private static function record_late_payment( \WC_Order $order, $incoming_intent_id, array $context, Logger $logger ) {
		$recorded = array();
		foreach ( (array) $order->get_meta( self::META_LATE_PAYMENT, false ) as $meta ) {
			$recorded[] = is_object( $meta ) && isset( $meta->value ) ? (string) $meta->value : (string) $meta;
		}
		if ( $incoming_intent_id !== '' && in_array( $incoming_intent_id, $recorded, true ) ) {
			return;
		}

		self::persist_meta( $order, Status_Codes::CONFIRMED, $context );
		if ( $incoming_intent_id !== '' ) {
			$order->add_meta_data( self::META_LATE_PAYMENT, $incoming_intent_id, false );
		}

		$paid_cents = isset( $context['paid_cents'] ) ? (int) $context['paid_cents'] : 0;
		$tx_hash    = isset( $context['tx_hash'] ) ? (string) $context['tx_hash'] : '';
		$order->add_order_note(
			sprintf(
				/* translators: 1: received cents, 2: Allscale intent id, 3: transaction hash */
				__( 'Allscale: payment confirmed after this order was cancelled. Received %1$d cents; intent: %2$s; transaction: %3$s. The order was not fulfilled automatically—review stock and refund or fulfil manually.', 'allscale-checkout' ),
				$paid_cents,
				$incoming_intent_id !== '' ? $incoming_intent_id : __( 'unknown', 'allscale-checkout' ),
				$tx_hash !== '' ? $tx_hash : __( 'unknown', 'allscale-checkout' )
			)
		);
		$order->save();

		$logger->warning(
			'Payment confirmed after WooCommerce order cancellation',
			array(
				'order_id'  => $order->get_id(),
				'intent_id' => $incoming_intent_id,
				'tx_hash'   => $tx_hash,
			)
		);
		do_action( 'allscale_checkout_late_payment_detected', $order, $context );
	}

	/**
	 * Refuse to transition an already-paid order into a failure state.
	 *
	 * Defense against out-of-order webhook delivery: if a CONFIRMED webhook
	 * landed first and we then receive a stale FAILED/REJECTED/UNDERPAID/CANCELED/
	 * TIMEOUT webhook, we log and ignore it rather than reverting a paid order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param int       $status Incoming Allscale status.
	 * @param Logger    $logger Logger.
	 * @return bool True if the order was paid and the caller should bail.
	 */
	private static function guard_paid_order( \WC_Order $order, $status, Logger $logger ) {
		if ( ! $order->is_paid() ) {
			return false;
		}
		$logger->warning(
			'Ignoring failure-state webhook for an already-paid order',
			array(
				'order_id'         => $order->get_id(),
				'incoming_status'  => (int) $status,
				'wc_status'        => $order->get_status(),
			)
		);
		return true;
	}

	/**
	 * Persist webhook/details metadata to order meta.
	 *
	 * @param \WC_Order $order  Order.
	 * @param int       $status Allscale status integer.
	 * @param array     $ctx    Context.
	 */
	private static function persist_meta( \WC_Order $order, $status, array $ctx ) {
		$order->update_meta_data( self::META_STATUS, $status );

		foreach (
			array(
				'tx_hash'             => self::META_TX_HASH,
				'payment_method_type' => self::META_PAYMENT_METHOD_TYPE,
				'chain_id'            => self::META_CHAIN_ID,
				'actual_paid_amount'  => self::META_ACTUAL_PAID_AMOUNT,
				'service_fee_amount'  => self::META_SERVICE_FEE_AMOUNT,
				'net_income_amount'   => self::META_NET_INCOME_AMOUNT,
				'coin_symbol'         => self::META_COIN_SYMBOL,
				'amount_coins'        => self::META_AMOUNT_COINS,
			) as $ctx_key => $meta_key
		) {
			if ( isset( $ctx[ $ctx_key ] ) && $ctx[ $ctx_key ] !== '' && $ctx[ $ctx_key ] !== null ) {
				$order->update_meta_data( $meta_key, $ctx[ $ctx_key ] );
			}
		}

		$order->save();
	}
}
