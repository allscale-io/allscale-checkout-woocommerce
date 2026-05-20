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
	 *                           Keys: tx_hash, paid_cents, payment_method_type, chain_id,
	 *                           actual_paid_amount, service_fee_amount, net_income_amount,
	 *                           coin_symbol, amount_coins, source ('webhook'|'return_url').
	 * @param Logger    $logger  Logger.
	 */
	public static function apply( \WC_Order $order, $status, array $context, Logger $logger ) {
		$status = (int) $status;

		// Don't touch terminally-finished orders.
		$current_status = $order->get_status();
		if ( in_array( $current_status, array( 'completed', 'refunded' ), true ) ) {
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
				if ( $current_status !== 'failed' ) {
					$order->update_status(
						'failed',
						__( 'Allscale: payment failed.', 'allscale-checkout' )
					);
				}
				return;

			case Status_Codes::REJECTED:
				if ( $current_status !== 'failed' ) {
					$order->update_status(
						'failed',
						__( 'Allscale: payment rejected by compliance check (KYT).', 'allscale-checkout' )
					);
				}
				return;

			case Status_Codes::UNDERPAID:
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
				if ( $current_status !== 'cancelled' ) {
					$order->update_status(
						'cancelled',
						__( 'Allscale: payment canceled by the customer.', 'allscale-checkout' )
					);
				}
				return;

			case Status_Codes::TIMEOUT:
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
