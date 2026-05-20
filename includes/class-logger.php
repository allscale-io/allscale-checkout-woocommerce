<?php
/**
 * Thin wrapper around wc_get_logger for the allscale-checkout source.
 *
 * Debug messages are only emitted when the gateway's debug-logging setting
 * is on. Info/warning/error are always emitted.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	const SOURCE = 'allscale-checkout';

	/**
	 * Whether debug-level messages should be emitted.
	 *
	 * @var bool
	 */
	private $debug_enabled;

	/**
	 * @param bool $debug_enabled Whether to emit debug messages.
	 */
	public function __construct( $debug_enabled = false ) {
		$this->debug_enabled = (bool) $debug_enabled;
	}

	public function debug( $message, array $context = array() ) {
		if ( ! $this->debug_enabled ) {
			return;
		}
		$this->log( 'debug', $message, $context );
	}

	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	public function warning( $message, array $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param string $level   wc-logger level.
	 * @param string $message Free-form message.
	 * @param array  $context Extra structured data appended to the message.
	 */
	private function log( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( ! $logger ) {
			return;
		}

		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
