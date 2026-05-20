<?php
/**
 * Allscale checkout-intent status codes and helpers.
 *
 * Source of truth: the Allscale checkout skill status enum table.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Status_Codes {

	// Success / progress.
	const CREATED              = 1;
	const PAYING               = 2;
	const TEMP_WALLET_RECEIVED = 3;
	const PENDING_MANUAL_OPERATION = 4;
	const SEND_BACK            = 5;
	const ON_CHAIN             = 10;
	const CONFIRMED            = 20;

	// Terminal failures.
	const FAILED    = -1;
	const REJECTED  = -2;
	const UNDERPAID = -3;
	const CANCELED  = -4;
	const TIMEOUT   = -5;

	/**
	 * Whether the status is terminal (no further transitions expected).
	 *
	 * @param int $status Allscale status integer.
	 * @return bool
	 */
	public static function is_terminal( $status ) {
		$status = (int) $status;
		return $status === self::CONFIRMED
			|| $status === self::FAILED
			|| $status === self::REJECTED
			|| $status === self::UNDERPAID
			|| $status === self::CANCELED
			|| $status === self::TIMEOUT;
	}

	/**
	 * Whether the status represents a successful payment.
	 *
	 * @param int $status Allscale status integer.
	 * @return bool
	 */
	public static function is_success( $status ) {
		return (int) $status === self::CONFIRMED;
	}

	/**
	 * Whether the status represents a payment that won't complete.
	 *
	 * @param int $status Allscale status integer.
	 * @return bool
	 */
	public static function is_failure( $status ) {
		$status = (int) $status;
		return $status === self::FAILED
			|| $status === self::REJECTED
			|| $status === self::UNDERPAID
			|| $status === self::CANCELED
			|| $status === self::TIMEOUT;
	}

	/**
	 * Human-readable label for a status code (i18n-friendly).
	 *
	 * @param int $status Allscale status integer.
	 * @return string Translated label.
	 */
	public static function label( $status ) {
		$labels = array(
			self::CREATED                   => __( 'Created', 'allscale-checkout' ),
			self::PAYING                    => __( 'Paying', 'allscale-checkout' ),
			self::TEMP_WALLET_RECEIVED      => __( 'Deposit wallet assigned', 'allscale-checkout' ),
			self::PENDING_MANUAL_OPERATION  => __( 'Manual review', 'allscale-checkout' ),
			self::SEND_BACK                 => __( 'Refund in progress', 'allscale-checkout' ),
			self::ON_CHAIN                  => __( 'On-chain (awaiting confirmation)', 'allscale-checkout' ),
			self::CONFIRMED                 => __( 'Confirmed', 'allscale-checkout' ),
			self::FAILED                    => __( 'Failed', 'allscale-checkout' ),
			self::REJECTED                  => __( 'Rejected', 'allscale-checkout' ),
			self::UNDERPAID                 => __( 'Underpaid', 'allscale-checkout' ),
			self::CANCELED                  => __( 'Canceled', 'allscale-checkout' ),
			self::TIMEOUT                   => __( 'Timed out', 'allscale-checkout' ),
		);

		$status = (int) $status;
		return isset( $labels[ $status ] )
			? $labels[ $status ]
			/* translators: %d is the unknown Allscale status integer */
			: sprintf( __( 'Unknown (%d)', 'allscale-checkout' ), $status );
	}
}
