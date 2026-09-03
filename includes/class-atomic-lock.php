<?php
/**
 * Database-backed atomic locks and expiring claims.
 *
 * @package Allscale\Checkout
 */

namespace Allscale\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Atomic_Lock {

	const OPTION_PREFIX = 'allscale_lock_';

	private static $cleaned_expired = false;

	/**
	 * Atomically claim a named resource.
	 *
	 * The wp_options.option_name unique index makes INSERT IGNORE an atomic
	 * compare-and-set across PHP workers. Expired owners are replaced with a
	 * conditional UPDATE, so one worker cannot steal a newly refreshed lock.
	 * Expiration is embedded in the value and stale rows are cleaned on the
	 * next lock-using request.
	 *
	 * @param string $resource          Logical resource name.
	 * @param int    $ttl_seconds       Claim lifetime.
	 * @param int    $wait_milliseconds Maximum time to wait.
	 * @return array|false|null Opaque handle when acquired, false when another
	 *                          owner holds it, or null on a storage failure.
	 */
	public static function acquire( $resource, $ttl_seconds, $wait_milliseconds = 0 ) {
		self::cleanup_expired();

		$ttl_seconds       = max( 1, (int) $ttl_seconds );
		$wait_milliseconds = max( 0, (int) $wait_milliseconds );
		$option_name       = self::OPTION_PREFIX . hash( 'sha256', (string) $resource );
		$owner             = wp_generate_uuid4();
		$deadline          = microtime( true ) + ( $wait_milliseconds / 1000 );

		do {
			$expires_at = time() + $ttl_seconds;
			$value      = $expires_at . ':' . $owner;

			$inserted = self::insert( $option_name, $value );
			if ( $inserted === null ) {
				return null;
			}
			if ( $inserted ) {
				return array(
					'option_name' => $option_name,
					'value'       => $value,
				);
			}

			$current = self::read( $option_name );
			if ( ! $current['ok'] ) {
				return null;
			}
			if ( $current['value'] === null ) {
				// The owner may have released between our INSERT and SELECT.
				// Retry immediately once instead of reporting false contention.
				$inserted = self::insert( $option_name, $value );
				if ( $inserted === null ) {
					return null;
				}
				if ( $inserted ) {
					return array(
						'option_name' => $option_name,
						'value'       => $value,
					);
				}
			}

			if ( $current['value'] !== null && self::is_expired( $current['value'] ) ) {
				$replaced = self::replace( $option_name, $current['value'], $value );
				if ( $replaced === null ) {
					return null;
				}
				if ( $replaced ) {
					return array(
						'option_name' => $option_name,
						'value'       => $value,
					);
				}
			}

			if ( microtime( true ) >= $deadline ) {
				return false;
			}

			$remaining_microseconds = (int) max( 0, ( $deadline - microtime( true ) ) * 1000000 );
			usleep( min( 100000, $remaining_microseconds ) );
		} while ( true );
	}

	/**
	 * Release a claim only when it is still owned by this handle.
	 *
	 * @param array $lock Opaque handle returned by acquire().
	 * @return bool Whether the owned row was removed.
	 */
	public static function release( array $lock ) {
		if ( empty( $lock['option_name'] )
			|| empty( $lock['value'] )
			|| strpos( $lock['option_name'], self::OPTION_PREFIX ) !== 0
		) {
			return false;
		}

		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock['option_name'],
				$lock['value']
			)
		);

		if ( 1 !== (int) $deleted ) {
			return false;
		}

		self::clear_option_cache( $lock['option_name'] );

		return true;
	}

	private static function insert( $option_name, $value ) {
		global $wpdb;
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_name,
				$value
			)
		);

		if ( $inserted === false ) {
			return null;
		}

		if ( 1 === (int) $inserted ) {
			self::clear_option_cache( $option_name );
			return true;
		}

		return false;
	}

	private static function read( $option_name ) {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		if ( $value === null && ! empty( $wpdb->last_error ) ) {
			return array( 'ok' => false, 'value' => null );
		}

		return array(
			'ok'    => true,
			'value' => $value === null ? null : (string) $value,
		);
	}

	private static function replace( $option_name, $expected, $replacement ) {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$replacement,
				$option_name,
				$expected
			)
		);

		if ( $updated === false ) {
			return null;
		}

		if ( 1 === (int) $updated ) {
			self::clear_option_cache( $option_name );
			return true;
		}

		return false;
	}

	private static function is_expired( $value ) {
		$separator = strpos( $value, ':' );
		if ( $separator === false ) {
			return true;
		}

		return (int) substr( $value, 0, $separator ) <= time();
	}

	/**
	 * Remove expired rows once per request.
	 *
	 * These locks deliberately live in the database, so cleanup remains
	 * consistent regardless of the site's object-cache backend.
	 */
	private static function cleanup_expired() {
		if ( self::$cleaned_expired ) {
			return;
		}
		self::$cleaned_expired = true;

		global $wpdb;
		$now = time();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				$now
			)
		);
	}

	private static function clear_option_cache( $option_name ) {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
