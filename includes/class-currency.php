<?php
/**
 * ISO ↔ Allscale currency-enum mapping.
 *
 * Source of truth: the Allscale checkout skill currency table.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Currency {

	const STABLE_COIN_USDT = 1;

	/**
	 * ISO 4217 → Allscale enum integer.
	 *
	 * @var array<string,int>
	 */
	private static $map = array(
		'USD' => 1,
		'AUD' => 9,
		'CAD' => 27,
		'CNY' => 31,
		'EUR' => 44,
		'GBP' => 48,
		'HKD' => 57,
		'JPY' => 72,
		'SGD' => 126,
	);

	/**
	 * Look up the Allscale enum integer for an ISO 4217 code.
	 *
	 * @param string $iso ISO 4217 code (case insensitive).
	 * @return int|null Null when unsupported.
	 */
	public static function to_enum( $iso ) {
		$iso = strtoupper( (string) $iso );
		return isset( self::$map[ $iso ] ) ? self::$map[ $iso ] : null;
	}

	/**
	 * Whether the ISO 4217 code is supported by Allscale.
	 *
	 * @param string $iso ISO 4217 code.
	 * @return bool
	 */
	public static function is_supported( $iso ) {
		return self::to_enum( $iso ) !== null;
	}

	/**
	 * List of supported ISO 4217 codes.
	 *
	 * @return string[]
	 */
	public static function supported_codes() {
		return array_keys( self::$map );
	}
}
