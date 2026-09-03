<?php
/**
 * Mutex around order state mutations.
 *
 * Webhook delivery and the customer's return-URL fallback can race; both
 * try to mutate the same order. We wrap every order write inside a lock
 * so notes don't double up and payment_complete isn't attempted twice.
 *
 * Uses an atomic row claim in wp_options so separate PHP workers cannot both
 * enter the critical section.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Order_Locker {

	const LOCK_TTL_SECONDS = 120;
	const LOCK_WAIT_MILLISECONDS = 3000;

	/**
	 * Run a callback under a lock for an order.
	 *
	 * If the lock cannot be acquired, the callable is not invoked. Webhook
	 * callers can then return a retryable response instead of mutating an order
	 * without mutual exclusion.
	 *
	 * @param int      $order_id WooCommerce order id.
	 * @param callable $fn       Callback executed under the lock.
	 * @param Logger   $logger   Logger.
	 * @return mixed|null Return value of $fn, or null when lock acquisition fails.
	 */
	public static function with_lock( $order_id, callable $fn, Logger $logger ) {
		$order_id = (int) $order_id;

		$lock = Atomic_Lock::acquire( 'order:' . $order_id, self::LOCK_TTL_SECONDS, self::LOCK_WAIT_MILLISECONDS );
		if ( ! is_array( $lock ) ) {
			$logger->warning( 'Order lock not acquired; deferring processing', array( 'order_id' => $order_id ) );
			return null;
		}

		try {
			return $fn();
		} finally {
			Atomic_Lock::release( $lock );
		}
	}
}
