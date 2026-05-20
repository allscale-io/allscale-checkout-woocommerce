<?php
/**
 * Mutex around order state mutations.
 *
 * Webhook delivery and the customer's return-URL fallback can race; both
 * try to mutate the same order. We wrap every order write inside a lock
 * so notes don't double up and payment_complete isn't attempted twice.
 *
 * Uses WooCommerce 8.6+'s wc_get_order_lock when available, falls back to
 * a transient-based mutex otherwise.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Order_Locker {

	const LOCK_TTL_SECONDS = 30;

	/**
	 * Run a callback under a lock for an order.
	 *
	 * If the lock can't be acquired the callable is still invoked (we never
	 * want to leave an order stuck because of a failed lock) but a warning
	 * is logged.
	 *
	 * @param int      $order_id WooCommerce order id.
	 * @param callable $fn       Callback executed under the lock.
	 * @param Logger   $logger   Logger.
	 * @return mixed Return value of $fn.
	 */
	public static function with_lock( $order_id, callable $fn, Logger $logger ) {
		$order_id = (int) $order_id;

		// WC 8.6+ — preferred path.
		if ( function_exists( 'wc_get_order_lock' ) ) {
			// wc_get_order_lock is documented in WC core; signature: wc_get_order_lock( $order_id, $context = 'process_payment' ).
			$got = wc_get_order_lock( $order_id, 'allscale_checkout' );
			try {
				return $fn();
			} finally {
				if ( function_exists( 'wc_release_order_lock' ) ) {
					wc_release_order_lock( $order_id, 'allscale_checkout' );
				}
				unset( $got );
			}
		}

		// Fallback — transient-based mutex with a short TTL.
		$lock_key = 'allscale_order_lock_' . $order_id;
		$acquired = false;
		for ( $i = 0; $i < 30; $i++ ) {
			if ( ! get_transient( $lock_key ) ) {
				set_transient( $lock_key, time(), self::LOCK_TTL_SECONDS );
				$acquired = true;
				break;
			}
			usleep( 100000 ); // 100ms.
		}

		if ( ! $acquired ) {
			$logger->warning( 'Order lock not acquired; proceeding without it', array( 'order_id' => $order_id ) );
		}

		try {
			return $fn();
		} finally {
			if ( $acquired ) {
				delete_transient( $lock_key );
			}
		}
	}
}
