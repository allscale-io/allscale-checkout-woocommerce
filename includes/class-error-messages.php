<?php
/**
 * Maps Allscale error codes to localized user-facing copy.
 *
 * Source of truth: the Allscale checkout skill error-codes table and the
 * Step 4.5 credential-validation messages.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Error_Messages {

	const VALIDATION   = 10001;
	const AUTH_MISSING = 20001;
	const BAD_SIGNATURE = 20002;
	const FORBIDDEN    = 30001;
	const RATE_LIMITED = 40001;
	const INTENT_NOT_FOUND = 50001;
	const INTENT_CREATE_FAILED = 50002;
	const INTERNAL = 90000;
	const UNKNOWN = 99999;

	/**
	 * Friendly copy intended for the merchant in the admin UI.
	 *
	 * @param int|null    $code           Allscale error code, or null when unknown.
	 * @param string|null $fallback_text  Backend error text to use if the code isn't mapped.
	 * @return string Localized, user-facing copy.
	 */
	public static function for_admin( $code, $fallback_text = null ) {
		$code = ( $code === null ) ? null : (int) $code;

		switch ( $code ) {
			case self::BAD_SIGNATURE:
				return __(
					'The API secret is incorrect — re-copy it from your Allscale dashboard.',
					'allscale-checkout'
				);
			case self::AUTH_MISSING:
				return __(
					"The API key isn't recognized. Double-check it in your Allscale dashboard.",
					'allscale-checkout'
				);
			case self::FORBIDDEN:
				return __(
					"Allscale's IP allowlist rejected this server. Add your server's outbound IP to your Allscale API settings.",
					'allscale-checkout'
				);
			case self::RATE_LIMITED:
				return __(
					'Allscale rate-limited the request. Try again in a moment.',
					'allscale-checkout'
				);
			case self::VALIDATION:
				return __(
					'Allscale rejected the request as invalid. Check the order details and try again.',
					'allscale-checkout'
				);
			case self::INTENT_NOT_FOUND:
				return __(
					'Allscale could not find this checkout intent.',
					'allscale-checkout'
				);
			case self::INTENT_CREATE_FAILED:
				return __(
					'Allscale could not create the checkout intent. Try again, or check WooCommerce logs.',
					'allscale-checkout'
				);
			case self::INTERNAL:
				return __(
					'Allscale returned an internal error. Try again in a moment.',
					'allscale-checkout'
				);
		}

		if ( $fallback_text ) {
			return (string) $fallback_text;
		}

		return __(
			'Allscale returned an error. Please try again, or check WooCommerce logs.',
			'allscale-checkout'
		);
	}

	/**
	 * Copy intended for the customer at checkout (less technical).
	 *
	 * @param int|null $code Allscale error code.
	 * @return string
	 */
	public static function for_customer( $code ) {
		return __(
			"We couldn't reach our payment provider just now. Please try again in a moment, or use another payment method.",
			'allscale-checkout'
		);
	}
}
